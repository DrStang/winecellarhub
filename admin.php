<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require __DIR__.'/analytics_track.php'; // <-- add this


if (!$_SESSION['is_admin']) {
    die("Access denied. Admins only.");
}

// === Fetch all users and their bottle counts ===
$users = $pdo->query("
    SELECT u.id, u.username, u.email, u.is_admin, COUNT(b.id) AS bottle_count
    FROM users u
    LEFT JOIN bottles b ON u.id = b.user_id
    GROUP BY u.id
    ORDER BY u.username
")->fetchAll();

// === Most common wines (name + vintage + count) ===
$popular = $pdo->query("
    SELECT name, vintage, COUNT(*) AS count
    FROM bottles
    GROUP BY name, vintage
    HAVING count > 1
    ORDER BY count DESC
    LIMIT 10
")->fetchAll();

// === Handle promote/delete user actions ===
if (isset($_GET['promote'])) {
    $stmt = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE id = ?");
    $stmt->execute([$_GET['promote']]);
    header("Location: admin.php");
    exit();
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: admin.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-900">
<div class="max-w-5xl mx-auto py-8">
    <h1 class="text-3xl font-bold mb-6">🛠 Admin Dashboard</h1>

    <h2 class="text-xl font-semibold mb-2">👥 Users</h2>
    <table class="w-full mb-6 bg-white rounded shadow">
        <thead class="bg-gray-200">
        <tr>
            <th class="p-3 text-left">Username</th>
            <th class="p-3 text-left">Email</th>
            <th class="p-3 text-center">Bottles</th>
            <th class="p-3 text-center">Admin</th>
            <th class="p-3 text-center">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr class="border-t hover:bg-gray-50">
                <td class="p-3"><?= htmlspecialchars($user['username']) ?></td>
                <td class="p-3"><?= htmlspecialchars($user['email']) ?></td>
                <td class="p-3 text-center"><?= $user['bottle_count'] ?></td>
                <td class="p-3 text-center"><?= $user['is_admin'] ? '✔️' : '' ?></td>
                <td class="p-3 text-center space-x-2">
                    <?php if (!$user['is_admin']): ?>
                        <a href="?promote=<?= $user['id'] ?>" class="text-blue-600 hover:underline">Promote</a>
                    <?php endif; ?>
                    <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                        <a href="?delete=<?= $user['id'] ?>" class="text-red-600 hover:underline" onclick="return confirm('Delete this user?')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 class="text-xl font-semibold mb-2">🍷 Most Common Wines</h2>
    <table class="w-full bg-white rounded shadow">
        <thead class="bg-gray-200">
        <tr>
            <th class="p-3 text-left">Name</th>
            <th class="p-3 text-left">Vintage</th>
            <th class="p-3 text-center">Users</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($popular as $wine): ?>
            <tr class="border-t hover:bg-gray-50">
                <td class="p-3"><?= htmlspecialchars($wine['name']) ?></td>
                <td class="p-3"><?= htmlspecialchars($wine['vintage']) ?></td>
                <td class="p-3 text-center"><?= $wine['count'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <a href="admin_cf.php" class="inline-flex items-center px-3 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">
        CF Health & Freshness
    </a>
    <?php
    // Ensure a CSRF token exists
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    $csrf = $_SESSION['csrf'];
    ?>
    <div class="bg-white border rounded-lg p-4 shadow mt-6">
        <h3 class="text-lg font-semibold mb-3">AI Insights Maintenance</h3>
        <form id="insights-one" class="flex items-center gap-2" onsubmit="return false;">
            <input type="number" min="1" id="insights-wine-id" class="border p-2 rounded" placeholder="winelist wine_id">
            <button class="px-3 py-2 bg-blue-600 text-white rounded" onclick="runInsightsOne()">Rebuild this wine</button>
        </form>
        <div class="mt-3">
            <button class="px-3 py-2 bg-gray-800 text-white rounded" onclick="runInsightsInventory()">Rebuild for my users' inventory</button>
        </div>
        <pre id="insights-log" class="text-xs text-gray-600 mt-3"></pre>
    </div>
    <script>
        function runInsightsOne(){
            const id = document.getElementById('insights-wine-id').value.trim();
            if(!id) return;
            fetch('/api/admin_insights_action.php', {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:new URLSearchParams({action:'insights_one', wine_id:id, csrf:'<?= $csrf ?>'})
            }).then(r=>r.json()).then(j=>{
                document.getElementById('insights-log').textContent = JSON.stringify(j,null,2);
            }).catch(e=>{document.getElementById('insights-log').textContent=String(e);});
        }
        function runInsightsInventory(){
            fetch('/api/admin_insights_action.php', {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:new URLSearchParams({action:'insights_inventory', csrf:'<?= $csrf ?>'})
            }).then(r=>r.json()).then(j=>{
                document.getElementById('insights-log').textContent = JSON.stringify(j,null,2);
            }).catch(e=>{document.getElementById('insights-log').textContent=String(e);});
        }
    </script>

    <div class="mt-6">
        <a href="inventory.php" class="text-blue-600 hover:underline">← Back to Inventory</a>
        <a href="admin_share_edit.php" class="text-[var(--text)] hover:underline">Share Edit</a>
        <a href="analytics.php" class="text-blue-600 hover:underline">Analytics</a>
    </div>
</div>
</body>
</html>
