<?php
// auth_helpers.php
declare(strict_types=1);

const REMEMBER_COOKIE_NAME = 'remember_me';
const REMEMBER_DAYS = 5;
// auth_helpers.php  —  drop-in replacement for setSessionParamsAndStart()

if (!function_exists('setSessionParamsAndStart')) {
    function setSessionParamsAndStart(): void {
        static $configured = false; // ensure idempotence across multiple includes
        $life = 5 * 24 * 60 * 60;   // 5 days

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Configure BEFORE session_start()
            @ini_set('session.gc_maxlifetime', (string)$life);
            // If you truly need cookie lifetime on the session cookie itself:
            // many PHP builds respect cookie_lifetime only if set before start
            // @ini_set('session.cookie_lifetime', (string)$life);

            @session_set_cookie_params([
                'lifetime' => $life,
                'path'     => '/',
                // 'domain' => '.winecellarhub.com', // only if you need cross-subdomain
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
            $configured = true;
            return;
        }
// Auto-restore via remember-me cookie when no authenticated session
        if (empty($_SESSION['user_id']) && function_exists('tryRememberMe')) {
            // try using global $pdo from db.php if available
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                $uid = tryRememberMe($GLOBALS['pdo']);
                if ($uid) {
                    $_SESSION['user_id'] = (int)$uid;
                    // Optionally hydrate username/email/admin flags
                    try {
                        $stmt = $GLOBALS['pdo']->prepare("SELECT username, email, is_admin FROM users WHERE id=?");
                        $stmt->execute([$uid]);
                        if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $_SESSION['username']   = (string)($u['username'] ?? '');
                            $_SESSION['user_email'] = (string)($u['email'] ?? '');
                            $_SESSION['is_admin']   = (int)($u['is_admin'] ?? 0);
                        }
                    } catch (Throwable $e) {
                        // non-fatal; user_id is sufficient
                    }
                }
            }
        }

        // Session already active — DO NOT change params now (causes warnings).
        // Instead, refresh the cookie so the browser keeps it for the full window.
        if (!$configured) {
            $params = session_get_cookie_params();
            // Extend expiry from "now" by $life without changing other attributes
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $life,
                'path'     => $params['path']     ?? '/',
                'domain'   => $params['domain']   ?? '',
                'secure'   => $params['secure']   ?? true,
                'httponly' => $params['httponly'] ?? true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
            $configured = true;
        }
    }
}


function issueRememberMeCookie(PDO $pdo, int $userId): void {
    // public id for lookup
    $selector  = bin2hex(random_bytes(9));   // 18 bytes -> 36 hex chars, stored as VARBINARY(24)
    // secret checked with password_verify (don’t store plain)
    $validator = bin2hex(random_bytes(32));  // 32 bytes -> 64 hex chars
    $hash      = password_hash($validator, PASSWORD_DEFAULT);
    $expires   = (new DateTimeImmutable(sprintf('+%d days', REMEMBER_DAYS)));

    // Optional: limit concurrent devices, e.g., keep last 5
    $limit = 5;
    $stmt = $pdo->prepare("SELECT id FROM user_remember_tokens WHERE user_id=? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) >= $limit) {
        $toDelete = array_slice($ids, $limit-1); // keep newest ($limit-1) then add this one
        if ($toDelete) {
            $in = implode(',', array_fill(0, count($toDelete), '?'));
            $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE id IN ($in)");
            $del->execute($toDelete);
        }
    }

    $ins = $pdo->prepare("
        INSERT INTO user_remember_tokens (user_id, selector, validator_hash, expires_at, ip, ua)
        VALUES (?, UNHEX(?), ?, ?, ?, ?)
    ");
    $ins->execute([
        $userId,
        $selector,
        $hash,
        $expires->format('Y-m-d H:i:s'),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    $cookieValue = $selector . ':' . $validator;

    setcookie(REMEMBER_COOKIE_NAME, $cookieValue, [
        'expires'  => $expires->getTimestamp(),
        'path'     => '/',
        // 'domain' => '.winecellarhub.com',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearRememberMeCookie(PDO $pdo): void {
    if (!empty($_COOKIE[REMEMBER_COOKIE_NAME])) {
        // Best-effort: remove current selector if parseable
        $parts = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
        if (count($parts) === 2) {
            [$selector, $validator] = $parts;
            $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = UNHEX(?)");
            $del->execute([$selector]);
        }
    }
    setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        // 'domain' => '.winecellarhub.com',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tryRememberMe(PDO $pdo): ?int {
    if (empty($_COOKIE[REMEMBER_COOKIE_NAME])) {
        return null;
    }

    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $validator] = $parts;

    $sql = "SELECT user_id, HEX(selector) AS selector_hex, validator_hash, expires_at
            FROM user_remember_tokens WHERE selector = UNHEX(?) LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    // expired?
    if (strtotime($row['expires_at']) < time()) {
        $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = UNHEX(?)");
        $del->execute([$selector]);
        clearRememberMeCookie($pdo);
        return null;
    }

    // validate
    if (!password_verify($validator, $row['validator_hash'])) {
        // possible theft or stale cookie: nuke this selector
        $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = UNHEX(?)");
        $del->execute([$selector]);
        clearRememberMeCookie($pdo);
        return null;
    }

    // success → rotate (delete old, issue new)
    $userId = (int)$row['user_id'];

    $upd = $pdo->prepare("UPDATE user_remember_tokens SET last_used_at = NOW() WHERE selector = UNHEX(?)");
    $upd->execute([$selector]);

    // Rotate to prevent replay
    $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = UNHEX(?)");
    $del->execute([$selector]);

    issueRememberMeCookie($pdo, $userId);
    return $userId;
}