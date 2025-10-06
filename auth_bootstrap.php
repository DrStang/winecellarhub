<?php
// auth_bootstrap.php (create this once)
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_helpers.php';

// 5-day session cookie + GC window
setSessionParamsAndStart();

// If no session yet, try remember-me (rotates token on success)
if (empty($_SESSION['user_id'])) {
    $uid = tryRememberMe($pdo);
    if ($uid) {
        $_SESSION['user_id']  = (int)$uid;
        // cache username in session for header display
        try {
            $st = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $_SESSION['username'] = (string)($st->fetchColumn() ?: 'User');
        } catch (Throwable $e) { /* ignore */ }
        session_regenerate_id(true);
    }
}

// If still unauthenticated, redirect to login with next
if (empty($_SESSION['user_id'])) {
    $next = $_SERVER['REQUEST_URI'] ?? '/home.php';
    if (strpos($next, '/') !== 0) $next = '/home.php';
    header('Location: /login.php?next=' . urlencode($next), true, 303);
    exit;
}
