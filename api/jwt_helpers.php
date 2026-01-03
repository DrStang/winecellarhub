<?php
/**
 * jwt_helpers.php — Lightweight JWT library for mobile API authentication
 *
 * No external dependencies required. Uses HMAC-SHA256 for signing.
 *
 * Usage:
 *   $token = jwt_encode(['user_id' => 123, 'username' => 'dan'], 3600);
 *   $payload = jwt_decode($token); // returns array or null if invalid/expired
 */

declare(strict_types=1);

// Load JWT secret from environment or use a default (CHANGE IN PRODUCTION!)
// Add JWT_SECRET=your-secret-key to your .env file
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: 'CHANGE_THIS_TO_A_RANDOM_64_CHAR_STRING_IN_PRODUCTION');
define('JWT_ALGO', 'HS256');
define('JWT_ACCESS_TTL', 900);        // 15 minutes for access tokens
define('JWT_REFRESH_TTL', 2592000);   // 30 days for refresh tokens

/**
 * Base64 URL-safe encode
 */
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL-safe decode
 */
function base64url_decode(string $data): string {
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) {
        $data .= str_repeat('=', $pad);
    }
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

/**
 * Create a JWT token
 *
 * @param array $payload Custom claims (user_id, username, etc.)
 * @param int $ttl Time to live in seconds (default: 15 minutes)
 * @param string $type Token type: 'access' or 'refresh'
 * @return string The JWT token
 */
function jwt_encode(array $payload, int $ttl = JWT_ACCESS_TTL, string $type = 'access'): string {
    $header = [
        'typ' => 'JWT',
        'alg' => JWT_ALGO,
    ];

    $now = time();
    $payload = array_merge($payload, [
        'iat' => $now,                    // Issued at
        'exp' => $now + $ttl,             // Expiration
        'nbf' => $now,                    // Not valid before
        'jti' => bin2hex(random_bytes(16)), // Unique token ID
        'type' => $type,                  // Token type
    ]);

    $headerEncoded = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $payloadEncoded = base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = base64url_encode($signature);

    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

/**
 * Decode and verify a JWT token
 *
 * @param string $token The JWT token
 * @param string|null $expectedType Expected token type ('access' or 'refresh'), or null to skip check
 * @return array|null The payload if valid, null otherwise
 */
function jwt_decode(string $token, ?string $expectedType = 'access'): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

    // Verify signature
    $expectedSignature = base64url_encode(
        hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true)
    );

    if (!hash_equals($expectedSignature, $signatureEncoded)) {
        return null; // Invalid signature
    }

    // Decode header and payload
    $header = json_decode(base64url_decode($headerEncoded), true);
    $payload = json_decode(base64url_decode($payloadEncoded), true);

    if (!$header || !$payload) {
        return null;
    }

    // Check algorithm
    if (($header['alg'] ?? '') !== JWT_ALGO) {
        return null;
    }

    // Check expiration
    $now = time();
    if (isset($payload['exp']) && $payload['exp'] < $now) {
        return null; // Token expired
    }

    // Check not-before
    if (isset($payload['nbf']) && $payload['nbf'] > $now) {
        return null; // Token not yet valid
    }

    // Check token type if specified
    if ($expectedType !== null && ($payload['type'] ?? '') !== $expectedType) {
        return null; // Wrong token type
    }

    return $payload;
}

/**
 * Create an access token for a user
 */
function create_access_token(int $userId, string $username, ?string $email = null, bool $isAdmin = false): string {
    return jwt_encode([
        'sub' => $userId,           // Subject (user ID) - standard JWT claim
        'user_id' => $userId,       // Also include for convenience
        'username' => $username,
        'email' => $email,
        'is_admin' => $isAdmin,
    ], JWT_ACCESS_TTL, 'access');
}

/**
 * Create a refresh token for a user
 */
function create_refresh_token(int $userId): string {
    return jwt_encode([
        'sub' => $userId,
        'user_id' => $userId,
    ], JWT_REFRESH_TTL, 'refresh');
}

/**
 * Extract Bearer token from Authorization header
 */
function get_bearer_token(): ?string {
    $headers = null;

    // Try different methods to get Authorization header
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER['Authorization']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(
            array_map('ucwords', array_keys($requestHeaders)),
            array_values($requestHeaders)
        );
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }

    if ($headers && preg_match('/Bearer\s+(.+)$/i', $headers, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Verify the current request has a valid access token and return the payload
 *
 * @return array|null User payload if valid, null otherwise
 */
function verify_api_auth(): ?array {
    $token = get_bearer_token();
    if (!$token) {
        return null;
    }
    return jwt_decode($token, 'access');
}

/**
 * Require valid API authentication or return 401 response
 *
 * @return array The user payload
 */
function require_api_auth(): array {
    $payload = verify_api_auth();
    if (!$payload) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'unauthorized',
            'message' => 'Valid access token required',
        ]);
        exit;
    }
    return $payload;
}

/**
 * Standard JSON error response
 */
function api_error(int $httpCode, string $error, string $message = ''): void {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => $error,
        'message' => $message ?: $error,
    ]);
    exit;
}

/**
 * Standard JSON success response
 */
function api_success(array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}
