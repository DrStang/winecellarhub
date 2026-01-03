<?php
/**
 * /api/auth/refresh.php — Refresh access token using refresh token
 *
 * POST /api/auth/refresh.php
 * Content-Type: application/json
 *
 * Request:
 * {
 *   "refresh_token": "eyJ..."
 * }
 *
 * Response (success):
 * {
 *   "ok": true,
 *   "access_token": "eyJ...",
 *   "expires_in": 900
 * }
 *
 * Optionally rotate refresh token (more secure):
 * {
 *   "ok": true,
 *   "access_token": "eyJ...",
 *   "refresh_token": "eyJ...",  // New refresh token
 *   "expires_in": 900
 * }
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/db.php';
require_once __DIR__ . '/../jwt_helpers.php';

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));
    $rotateToken = (bool)($input['rotate'] ?? true); // Default: rotate refresh tokens

    if ($refreshToken === '') {
        api_error(400, 'missing_token', 'Refresh token is required');
    }

    // Decode and verify refresh token
    $payload = jwt_decode($refreshToken, 'refresh');
    if (!$payload) {
        api_error(401, 'invalid_token', 'Invalid or expired refresh token');
    }

    $userId = (int)($payload['user_id'] ?? $payload['sub'] ?? 0);
    $tokenId = $payload['jti'] ?? null;

    if ($userId <= 0) {
        api_error(401, 'invalid_token', 'Invalid refresh token payload');
    }

    // Check if token has been revoked (if table exists)
    try {
        if ($tokenId) {
            $checkRevoked = $pdo->prepare("
                SELECT revoked_at FROM api_refresh_tokens 
                WHERE user_id = :uid AND token_id = :tid
                LIMIT 1
            ");
            $checkRevoked->execute([':uid' => $userId, ':tid' => $tokenId]);
            $tokenRecord = $checkRevoked->fetch(PDO::FETCH_ASSOC);

            if ($tokenRecord && $tokenRecord['revoked_at'] !== null) {
                api_error(401, 'token_revoked', 'This refresh token has been revoked');
            }
        }
    } catch (PDOException $e) {
        // Table might not exist - continue without revocation check
        error_log('[api/auth/refresh] Could not check token revocation: ' . $e->getMessage());
    }

    // Fetch current user data
    $stmt = $pdo->prepare("
        SELECT id, username, email, COALESCE(is_admin, 0) AS is_admin
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        api_error(401, 'user_not_found', 'User no longer exists');
    }

    // Generate new access token
    $accessToken = create_access_token(
        (int)$user['id'],
        (string)$user['username'],
        (string)$user['email'],
        (bool)$user['is_admin']
    );

    $response = [
        'access_token' => $accessToken,
        'token_type' => 'Bearer',
        'expires_in' => JWT_ACCESS_TTL,
    ];

    // Optionally rotate refresh token (recommended for security)
    if ($rotateToken) {
        // Revoke old token
        try {
            if ($tokenId) {
                $revokeOld = $pdo->prepare("
                    UPDATE api_refresh_tokens 
                    SET revoked_at = NOW() 
                    WHERE user_id = :uid AND token_id = :tid
                ");
                $revokeOld->execute([':uid' => $userId, ':tid' => $tokenId]);
            }
        } catch (PDOException $e) {
            // Continue without revocation
        }

        // Issue new refresh token
        $newRefreshToken = create_refresh_token($userId);
        $newPayload = jwt_decode($newRefreshToken, 'refresh');
        $newTokenId = $newPayload['jti'] ?? bin2hex(random_bytes(16));
        $newExpiresAt = date('Y-m-d H:i:s', $newPayload['exp'] ?? (time() + JWT_REFRESH_TTL));

        try {
            $insertToken = $pdo->prepare("
                INSERT INTO api_refresh_tokens (user_id, token_id, expires_at, ip, user_agent, created_at)
                VALUES (:user_id, :token_id, :expires_at, :ip, :ua, NOW())
            ");
            $insertToken->execute([
                ':user_id' => $userId,
                ':token_id' => $newTokenId,
                ':expires_at' => $newExpiresAt,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            error_log('[api/auth/refresh] Could not store new refresh token: ' . $e->getMessage());
        }

        $response['refresh_token'] = $newRefreshToken;
    }

    api_success($response);

} catch (Throwable $e) {
    error_log('[api/auth/refresh] Error: ' . $e->getMessage());
    api_error(500, 'server_error', 'An unexpected error occurred');
}
