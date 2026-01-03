<?php
/**
 * /api/auth/login.php — JWT-based login for mobile apps
 *
 * POST /api/auth/login.php
 * Content-Type: application/json
 *
 * Request:
 * {
 *   "login": "username or email",
 *   "password": "password"
 * }
 *
 * Response (success):
 * {
 *   "ok": true,
 *   "access_token": "eyJ...",
 *   "refresh_token": "eyJ...",
 *   "expires_in": 900,
 *   "user": {
 *     "id": 1,
 *     "username": "dan",
 *     "email": "dan@example.com"
 *   }
 * }
 *
 * Response (error):
 * {
 *   "ok": false,
 *   "error": "invalid_credentials",
 *   "message": "Invalid username or password"
 * }
 */

declare(strict_types=1);

// CORS headers for mobile app
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$root = dirname(__DIR__, 2); // Go up from /api/auth to root
require_once $root . '/db.php';
require_once __DIR__ . '/../jwt_helpers.php';

try {
    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Also accept form data
        $input = $_POST;
    }

    $login = trim((string)($input['login'] ?? $input['email'] ?? $input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($login === '' || $password === '') {
        api_error(400, 'missing_fields', 'Login and password are required');
    }

    // Find user by email or username
    $stmt = $pdo->prepare("
        SELECT id, username, email, password_hash, COALESCE(is_admin, 0) AS is_admin
        FROM users
        WHERE email = :login OR username = :login
        LIMIT 1
    ");
    $stmt->execute([':login' => $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Use same error for security (don't reveal if user exists)
        api_error(401, 'invalid_credentials', 'Invalid username or password');
    }

    // Verify password
    $storedHash = (string)$user['password_hash'];
    $passwordInfo = password_get_info($storedHash);

    if ($passwordInfo['algo']) {
        // Standard password_hash format
        $valid = password_verify($password, $storedHash);
    } else {
        // Legacy plain comparison (shouldn't be used, but handle it)
        $valid = hash_equals($storedHash, $password);
    }

    if (!$valid) {
        api_error(401, 'invalid_credentials', 'Invalid username or password');
    }

    // Generate tokens
    $userId = (int)$user['id'];
    $username = (string)$user['username'];
    $email = (string)$user['email'];
    $isAdmin = (bool)$user['is_admin'];

    $accessToken = create_access_token($userId, $username, $email, $isAdmin);
    $refreshToken = create_refresh_token($userId);

    // Store refresh token hash in database for revocation support
    $refreshPayload = jwt_decode($refreshToken, 'refresh');
    $tokenId = $refreshPayload['jti'] ?? bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', $refreshPayload['exp'] ?? (time() + JWT_REFRESH_TTL));

    // Insert refresh token record (for token revocation)
    try {
        $insertToken = $pdo->prepare("
            INSERT INTO api_refresh_tokens (user_id, token_id, expires_at, ip, user_agent, created_at)
            VALUES (:user_id, :token_id, :expires_at, :ip, :ua, NOW())
        ");
        $insertToken->execute([
            ':user_id' => $userId,
            ':token_id' => $tokenId,
            ':expires_at' => $expiresAt,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Table might not exist yet - log but don't fail login
        error_log('[api/auth/login] Could not store refresh token: ' . $e->getMessage());
    }

    // Return tokens
    api_success([
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type' => 'Bearer',
        'expires_in' => JWT_ACCESS_TTL,
        'user' => [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'is_admin' => $isAdmin,
        ],
    ]);

} catch (Throwable $e) {
    error_log('[api/auth/login] Error: ' . $e->getMessage());
    api_error(500, 'server_error', 'An unexpected error occurred');
}