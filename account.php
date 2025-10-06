<?php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // if you have require_login(); otherwise use session check below

// --- PDO shim if your db.php uses $pdo:
if (!isset($wine_pdo) && isset($pdo)) { $wine_pdo = $pdo; }

// --- Basic auth gate (use your own if you have one)
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// --- Create a tiny table for recommendations prefs (safe if already exists)
$wine_pdo->exec("
CREATE TABLE IF NOT EXISTS user_reco_prefs (
  user_id INT PRIMARY KEY,
  varietals TEXT NULL,
  styles TEXT NULL,
  updated_at DATETIME NULL,
  CONSTRAINT urp_fk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Load current user & prefs
$userStmt = $wine_pdo->prepare("SELECT id, email FROM users WHERE id = ?");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

$epStmt = $wine_pdo->prepare("SELECT weekly_digest FROM email_prefs WHERE user_id = ?");
$epStmt->execute([$user_id]);
$email_prefs = $epStmt->fetch(PDO::FETCH_ASSOC);

$urpStmt = $wine_pdo->prepare("SELECT varietals, styles FROM user_reco_prefs WHERE user_id = ?");
$urpStmt->execute([$user_id]);
$reco_prefs = $urpStmt->fetch(PDO::FETCH_ASSOC) ?: ['varietals'=>'', 'styles'=>''];

// --- Form handling
$flash = null; $flash_err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_digest') {
        $enabled = isset($_POST['weekly_digest']) ? 1 : 0;
        $stmt = $wine_pdo->prepare("
            INSERT INTO email_prefs (user_id, weekly_digest)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE weekly_digest = VALUES(weekly_digest)
        ");
        $stmt->execute([$user_id, $enabled]);
        $email_prefs['weekly_digest'] = $enabled;
        $flash = 'Weekly digest preference updated.';

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new) < 8) {
            $flash_err = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $flash_err = 'New password and confirmation do not match.';
        } else {
            // Fetch hash; adjust column if your schema uses a different name
            $ph = $wine_pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $ph->execute([$user_id]);
            $row = $ph->fetch(PDO::FETCH_ASSOC);

            $hash = $row['password_hash'] ?? null;

            // If your schema stores plain text (hopefully not), adjust this check accordingly.
            if (!$hash || !password_verify($current, $hash)) {
                $flash_err = 'Current password is incorrect.';
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $up = $wine_pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $up->execute([$newHash, $user_id]);
                $flash = 'Password updated successfully.';
            }
        }

        // Save to user_profiles instead of a separate table
    } elseif ($action === 'save_reco_prefs') {
        $varietals = array_values(array_filter(array_map('trim', explode(',', $_POST['varietals'] ?? ''))));
        $styles_in = array_values(array_filter(array_map('trim', explode(',', $_POST['styles'] ?? ''))));

        // Simple style weights: first gets 0.7, second 0.2, third 0.1 (normalize if fewer)
        $weights = [0.7, 0.2, 0.1];
        $style_vec = [];
        foreach ($styles_in as $i => $label) {
            if ($i > 2) break;
            $style_vec[$label] = $weights[$i];
        }
        // Normalize if only 1–2 styles selected
        $sum = array_sum($style_vec) ?: 1.0;
        foreach ($style_vec as $k => $v) $style_vec[$k] = round($v / $sum, 3);

        $st = $wine_pdo->prepare("
        INSERT INTO user_profiles (user_id, varietal_top3, style_vector, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          varietal_top3 = VALUES(varietal_top3),
          style_vector  = VALUES(style_vector),
          updated_at    = VALUES(updated_at)
    ");
        $st->execute([
            $user_id,
            json_encode(array_slice($varietals, 0, 3), JSON_UNESCAPED_UNICODE),
            json_encode($style_vec, JSON_UNESCAPED_UNICODE)
        ]);

        $flash = 'Recommendation preferences saved.';
    }

}

$weekly_enabled = (int)($email_prefs['weekly_digest'] ?? 1);
?>
<!doctype html>
<html lang="en">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Account</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
</head>
<body class="bg-gray-50 text-gray-900">
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="max-w-3xl mx-auto p-4 md:p-8">
    <h1 class="text-3xl font-semibold mb-6">Account</h1>

    <?php if ($flash): ?>
        <div class="mb-4 rounded-lg bg-green-50 text-green-800 px-4 py-3 border border-green-200">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
        <div class="mb-4 rounded-lg bg-red-50 text-red-800 px-4 py-3 border border-red-200">
            <?= htmlspecialchars($flash_err) ?>
        </div>
    <?php endif; ?>

    <!-- Weekly Digest -->
    <div class="bg-white rounded-2xl shadow p-6 mb-8">
        <h2 class="text-xl font-semibold mb-2">Email Preferences</h2>
        <p class="text-sm text-gray-600 mb-4">Control your weekly digest emails.</p>
        <form method="post" class="space-y-4">
            <input type="hidden" name="action" value="toggle_digest" />
            <label class="flex items-center gap-3">
                <input type="checkbox" name="weekly_digest" value="1" <?= $weekly_enabled ? 'checked' : '' ?> class="h-5 w-5 rounded border-gray-300">
                <span>Receive weekly digest</span>
            </label>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600">
                Save
            </button>
        </form>
    </div>
    <!-- CSV Import / Export -->
    <div class="bg-white rounded-2xl shadow p-6 mb-8">
        <h2 class="text-xl font-semibold mb-2">Inventory CSV</h2>
        <p class="text-sm text-gray-600 mb-4">
            Export your current bottles or import new ones from a CSV. Import is insert-only and won’t change existing bottles.
        </p>

        <div class="flex flex-wrap items-center gap-4">
            <a href="/export_inventory.php"
               class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500">
                Download CSV
            </a>

            <form action="/import_inventory.php" method="post" enctype="multipart/form-data" class="flex items-center gap-3">
                <input type="file" name="csv" accept=".csv,text/csv" required
                       class="block text-sm file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-gray-100 file:text-gray-700 file:hover:bg-gray-200" />
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 rounded-lg bg-gray-900 text-white hover:bg-black focus:ring-2 focus:ring-gray-700">
                    Import CSV
                </button>
            </form>

            <details class="ml-auto">
                <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700">CSV columns</summary>
                <div class="mt-2 text-sm text-gray-600">
                    <code>winery, name, vintage, region, grapes, price_paid, my_rating, location, past, wine_id, photo_path, image_url</code>
                </div>
            </details>
        </div>
    </div>


    <!-- Change Password -->
    <div class="bg-white rounded-2xl shadow p-6 mb-8">
        <h2 class="text-xl font-semibold mb-2">Change Password</h2>
        <p class="text-sm text-gray-600 mb-4">Update your password for this account.</p>
        <form method="post" class="grid gap-4 max-w-md">
            <input type="hidden" name="action" value="change_password" />
            <div>
                <label class="block text-sm text-gray-700 mb-1">Current password</label>
                <input type="password" name="current_password" required class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">New password</label>
                <input type="password" name="new_password" minlength="8" required class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">Confirm new password</label>
                <input type="password" name="confirm_password" minlength="8" required class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
            </div>
            <div>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600">
                    Update Password
                </button>
            </div>
        </form>
    </div>

    <!-- Recommendation Preferences (very light) -->
    <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-2">Recommendation Preferences</h2>
        <p class="text-sm text-gray-600 mb-4">Optionally list favorite varietals and styles to guide early recommendations. (Comma-separated)</p>
        <form method="post" class="grid gap-4">
            <input type="hidden" name="action" value="save_reco_prefs" />
            <div>
                <label class="block text-sm text-gray-700 mb-1">Favorite varietals</label>
                <input type="text" name="varietals" placeholder="e.g., Cabernet Sauvignon, Riesling"
                       value="<?= htmlspecialchars($reco_prefs['varietals'] ?? '') ?>"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">Favorite styles</label>
                <input type="text" name="styles" placeholder="e.g., Bold & structured, Light & crisp"
                       value="<?= htmlspecialchars($reco_prefs['styles'] ?? '') ?>"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
            </div>
            <div>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600">
                    Save Preferences
                </button>
            </div>
        </form>
    </div>

    <div class="mt-10 text-sm text-gray-500">
        Logged in as <span class="font-medium"><?= htmlspecialchars($user['email'] ?? '') ?></span>
    </div>
</div>
</body>
<?php require __DIR__ . '/partials/footer.php'; ?>

</html>
