<?php
$log_dir = "/mnt/wine/logs";
$status_dir = "/mnt/wine/status";
$covers_dir = "/mnt/wine/covers";

// Get logs
$logs = glob("$log_dir/*.log");

// Get status files
$status_files = glob("$status_dir/*.json");

// Get image thumbnails
$images = glob("$covers_dir/*.jpg");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Wine Crawler Dashboard</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss/dist/tailwind.min.css">
  <style>
    .log-box { max-height: 300px; overflow: auto; font-family: monospace; background: #f3f3f3; padding: 1em; }
    .thumb { width: 100px; height: auto; object-fit: contain; border: 1px solid #ccc; }
    .grid { display: flex; flex-wrap: wrap; gap: 10px; }
  </style>
</head>
<body class="bg-gray-100 text-gray-800 p-6">
  <h1 class="text-3xl font-bold mb-6">📊 Wine Crawler Dashboard</h1>

  <!-- Status Section -->
  <section class="mb-10">
    <h2 class="text-xl font-semibold mb-4">🟢 Crawler Status</h2>
    <div class="bg-white shadow rounded p-4">
      <ul class="space-y-2">
        <?php foreach ($status_files as $file): 
          $data = json_decode(file_get_contents($file), true);
          ?>
          <li>
            <span class="font-bold"><?= htmlspecialchars($data['node']) ?> — <?= htmlspecialchars($data['script']) ?>:</span>
            <span><?= htmlspecialchars($data['status']) ?> @ <?= htmlspecialchars($data['timestamp']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <!-- Logs Section -->
  <section class="mb-10">
    <h2 class="text-xl font-semibold mb-4">📜 Logs</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <?php foreach ($logs as $log): ?>
        <div class="bg-white shadow rounded p-4">
          <h3 class="font-bold mb-2"><?= basename($log) ?></h3>
          <div class="log-box">
            <?php
              $lines = file($log);
              $last = array_slice($lines, -30); // last 30 lines
              echo "<pre>" . htmlspecialchars(implode("", $last)) . "</pre>";
            ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Images / Covers Section -->
  <section class="mb-10">
    <h2 class="text-xl font-semibold mb-4">🍷 Thumbnails (<?= count($images) ?>)</h2>
    <input type="text" id="filter" placeholder="🔎 Filter by name..." class="border rounded px-3 py-1 mb-4 w-full max-w-md">
    <div class="grid" id="imageGrid">
      <?php foreach ($images as $img): ?>
        <div class="text-center">
          <img src="<?= str_replace("/mnt/wine/", "/", $img) ?>" class="thumb" alt="<?= basename($img) ?>">
          <div class="text-sm truncate"><?= basename($img) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <script>
    // Live filter for covers
    document.getElementById('filter').addEventListener('input', function () {
      const val = this.value.toLowerCase();
      document.querySelectorAll('#imageGrid div').forEach(div => {
        const name = div.textContent.toLowerCase();
        div.style.display = name.includes(val) ? '' : 'none';
      });
    });

    // Optional: auto-refresh every 60s
    // setTimeout(() => location.reload(), 60000);
  </script>
</body>
</html>
