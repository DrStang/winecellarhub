<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_helpers.php';

// auth.php  (keep ONE copy here)
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return !empty($_SESSION['user_id']);
    }
}


// 1) Always start session with the same parameters FIRST
setSessionParamsAndStart(); // sets 5-day cookie + session_start()

// 2) Never redirect while already on public auth pages
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (in_array($path, ['/login.php', '/register.php'], true)) {
    // still allow remember-me to promote a session if present
    if (empty($_SESSION['user_id'])) {
        $uid = tryRememberMe($pdo);
        if ($uid) {
            $_SESSION['user_id'] = (int)$uid;
            // optional: cache username
            try {
                $st = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
                $st->execute([$uid]);
                $_SESSION['username'] = (string)($st->fetchColumn() ?: 'User');
            } catch (Throwable $e) {}
            session_regenerate_id(true);
        }
    }
    return; // DO NOT redirect from login/register
}

// 3) If no session yet, try remember-me BEFORE redirecting
if (empty($_SESSION['user_id'])) {
    $uid = tryRememberMe($pdo); // should rotate token and set cookie
    if ($uid) {
        $_SESSION['user_id'] = (int)$uid;
        try {
            $st = $pdo->prepare('SELECT username, COALESCE(is_admin,0) AS is_admin FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $_SESSION['username'] = (string)($row['username'] ?? 'User');
            $_SESSION['is_admin'] = !empty($row['is_admin']);
        } catch (Throwable $e) {}
        session_regenerate_id(true);
    }
}

// 4) Still unauthenticated? send to login with a SAFE next
if (empty($_SESSION['user_id'])) {
    // normalize next: only a path, never /login.php, and unwind over-encoding
    $raw = $_SERVER['REQUEST_URI'] ?? '/';
    for ($i = 0; $i < 5; $i++) { $d = urldecode($raw); if ($d === $raw) break; $raw = $d; }
    $next = parse_url($raw, PHP_URL_PATH) ?? '/';
    if ($next === '' || $next[0] !== '/') $next = '/' . $next;
    if (stripos($next, '/login.php') === 0) $next = '/';
    header('Location: /login.php?next=' . rawurlencode($next), true, 303);
    exit;
}

// 5) Optional one-liner to confirm what the app sees at protected pages
error_log('[auth] session_id=' . session_id() . ' uid=' . ($_SESSION['user_id'] ?? '(none)'));
