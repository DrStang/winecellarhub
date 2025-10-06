<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_helpers.php';
require __DIR__.'/analytics_track.php'; // <-- add this
require __DIR__.'/analytics_events.php';

// ---- BEGIN: anti-buffer + session start ----
if (!headers_sent() && !ob_get_level()) { ob_start(); }
setSessionParamsAndStart();
// ---- END ----

function normalizeNext(string $raw, string $default = '/home.php'): string {
    $s = $raw !== '' ? $raw : $default;
    for ($i = 0; $i < 5; $i++) { $d = urldecode($s); if ($d === $s) break; $s = $d; }
    $p = parse_url($s, PHP_URL_PATH) ?? '';
    if ($p === '/login.php' || $p === '/reset_password.php' || $p === '/forgot_password.php') {
        return $default;
    }
    if (!str_starts_with($s, '/')) return $default;
    return $s;
}

$defaultNext = '/home.php';
$next        = normalizeNext($_GET['next'] ?? $_POST['next'] ?? '', $defaultNext);

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . $next, true, 302);
    @session_write_close();
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$debugUI = ($_ENV['DEBUG_LOGIN'] ?? '0') === '1';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf     = (string)($_POST['csrf'] ?? '');
    $login    = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    $next     = normalizeNext($_POST['next'] ?? $defaultNext, $defaultNext);

    try {
        if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        if ($login === '' || $password === '') {
            throw new InvalidArgumentException('Missing login or password.');
        }

        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new RuntimeException('$pdo is not defined by db.php (or not a PDO).');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Your schema: id, username, password_hash, email, is_admin, created_at
        $sql = <<<SQL
SELECT id, username, email, is_admin, password_hash AS pwd
FROM users
WHERE (email = :v OR username = :v)
LIMIT 1
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':v' => $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$user || empty($user['pwd'])) {
            throw new RuntimeException('Invalid credentials.');
        }

        $stored = (string)$user['pwd'];
        $info   = password_get_info($stored);
        $ok     = $info['algo'] ? password_verify($password, $stored) : hash_equals($stored, $password);

        if (!$ok) {
            throw new RuntimeException('Invalid credentials.');
        }

        // Success
        $_SESSION['user_id']     = (int)$user['id'];
        $_SESSION['username']    = (string)$user['username'];
        $_SESSION['user_email']  = (string)$user['email'];
        $_SESSION['is_admin']    = isset($user['is_admin']) ? (int)$user['is_admin'] : 0;  // <-- add this
        $_SESSION['last_login']  = date('Y-m-d H:i:s');

        analytics_log_event($pdo, 'login');

        if ($remember) {
            if (function_exists('issueRememberMeCookie')) {
                issueRememberMeCookie($pdo, (int)$user['id']);
            } elseif (function_exists('setRememberMeCookie')) {
                // Backward compatibility if you still have an older helper somewhere
                setRememberMeCookie((int)$user['id']);
            }
        } else {
            if (function_exists('clearRememberMeCookie')) {
                clearRememberMeCookie($pdo);
            } else {
                $cookieName = defined('REMEMBER_COOKIE_NAME') ? REMEMBER_COOKIE_NAME : 'remember_me';
                setcookie($cookieName, '', time() - 3600, '/', '', true, true);
            }
        }


        @session_write_close();
        if (ob_get_length() !== false) { @ob_clean(); }
        header('Location: ' . $next, true, 302);
        exit;

    } catch (Throwable $t) {
        error_log('[login] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
        $error = $debugUI ? ('DEBUG: ' . $t->getMessage()) : 'Something went wrong. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <title>Welcome • Sign in to WineCellarHub</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <?php require __DIR__ . '/head.php'; ?>
    <style>
        .hero-bg {
            background: radial-gradient(1200px 600px at -10% -10%, rgba(99,102,241,.10), transparent),
            radial-gradient(800px 400px at 120% 10%, rgba(236,72,153,.10), transparent),
            linear-gradient(180deg, #0b1020 0%, #0e1326 100%);
        }
        .glass { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 10px 30px rgba(0,0,0,.25); backdrop-filter: blur(8px); }
        .feature { display:flex; gap:.75rem; align-items:flex-start; }
        .feature svg { flex: none; }
        /* Screenshot strip */
        .shots { position: relative; height: 220px; }
        .shot { position:absolute; width: 54%; max-width: 360px; border-radius: 14px; overflow:hidden; box-shadow: 0 10px 30px rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.18); }
        .shot--1 { left:0; top:0; transform: rotate(-4deg); }
        .shot--2 { left:22%; top:18px; transform: rotate(2deg); z-index:2; }
        .shot--3 { right:0; bottom:-10px; transform: rotate(-1deg); }
        .shot img { display:block; width:100%; height:auto; }
        @media (max-width: 768px){ .shots{ display:none; } }
        /* Mobile carousel (shown only on small screens) */
        .mobile-carousel { display:none; }
        @media (max-width: 768px){ .mobile-carousel{ display:block; overflow-x:auto; white-space:nowrap; gap:12px; padding-bottom:8px; }
            .mobile-carousel img{ display:inline-block; width:82%; max-width:420px; border-radius:12px; margin-right:10px; box-shadow:0 10px 24px rgba(0,0,0,.28); }
        }
    </style>
</head>
<body class="min-h-svh bg-gray-50 text-[var(--text, #0f172a)]">
<div class="grid md:grid-cols-2 min-h-svh">
    <!-- Left: marketing / landing -->
    <section class="hero-bg text-white p-8 md:p-12 flex items-center">
        <div class="max-w-xl mx-auto">
            <div class="flex items-center gap-3 mb-6">
                <svg class="w-10 h-10 opacity-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M6 2v7a6 6 0 1 0 12 0V2"/><path d="M8 22h8"/>
                </svg>
                <span class="font-extrabold text-[clamp(28px,4vw,40px)] tracking-tight">WineCellarHub</span>
            </div>

            <h1 class="text-3xl sm:text-4xl font-bold leading-tight tracking-tight">
                Your cellar. Organized. <span class="text-fuchsia-300">Smart.</span>
            </h1>
            <p class="mt-3 text-slate-200/90 text-base">
                Track bottles, auto‑fill details, see expert lists, and get AI tasting notes & food pairings.
                Built for collectors who want less spreadsheet, more sipping.
            </p>

            <div class="mt-6 grid sm:grid-cols-2 gap-3">
                <div class="feature">
                    <svg class="w-5 h-5 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 7l-8 10-5-5"/></svg>
                    <div>
                        <div class="font-semibold">Fast bottle add</div>
                        <div class="text-sm text-slate-200/80">Scan a label and auto‑populate varietal, region, and image.</div>
                    </div>
                </div>
                <div class="feature">
                    <svg class="w-5 h-5 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 7l-8 10-5-5"/></svg>
                    <div>
                        <div class="font-semibold">Smart insights</div>
                        <div class="text-sm text-slate-200/80">AI tasting notes, drink windows, pairings, and investability.</div>
                    </div>
                </div>
                <div class="feature">
                    <svg class="w-5 h-5 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 7l-8 10-5-5"/></svg>
                    <div>
                        <div class="font-semibold">Expert lists</div>
                        <div class="text-sm text-slate-200/80">Decanter, Wine Spectator Top 100, and more—matched to your cellar.</div>
                    </div>
                </div>
                <div class="feature">
                    <svg class="w-5 h-5 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 7l-8 10-5-5"/></svg>
                    <div>
                        <div class="font-semibold">Private by design</div>
                        <div class="text-sm text-slate-200/80">Your data stays yours with secure accounts and per‑user inventories.</div>
                    </div>
                </div>
            </div>

            <!-- Screenshot strip (desktop) -->
            <div class="mt-8 shots" aria-hidden="true">
                <div class="shot shot--1"><img src="/assets/screens/inventory.jpg" alt="Inventory screenshot placeholder"></div>
                <div class="shot shot--2"><img src="/assets/screens/bottle.jpg" alt="Bottle detail screenshot placeholder"></div>
                <div class="shot shot--3"><img src="/assets/screens/analytics.jpg" alt="Analytics dashboard screenshot placeholder"></div>
            </div>
            <!-- Mobile carousel -->
            <div class="mt-6 mobile-carousel" aria-label="Screenshots">
                <img src="/assets/screens/inventory.jpg" alt="Inventory screenshot placeholder">
                <img src="/assets/screens/bottle.jpg" alt="Bottle detail screenshot placeholder">
                <img src="/assets/screens/analytics.jpg" alt="Analytics dashboard screenshot placeholder">
            </div>

            <div class="mt-8 glass rounded-2xl p-4">
                <div class="text-sm/6">
                    <div class="font-semibold">Why create an account?</div>
                    <ul class="list-disc pl-5 space-y-1 text-slate-100/90 mt-2">
                        <li>Sync your cellar across devices.</li>
                        <li>Get weekly personalized picks and trends.</li>
                        <li>Unlock the dashboard, charts, and exports.</li>
                        <li>And best of all, registration is FREE!</li>
                        <li style="margin-top:16px">
                            <a href="/features.php">Click here to see the features of the site</a>
                        </li>
                    </ul>
                </div>
                <div class="mt-4 flex items-center gap-4 text-xs text-slate-300/80">
                    <div>🔒 Encrypted logins</div>
                    <div>📦 Image caching</div>
                    <div>⚡ Fast search</div>


                </div>
            </div>

            <figure class="mt-8 text-slate-200/80">
                <blockquote class="italic">&ldquo;Five minutes in and my cellar finally makes sense. The AI notes are spooky good.&rdquo;</blockquote>
                <figcaption class="mt-1 text-sm text-slate-300/70">— Early user</figcaption>
            </figure>
        </div>
    </section>

    <!-- Right: auth card -->
    <section class="p-6 md:p-10 flex items-center bg-white">
        <div class="w-full max-w-md mx-auto">
            <div class="mb-6 text-center md:hidden">
                <div class="inline-flex items-center gap-2">
                    <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 2v7a6 6 0 1 0 12 0V2"/><path d="M8 22h8"/></svg>
                    <span class="font-extrabold text-xl tracking-tight">WineCellarHub</span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 shadow-sm p-6 bg-white">
                <h1 class="text-2xl font-semibold">Sign in</h1>
                <p class="text-sm text-gray-500 mt-1 mb-4">Use your username or email.</p>

                <?php if (!empty($error)): ?>
                    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-red-700">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="/login.php" autocomplete="off" class="space-y-4">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Username or Email</label>
                        <input type="text" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="you@example.com" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" required
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="••••••••" />
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="remember" value="1" class="rounded border-gray-300">
                            <span>Stay signed in</span>
                        </label>
                        <a href="/forgot_password.php" class="text-sm text-blue-700 hover:underline">Forgot password?</a>
                    </div>

                    <button type="submit"
                            class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-white font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600">
                        Sign in
                    </button>
                </form>

                <p class="mt-4 text-center text-sm text-gray-600">
                    Don’t have an account?
                    <a href="/register.php<?= $next && $next !== $defaultNext ? ('?next=' . urlencode($next)) : '' ?>" class="text-blue-700 hover:underline">Create one</a>
                </p>

                <div class="mt-6 grid grid-cols-3 gap-3 text-center text-xs text-gray-500">
                    <div><div class="font-semibold text-gray-900">25k+</div>Catalog wines</div>
                    <div><div class="font-semibold text-gray-900">FREE</div>No cost to use site</div>
                    <div><div class="font-semibold text-gray-900">AI</div>Insights & pairings</div>
                </div>
            </div>

            <p class="mt-6 text-xs text-gray-500 text-center">By signing in, you agree to our <a class="underline" href="/terms.php">Terms</a> and <a class="underline" href="/privacy.php">Privacy Policy</a>.</p>
        </div>
    </section>
</div>
</body>
</html>
