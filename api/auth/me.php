<?php
/**
 * /api/auth/me.php — Get current user profile
 *
 * GET /api/auth/me.php
 * Authorization: Bearer <access_token>
 *
 * Response:
 * {
 *   "ok": true,
 *   "user": {
 *     "id": 1,
 *     "username": "dan",
 *     "email": "dan@example.com",
 *     "is_admin": false,
 *     "created_at": "2024-01-15 10:30:00",
 *     "stats": {
 *       "total_bottles": 42,
 *       "current_bottles": 38,
 *       "wantlist_count": 5
 *     }
 *   }
 * }
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    // Fetch user details
    $stmt = $pdo->prepare("
        SELECT id, username, email, COALESCE(is_admin, 0) AS is_admin, created_at
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        api_error(404, 'user_not_found', 'User not found');
    }

    // Get user stats
    $stats = [
        'total_bottles' => 0,
        'current_bottles' => 0,
        'past_bottles' => 0,
        'wantlist_count' => 0,
    ];

    try {
        // Total bottles
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bottles WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $stats['total_bottles'] = (int)$stmt->fetchColumn();

        // Current bottles (not marked as past/consumed)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bottles WHERE user_id = :uid AND (past IS NULL OR past = 0)");
        $stmt->execute([':uid' => $userId]);
        $stats['current_bottles'] = (int)$stmt->fetchColumn();

        // Past bottles
        $stats['past_bottles'] = $stats['total_bottles'] - $stats['current_bottles'];

        // Wantlist count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM wantlist WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $stats['wantlist_count'] = (int)$stmt->fetchColumn();

    } catch (PDOException $e) {
        // Stats are optional - don't fail if tables don't exist
        error_log('[api/auth/me] Could not fetch stats: ' . $e->getMessage());
    }

    api_success([
        'user' => [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'email' => (string)$user['email'],
            'is_admin' => (bool)$user['is_admin'],
            'created_at' => $user['created_at'],
            'stats' => $stats,
        ],
    ]);

} catch (Throwable $e) {
    error_log('[api/auth/me] Error: ' . $e->getMessage());
    api_error(500, 'server_error', 'An unexpected error occurred');
}
