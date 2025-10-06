<?php
require 'db.php';
require 'auth.php';
require __DIR__.'/analytics_track.php'; // <-- add this

$user_id = $_SESSION['user_id'];

// Ensure table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS wantlist (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  wine_id INT NULL,
  name VARCHAR(255) NULL,
  winery VARCHAR(255) NULL,
  region VARCHAR(255) NULL,
  type VARCHAR(50) NULL,
  vintage VARCHAR(12) NULL,
  notes TEXT NULL,
  added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id),
  INDEX(wine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Fetch current wantlist
$stmt = $pdo->prepare("SELECT * FROM wantlist WHERE user_id = ? ORDER BY added_on DESC");
$stmt->execute([$user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <meta charset="UTF-8" />
  <title>Wantlist</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
<?php require __DIR__ . '/partials/header.php'; ?>
  <div class="max-w-6xl mx-auto p-6">
    <h1 class="text-3xl font-bold mb-1">🧞 Wantlist</h1>
    <p class="text-gray-600 mb-6">Search the central catalog or add manually.</p>

    <div class="grid md:grid-cols-2 gap-6">
      <!-- Catalog search (if available) -->
      <div class="bg-white rounded-2xl shadow p-4">
        <h2 class="text-xl font-semibold mb-3">🔎 Search Catalog</h2>
        <?php if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)): ?>
          <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 p-2 rounded">
            Central catalog connection not configured. Manual entry only for now.
          </p>
        <?php endif; ?>
        <form id="formSearch" onsubmit="return false" class="flex gap-2 mt-2">
          <input id="q" class="flex-1 border rounded-lg p-2" placeholder="Search by name, winery, grape, region..." />
          <button id="btnSearch" class="px-4 py-2 rounded-xl bg-indigo-600 text-white">Search</button>
        </form>
        <div id="searchResults" class="mt-4 divide-y"></div>
      </div>

      <!-- Manual add -->
      <div class="bg-white rounded-2xl shadow p-4">
        <h2 class="text-xl font-semibold mb-3">✍️ Add Manually</h2>
        <form method="post" action="wantlist_api.php">
          <input type="hidden" name="action" value="add_manual" />
          <div class="grid grid-cols-2 gap-3">
            <input class="border rounded-lg p-2 col-span-2" name="name" placeholder="Wine name" required>
            <input class="border rounded-lg p-2" name="winery" placeholder="Winery">
            <input class="border rounded-lg p-2" name="region" placeholder="Region">
            <input class="border rounded-lg p-2" name="type" placeholder="Type (red, white, ...)">
            <input class="border rounded-lg p-2" name="vintage" placeholder="Vintage">
          </div>
          <textarea class="border rounded-lg p-2 mt-3 w-full" name="notes" placeholder="Notes"></textarea>
          <div class="mt-3">
            <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white">Add to Wantlist</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Current wantlist -->
    <div class="bg-white rounded-2xl shadow mt-8">
      <div class="p-4 border-b flex items-center justify-between">
        <h2 class="text-xl font-semibold">My Wantlist</h2>
      </div>
      <?php if (!$items): ?>
        <p class="p-4 text-gray-600">Empty for now.</p>
      <?php else: ?>
        <div class="divide-y">
          <?php foreach ($items as $it): ?>
            <div class="p-4 flex items-center justify-between">
              <div class="min-w-0">
                <div class="font-medium truncate"><?= htmlspecialchars($it['name'] ?? 'Untitled') ?></div>
                <div class="text-sm text-gray-600 truncate">
                  <?= htmlspecialchars($it['winery'] ?? '') ?> <?= !empty($it['winery'])?' · ':'' ?>
                  <?= htmlspecialchars($it['region'] ?? '') ?> <?= !empty($it['region'])?' · ':'' ?>
                  <?= htmlspecialchars($it['type'] ?? '') ?> <?= !empty($it['type'])?' · ':'' ?>
                  <?= htmlspecialchars($it['vintage'] ?? '') ?>
                </div>
                <?php if (!empty($it['notes'])): ?>
                  <div class="text-sm text-gray-700 mt-1"><?= nl2br(htmlspecialchars($it['notes'])) ?></div>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2">
                <form method="post" action="wantlist_api.php">
                  <input type="hidden" name="action" value="move_to_inventory" />
                  <input type="hidden" name="id" value="<?= (int)$it['id'] ?>" />
                  <button class="px-3 py-1 rounded-lg border hover:bg-gray-50" title="Move to Inventory">Move</button>
                </form>
                <form method="post" action="wantlist_api.php">
                  <input type="hidden" name="action" value="remove" />
                  <input type="hidden" name="id" value="<?= (int)$it['id'] ?>" />
                  <button class="px-3 py-1 rounded-lg border hover:bg-gray-50">Remove</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="mt-6">
      <a href="home.php" class="text-indigo-600 hover:underline">← Back to Home</a>
    </div>
  </div>

  <script>
    const btn = document.getElementById('btnSearch');
    const q = document.getElementById('q');
    const results = document.getElementById('searchResults');
    btn?.addEventListener('click', async () => {
      if (!q.value.trim()) {
        results.innerHTML = '<div class="p-2 text-gray-600">Enter a search term.</div>';
        return;
      }
      results.innerHTML = '<div class="p-2 text-gray-600">Searching…</div>';
      const res = await fetch('wantlist_api.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'search', q: q.value})
      });
      const html = await res.text();
      results.innerHTML = html;
    });
  </script>
</body>
</html>
