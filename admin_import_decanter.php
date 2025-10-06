
<?php
require 'db.php';
require_once __DIR__ . '/auth.php';

// Optional admin gate:
if (empty($_SESSION['is_admin'])) { http_response_code(403); exit('Admins only'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <?php include __DIR__."/theme_snippet.php"; ?>
    <title>Import Decanter Picks</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="card">
<div class="card">
    <h1 class="text-2xl font-bold mb-4">Import Decanter Picks → Wines + Expert Picks</h1>

    <form class="bg-white p-4 rounded-2xl shadow" method="post" action="import_decanter.php" enctype="multipart/form-data">
        <div class="mb-3">
            <label class="block text-sm mb-1">Upload CSV or JSON</label>
            <input type="file" name="file" accept=".csv,application/json,.json" required class="border p-2 rounded w-full">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <input class="input" name="default_source" placeholder="Default source" value="Decanter DWWA">
            <input class="input" name="default_year" placeholder="Default year (e.g. 2025)">
            <input class="input" name="default_list_name" placeholder="Default list (Best in Show / Platinum)">
            <input class="input" name="default_medal" placeholder="Default medal (optional)">
        </div>

        <label class="inline-flex items-center gap-2 mt-3">
            <input type="checkbox" name="update_rating" value="1" class="border">
            <span class="text-sm">Also write <code>score/rating</code> → <code>wines.rating</code> (when matched/inserted)</span>
        </label>

        <label class="inline-flex items-center gap-2 mt-3">
            <input type="checkbox" name="update_only" value="1" class="border">
            <span class="text-sm">Update existing <code>expert_picks</code> only (avoid adding new rows)</span>
        </label>

        <p class="text-sm text-gray-600 mt-2">
            Recognized columns (CSV/JSON):
            <code>source, year, list_name, medal, score, name, winery, region, type, vintage, country, grapes, image_url, notes</code>
        </p>

        <div class="mt-4 flex gap-2">
            <button class="btn">Import</button>
            <a class="px-4 py-2 rounded-xl border" href="home.php">Back</a>
        </div>
    </form>

    <div class="mt-6 text-sm text-gray-600">
        Tip: Upload directly here—no need to place CSV on disk.
    </div>
</div>
</body>
</html>
