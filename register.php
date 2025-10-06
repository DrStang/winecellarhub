<?php
declare(strict_types=1);

/**
 * register.php — invite-only registration + welcome email + remember-me token
 * Depends:
 *   - .env: INVITE_REQUIRED=true|false, INVITE_CODE=YOURCODE
 *   - mailer.php: send_mail($to, $subject, $html, $altText='', $bcc=null)
 *   - (optional) auth_helpers.php with setSessionParamsAndStart() and issueRememberMeCookie()
 * Schema assumptions:
 *   users(id PK AUTO_INCREMENT, username VARCHAR UNIQUE, email VARCHAR UNIQUE, password_hash TEXT/VARCHAR, created_at DATETIME)
 *   user_remember_tokens(...) as previously shared (selector/validator_hash/expires_at/etc.)
 */

require_once __DIR__ . '/db.php';      // provides $pdo (PDO)
require_once __DIR__ . '/mailer.php';  // provides send_mail(...)
require __DIR__.'/analytics_track.php'; // <-- add this
require __DIR__.'/analytics_events.php';

// Try to use shared helpers if present
$AUTH_HELPERS_PATH = __DIR__ . '/auth.php';
if (file_exists($AUTH_HELPERS_PATH)) {
    require_once $AUTH_HELPERS_PATH;
}

/* ---------- Fallback helpers if auth_helpers.php not present ---------- */
if (!function_exists('setSessionParamsAndStart')) {
    function setSessionParamsAndStart(): void {
        $life = 5 * 24 * 60 * 60; // 5 days
        // Must be called before session_start()
        @session_set_cookie_params([
            'lifetime' => $life,
            'path'     => '/',
            // 'domain' => '.winecellarhub.com', // uncomment if you serve both www/non-www
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @ini_set('session.gc_maxlifetime', (string)$life);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('issueRememberMeCookie')) {
    // Minimal inline implementation; matches earlier schema/logic
    function issueRememberMeCookie(PDO $pdo, int $userId): void {
        $selector  = bin2hex(random_bytes(9));   // public id
        $validator = bin2hex(random_bytes(32));  // secret
        $hash      = password_hash($validator, PASSWORD_DEFAULT);
        $expires   = (new DateTimeImmutable('+5 days'));

        // Insert token (assumes user_remember_tokens exists)
        $ins = $pdo->prepare("
            INSERT INTO user_remember_tokens (user_id, selector, validator_hash, expires_at, created_at, ip, ua)
            VALUES (?, UNHEX(?), ?, ?, NOW(), ?, ?)
        ");
        try {
            $ins->execute([
                $userId,
                $selector,
                $hash,
                $expires->format('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable $e) {
            // If table missing or FK mismatch, fail softly—user still has a session
            error_log('issueRememberMeCookie insert failed: ' . $e->getMessage());
        }

        $cookieValue = $selector . ':' . $validator;

        setcookie('remember_me', $cookieValue, [
            'expires'  => $expires->getTimestamp(),
            'path'     => '/',
            // 'domain' => '.winecellarhub.com',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/* ---------- ENV helpers ---------- */
function env_str(string $key, string $default = ''): string {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null) ? $default : (string)$v;
}
function env_bool(string $key, bool $default = true): bool {
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') return $default;
    $parsed = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return ($parsed === null) ? $default : $parsed;
}

/* ---------- Session ---------- */
setSessionParamsAndStart();

/* ---------- Already logged in? ---------- */
if (!empty($_SESSION['user_id'])) {
    header('Location: /home.php');
    exit;
}

/* ---------- Simple CSRF ---------- */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- Handle POST ---------- */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf     = (string)($_POST['csrf'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $refCode  = trim((string)($_POST['referral_code'] ?? ''));

    // CSRF
    if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
        $error = 'Invalid session. Please refresh and try again.';
    }

    // Invite-only gate (Option B)
    if (!$error) {
        $inviteRequired = env_bool('INVITE_REQUIRED', true);
        $expectedCode   = env_str('INVITE_CODE', '');
        if ($inviteRequired) {
            if ($expectedCode === '' || !hash_equals($expectedCode, $refCode)) {
                $error = 'Referral code required (or incorrect).';
            }
        }
    }

    // Basic validation
    if (!$error) {
        // username: 3–32 chars, letters/numbers/._-
        if ($username === '' || strlen($username) < 3 || strlen($username) > 32 || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            $error = 'Username must be 3–32 characters and use only letters, numbers, dot, underscore, or hyphen.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        }
    }

    // Duplicate username/email checks
    if (!$error) {
        $chk = $pdo->prepare('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
        $chk->execute([$username, $email]);
        if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
            if (strcasecmp($row['username'], $username) === 0) {
                $error = 'Username is already taken.';
            } elseif (strcasecmp($row['email'], $email) === 0) {
                $error = 'Email is already registered.';
            }
        }
    }

    // Create user
    if (!$error) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $ins = $pdo->prepare('
                INSERT INTO users (username, email, password_hash, created_at)
                VALUES (?, ?, ?, NOW())
            ');
            $ins->execute([$username, $email, $hash]);

            $userId = (int)$pdo->lastInsertId();

            // --- Welcome email via mailer.php ---
            $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $subject  = 'Welcome to WineCellarHub 🥂';
            $htmlBody = '
                <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:16px;line-height:1.6">
                  <p>Hi ' . $safeUsername . ',</p>
                  <p>Welcome to <strong>WineCellarHub</strong>! Your account has been created.</p>
                  <ul>
                    <li>📦 Catalog your bottles with barcode scans or label uploads</li>
                    <li>🗂️ Organize by varietal, region, vintage, and more</li>
                    <li>⭐ Rate and review your wines</li>
                    <li>📊 See collection stats and trends</li>
                  </ul>
                  <p>Head to your dashboard to start adding bottles.</p>
                  <p style="margin-top:16px">Cheers,<br>WineCellarHub</p>
                </div>';
            $altBody = "Hi {$username},\n\nWelcome to WineCellarHub! Your account has been created.\n\n"
                . "• Catalog bottles with barcodes or label uploads\n"
                . "• Organize by varietal, region, vintage\n"
                . "• Rate and review your wines\n"
                . "• See collection stats and trends\n\n"
                . "Cheers,\nWineCellarHub";

            $bccDefault = 'dandolewski@gmail.com';
            if (function_exists('send_mail')) {
                @send_mail($email, $subject, $htmlBody, $altBody, $bccDefault);
            } else {
                error_log('[register] send_mail() not found in mailer.php');
            }

            // --- Session + Remember-me token (5 days) ---
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            analytics_log_event($pdo, 'signup', ['method'=>'email']);

            session_regenerate_id(true);

            // Immediately issue remember-me token so the user stays signed in
            issueRememberMeCookie($pdo, $userId);

            header('Location: /home.php');
            exit;

        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, '1062') !== false || stripos($msg, 'duplicate') !== false) {
                // Clarify which field is dup
                $chk2 = $pdo->prepare('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
                $chk2->execute([$username, $email]);
                if ($row = $chk2->fetch(PDO::FETCH_ASSOC)) {
                    if (strcasecmp($row['username'], $username) === 0) {
                        $error = 'Username is already taken.';
                    } elseif (strcasecmp($row['email'], $email) === 0) {
                        $error = 'Email is already registered.';
                    } else {
                        $error = 'Account already exists.';
                    }
                } else {
                    $error = 'Account already exists.';
                }
            } else {
                error_log('register.php DB error: ' . $msg);
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <title>Register • WineCellarHub</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:24px;background:#f7f7f8}
        .card{max-width:520px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06);padding:24px}
        .row{margin-bottom:14px}
        label{display:block;font-weight:600;margin-bottom:6px}
        input{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:16px}
        button{width:100%;padding:12px 14px;border:0;border-radius:10px;background:#7c3aed;color:#fff;font-weight:700;font-size:16px;cursor:pointer}
        button:hover{opacity:.95}
        .error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:10px 12px;border-radius:8px;margin-bottom:12px}
        .hint{color:#6b7280;font-size:14px;margin-top:6px}
    </style>
</head>
<body>
<div class="card">
    <h1 style="margin:0 0 10px">Create your account</h1>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="/register.php" autocomplete="off" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>"/>

        <div class="row">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" required
                   minlength="3" maxlength="32"
                   pattern="[A-Za-z0-9._-]+"
                   value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div class="hint">3–32 chars; letters, numbers, dot, underscore, or hyphen.</div>
        </div>

        <div class="row">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="row">
            <label for="password">Password (min 8 chars)</label>
            <input id="password" name="password" type="password" minlength="8" required>
        </div>

       <!-- <div class="row">
            <label for="referral_code">Referral code</label>
            <input id="referral_code" name="referral_code" type="text" required
                   value="<?= htmlspecialchars($_POST['referral_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div class="hint">Invite-only while we’re in private beta.</div>
        </div>
        -->
        <button type="submit">Create account</button>
    </form>

    <p style="margin-top:14px"><a href="/login.php">Already have an account? Sign in</a></p>
</div>
</body>
</html>
