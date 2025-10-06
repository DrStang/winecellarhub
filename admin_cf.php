<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';


if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Forbidden'); }

/**
 * Tail last N lines of a file. Returns empty string on failure.
 */
function tail_file($path, $lines = 200) {
    if (!is_readable($path)) return "";
    $all = @file($path);
    if (!$all) return "";
    return implode("", array_slice($all, max(0, count($all) - $lines)));
}

function pct($num, $den) {
    if ($den <= 0) return '0%';
    return number_format(($num / $den) * 100, 1) . '%';
}

// --- Load metrics ---
try {
    $totalUsers = intval($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn());
    $totalWines = intval($winelist_pdo->query("SELECT COUNT(*) FROM wines")->fetchColumn());

    $cfUserCount = intval($pdo->query("SELECT COUNT(*) FROM cf_user_factors")->fetchColumn());
    $cfWineCount = intval($pdo->query("SELECT COUNT(*) FROM cf_wine_factors")->fetchColumn());

    $cfUserFresh = $pdo->query("SELECT MIN(updated_at) min_u, MAX(updated_at) max_u,
    SUM(updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) stale_u,
    COUNT(*) total_u FROM cf_user_factors")->fetch(PDO::FETCH_ASSOC);
    $cfWineFresh = $pdo->query("SELECT MIN(updated_at) min_i, MAX(updated_at) max_i,
    SUM(updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) stale_i,
    COUNT(*) total_i FROM cf_wine_factors")->fetch(PDO::FETCH_ASSOC);

    $activeRerankUsers = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_recos WHERE source='rerank' AND (expires_at IS NULL OR expires_at >= NOW())")->fetchColumn();
    $totalProfiles = intval($pdo->query("SELECT COUNT(*) FROM user_profiles")->fetchColumn());

    $stalestUsers = $pdo->query("
    SELECT c.user_id, c.updated_at, u.email
    FROM cf_user_factors c
    LEFT JOIN users u ON u.id=c.user_id
    ORDER BY c.updated_at ASC LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC);

    $catalogDb = $_ENV['WINELIST_DB'] ?? 'winelist';
    $stalestItems = $pdo->query("
    SELECT c.wine_id, c.updated_at, w.name, w.region, w.grapes
    FROM cf_wine_factors c
    LEFT JOIN {$catalogDb}.wines w ON w.id=c.wine_id
    ORDER BY c.updated_at ASC LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    http_response_code(500);
    echo "Error loading metrics: " . htmlspecialchars($e->getMessage());
    exit;
}

$cf_log     = tail_file('/var/log/wine/cf_train.log', 200);
$rerank_log = tail_file('/var/log/wine/rerank.log', 200);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin · CF Health</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">Collaborative Filtering · Health & Freshness</h1>
        <a href="admin.php" class="text-indigo-600 hover:underline">← Back to Admin</a>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm text-gray-500">CF User Coverage</div>
            <div class="text-2xl font-semibold mt-1"><?= $cfUserCount, " / ", $totalUsers ?></div>
            <div class="text-xs text-gray-500 mt-1"><?= pct($cfUserCount, max(1,$totalUsers)) ?></div>
        </div>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm text-gray-500">CF Wine Coverage</div>
            <div class="text-2xl font-semibold mt-1"><?= $cfWineCount, " / ", $totalWines ?></div>
            <div class="text-xs text-gray-500 mt-1"><?= pct($cfWineCount, max(1,$totalWines)) ?></div>
        </div>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm text-gray-500">Users w/ Active Curated Picks (7d)</div>
            <div class="text-2xl font-semibold mt-1"><?= intval($activeRerankUsers), " / ", $totalProfiles ?></div>
            <div class="text-xs text-gray-500 mt-1"><?= pct(intval($activeRerankUsers), max(1,$totalProfiles)) ?></div>
        </div>
    </div>

    <!-- Freshness -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm text-gray-500 mb-2">User Factors Freshness</div>
            <div class="text-sm">Newest: <span class="font-medium"><?= htmlspecialchars($cfUserFresh['max_u'] ?? '—') ?></span></div>
            <div class="text-sm">Oldest: <span class="font-medium"><?= htmlspecialchars($cfUserFresh['min_u'] ?? '—') ?></span></div>
            <div class="text-sm mt-1">Stale (>7d): <span class="font-medium"><?= intval($cfUserFresh['stale_u'] ?? 0) ?></span> / <?= intval($cfUserFresh['total_u'] ?? 0) ?></div>
        </div>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm text-gray-500 mb-2">Wine Factors Freshness</div>
            <div class="text-sm">Newest: <span class="font-medium"><?= htmlspecialchars($cfWineFresh['max_i'] ?? '—') ?></span></div>
            <div class="text-sm">Oldest: <span class="font-medium"><?= htmlspecialchars($cfWineFresh['min_i'] ?? '—') ?></span></div>
            <div class="text-sm mt-1">Stale (>7d): <span class="font-medium"><?= intval($cfWineFresh['stale_i'] ?? 0) ?></span> / <?= intval($cfWineFresh['total_i'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Stalest lists -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow p-5 overflow-x-auto">
            <div class="text-sm text-gray-500 mb-2">Stalest User Factors</div>
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th>User</th><th>Updated</th></tr></thead>
                <tbody>
                <?php if ($stalestUsers): foreach ($stalestUsers as $row): ?>
                    <tr class="border-t">
                        <td class="py-1"><?= htmlspecialchars($row['email'] ?: ('User #'.$row['user_id'])) ?></td>
                        <td class="py-1"><?= htmlspecialchars($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="2" class="py-3 text-gray-500">No data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="bg-white rounded-xl shadow p-5 overflow-x-auto">
            <div class="text-sm text-gray-500 mb-2">Stalest Wine Factors</div>
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th>Wine</th><th>Updated</th></tr></thead>
                <tbody>
                <?php if ($stalestItems): foreach ($stalestItems as $row): ?>
                    <tr class="border-t">
                        <td class="py-1">
                            <?php
                            $label = $row['name'] ?: ('Wine #'.$row['wine_id']);
                            $meta = [];
                            if (!empty($row['region'])) $meta[] = $row['region'];
                            if (!empty($row['grapes'])) $meta[] = $row['grapes'];
                            echo htmlspecialchars($label . (count($meta)? ' — '.implode(', ', $meta):''));
                            ?>
                        </td>
                        <td class="py-1"><?= htmlspecialchars($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="2" class="py-3 text-gray-500">No data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Logs -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm text-gray-500 mb-2">CF Train Log (tail)</div>
            <pre class="text-xs bg-gray-50 p-3 rounded overflow-auto max-h-80 whitespace-pre-wrap"><?= htmlspecialchars($cf_log ?: 'No log / unreadable.') ?></pre>
        </div>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="text-sm text-gray-500 mb-2">Rerank Log (tail)</div>
            <pre class="text-xs bg-gray-50 p-3 rounded overflow-auto max-h-80 whitespace-pre-wrap"><?= htmlspecialchars($rerank_log ?: 'No log / unreadable.') ?></pre>
        </div>
    </div>

</div>
</body>
</html>
