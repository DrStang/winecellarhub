<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require __DIR__.'/analytics_track.php'; // <-- add this


// Make sure new columns exist (idempotent)
try {
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS past TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS my_rating DECIMAL(3,2) NULL");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS my_review TEXT NULL");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS reviewed_on DATETIME NULL");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL");
} catch (Exception $e) {}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header("Location: login.php"); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header("Location: inventory.php"); exit; }

// Fetch bottle
$stmt = $pdo->prepare("SELECT * FROM bottles WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$id, $user_id]);
$bottle = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$bottle) {
    die("Bottle not found or access denied.");
}

// Optional: enrich from winelist if present
$catalog = null;
if (!empty($bottle['wine_id']) && isset($winelist_pdo)) {
    try {
        $q = $winelist_pdo->prepare("SELECT id, name AS wine_name, winery, region, country, grapes, style, rating, food_pairings, image_url, price AS catalog_price FROM wines WHERE id = ?");
        $q->execute([$bottle['wine_id']]);
        $catalog = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {}
}

// Handle update
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Fields the user can edit (from bottle.php view)
    $fields = [
        'name'        => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'winery'      => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'region'      => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'country'     => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'grapes'      => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'style'       => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'vintage'     => FILTER_SANITIZE_NUMBER_INT,
        'rating'      => FILTER_SANITIZE_NUMBER_FLOAT, // catalog/expert rating if tracked locally
        'my_rating'   => ['filter' => FILTER_SANITIZE_NUMBER_FLOAT, 'flags' => FILTER_FLAG_ALLOW_FRACTION],
        'price_paid'  => FILTER_SANITIZE_NUMBER_FLOAT,
        'location'    => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'upc'         => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'my_review'   => FILTER_UNSAFE_RAW, // allow full text; we'll parameterize safely
    ];

    $data = filter_input_array(INPUT_POST, $fields) ?: [];

    // Normalize numbers
    $data['vintage']    = isset($data['vintage']) && $data['vintage'] !== '' ? (int)$data['vintage'] : null;
    if (isset($data['my_rating']) && $data['my_rating'] !== '') {
        $val = str_replace(',', '.', (string)$data['my_rating']); // support "4,2"
        $val = (float)$val;
        $val = max(0, min(5, $val));
        $data['my_rating'] = round($val, 2); // DECIMAL(3,2) friendly
    } else {
        $data['my_rating'] = null;
    }
    $data['rating']     = isset($data['rating']) && $data['rating'] !== '' ? (float)$data['rating'] : null;
    $data['price_paid'] = isset($data['price_paid']) && $data['price_paid'] !== '' ? (float)$data['price_paid'] : null;

    $past = isset($_POST['past']) ? 1 : 0;

    // Handle photo upload (optional)
    $photo_path = $bottle['photo_path'] ?? null;
    if (!empty($_POST['remove_photo'])) {
        // user requested to remove current photo
        $photo_path = null;
    }
    if (isset($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $errors[] = "Unsupported photo type. Please upload JPG, PNG, or WEBP.";
        } else {
            $dir = __DIR__ . '/uploads/bottles/' . $user_id;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $fname = 'bottle_' . $id . '_' . time() . '.' . $ext;
            $dest  = $dir . '/' . $fname;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                // store relative path for web
                $photo_path = 'uploads/bottles/' . $user_id . '/' . $fname;
            } else {
                $errors[] = "Failed to save uploaded photo.";
            }
        }
    }

    if (!$errors) {
        // Build dynamic SQL
        $sql = "UPDATE bottles SET 
            name = :name,
            winery = :winery,
            region = :region,
            country = :country,
            grapes = :grapes,
            style = :style,
            vintage = :vintage,
            rating = :rating,
            my_rating = :my_rating,
            price_paid = :price_paid,
            location = :location,
            upc = :upc,
            my_review = :my_review,
            past = :past" .
            ($photo_path !== $bottle['photo_path'] ? ", photo_path = :photo_path" : "") .
            // If user changed/added my_rating, refresh reviewed_on
            ((isset($data['my_rating']) && $data['my_rating'] !== $bottle['my_rating']) ? ", reviewed_on = NOW()" : "") . "
        WHERE id = :id AND user_id = :user_id";

        $stmt = $pdo->prepare($sql);
        $params = [
            ':name'       => $data['name'] ?? $bottle['name'],
            ':winery'     => $data['winery'] ?? $bottle['winery'],
            ':region'     => $data['region'] ?? $bottle['region'],
            ':country'    => $data['country'] ?? $bottle['country'],
            ':grapes'     => $data['grapes'] ?? $bottle['grapes'],
            ':style'      => $data['style'] ?? $bottle['style'],
            ':vintage'    => $data['vintage'],
            ':rating'     => $data['rating'],
            ':my_rating'  => $data['my_rating'],
            ':price_paid' => $data['price_paid'],
            ':location'   => $data['location'] ?? $bottle['location'],
            ':upc'        => $data['upc'] ?? $bottle['upc'],
            ':my_review'  => $data['my_review'] ?? $bottle['my_review'],
            ':past'       => $past,
            ':id'         => $id,
            ':user_id'    => $user_id,
        ];
        if ($photo_path !== $bottle['photo_path']) {
            $params[':photo_path'] = $photo_path;
        }

        if ($stmt->execute($params)) {
            $success = true;
            // Refresh the in-memory bottle for redisplay
            $stmt2 = $pdo->prepare("SELECT * FROM bottles WHERE id = ? AND user_id = ? LIMIT 1");
            $stmt2->execute([$id, $user_id]);
            $bottle = $stmt2->fetch(PDO::FETCH_ASSOC);
        } else {
            $errors[] = "Database update failed.";
        }
    }
}

// Convenience values for form defaults
function val($arr, $key, $fallback = '') {
    return htmlspecialchars($arr[$key] ?? $fallback ?? '', ENT_QUOTES, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <?php include __DIR__."/theme_snippet.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Bottle</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="card">

    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Edit Bottle</h1>
        <div class="flex gap-2">
            <a href="home.php" class="px-3 py-2 rounded-lg shadow bg-white hover:bg-gray-50 border">🏠 Home</a>
            <a href="inventory.php" class="px-3 py-2 rounded-lg shadow bg-white hover:bg-gray-50 border">📦 Inventory</a>
            <button id="addBottleBtn" class="px-3 py-2 rounded-lg shadow bg-white hover:bg-gray-50 border">
                <h2 class="text-base">➕ Add another </h2>
            </button>
            <!--<a href="add_bottle.php" class="px-3 py-2 rounded-lg shadow bg-white hover:bg-gray-50 border">➕ Add another</a>-->
            <a href="bottle.php?id=<?= (int)$id ?>" class="px-3 py-2 rounded-lg shadow bg-white hover:bg-gray-50 border">🍷 View</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="mb-4 p-3 rounded bg-green-50 border border-green-200 text-green-800">
            Saved! Your bottle has been updated.
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800">
            <ul class="list-disc ml-6">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($catalog): ?>
        <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-2 p-4 rounded-xl bg-white shadow border">
                <h2 class="font-semibold mb-2">Catalog Details</h2>
                <div class="grid sm:grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-500">Wine:</span> <span class="font-medium"><?= val($catalog, 'wine_name') ?></span></div>
                    <div><span class="text-gray-500">Winery:</span> <span class="font-medium"><?= val($catalog, 'winery') ?></span></div>
                    <div><span class="text-gray-500">Region:</span> <span class="font-medium"><?= val($catalog, 'region') ?></span></div>
                    <div><span class="text-gray-500">Country:</span> <span class="font-medium"><?= val($catalog, 'country') ?></span></div>
                    <div><span class="text-gray-500">Grapes:</span> <span class="font-medium"><?= val($catalog, 'grapes') ?></span></div>
                    <div><span class="text-gray-500">Style:</span> <span class="font-medium"><?= val($catalog, 'style') ?></span></div>
                    <div><span class="text-gray-500">Rating:</span> <span class="font-medium"><?= val($catalog, 'rating') ?></span></div>
                    <div><span class="text-gray-500">Catalog Price:</span> <span class="font-medium"><?= val($catalog, 'catalog_price') ?></span></div>
                </div>
            </div>
            <div class="p-4 rounded-xl bg-white shadow border flex items-center justify-center">
                <?php if (!empty($bottle['photo_path'])): ?>
                    <img src="<?= htmlspecialchars($bottle['photo_path']) ?>" class="w-full max-h-52 object-contain rounded" alt="Bottle photo">
                <?php elseif ($catalog && !empty($catalog['image_url'])): ?>
                    <img src="<?= htmlspecialchars($catalog['image_url']) ?>" class="w-full max-h-52 object-contain rounded" alt="Catalog image">
                <?php else: ?>
                    <div class="text-gray-400">No image</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="p-4 sm:p-6 rounded-2xl bg-white shadow border space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Name</label>
                <input type="text" name="name" value="<?= val($bottle,'name') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Winery</label>
                <input type="text" name="winery" value="<?= val($bottle,'winery') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Region</label>
                <input type="text" name="region" value="<?= val($bottle,'region') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Country</label>
                <input type="text" name="country" value="<?= val($bottle,'country') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Grapes</label>
                <input type="text" name="grapes" value="<?= val($bottle,'grapes') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Style</label>
                <input type="text" name="style" value="<?= val($bottle,'style') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Vintage</label>
                <input type="number" inputmode="numeric" name="vintage" value="<?= htmlspecialchars($bottle['vintage'] ?? '') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., 2018">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Price Paid (USD)</label>
                <input type="number" step="0.01" name="price_paid" value="<?= htmlspecialchars($bottle['price_paid'] ?? '') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">UPC</label>
                <input type="text" name="upc" value="<?= val($bottle,'upc') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Rating (catalog/expert)</label>
                <input type="number" step="0.1" name="rating" value="<?= htmlspecialchars($bottle['rating'] ?? '') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., 4.1">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">My Rating (0–5)</label>
                <input type="number" min="0" max="5" step="0.01" inputmode="decimal" name="my_rating" value="<?= htmlspecialchars($bottle['my_rating'] ?? '') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., 4.1">
                <p class="text-xs text-gray-500 mt-1">Updating this sets <em>reviewed_on</em> to now.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Location</label>
                <input type="text" name="location" value="<?= val($bottle,'location') ?>" class="mt-1 w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., A2, B3">
                <p class="text-xs text-gray-500 mt-1">Your storage spot. We like lettered racks and numbered columns like <strong>A2, B3</strong>, but label however you like.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">My Notes</label>
                <textarea name="my_review" rows="5" class="mt-1 w-full rounded-lg bg-[var(--surface)] text-[var(--text)]focus:border-[var(--primary-600)] focus:ring-[var(--primary-600)]"><?= htmlspecialchars($bottle['my_review'] ?? '') ?></textarea>
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-700">Bottle Photo</label>
                <?php if (!empty($bottle['photo_path'])): ?>
                    <img src="<?= htmlspecialchars($bottle['photo_path']) ?>" class="w-full max-h-56 object-contain rounded border">
                    <label class="inline-flex items-center gap-2 text-sm mt-2">
                        <input type="checkbox" name="remove_photo" value="1" class="rounded border-gray-300">
                        Remove current photo
                    </label>
                <?php endif; ?>
                <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"/>
                <p class="text-xs text-gray-500">JPG, PNG, or WEBP.</p>

                <label class="inline-flex items-center gap-2 mt-4">
                    <input type="checkbox" name="past" value="1" <?= !empty($bottle['past']) ? 'checked' : '' ?> class="rounded border-gray-300">
                    <span class="text-sm">Move to <strong>Past</strong> (no longer in your cellar)</span>
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between pt-2">
            <div class="flex gap-2">
                <a href="bottle.php?id=<?= (int)$id ?>" class="px-3 py-2 rounded-lg shadow bg-white hover:bg-gray-50 border">Cancel</a>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg text-white bg-[var(--primary-600)] hover:bg-[var(--primary-700)]">Save</button>
        </div>
    </form>

    <!--<div class="mt-6 flex flex-wrap gap-2">
        <a href="scan.php" class="px-3 py-2 rounded-lg shadow bg-white hover:bg-gray-50 border">📷 Analyze Label</a>
    </div>-->

</div>
<div id="addChooser"
     class="fixed inset-0 z-[100] hidden"
     aria-hidden="true">
    <!-- backdrop -->
    <div class="absolute inset-0 bg-black/50" data-close></div>

    <!-- panel: bottom sheet on mobile, centered box on md+ -->
    <div class="absolute left-1/2 -translate-x-1/2 w-full max-w-lg
              md:top-1/2 md:-translate-y-1/2 md:rounded-2xl
              bottom-0 md:bottom-auto
              bg-[var(--surface)] text-[var(--text)] shadow-xl
              rounded-t-2xl p-4 md:p-6">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">Add a bottle</h3>
            <button class="p-2 rounded-lg hover:bg-black/5" data-close
                    aria-label="Close">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </div>

        <p class="text-sm text-[var(--muted)] mt-1">
            Choose how you’d like to add it.
        </p>

        <div class="mt-4 grid gap-3">
            <!-- Scan label -->
            <a href="/scan.php"
               id="optScan"
               class="flex items-center gap-3 rounded-xl border border-black/10 p-4 hover:bg-black/5">
                <div class="rounded-lg p-2 bg-black/5">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M4 4h5M15 4h5M4 20h5M15 20h5M4 9v6M20 9v6M9 4v5M15 4v5M9 15v5M15 15v5"/>
                    </svg>
                </div>
                <div>
                    <div class="font-medium">Scan label</div>
                    <div class="text-sm text-[var(--muted)]">Use your camera; AI will prefill.</div>
                </div>
            </a>

            <!-- Search / manual -->
            <a href="/add_bottle.php?mode=manual"
               id="optManual"
               class="flex items-center gap-3 rounded-xl border border-black/10 p-4 hover:bg-black/5">
                <div class="rounded-lg p-2 bg-black/5">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
                    </svg>
                </div>
                <div>
                    <div class="font-medium">Search catalog / manual entry</div>
                    <div class="text-sm text-[var(--muted)]">Find in catalog or enter details.</div>
                </div>
            </a>

        </div>
    </div>
</div>
<script>
    (function(){
        const openBtn  = document.getElementById('addBottleBtn');
        const modal    = document.getElementById('addChooser');
        const closes   = modal ? modal.querySelectorAll('[data-close]') : [];


        function open(){ if (modal){ modal.classList.remove('hidden'); document.body.style.overflow='hidden'; } }
        function close(){ if (modal){ modal.classList.add('hidden'); document.body.style.overflow=''; } }

        if (openBtn) openBtn.addEventListener('click', open);
        closes.forEach(el => el.addEventListener('click', close));
        document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });

    })();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
