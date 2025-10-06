<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analytics_track.php';

// CSRF for admin actions on this page
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];


// Ensure schema additions exist
try {
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS past TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS my_rating DECIMAL(3,2) NULL");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS my_review TEXT NULL");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS reviewed_on DATETIME NULL");
    $pdo->exec("ALTER TABLE bottles ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL");
} catch (Exception $e) {}

// Resolve bottle by bottle_id or wine_id
$user_id   = $_SESSION['user_id'];
$bottle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$wine_id   = isset($_GET['wine_id']) ? (int)$_GET['wine_id'] : 0;

if ($bottle_id <= 0 && $wine_id > 0) {
    // pick most recent bottle for this wine
    $stmt = $pdo->prepare("SELECT id FROM bottles WHERE user_id = ? AND wine_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id, $wine_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $bottle_id = (int)$row['id'];
}

if ($bottle_id <= 0) {
    header("Location: inventory.php");
    exit();
}

// Handle review/photo update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_review'])) {
        $rating = isset($_POST['my_rating']) && $_POST['my_rating'] !== '' ? (int)$_POST['my_rating'] : null;
        if ($rating !== null) $rating = max(0, min(100, $rating));
        $review = trim($_POST['my_review'] ?? '');
        $stmt = $pdo->prepare("UPDATE bottles SET my_rating = :r, my_review = :v, reviewed_on = NOW() WHERE id = :id AND user_id = :uid");
        $stmt->execute([':r'=>$rating, ':v'=>$review, ':id'=>$bottle_id, ':uid'=>$user_id]);
    } elseif (isset($_POST['upload_photo']) && isset($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $tmp = $_FILES['photo']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) $ext = 'jpg';
        @mkdir(__DIR__ . '/covers', 0775, true);
        $dest = __DIR__ . '/covers/bottle_' . $bottle_id . '.' . $ext;
        if (move_uploaded_file($tmp, $dest)) {
            $rel = 'covers/' . basename($dest);
            $stmt = $pdo->prepare("UPDATE bottles SET photo_path = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$rel, $bottle_id, $user_id]);
        }
    }
    header("Location: bottle.php?id=" . $bottle_id);
    exit();
}

// Fetch bottle + local catalog (user DB)
try {
    $stmt = $pdo->prepare("SELECT b.*, 
                              w.name AS wine_name, w.winery, w.country, w.region, w.type AS wine_type, w.style, w.grapes,
                              w.vintage AS catalog_vintage, w.rating as catalog_rating, w.upc as catalog_upc, 
                              w.food_pairings, w.image_url AS catalog_image_url, w.price as catalog_price
                       FROM bottles b
                       LEFT JOIN wines w ON b.wine_id = w.id
                       WHERE b.id = ? AND b.user_id = ?");
    $stmt->execute([$bottle_id, $user_id]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // fallback: bottle only
    $stmt = $pdo->prepare("SELECT * FROM bottles WHERE id = ? AND user_id = ?");
    $stmt->execute([$bottle_id, $user_id]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$b) {
    header("Location: inventory.php");
    exit();
}

// -------- Hydration helpers --------
function is_empty_val($v) { return !isset($v) || trim((string)$v) === ''; }
function first_nonempty(...$args) {
    foreach ($args as $a) if (!is_empty_val($a)) return $a;
    return null;
}

function hydrate_with_catalog(array $b, array $w2): array {
    $mapping = [
        'wine_name'     => ['name','wine_name'],
        'winery'        => ['winery'],
        'country'       => ['country'],
        'region'        => ['region'],
        'wine_type'     => ['type','wine_type'],
        'style'         => ['style'],
        'grapes'        => ['grapes'],
        'catalog_image_url'     => ['image_url'],
        'upc'           => ['upc'],
        'food_pairings' => ['food_pairings','food'],
    ];
    foreach ($mapping as $target => $sources) {
        if (is_empty_val($b[$target] ?? null)) {
            if ($target === 'food_pairings') {
                $fromBottleLegacy = $b['food'] ?? null;
                $fromWinelist = first_nonempty(...array_map(fn($k)=>$w2[$k] ?? null, $sources));
                $b[$target] = first_nonempty($b[$target] ?? null, $fromBottleLegacy, $fromWinelist);
            } else {
                $candidate = first_nonempty(...array_map(fn($k)=>$w2[$k] ?? null, $sources));
                if (!is_empty_val($candidate)) $b[$target] = $candidate;
            }
        }
    }
    $catalog_map = [
        'catalog_vintage' => ['vintage'],
        'catalog_rating'  => ['rating'],
        'catalog_upc'     => ['upc'],
        'catalog_price'   => ['price'],
    ];
    foreach ($catalog_map as $target => $sources) {
        if (is_empty_val($b[$target] ?? null)) {
            $candidate = first_nonempty(...array_map(fn($k)=>$w2[$k] ?? null, $sources));
            if (!is_empty_val($candidate)) $b[$target] = $candidate;
        }
    }
    return $b;
}
$catalogRow = null;

// Robust catalog row finder: by id, then by UPC, then by (name+winery+vintage)
function find_catalog_row(PDO $winelist_pdo, array $b) {
    // 0) Normalize helpers
    $norm = function($s) {
        $s = trim((string)$s);
        $s = mb_strtolower($s);
        $s = str_replace(['’','‘','“','”'], ["'","'","\"","\""], $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    };

    // 1) Try direct by id (only if clearly a central id)
    if (!empty($b['wine_id']) && ctype_digit((string)$b['wine_id'])) {
        $q = $winelist_pdo->prepare("SELECT * FROM wines WHERE id = ? LIMIT 1");
        $q->execute([(int)$b['wine_id']]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) return $row;
    }

    // 2) UPC exact
    $upc = $b['catalog_upc'] ?? $b['upc'] ?? null;
    if (!empty($upc)) {
        $q = $winelist_pdo->prepare("SELECT * FROM wines WHERE upc = ? LIMIT 1");
        $q->execute([$upc]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) return $row;
    }

    // 3) Name + winery + vintage (case-insensitive LIKE)
    $name   = $b['wine_name'] ?? $b['name'] ?? null;
    $winery = $b['winery'] ?? null;
    $vint   = $b['catalog_vintage'] ?? $b['vintage'] ?? null;

    $name   = $name ? $norm($name) : null;
    $winery = $winery ? $norm($winery) : null;
    $vint   = $vint ? (int)$vint : null;

    if ($name || $winery) {
        $sql = "SELECT * FROM wines WHERE 1=1";
        $params = [];
        if ($name)   { $sql .= " AND LOWER(name) LIKE ?";   $params[] = "%{$name}%"; }
        if ($winery) { $sql .= " AND LOWER(winery) LIKE ?"; $params[] = "%{$winery}%"; }
        if ($vint)   { $sql .= " AND vintage = ?";          $params[] = $vint; }
        $sql .= " ORDER BY rating DESC, id DESC LIMIT 1";
        $q = $winelist_pdo->prepare($sql);
        $q->execute($params);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    return null;
}


// -------- Hydrate from central winelist if needed --------
if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
    try {
        $catalogRow = find_catalog_row($winelist_pdo, $b);
        if ($catalogRow) {
            $b = hydrate_with_catalog($b, $catalogRow);
        }
    } catch (Throwable $e) {
        // ignore hydration errors; keep local values
    }
}
$catalog_wine_id = isset($catalogRow['id']) ? (int)$catalogRow['id'] : 0;


// -------- Derived display values --------
function primary_image($b) {
    // Priority: catalog image → bottle image_url → user photo → local fallbacks
    if (!empty($b['catalog_image_url'])) {
        return $b['catalog_image_url'];
    }
    if (!empty($b['image_url'])) {
        return $b['image_url'];
    }
    if (!empty($b['photo_path'])) {
        return $b['photo_path'];
    }
    if (!empty($b['upc'])) {
        $try = 'covers/' . preg_replace('/[^0-9A-Za-z_\.-]/','_', $b['upc']) . '.jpg';
        if (file_exists(__DIR__ . '/' . $try)) return $try;
    }
    if (!empty($b['wine_id'])) {
        $try = 'covers/wine_' . intval($b['wine_id']) . '.jpg';
        if (file_exists(__DIR__ . '/' . $try)) return $try;
    }
    return '';
}



$img        = primary_image($b);
$name       = first_nonempty($b['name'] ?? null, $b['wine_name'] ?? null) ?? '';
$winery     = $b['winery'] ?? '';
$country    = $b['country'] ?? '';
$region     = $b['region'] ?? '';
$type       = first_nonempty($b['type'] ?? null, $b['wine_type'] ?? null) ?? '';
$style      = $b['style'] ?? '';
$grapes     = $b['grapes'] ?? '';
$vintage    = first_nonempty($b['vintage'] ?? null, $b['catalog_vintage'] ?? null) ?? '';
$rating     = first_nonempty($b['rating'] ?? null, $b['catalog_rating'] ?? null);
$my_rating  = $b['my_rating'] ?? null;
$price      = $b['catalog_price'] ?? null;
$price_paid = $b['price_paid'] ?? null;
$upc        = first_nonempty($b['upc'] ?? null, $b['catalog_upc'] ?? null) ?? '';
$food       = $b['food_pairings'] ?? ''; // hydrated above if empty
$location   = $b['location'] ?? '';
$past       = (int)($b['past'] ?? 0);
$my_review  = $b['my_review'] ?? '';

?>
<!doctype html>
<html>
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($name ?: 'Bottle') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
</head>
<body class="bg-gray-50 text-gray-900">
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="max-w-5xl mx-auto p-6">
    <div class="flex items-center justify-between">
        <a href="inventory.php" class="text-blue-600 hover:underline">&larr; Back to Inventory</a>
        <div class="flex gap-2">
            <a href="edit_bottle.php?id=<?= (int)$bottle_id ?>"
               class="inline-block px-4 py-2 rounded-lg shadow bg-blue-600 text-white hover:bg-blue-700 transition">✏️ Edit Bottle</a>
            <!--<a href="label_upload.php?id=<?= (int)$bottle_id ?>"
               class="inline-block px-4 py-2 rounded-lg shadow bg-white border hover:bg-gray-50">🖼️ Upload Label</a>-->
            <!-- Trigger button (e.g., near other bottle actions) -->
            <button id="btnCreateShare" class="btn"> Share this Bottle</button>

            <!-- Modal -->
            <div id="shareModal" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.6);z-index:9999">
                <div style="width:min(560px,90vw);background:#13131a;color:#f6f6f7;border:1px solid #22222b;border-radius:14px;padding:18px">
                    <h3 style="margin:0 0 10px">Create a public share</h3>
                    <p style="margin:0 0 12px;color:#a1a1aa">This creates a public page for this wine with your custom title & blurb.</p>

                    <label style="display:block;margin:8px 0 6px">Title</label>
                    <input id="shareTitle" type="text" style="width:100%;padding:10px;border-radius:8px;border:1px solid #2a2a35;background:#0e0e14;color:#f6f6f7">

                    <label style="display:block;margin:12px 0 6px">Excerpt (short blurb)</label>
                    <textarea id="shareExcerpt" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #2a2a35;background:#0e0e14;color:#f6f6f7"></textarea>

                    <div style="display:flex;gap:12px;align-items:center;margin:12px 0">
                        <label><input id="shareIndexable" type="checkbox" checked> Allow search engines (indexable)</label>
                        <label>Expires: <input id="shareExpires" type="date" style="background:#0e0e14;color:#f6f6f7;border:1px solid #2a2a35;border-radius:8px;padding:6px"></label>
                    </div>

                    <div id="shareResult" style="display:none;margin:10px 0;padding:10px;border:1px dashed #2a2a35;border-radius:10px;background:#0e0e14"></div>

                    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">
                        <button id="shareCancel" class="btn btn-outline" type="button">Cancel</button>
                        <button id="shareCreate" class="btn btn-cta" type="button">Create</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
        <div>
            <?php if ($img): ?>
                <img src="<?= htmlspecialchars($img) ?>" class="max-h-[500px] w-auto mx-auto object-contain bg-gray-100 p-2 rounded" loading="lazy" alt="<?= htmlspecialchars($name) ?>">
            <?php else: ?>
                <div class="w-full h-64 bg-gray-200 rounded flex items-center justify-center text-gray-500">No Image</div>
            <?php endif; ?>
            <form class="mt-3" method="post" enctype="multipart/form-data">
                <label class="block text-sm mb-1">Upload/replace photo</label>
                <input type="file" name="photo" accept="image/*" class="border p-2 rounded w-full mb-2">
                <button name="upload_photo" class="px-3 py-2 bg-gray-800 text-white rounded">Save Photo</button>
            </form>
        </div>
        <div class="md:col-span-2">
            <h1 class="text-2xl font-semibold mb-2"><?= htmlspecialchars($name) ?></h1>
            <?php if (!empty($catalog_wine_id)): ?>
                <div class="mt-2 mb-3 flex items-center gap-2 text-sm">
                    <span class="text-gray-600">winelist id:</span>
                    <input id="central-wine-id" class="border rounded px-2 py-1 text-sm w-28"
                           value="<?= (int)$catalog_wine_id ?>" readonly>
                    <button type="button" class="px-2 py-1 rounded bg-gray-100 border hover:bg-gray-200"
                            onclick="(async()=>{try{const el=document.getElementById('central-wine-id');el.select();document.execCommand('copy');el.blur();const s=document.getElementById('central-copy-status');s.textContent='Copied';setTimeout(()=>s.textContent='',1200);}catch(e){alert('Copy failed');}})()">
                        Copy
                    </button>
                    <span id="central-copy-status" class="text-xs text-gray-500"></span>

                    <?php if (!empty($_SESSION['is_admin'])): ?>
                        <button type="button" class="ml-3 px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700"
                                onclick="(async()=>{
                                        const out=document.getElementById('central-rebuild-status');
                                        out.textContent='Triggering…';
                                        try{
                                        const res=await fetch('/api/admin_insights_action.php',{
                                        method:'POST',
                                        headers:{'Content-Type':'application/x-www-form-urlencoded'},
                                        body:new URLSearchParams({
                                        action:'insights_one',
                                        wine_id:'<?= (int)$catalog_wine_id ?>',
                                        csrf:'<?= $csrf ?>'
                                        })
                                        });
                                        const j=await res.json();
                                        out.textContent = j.ok ? ('Rebuild started (PID '+(j.pid||'?')+')') : ('Error: '+(j.error||'unknown'));
                                        }catch(e){ out.textContent='Error: '+e; }
                                        })()">
                            Rebuild AI Insights
                        </button>
                        <span id="central-rebuild-status" class="ml-2 text-xs text-gray-600"></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-white p-4 rounded shadow">
                <div><span class="font-medium">Winery:</span> <?= htmlspecialchars($winery) ?></div>
                <div><span class="font-medium">Country:</span> <?= htmlspecialchars($country) ?></div>
                <div><span class="font-medium">Region:</span> <?= htmlspecialchars($region) ?></div>
                <div><span class="font-medium">Grapes:</span> <?= htmlspecialchars($grapes) ?></div>
                <div><span class="font-medium">Vintage:</span> <?= htmlspecialchars($vintage) ?></div>
                <div><span class="font-medium">Type:</span> <?= htmlspecialchars($type) ?></div>
                <div><span class="font-medium">Style:</span> <?= htmlspecialchars($style) ?></div>
                <div><span class="font-medium">Rating:</span> <?= htmlspecialchars((string)$rating) ?></div>
                <div><span class="font-medium">My Rating:</span> <?= htmlspecialchars((string)$my_rating) ?></div>
                <div><span class="font-medium">List Price:</span> <?= htmlspecialchars((string)$price) ?></div>
                <div><span class="font-medium">Price I Paid:</span> <?= htmlspecialchars((string)$price_paid) ?></div>
                <div><span class="font-medium">UPC:</span> <?= htmlspecialchars($upc) ?></div>
                <div class="md:col-span-2"><span class="font-medium">Food Pairings:</span> <?= htmlspecialchars($food) ?></div>
                <div><span class="font-medium">Location:</span> <?= htmlspecialchars($location) ?></div>
                <div><span class="font-medium">Status:</span> <?= $past ? 'Past' : 'Current' ?></div>
            </div>
            <?php
            // --- Price Trajectory Mini-Widget (robust id resolution; prefer central id) ---
            // Price widget id resolution — use ONLY central id
            $__wid = !empty($catalog_wine_id) ? (int)$catalog_wine_id : 0;
            // If $__wid == 0, render a small “Link to catalog” helper instead of loading the widget.

            ?>


            <?php if ($__wid > 0): ?>
                <section class="mt-6 mb-8" id="price-trajectory" data-wine-id="<?= (int)$__wid ?>">
                    <div class="bg-white border border-gray-200 rounded-2xl p-4">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <h3 style="font-weight:600;font-size:16px;margin:0;">Price trajectory</h3>
                            <small id="pt-asof" style="color:#6b7280;"></small>
                        </div>

                        <div id="pt-row" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;"></div>

                        <div style="margin-top:10px;font-size:14px;">
                            <div><strong>Now:</strong> <span id="pt-now">—</span></div>
                            <div id="pt-ci" style="color:#6b7280;font-size:12px;margin-top:6px;display:none;"></div>
                            <div id="pt-expl" style="color:#374151;font-size:13px;margin-top:8px;"></div>
                        </div>
                    </div>
                </section>
                <script>
                    (function(){
                        const root = document.getElementById('price-trajectory');
                        if (!root) return;
                        const wineId = parseInt(root.getAttribute('data-wine-id') || '0', 10);
                        if (!wineId) return;

                        const row = document.getElementById('pt-row');
                        const nowEl = document.getElementById('pt-now');
                        const ciEl  = document.getElementById('pt-ci');
                        const expl  = document.getElementById('pt-expl');
                        const asof  = document.getElementById('pt-asof');

                        const chip = (label, pct, active) => {
                            const el = document.createElement('button');
                            el.type = 'button';
                            el.style.padding = '6px 10px';
                            el.style.borderRadius = '9999px';
                            el.style.border = '1px solid ' + (active ? '#4f46e5' : '#e5e7eb');
                            el.style.background = active ? '#4f46e5' : '#fff';
                            el.style.color = active ? '#fff' : '#111827';
                            el.style.fontSize = '12px';
                            el.style.display = 'inline-flex';
                            el.style.alignItems = 'center';
                            el.style.gap = '6px';
                            const arrow = (pct!=null) ? (pct>0 ? '▲' : (pct<0 ? '▼' : '—')) : '—';
                            const pctTxt = (pct!=null) ? ((pct>0?'+':'') + pct.toFixed(1) + '%') : '—';
                            el.innerHTML = `<span>${label}</span><span style="opacity:0.9">${arrow} ${pctTxt}</span>`;
                            return el;
                        };

                        let state = { wine: null, forecasts: [], active: 0 };

                        function render() {
                            const w = state.wine || {};
                            const f = state.forecasts || [];

                            nowEl.textContent = (typeof w.price === 'number' && !isNaN(w.price)) ? `$${w.price.toFixed(2)}` : '—';

                            row.innerHTML = '';
                            f.forEach((it, idx) => {
                                const c = chip(it.horizon, (typeof it.pct==='number'? it.pct : null), idx===state.active);
                                c.addEventListener('click', () => { state.active = idx; render(); });
                                row.appendChild(c);
                            });

                            const cur = f[state.active];
                            if (cur) {
                                asof.textContent = cur.asof_date ? `as of ${cur.asof_date}` : '';
                                if (typeof cur.lower_ci === 'number' && typeof cur.upper_ci === 'number') {
                                    ciEl.style.display = 'block';
                                    ciEl.textContent = `Confidence band: $${cur.lower_ci.toFixed(2)} – $${cur.upper_ci.toFixed(2)}`
                                        + (cur.confidence!=null ? ` (conf ${Math.round(cur.confidence*100)}%)` : '');
                                } else if (cur.confidence!=null) {
                                    ciEl.style.display = 'block';
                                    ciEl.textContent = `Confidence: ${Math.round(cur.confidence*100)}%`;
                                } else {
                                    ciEl.style.display = 'none';
                                }
                                expl.textContent = cur.explanation || '';
                            } else {
                                asof.textContent = '';
                                ciEl.style.display = 'none';
                                expl.textContent = '';
                            }
                        }

                        fetch('/api/price_forecast.php?wine_id=' + encodeURIComponent(wineId), { credentials: 'include' })
                            .then(r => r.json())
                            .then(data => {
                                if (!data || data.error) return;
                                state.wine = data.wine || null;
                                state.forecasts = Array.isArray(data.forecasts) ? data.forecasts : [];
                                render();
                            })
                            .catch(console.error);
                    })();
                </script>
            <?php endif; ?>

            <!-- /Price Trajectory Mini-Widget -->


            <form method="post" class="mt-6 bg-white p-4 rounded shadow">
                <h2 class="text-lg font-medium mb-3">Your Review</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="md:col-span-1">
                        <label class="block text-sm mb-1">My Rating (0–100)</label>
                        <input type="number" min="0" max="5" step="0.01" inputmode="decimal" name="my_rating" value="<?= htmlspecialchars((string)$my_rating) ?>" class="border p-2 rounded w-full"placeholder="e.g. 4.1">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-sm mb-1">My Notes</label>
                        <textarea name="my_review" rows="4" class="border p-2 rounded w-full"><?= htmlspecialchars($my_review) ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <button name="save_review" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// ==== AI Insights Widget id resolution (prefer central id) ====
// ==== AI Insights Widget id resolution (prefer central id) ====
$__WINE_ID = 0;
if (!empty($catalog_wine_id)) {
    $__WINE_ID = (int)$catalog_wine_id;
} elseif (!empty($b['wine_id'])) {
    $__WINE_ID = (int)$b['wine_id'];
} elseif (!empty($_GET['wine_id'])) {
    $__WINE_ID = (int)$_GET['wine_id'];
}

?>
<div id="ai-insights" data-wine-id="<?= $__WINE_ID > 0 ? (int)$__WINE_ID : '' ?>" class="max-w-3xl mx-auto my-6"></div>
<?php if (empty($catalog_wine_id) && !empty($_SESSION['is_admin'])): ?>
    <div class="mt-4 p-3 border rounded bg-amber-50 text-amber-900">
        <div class="text-sm font-medium mb-2">No central winelist link found</div>
        <form method="post" action="/api/admin_insights_action.php" onsubmit="return confirm('Link this bottle to the given central winelist id?');">
            <input type="hidden" name="action" value="link_bottle_to_central">
            <input type="hidden" name="bottle_id" value="<?= (int)$bottle_id ?>">
            <label class="text-sm mr-2">Central wine id:</label>
            <input name="central_wine_id" class="border rounded px-2 py-1 text-sm w-28" placeholder="e.g. 12345">
            <button class="ml-2 px-3 py-1 rounded bg-blue-600 text-white">Link</button>
        </form>
        <div class="text-xs mt-2 opacity-80">Tip: paste the id from a winelist match, or add a UPC to this bottle and refresh.</div>
    </div>
<?php endif; ?>

<script>
    (function(){
        const mount = document.getElementById('ai-insights');
        if (!mount) return;
        const wineId = parseInt(mount.dataset.wineId || '0', 10);
        if (!wineId) { mount.innerHTML = ''; return; }

        const spinner = `
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-lg font-semibold">AI Insights</h3>
        <div class="text-xs text-gray-500">refreshing…</div>
      </div>
      <div class="text-sm text-gray-500">Rebuilding insights, this can take a moment.</div>
    </div>`;

        function mdList(md) {
            if (!md) return '';
            const items = md.split(/\n/).map(s => s.trim()).filter(Boolean).map(s=>s.replace(/^-+\s?/, ''));
            return '<ul class="list-disc pl-6 space-y-1">' + items.map(i=>'<li>'+i.replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</li>').join('') + '</ul>';
        }

        function renderData(data){
            if (!data || !data.insights) { mount.innerHTML=''; return; }
            const ins = data.insights;
            const notes = mdList(ins.notes_md);
            const pairings = Array.isArray(ins.pairings) ? ins.pairings : [];
            const df = ins.drink_from || null;
            const dt = ins.drink_to || null;
            const inv = (ins.investability_score != null) ? Number(ins.investability_score) : null;

            const badge = (()=>{
                const today = new Date().toISOString().slice(0,10);
                if (dt && today > dt) return '<span class="ml-2 px-2 py-0.5 text-xs rounded bg-red-100 text-red-700">Past peak</span>';
                if (df && today >= df) return '<span class="ml-2 px-2 py-0.5 text-xs rounded bg-emerald-100 text-emerald-700">Drink now</span>';
                if (df) {
                    const d1 = new Date(df), d0 = new Date();
                    const diff = Math.round((d1 - d0)/(1000*60*60*24));
                    if (diff <= 180 && diff >= 0) return '<span class="ml-2 px-2 py-0.5 text-xs rounded bg-amber-100 text-amber-700">Nearing peak</span>';
                }
                return '';
            })();

            const pairingGroups = pairings.map(g => {
                if (Array.isArray(g)) return g.join(', ');
                if (typeof g === 'object' && g !== null) {
                    const title = g.title || '';
                    const items = Array.isArray(g.items)? g.items.join(', ') : '';
                    return (title? (title+': '):'') + items;
                }
                return String(g);
            }).filter(Boolean);

            mount.innerHTML = `
      <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-lg font-semibold">AI Insights ${badge}</h3>
          ${inv!=null ? `<div class="text-sm text-gray-600">Investability: <span class="font-medium">${inv}/100</span></div>` : ''}
        </div>
        ${notes ? `<div class="mb-4">${notes}</div>` : ''}
        ${pairingGroups.length ? `<div class="mb-2"><div class="text-sm text-gray-600 mb-1">Food pairings</div><div class="text-sm">${pairingGroups.map(p=>`<span class="inline-block mr-2 mb-1 px-2 py-1 bg-gray-100 rounded">${p.replace(/</g,'&lt;')}</span>`).join('')}</div></div>`: ''}
        <div class="text-xs text-gray-500">
          ${df?`Drink from: <strong>${df}</strong>`:''}
          ${df && dt ? ' • ' : ''}
          ${dt?`Drink to: <strong>${dt}</strong>`:''}
        </div>
        <?php if (!empty($_SESSION['is_admin'])): ?>
        <div class="mt-3">
          <button type="button" class="text-xs text-blue-600 hover:underline"
                  onclick="window.refreshInsightsFor && window.refreshInsightsFor(${wineId})">Refresh</button>
        </div>
        <?php endif; ?>
      </div>`;
        }

        async function fetchOnce(id) {
            const res = await fetch('/api/ai_insights.php?wine_id=' + encodeURIComponent(id) + '&t=' + Date.now(),
                { credentials:'include', cache:'no-store' });
            const raw = await res.text();
            try { return JSON.parse(raw); } catch { return null; }
        }

        async function pollUntilReady(id, opts={}) {
            const { intervalMs=3000, maxTries=20 } = opts;
            mount.innerHTML = spinner;

            for (let i=0; i<maxTries; i++) {
                const data = await fetchOnce(id);
                // Consider it "ready" if we have an insights object with any meaningful field
                if (data && data.insights && (
                    data.insights.notes_md ||
                    (Array.isArray(data.insights.pairings) && data.insights.pairings.length) ||
                    data.insights.investability_score != null ||
                    data.insights.drink_from || data.insights.drink_to
                )) {
                    renderData(data);
                    return true;
                }
                await new Promise(r => setTimeout(r, intervalMs));
            }
            // Timeout – show whatever we got (likely empty) so UI doesn't look broken
            const fallback = await fetchOnce(id);
            renderData(fallback || { insights:null });
            return false;
        }

        // Expose a function so the button can trigger a refresh
        window.refreshInsightsFor = function(id) {
            pollUntilReady(id, { intervalMs: 3000, maxTries: 20 });
        };

        // Initial load (no spinner unless empty)
        (async () => {
            const data = await fetchOnce(wineId);
            if (data && data.insights) {
                renderData(data);
            } else {
                // nothing cached → start polling to catch the rebuild
                pollUntilReady(wineId);
            }
            const nothing = !ins.notes_md &&
                !(Array.isArray(ins.pairings) && ins.pairings.length) &&
                ins.investability_score == null &&
                !ins.drink_from && !ins.drink_to;

            if (nothing) {
                mount.innerHTML = `
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 text-sm text-gray-500">
      No insights yet. Use “Rebuild AI Insights” above.
    </div>`;
                return;
            }

        })()
    })();
</script>
<script>
    (function(){
        const wineId = <?= (int)$catalog_wine_id ?>; // ensure this variable exists on your bottle page
        const modal = document.getElementById('shareModal');
        const btnOpen = document.getElementById('btnCreateShare');
        const btnCancel = document.getElementById('shareCancel');
        const btnCreate = document.getElementById('shareCreate');
        const title = document.getElementById('shareTitle');
        const excerpt = document.getElementById('shareExcerpt');
        const indexable = document.getElementById('shareIndexable');
        const expires = document.getElementById('shareExpires');
        const result = document.getElementById('shareResult');

        btnOpen.addEventListener('click', ()=>{ modal.style.display='flex'; title.focus(); });
        btnCancel.addEventListener('click', ()=>{ modal.style.display='none'; result.style.display='none'; });

        btnCreate.addEventListener('click', async ()=>{
            btnCreate.disabled = true;
            result.style.display = 'none';
            try {
                const fd = new FormData();
                fd.append('wine_id', wineId);
                fd.append('title', title.value.trim());
                fd.append('excerpt', excerpt.value.trim());
                fd.append('is_indexable', indexable.checked ? '1' : '0');
                fd.append('expires_at', expires.value);

                const resp = await fetch('/api/create_share.php', { method:'POST', body:fd, credentials:'same-origin' });
                const data = await resp.json();

                if (!resp.ok || !data.ok) throw new Error(data.error || ('HTTP '+resp.status));

                const url = data.share_url;
                result.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <strong>Share URL:</strong>
          <input id="shareUrlOut" type="text" value="${url}" readonly style="flex:1;padding:8px;border-radius:8px;border:1px solid #2a2a35;background:#0e0e14;color:#f6f6f7">
          <button id="shareCopy" class="btn btn-outline" type="button">Copy</button>
          <a class="btn btn-cta" href="${url}" target="_blank" rel="noopener">View</a>
        </div>
        <div style="font-size:12px;color:#a1a1aa">An OG image was generated: <code>${data.og_image}</code></div>
      `;
                result.style.display = 'block';

                const copyBtn = document.getElementById('shareCopy');
                copyBtn.addEventListener('click', async ()=>{
                    const inp = document.getElementById('shareUrlOut');
                    inp.select(); inp.setSelectionRange(0, 99999);
                    try { await navigator.clipboard.writeText(inp.value); copyBtn.textContent='Copied'; }
                    catch(e){ copyBtn.textContent='Copy failed'; }
                    setTimeout(()=> copyBtn.textContent='Copy', 1500);
                });
            } catch (e) {
                result.style.display='block';
                result.innerHTML = `<div style="color:#ffb4b4">Error: ${e.message}</div>`;
            } finally {
                btnCreate.disabled = false;
            }
        });

        // Close modal by clicking backdrop
        modal.addEventListener('click', (e)=>{ if (e.target === modal) { modal.style.display='none'; result.style.display='none'; }});
    })();
</script>

</body>
<?php require __DIR__ . '/partials/footer.php'; ?>

</html>
