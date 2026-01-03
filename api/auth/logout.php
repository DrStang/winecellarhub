<?php
/**
 * /api/auth/logout.php — Revoke refresh token (logout from mobile app)
 *
 * POST /api/auth/logout.php
 * Authorization: Bearer <access_token>
 * Content-Type: application/json
 *
 * Request (optional - to revoke specific token):
 * {
 *   "refresh_token": "eyJ..."
 * }
 *
 * Or to revoke all tokens for the user:
 * {
 *   "all": true
 * }
 *
 * Response:
 * {
 *   "ok": true,
 *   "message": "Logged out successfully"
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
    // Require valid access token
    $auth = require_api_auth();
    $userId = (int)($auth['user_id'] ?? $auth['sub'] ?? 0);

    if ($userId <= 0) {
        api_error(401, 'invalid_token', 'Invalid access token');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $revokeAll = (bool)($input['all'] ?? false);
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));

    try {
        if ($revokeAll) {
            // Revoke ALL refresh tokens for this user
            $stmt = $pdo->prepare("
                UPDATE api_refresh_tokens 
                SET revoked_at = NOW() 
                WHERE user_id = :uid AND revoked_at IS NULL
            ");
            $stmt->execute([':uid' => $userId]);
            $count = $stmt->rowCount();

            api_success([
                'message' => "Logged out from all devices ($count sessions revoked)",
                'revoked_count' => $count,
            ]);

        } elseif ($refreshToken !== '') {
            // Revoke specific refresh token
            $payload = jwt_decode($refreshToken, 'refresh');
            if ($payload && isset($payload['jti'])) {
                $stmt = $pdo->prepare("
                    UPDATE api_refresh_tokens 
                    SET revoked_at = NOW() 
                    WHERE user_id = :uid AND token_id = :tid
                ");
                $stmt->execute([':uid' => $userId, ':tid' => $payload['jti']]);
            }
            api_success(['message' => 'Logged out successfully']);

        } else {
            // No specific token provided - just acknowledge
            api_success(['message' => 'Logged out successfully']);
        }

    } catch (PDOException $e) {
        // Table might not exist - still return success
        error_log('[api/auth/logout] Could not revoke tokens: ' . $e->getMessage());
        api_success(['message' => 'Logged out successfully']);
    }

} catch (Throwable $e) {
    error_log('[api/auth/logout] Error: ' . $e->getMessage());
    api_error(500, 'server_error', 'An unexpected error occurred');
}
