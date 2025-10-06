<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // keep your bootstrap include
require __DIR__.'/analytics_track.php'; // <-- add this

$userId = $_SESSION['user_id'] ?? null;     // normalize to camelCase and reuse everywhere
error_log("home sees uid=" . ($userId ?? ''));

// Read UI filters (default to Best in Show; All types)
$ui_list = isset($_GET['list']) ? strtolower(trim($_GET['list'])) : 'best';
$ui_type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';

// Normalize list names to what we store
// We'll use list_name LIKE 'Best in Show' or 'Platinum'
$list_label = ($ui_list === 'platinum') ? 'Platinum' : 'Best in Show';

$expert = [];
try {
    if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        // Prefer expert_picks if available
        $has_expert = $winelist_pdo->query("SHOW TABLES LIKE 'expert_picks'")->fetchColumn();

        if ($has_expert) {
            // Build WHERE with optional type filter
            $where = "ep.list_name = :list";
            $params = [':list' => $list_label];

            if ($ui_type) {
                $where .= " AND LOWER(COALESCE(w.type, ep.type)) = :t";
                $params[':t'] = $ui_type;
            }

            $sql = "
        SELECT 
          ep.id AS expert_id, ep.source, ep.year, ep.list_name, ep.medal, ep.score,
          COALESCE(w.id, ep.wine_id) AS wine_id,
          COALESCE(w.name, ep.name) AS name,
          COALESCE(w.winery, ep.winery) AS winery,
          COALESCE(w.region, ep.region) AS region,
          LOWER(COALESCE(w.type, ep.type)) AS type,
          COALESCE(w.vintage, ep.vintage) AS vintage
        FROM expert_picks ep
        LEFT JOIN wines w ON w.id = ep.wine_id
        WHERE $where
        ORDER BY (ep.score IS NULL), ep.score DESC, ep.year DESC, ep.added_on DESC
        LIMIT 200
      ";
            $st = $winelist_pdo->prepare($sql);
            $st->execute($params);
            $expert = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // If no expert_picks, fall back to wines with medal/score hints when possible
        if (!$expert) {
            // Try to approximate by score; ignore list_name in this fallback
            $where2 = "name IS NOT NULL";
            $params2 = [];
            if ($ui_type) { $where2 .= " AND LOWER(type) = :t"; $params2[':t'] = $ui_type; }

            $sqlTop = "
        SELECT id AS wine_id, name, winery, region, LOWER(type) AS type, vintage,
               COALESCE(critic_score, avg_rating) AS score
        FROM wines
        WHERE $where2
        ORDER BY (COALESCE(critic_score, avg_rating) IS NULL),
                 COALESCE(critic_score, avg_rating) DESC,
                 vintage DESC
        LIMIT 200
      ";
            $st = $winelist_pdo->prepare($sqlTop);
            $st->execute($params2);
            $expert = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {}

// Final fallback if nothing available
if (!$expert) {
    $expert = [
        ['wine_id'=>0,'name'=>'Super Tuscan','winery'=>'—','region'=>'Tuscany','type'=>'red','vintage'=>'NV','score'=>95],
        ['wine_id'=>0,'name'=>'Vintage Champagne','winery'=>'—','region'=>'Champagne','type'=>'sparkling','vintage'=>'2012','score'=>94],
        ['wine_id'=>0,'name'=>'Grand Cru Chablis','winery'=>'—','region'=>'Burgundy','type'=>'white','vintage'=>'2020','score'=>93],
        ['wine_id'=>0,'name'=>'PX Sherry','winery'=>'—','region'=>'Jerez','type'=>'dessert','vintage'=>'NV','score'=>93],
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <meta charset="UTF-8" />
    <title>WineCellar Home</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="max-w-6xl mx-auto p-6">
    <header class="mb-8">
        <h1 class="text-3xl font-bold">🍷 Welcome back</h1>
        <p class="text-gray-600">Quick actions, stats, and new tools to explore your cellar.</p>
    </header>
    <!-- Stats -->
    <?php

    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

    /** ---------- TOTAL BOTTLES ---------- **/
    $st = $pdo->prepare("SELECT COUNT(*) FROM bottles WHERE user_id = :uid");
    $st->execute([':uid'=>$userId]);
    $totalBottles = (int)$st->fetchColumn();

    /** ---------- LOAD USER BOTTLES (wine_id, grapes, vintage, name) ---------- **/
    $st = $pdo->prepare("
  SELECT id, wine_id, grapes, vintage, name
  FROM bottles
  WHERE user_id = :uid
");
    $st->execute([':uid'=>$userId]);
    $bottles = $st->fetchAll(PDO::FETCH_ASSOC);

    $wineIds = [];
    foreach ($bottles as $b) {
        if (!empty($b['wine_id'])) $wineIds[(int)$b['wine_id']] = true;
    }
    $wineIdsList = array_keys($wineIds);

    /** ---------- UNIQUE GRAPES (from bottles.grapes + fallback to catalog.wines.grapes) ---------- **/
    $grapeSet = [];

    // from bottles
    foreach ($bottles as $b) {
        $g = trim((string)($b['grapes'] ?? ''));
        if ($g !== '') {
            foreach (preg_split('/\s*,\s*/', $g) as $tok) {
                if ($tok !== '') $grapeSet[mb_strtolower($tok)] = true;
            }
        }
    }

    // fill gaps from catalog by wine_id
    if (!empty($wineIdsList)) {
        $in = implode(',', array_fill(0, count($wineIdsList), '?'));
        $q = $winelist_pdo->prepare("SELECT wine_id, grapes FROM wines WHERE wine_id IN ($in)");
        $q->execute($wineIdsList);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $g = trim((string)($row['grapes'] ?? ''));
            if ($g !== '') {
                foreach (preg_split('/\s*,\s*/', $g) as $tok) {
                    if ($tok !== '') $grapeSet[mb_strtolower($tok)] = true;
                }
            }
        }
    }
    $uniqueVarietals = count($grapeSet); // label as “Unique Varietals” per your UI copy

    /** ---------- UNIQUE countries ---------- **/
    $countrySet = [];

    // from bottles
    foreach ($bottles as $b) {
        $g = trim((string)($b['country'] ?? ''));
        if ($g !== '') {
            foreach (preg_split('/\s*,\s*/', $g) as $tok) {
                if ($tok !== '') $countrySet[mb_strtolower($tok)] = true;
            }
        }
    }

    // fill gaps from catalog by wine_id
    if (!empty($wineIdsList)) {
        $in = implode(',', array_fill(0, count($wineIdsList), '?'));
        $q = $winelist_pdo->prepare("SELECT wine_id, country FROM wines WHERE wine_id IN ($in)");
        $q->execute($wineIdsList);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $g = trim((string)($row['country'] ?? ''));
            if ($g !== '') {
                foreach (preg_split('/\s*,\s*/', $g) as $tok) {
                    if ($tok !== '') $countrySet[mb_strtolower($tok)] = true;
                }
            }
        }
    }
    $uniqueCountries = count($countrySet);


    /** ---------- OLDEST VINTAGE (prefer bottle; fallback to catalog by wine_id) ---------- **/
    $st = $pdo->prepare("SELECT MIN(vintage) FROM bottles WHERE user_id = :uid AND vintage IS NOT NULL AND vintage > 0");
    $st->execute([':uid'=>$userId]);
    $oldestVintage = $st->fetchColumn();
    $oldestVintage = $oldestVintage ? (int)$oldestVintage : null;

    if ($oldestVintage === null && !empty($wineIdsList)) {
        $in = implode(',', array_fill(0, count($wineIdsList), '?'));
        $q = $winelist_pdo->prepare("SELECT MIN(vintage) AS min_v FROM wines WHERE wine_id IN ($in) AND vintage IS NOT NULL AND vintage > 0");
        $q->execute($wineIdsList);
        $v = $q->fetchColumn();
        if ($v) $oldestVintage = (int)$v;
    }

    /** ---------- DRINK-BY SOON (from wines_ai by wine_id, nearest first) ---------- **/
    $upcoming = [];
    if (!empty($wineIdsList)) {
        // Get up to 5 nearest windows for the user’s wine_ids
        $in = implode(',', array_fill(0, count($wineIdsList), '?'));
        // NOTE: if wines_ai is in the catalog DB, switch to $winelist_pdo here.
        $ai = $winelist_pdo->prepare("
    SELECT wine_id, drink_from, drink_to
    FROM wines_ai
    WHERE wine_id IN ($in) AND drink_to IS NOT NULL
    ORDER BY drink_to ASC
    LIMIT 5
  ");
        $ai->execute($wineIdsList);
        $aiRows = $ai->fetchAll(PDO::FETCH_ASSOC);

        // Index bottles by wine_id to pick a representative bottle (for name/vintage and link)
        $byWine = [];
        foreach ($bottles as $b) {
            $wid = (int)$b['wine_id'];
            if ($wid) {
                // keep first bottle encountered for this wine_id
                if (!isset($byWine[$wid])) $byWine[$wid] = $b;
            }
        }

        // Fetch catalog names/vintages for enrichment (only for wine_ids we’ll render)
        $renderWineIds = array_map('intval', array_column($aiRows, 'wine_id'));
        $catalogMeta = [];
        if (!empty($renderWineIds)) {
            $in2 = implode(',', array_fill(0, count($renderWineIds), '?'));
            $c = $winelist_pdo->prepare("SELECT wine_id, name, vintage FROM wines WHERE wine_id IN ($in2)");
            $c->execute($renderWineIds);
            foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $catalogMeta[(int)$row['wine_id']] = [
                    'name' => $row['name'] ?? '',
                    'vintage' => !empty($row['vintage']) ? (int)$row['vintage'] : null,
                ];
            }
        }

        foreach ($aiRows as $r) {
            $wid = (int)$r['wine_id'];
            $bottle   = $byWine[$wid] ?? null;
            $bottleId = $bottle ? (int)$bottle['id'] : null;
            $name     = $bottle && !empty($bottle['name']) ? (string)$bottle['name'] : '';
            $vintage  = $bottle && !empty($bottle['vintage']) ? (int)$bottle['vintage'] : null;

            // enrich with catalog if missing
            if ($name === '' || !$vintage) {
                if (isset($catalogMeta[$wid])) {
                    if ($name === '' && !empty($catalogMeta[$wid]['name'])) $name = $catalogMeta[$wid]['name'];
                    if (!$vintage && !empty($catalogMeta[$wid]['vintage'])) $vintage = (int)$catalogMeta[$wid]['vintage'];
                }
            }

            $upcoming[] = [
                'wine_id'    => $wid,
                'bottle_id'  => $bottleId,
                'name'       => $name !== '' ? $name : 'Untitled Wine',
                'vintage'    => $vintage ?: null,
                'drink_from' => $r['drink_from'],
                'drink_to'   => $r['drink_to'],
            ];
        }
    }
    ?>
    <?php
    function drink_window_status(?string $from, ?string $to): array {
        // Returns ['label', 'class', 'hint']
        try {
            $now = new DateTimeImmutable('now');
            $toDt   = $to ? new DateTimeImmutable($to) : null;
            $fromDt = $from ? new DateTimeImmutable($from) : null;

            if ($toDt && $now > $toDt) {
                $days = (int)$toDt->diff($now)->format('%a');
                return ['Past', 'bg-red-100 text-red-700', $days === 0 ? 'today' : "$days day".($days!==1?'s':'')." ago"];
            }
            if ($fromDt && $now < $fromDt) {
                $days = (int)$now->diff($fromDt)->format('%a');
                return ['Approaching', 'bg-amber-100 text-amber-700', $days === 0 ? 'today' : "in $days day".($days!==1?'s':'')];
            }
            // In window if we have either: now >= from (or no from) AND (no to OR now <= to)
            $inWindow = (!$fromDt || $now >= $fromDt) && (!$toDt || $now <= $toDt);
            if ($inWindow) {
                // optional: show days left until 'to'
                $hint = $toDt ? ("until ".((int)$now->diff($toDt)->format('%a'))." day".(((int)$now->diff($toDt)->format('%a'))!==1?'s':'')) : '';
                return ['Drink now', 'bg-green-100 text-green-700', $hint];
            }
            // Fallback if dates are odd/missing
            return ['—', 'bg-gray-100 text-gray-600', ''];
        } catch (Throwable $e) {
            return ['—', 'bg-gray-100 text-gray-600', ''];
        }
    }
    ?>
    <section class="mt-6 mb-10">
        <h2 class="text-2xl font-semibold mb-4">App stats</h2>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 items-stretch">
            <!-- Total Bottles -->
            <div class="rounded-2xl shadow p-4 md:p-6 bg-white">
                <div class="text-sm text-gray-500">Total Bottles</div>
                <div class="text-2xl font-semibold mt-1"><?= number_format($totalBottles) ?></div>
            </div>

            <!-- Unique Varietals (from grapes) -->
            <div class="rounded-2xl shadow p-4 md:p-6 bg-white">
                <div class="text-sm text-gray-500">Unique Varietals</div>
                <div class="text-2xl font-semibold mt-1"><?= number_format($uniqueVarietals) ?></div>
            </div>

            <!-- Oldest Vintage -->
            <div class="rounded-2xl shadow p-4 md:p-6 bg-white">
                <div class="text-sm text-gray-500">Oldest Vintage</div>
                <div class="text-2xl font-semibold mt-1"><?= $oldestVintage ? h($oldestVintage) : '—' ?></div>
            </div>

            <!-- Unique Countries -->
            <div class="rounded-2xl shadow p-4 md:p-6 bg-white">
                <div class="text-sm text-gray-500">Unique Countries</div>
                <div class="text-2xl font-semibold mt-1"><?= number_format($uniqueCountries) ?></div>
            </div>
        </div>
    </section>

    <!-- === AI Natural-Language Wine Search (inline widget) === -->
    <section class="mt-6 mb-10 bg-white rounded-2xl shadow p-5">
        <h2 class="text-xl font-semibold mb-2">🔎 Search your cellar & catalog (natural language)</h2>
        <p class="text-gray-600 text-sm mb-4">
            Try: <em>peppery syrah under $30 to drink this fall</em> · <em>earthy pinot around $40</em> · <em>aged Bordeaux to lay down</em>
        </p>

        <form id="nlq-form" class="flex gap-2 mb-3" onsubmit="return false;">
            <input
                    id="nlq-input"
                    type="text"
                    class="flex-1 border rounded-lg px-3 py-2 focus:outline-none focus:ring focus:border-indigo-400"
                    placeholder="Describe what you want… (e.g., ‘minerally chablis under $35 for seafood’)"
                    autocomplete="off"
            />
            <button id="nlq-button" class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                Search
            </button>
        </form>

        <div id="nlq-status" class="text-sm text-gray-500 mb-2" style="display:none;"></div>
        <div id="nlq-results" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"></div>

        <!-- Result card template -->
        <template id="nlq-card-tpl">
            <a class="block rounded-xl border border-gray-200 hover:shadow-md transition overflow-hidden bg-white">
                <div class="flex">
                    <img class="w-20 h-28 object-cover bg-gray-100" />
                    <div class="p-3 flex-1">
                        <div class="font-medium leading-tight line-clamp-2 name"></div>
                        <div class="text-xs text-gray-500 mt-0.5 meta"></div>
                        <div class="text-sm mt-1 price"></div>
                        <div class="text-xs text-gray-500 mt-1 reason"></div>
                    </div>
                </div>
            </a>
        </template>
    </section>

    <script>
        (function(){
            const input = document.getElementById('nlq-input');
            const btn = document.getElementById('nlq-button');
            const results = document.getElementById('nlq-results');
            const statusEl = document.getElementById('nlq-status');
            const tpl = document.getElementById('nlq-card-tpl');

            let debounceTimer = null;

            function setStatus(msg) {
                if (!msg) { statusEl.style.display = 'none'; statusEl.textContent = ''; return; }
                statusEl.style.display = 'block';
                statusEl.textContent = msg;
            }

            function render(items){
                results.innerHTML = '';
                if (!items || !items.length) {
                    results.innerHTML = '<div class="text-sm text-gray-500">No matches yet. Try adjusting your wording or budget.</div>';
                    return;
                }
                items.slice(0, 12).forEach(w => {
                    const node = tpl.content.cloneNode(true);
                    const a = node.querySelector('a');
                    const img = node.querySelector('img');
                    const name = node.querySelector('.name');
                    const meta = node.querySelector('.meta');
                    const price = node.querySelector('.price');
                    const reason = node.querySelector('.reason');

                    a.href = '/bottle.php?id=' + encodeURIComponent(w.id); // adjust if your detail route differs
                    img.src = w.image_url || '/img/placeholder.png';
                    img.alt = w.name || 'Wine';

                    const vintage = w.vintage ? ` (${w.vintage})` : '';
                    name.textContent = (w.name || 'Untitled') + vintage;

                    const bits = [];
                    if (w.region) bits.push(w.region);
                    if (w.grapes) bits.push(w.grapes);
                    meta.textContent = bits.join(' • ');

                    price.textContent = (typeof w.price === 'number')
                        ? `$${w.price.toFixed(2)}`
                        : (w.price || '');

                    if (w.reason) reason.textContent = w.reason;

                    results.appendChild(node);
                });
            }

            async function searchNow(q){
                if (!q || !q.trim()) { setStatus(''); render([]); return; }
                setStatus('Searching…');
                try {
                    const res = await fetch('/api/search_nlq.php?q=' + encodeURIComponent(q), { credentials: 'include' });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    render(data.results || []);
                    setStatus('');
                } catch (e) {
                    console.error(e);
                    setStatus('Something went wrong searching. Please try again.');
                }
            }

            // Button click
            btn.addEventListener('click', () => searchNow(input.value));

            // Enter to search
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); searchNow(input.value); }
            });

            // Debounce live typing (optional, 500ms)
            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => searchNow(input.value), 500);
            });

            // Prefill a fun example on first load (optional)
            // input.value = 'peppery syrah under $30 to drink this fall';
            // searchNow(input.value);
        })();
    </script>
    <!-- === /AI Natural-Language Wine Search === -->

    <!-- Quick Actions -->
    <section class="grid md:grid-cols-3 gap-6">
        <a href="add_bottle.php" class="block bg-white rounded-2xl shadow p-6 hover:shadow-md transition">
            <h2 class="text-xl font-semibold">➕ Add a Bottle</h2>
            <p class="text-gray-600 mt-2">Scan or add manually.</p>
        </a>

        <button id="btnPickBottle" class="bg-white rounded-2xl shadow p-6 text-left hover:shadow-md transition w-full">
            <h2 class="text-xl font-semibold">🎲 Pick a bottle for me</h2>
            <p class="text-gray-600 mt-2">Let chance choose tonight’s bottle.</p>
        </button>

        <a href="pairing.php" class="block bg-white rounded-2xl shadow p-6 hover:shadow-md transition">
            <h2 class="text-xl font-semibold">🍽️ Pairing</h2>
            <p class="text-gray-600 mt-2">Find wines in your cellar for tonight’s menu.</p>
        </a>
    </section>

    <section class="grid md:grid-cols-2 gap-6 mt-8 items-stretch">
        <!-- Upcoming Drink-By -->
        <div class="rounded-2xl shadow p-4 md:p-6 bg-white h-full flex flex-col min-h-[28rem]">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-2xl font-semibold">Drink by soon</h3>
                <span class="text-xs text-gray-500">Soonest first</span>
            </div>
            <?php if (empty($upcoming)): ?>
                <div class="text-sm text-gray-500">No bottles with a “drink by” date yet.</div>
            <?php else: ?>
                <ul class="divide-y grow overflow-y-auto">
                    <?php foreach ($upcoming as $u):
                        [$lbl, $cls, $hint] = drink_window_status($u['drink_from'] ?? null, $u['drink_to'] ?? null);
                        ?>
                        <li class="py-3 flex items-center justify-between">
                            <div class="min-w-0">
                                <div class="font-medium truncate">
                                    <?= h($u['name']) ?><?= $u['vintage'] ? ' ('.h($u['vintage']).')' : '' ?>
                                </div>
                                <div class="text-xs text-gray-500 mt-0.5">
                                    <?php if (!empty($u['drink_from'])): ?>
                                        Window: <?= h(date('M j, Y', strtotime($u['drink_from']))) ?> – <?= h(date('M j, Y', strtotime($u['drink_to']))) ?>
                                    <?php else: ?>
                                        Drink by: <?= h(date('M j, Y', strtotime($u['drink_to']))) ?>
                                    <?php endif; ?>
                                    <?php if ($hint): ?>
                                        <span class="ml-2 text-gray-400">(<?= h($hint) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 shrink-0 ml-4">
                              <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium <?= $cls ?>">
                                <?= h($lbl) ?>
                              </span>
                                <?php if (!empty($u['bottle_id'])): ?>
                                    <a href="/bottle.php?id=<?= (int)$u['bottle_id'] ?>"
                                       class="text-sm font-medium text-blue-600 hover:underline">View</a>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">No bottle link</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>

                </ul>
            <?php endif; ?>
        </div>
        <!-- Expert Top List -->
        <div class="bg-white p-6 rounded-2xl shadow h-full flex flex-col min-h-[28rem]">
            <div class="flex items-center justify-between gap-3 mb-2">
                <h2 class="text-2xl font-semibold">🏅 Wine Expert Top List</h2>
                <!-- List toggle -->
                <div class="flex items-center gap-2">
                    <?php
                    // Build base URL without list/type params
                    $base = strtok($_SERVER['REQUEST_URI'], '?');
                    $curType = $ui_type ? '&type='.$ui_type : '';
                    ?>
                    <a href="<?= htmlspecialchars($base.'?list=best'.$curType) ?>"
                       class="text-sm px-3 py-1 rounded-lg border <?= $ui_list!=='platinum'?'bg-gray-100':'' ?>">Best in Show</a>
                    <a href="<?= htmlspecialchars($base.'?list=platinum'.$curType) ?>"
                       class="text-sm px-3 py-1 rounded-lg border <?= $ui_list==='platinum'?'bg-gray-100':'' ?>">Platinum</a>
                    <div class="text-right mt-4">
                        <a href="expert_lists.php" class="text-blue-600 hover:underline">More →</a>
                    </div>
                </div>
            </div>

            <!-- Filters row -->
            <form method="get" class="flex flex-wrap items-center gap-2 mb-3">
                <input type="hidden" name="list" value="<?= htmlspecialchars($ui_list) ?>">
                <label class="text-sm text-gray-600">Type:</label>
                <?php
                $types = ['', 'red','white','rose','sparkling','dessert','fortified'];
                foreach ($types as $t):
                    $label = $t ?: 'All';
                    $active = ($ui_type===$t);
                    $url = htmlspecialchars($base.'?list='.$ui_list.($t!==''?('&type='.$t):''));
                    ?>
                    <a href="<?= $url ?>" class="text-xs px-3 py-1 rounded-lg border <?= $active?'bg-gray-100':'' ?>"><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </form>

            <!-- Scrollable list to keep card compact -->
            <div class="max-h-80 overflow-y-auto border rounded-xl">
                <ul class="divide-y">
                    <?php foreach ($expert as $w): ?>
                        <li class="py-3 px-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium truncate"><?= htmlspecialchars($w['name']) ?></div>
                                <div class="text-sm text-gray-600 truncate">
                                    <?= htmlspecialchars(($w['winery'] ?? '')) ?> <?= !empty($w['winery']) ? '· ' : '' ?>
                                    <?= htmlspecialchars(($w['region'] ?? '')) ?> <?= !empty($w['region']) ? '· ' : '' ?>
                                    <?= htmlspecialchars(($w['type'] ?? '')) ?> <?= !empty($w['type']) ? '· ' : '' ?>
                                    <?= htmlspecialchars(($w['vintage'] ?? '')) ?>
                                    <?php if (!empty($w['score'])): ?>
                                        <span class="ml-2 text-xs px-2 py-0.5 rounded bg-gray-100">Score: <?= htmlspecialchars($w['score']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <?php if (!empty($w['wine_id'])): ?>
                                    <!-- Add to Wantlist -->
                                    <form method="post" action="wantlist_api.php">
                                        <input type="hidden" name="action" value="add_from_catalog" />
                                        <input type="hidden" name="wine_id" value="<?= (int)$w['wine_id'] ?>" />
                                        <input type="hidden" name="name" value="<?= htmlspecialchars($w['name']) ?>" />
                                        <input type="hidden" name="winery" value="<?= htmlspecialchars($w['winery'] ?? '') ?>" />
                                        <input type="hidden" name="region" value="<?= htmlspecialchars($w['region'] ?? '') ?>" />
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($w['type'] ?? '') ?>" />
                                        <input type="hidden" name="vintage" value="<?= htmlspecialchars($w['vintage'] ?? '') ?>" />
                                        <button class="text-xs px-3 py-1 rounded-lg border hover:bg-gray-50" title="Add to Wantlist">
                                            Wantlist
                                        </button>
                                    </form>

                                    <!-- Move to Inventory -->
                                    <form method="post" action="move_to_inventory.php">
                                        <input type="hidden" name="wine_id" value="<?= (int)$w['wine_id'] ?>" />
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($w['type'] ?? '') ?>" />
                                        <input type="hidden" name="vintage" value="<?= htmlspecialchars($w['vintage'] ?? '') ?>" />
                                        <button class="text-xs px-3 py-1 rounded-lg border hover:bg-gray-50" title="Move to Inventory">
                                            Inventory
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="text-xs px-3 py-1 rounded-lg border opacity-60 cursor-not-allowed" disabled>Wantlist</button>
                                    <button class="text-xs px-3 py-1 rounded-lg border opacity-60 cursor-not-allowed" disabled>Inventory</button>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="mt-3">
                <a href="wantlist.php" class="text-indigo-600 hover:underline">Open my wantlist →</a>
            </div>
        </div>

    </section>

    <!-- Secondary -->
    <section class="mt-8 bg-white rounded-2xl shadow p-6">
        <h2 class="text-2xl font-semibold mb-4">🧾 My Lists</h2>
        <div class="flex flex-wrap gap-3">
            <a href="inventory.php" class="px-4 py-2 rounded-xl border hover:bg-gray-50">View Inventory</a>
            <a href="wantlist.php" class="px-4 py-2 rounded-xl border hover:bg-gray-50">Wantlist</a>
        </div>
    </section>

    <footer class="mt-10">
        <a href="logout.php" class="text-red-600 hover:underline">Log out</a>
        <?php if (!empty($_SESSION['is_admin'])): ?>
            <a href="admin_import_decanter.php" class="px-3 py-1 rounded-lg border">Admin · Expert Picks</a>
            <a href="admin.php" class="px-3 py-1 rounded-lg border">Admin Dashboard</a>
            <a href="admin_csv_import.php" class="px-3 py-1 rounded-lg border">Admin · CSV Import</a>
        <?php endif; ?>
    </footer>
</div>

<!-- Pick a bottle Modal -->
<dialog id="pickDialog" class="rounded-2xl p-0 w-11/12 md:w-[28rem]">
    <form method="dialog">
        <div class="p-6">
            <h3 class="text-xl font-semibold mb-3">Pick a bottle</h3>
            <p class="text-gray-600 mb-4">Choose a style and I’ll pick from your inventory.</p>
            <div>
                <label class="block text-sm mb-1" for="wineType">Wine type</label>
                <select id="wineType" class="w-full border rounded-lg p-2">
                    <option value="">Surprise me</option>
                    <option>red</option>
                    <option>white</option>
                    <option>rose</option>
                    <option>sparkling</option>
                    <option>dessert</option>
                    <option>fortified</option>
                </select>
            </div>
            <div id="pickResult" class="mt-4 hidden"></div>
            <div class="mt-6 flex justify-end gap-2">
                <button class="px-4 py-2 rounded-xl border" value="cancel">Close</button>
                <button id="btnDoPick" type="button" class="px-4 py-2 rounded-xl bg-indigo-600 text-white">Pick</button>
            </div>
        </div>
    </form>
</dialog>

<script>
    const pickBtn = document.getElementById('btnPickBottle');
    const dlg = document.getElementById('pickDialog');
    const doPick = document.getElementById('btnDoPick');
    const wineType = document.getElementById('wineType');
    const pickResult = document.getElementById('pickResult');

    pickBtn?.addEventListener('click', () => dlg.showModal());
    doPick?.addEventListener('click', async () => {
        pickResult.classList.add('hidden');
        pickResult.innerHTML = '';
        const res = await fetch('pick_random_bottle.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ type: wineType.value })
        });
        const data = await res.json();
        if (data.error) {
            pickResult.classList.remove('hidden');
            pickResult.innerHTML = `<div class="text-red-600">${data.error}</div>`;
        } else if (data.bottle) {
            const b = data.bottle;
            pickResult.classList.remove('hidden');
            pickResult.innerHTML = `
          <div class="p-3 rounded-xl bg-gray-50">
            <div class="font-semibold">${b.name ?? 'Unnamed wine'}</div>
            <div class="text-sm text-gray-600">${[b.winery, b.region, b.type, b.vintage].filter(Boolean).join(' · ')}</div>
            <div class="mt-2">
              <a class="text-indigo-600 hover:underline" href="edit_bottle.php?id=${b.bottle_id}">Open bottle details →</a>
            </div>
          </div>`;
        } else {
            pickResult.classList.remove('hidden');
            pickResult.innerHTML = `<div class="text-gray-700">No matching bottles found.</div>`;
        }
    });
</script>
</body>
</html>
