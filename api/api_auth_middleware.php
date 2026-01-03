<?php
/**
 * api_auth_middleware.php — Dual authentication support for web (sessions) and mobile (JWT)
 *
 * Include this in API endpoints that need to support both authentication methods.
 * It will check for JWT first, then fall back to session auth.
 *
 * Usage:
 *   require_once __DIR__ . '/api_auth_middleware.php';
 *   $userId = get_authenticated_user_id(); // Returns user ID or exits with 401
 *
 * Or for optional auth (some endpoints may work without auth):
 *   $userId = get_authenticated_user_id_optional(); // Returns user ID or null
 */

declare(strict_types=1);

// Load JWT helpers
require_once __DIR__ . '/jwt_helpers.php';

/**
 * Get authenticated user ID from either JWT or session
 * Returns null if not authenticated (doesn't exit)
 */
function get_authenticated_user_id_optional(): ?int {
    // First, try JWT authentication
    $token = get_bearer_token();
    if ($token) {
        $payload = jwt_decode($token, 'access');
        if ($payload) {
            $userId = (int)($payload['user_id'] ?? $payload['sub'] ?? 0);
            if ($userId > 0) {
                return $userId;
            }
        }
        // Invalid JWT - don't fall back to session (mobile should use JWT)
        return null;
    }

    // No JWT provided - try session authentication (web fallback)
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Only start session if not already started and no JWT was attempted
        @session_start();
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    return $userId > 0 ? $userId : null;
}

/**
 * Get authenticated user ID - exits with 401 if not authenticated
 */
function get_authenticated_user_id(): int {
    $userId = get_authenticated_user_id_optional();

    if ($userId === null || $userId <= 0) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Not authenticated',
            'hint' => 'Provide Authorization: Bearer <token> header or valid session',
        ]);
        exit;
    }

    return $userId;
}

/**
 * Get full auth context (user ID + admin status + source)
 */
function get_auth_context(): ?array {
    // Try JWT first
    $token = get_bearer_token();
    if ($token) {
        $payload = jwt_decode($token, 'access');
        if ($payload) {
            return [
                'user_id' => (int)($payload['user_id'] ?? $payload['sub'] ?? 0),
                'username' => $payload['username'] ?? null,
                'email' => $payload['email'] ?? null,
                'is_admin' => (bool)($payload['is_admin'] ?? false),
                'auth_source' => 'jwt',
            ];
        }
        return null;
    }

    // Fall back to session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (!empty($_SESSION['user_id'])) {
        return [
            'user_id' => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'is_admin' => (bool)($_SESSION['is_admin'] ?? false),
            'auth_source' => 'session',
        ];
    }

    return null;
}

/**
 * Add CORS headers for mobile app requests
 * Call at the top of API endpoints
 */
function add_cors_headers(): void {
    // In production, replace * with your app's domain or use a whitelist
    $allowedOrigins = [
        '*', // Allow all for development
        // 'https://yourdomain.com',
        // 'capacitor://localhost',
        // 'http://localhost:8081', // Expo dev
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

    // For development, allow all origins
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
