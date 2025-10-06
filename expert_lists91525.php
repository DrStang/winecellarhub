<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require __DIR__.'/analytics_track.php'; // <-- add this

// ---------- helpers ----------
// Choose the best image for a wine row.
// Priority: real local cover (/covers/*.jpg|png|webp) > remote http(s) URL > placeholder
function best_img_src(array $row): string {
    // 1) Try local cover by wine_id (common cache naming)
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__), '/');
    $wineId  = isset($row['wine_id']) ? (int)$row['wine_id'] : 0;
    if ($wineId > 0) {
        foreach (['jpg','jpeg','png','webp'] as $ext) {
            $rel = "/covers/{$wineId}.{$ext}";
            if (is_file($docroot . $rel)) return htmlspecialchars($rel, ENT_QUOTES);
        }
    }

    // 2) If DB already stores a local path (e.g. "/covers/abc.jpg"), use it if it exists
    $u = trim((string)($row['image_url'] ?? ''));
    if ($u !== '' && $u[0] === '/') {
        // Normalize to absolute filesystem path
        if (strpos($u, '/covers/') === 0 && is_file($docroot . $u)) {
            return htmlspecialchars($u, ENT_QUOTES);
        }
    }

    // 3) If it's a bare filename (no slash), assume it lives in /covers
    if ($u !== '' && strpos($u, '/') === false && strpos($u, 'http') !== 0) {
        $guess = "/covers/{$u}";
        if (is_file($docroot . $guess)) return htmlspecialchars($guess, ENT_QUOTES);
    }

    // 4) If it's a full remote link, allow it
    if ($u !== '' && preg_match('#^https?://#i', $u)) {
        return htmlspecialchars($u, ENT_QUOTES);
    }

    // 5) Last resort: placeholder
    return '/covers/placeholder.png';
}

function action_buttons($row){
    $wine_id = $row['wine_id'] ?? null;
    $name    = htmlspecialchars($row['name'] ?? '', ENT_QUOTES);
    $winery  = htmlspecialchars($row['winery'] ?? '', ENT_QUOTES);
    $vintage = htmlspecialchars($row['vintage'] ?? '', ENT_QUOTES);
    $q = urlencode(trim(($row['winery'] ?? ' ') . ' ' . ($row['name'] ?? '') . ' ' . ($row['vintage'] ?? '')));

    ob_start(); ?>
    <div class="flex flex-col sm:flex-row gap-2">
        <form method="post" action="wantlist_api.php" class="inline">
            <?php if ($wine_id): ?>
                <input type="hidden" name="wine_id" value="<?= (int)$wine_id ?>">
            <?php else: ?>
                <input type="hidden" name="name" value="<?= $name ?>">
                <input type="hidden" name="winery" value="<?= $winery ?>">
                <input type="hidden" name="vintage" value="<?= $vintage ?>">
            <?php endif; ?>
            <button class="px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Add to Wantlist</button>
        </form>

        <?php if ($wine_id): ?>
        <form method="post" action="move_to_inventory.php" class="inline">
            <input type="hidden" name="wine_id" value="<?= (int)$wine_id ?>">
            <button class="px-3 py-1.5 rounded-lg bg-[var(--accent)] text-white hover:bg-[var(--primary-600)]">Add to Inventory</button>
        </form>
        <?php endif; ?>

        <a href="https://www.wine.com/search/<?= $q ?>" class="px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 text-center">Find Wine</a>

        <?php if (!empty($row['image_url'])): ?>
            <a href="<?= htmlspecialchars($row['image_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener" class="px-3 py-1.5 rounded-lg bg-gray-600 text-white hover:bg-gray-700 text-center">View Image</a>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ---------- dynamic years ----------
$decYears = $winelist_pdo->query("
    SELECT DISTINCT `year`
    FROM expert_picks
    WHERE (`source` LIKE 'Decanter%' OR `source` LIKE 'DWWA%')
      AND `year` IS NOT NULL
      AND `medal` IN ('Best in Show','Platinum')
    ORDER BY `year` DESC
    LIMIT 3
")->fetchAll(PDO::FETCH_COLUMN);

$wsYears = $winelist_pdo->query("
    SELECT DISTINCT `year`
    FROM expert_picks
    WHERE ( `source` LIKE 'Wine Spectator%' OR `list_name` LIKE 'Wine Spectator Top 100%' )
      AND ( `list_name` LIKE 'Top 100%' OR `list_name` LIKE '%Top 100%' )
      AND `year` IS NOT NULL
    ORDER BY `year` DESC
    LIMIT 3
")->fetchAll(PDO::FETCH_COLUMN);

$weYears = $winelist_pdo->query("
    SELECT DISTINCT `year`
    FROM expert_picks
    WHERE ( `source` LIKE 'Wine Enthusiast%' OR `list_name` LIKE 'Wine Enthusiast Best of 2024' )
      AND ( `list_name` LIKE 'Best Wines of 2024%' OR `list_name` LIKE '%Best Wines of 2024%' )
      AND `year` IS NOT NULL
    ORDER BY `year` DESC
    LIMIT 3
")->fetchAll(PDO::FETCH_COLUMN);

// ---------- datasets ----------
$decStmt = $winelist_pdo->prepare("
    SELECT id, `source`, `year`, `list_name`, `medal`, `score`, `wine_id`,
           `name`, `winery`, `region`, `type`, `vintage`, `country`, `notes`, `grapes`, `image_url`
    FROM expert_picks
    WHERE (`source` LIKE 'Decanter%' OR `source` LIKE 'DWWA%')
      AND `medal` IN ('Best in Show','Platinum')
      AND `year` = ?
    ORDER BY
      CASE `medal` WHEN 'Best in Show' THEN 1 WHEN 'Platinum' THEN 2 ELSE 3 END,
      `score` DESC, `name` ASC
");

$wsStmt = $winelist_pdo->prepare("
    SELECT id, `source`, `year`, `list_name`, `medal`, `score`, `wine_id`,
           `name`, `winery`, `region`, `type`, `vintage`, `country`, `notes`, `grapes`, `rank`,`image_url`
    FROM expert_picks
    WHERE ( `source` LIKE 'Wine Spectator%' OR `list_name` LIKE 'Wine Spectator Top 100%' )
      AND ( `list_name` LIKE 'Top 100%' OR `list_name` LIKE '%Top 100%' )
      AND `year` = ?
    ORDER BY CAST(`rank` AS UNSIGNED) ASC, `name` ASC
");

$weStmt = $winelist_pdo->prepare("
    SELECT id, `source`, `year`, `list_name`, `medal`, `score`, `wine_id`,
           `name`, `winery`, `region`, `type`, `vintage`, `country`, `notes`, `grapes`, `rank`,`image_url`
    FROM expert_picks
    WHERE ( `source` LIKE 'Wine Enthusiast%' OR `list_name` LIKE 'Wine Enthusiast Best of 2024' )
      AND ( `list_name` LIKE 'Best Wines of 2024%' OR `list_name` LIKE '%Best Wines of 2024' )
      AND `year` = ?
    ORDER BY CAST(`rank` AS UNSIGNED) ASC, `name` ASC
");

$decanter = [];
foreach ($decYears as $y) { $decStmt->execute([$y]); $decanter[$y] = $decStmt->fetchAll(PDO::FETCH_ASSOC); }

$ws = [];
foreach ($wsYears as $y) { $wsStmt->execute([$y]); $ws[$y] = $wsStmt->fetchAll(PDO::FETCH_ASSOC); }

$we = [];
foreach ($weYears as $y) { $weStmt->execute([$y]); $we[$y] = $weStmt->fetchAll(PDO::FETCH_ASSOC); }

// ---------- tabs ----------
$tabs = [];
foreach ($decYears as $y) { $tabs[] = ['key'=>"dec_$y", 'label'=>"Decanter Wine Awards $y", 'count'=>count($decanter[$y] ?? [])]; }
foreach ($wsYears  as $y) { $tabs[] = ['key'=>"ws_$y",  'label'=>"Wine Spectator Top 100 $y", 'count'=>count($ws[$y] ?? [])]; }
foreach ($weYears  as $y) { $tabs[] = ['key'=>"we_$y",  'label'=>"Wine Enthusiast Best Wines of $y", 'count'=>count($we[$y] ?? [])]; }

if (!$tabs) { $tabs[] = ['key'=>'none','label'=>'No Lists Found','count'=>0]; }

$active = $_GET['t'] ?? $tabs[0]['key'];
$data = [];
$subtitle = '';

if (preg_match('/^dec_(\d{4})$/', $active, $m)) {
    $yr = (int)$m[1]; $data = $decanter[$yr] ?? []; $subtitle = "Decanter DWWA $yr — Best in Show & Platinum";
} elseif (preg_match('/^ws_(\d{4})$/', $active, $m)) {
    $yr = (int)$m[1]; $data = $ws[$yr] ?? []; $subtitle = "Wine Spectator Top 100 $yr";

} elseif (preg_match('/^we_(\d{4})$/', $active, $m)) {
    $yr = (int)$m[1]; $data = $we[$yr] ?? []; $subtitle = "Wine Enthusiast Best Wines of $yr";
}
require_once __DIR__ . '/ai_lib.php';

// warm a few (example)
$seen = [];
$max  = 50;
$count = 0;
foreach ($data as $row) {
    $ck = ai_cache_key_from_row($row);
    if (isset($seen[$ck])) continue;
    $seen[$ck] = true;

    if (ai_get_cached($winelist_pdo, $ck) === null) {
        ai_warm_one($winelist_pdo, $row); // <-- pass $winelist_pdo
        if (++$count >= $max) break;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Expert Wine Lists • WineCellarHub</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.3/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
<?php require __DIR__ . '/partials/header.php'; ?>
  <div class="max-w-6xl mx-auto p-4 md:p-8">
    <h1 class="text-3xl font-semibold mb-6">Expert Wine Lists</h1>

    <div class="flex flex-wrap gap-3 mb-6">
      <?php foreach ($tabs as $t): $is = ($active === $t['key']); ?>
        <a href="?t=<?= urlencode($t['key']) ?>"
           class="px-4 py-2 rounded-full border transition
                  <?= $is ? 'bg-[var(--primary-600)] text-white border-[var(--primary-600)]' : 'bg-white text-gray-700 hover:bg-gray-100 border-gray-200' ?>">
          <?= htmlspecialchars($t['label']) ?>
          <span class="ml-2 text-xs opacity-80"><?= (int)$t['count'] ?> wines</span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="mb-4">
      <h2 class="text-2xl font-semibold"><?= htmlspecialchars($subtitle ?: 'Select a tab') ?></h2>
      <div class="text-sm text-gray-500 mt-1">Quickly add standout bottles to your wantlist or inventory.</div>
    </div>

    <?php if (empty($data)): ?>
      <div class="p-6 bg-white rounded-xl shadow text-gray-600">No wines found for this tab.</div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($data as $row): ?>
          <div class="p-4 bg-white rounded-xl shadow flex flex-col sm:flex-row gap-4">
            <div class="w-full sm:w-28">
                <img src="<?= best_img_src($row) ?>"
                     alt="<?= htmlspecialchars($row['name'] ?? 'Wine') ?>"
                     class="w-20 h-32 sm:w-24 object-contain bg-gray-100 p-1 rounded mx-auto sm:mx-0"
                     loading="lazy">
            </div>

            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <?php if (!empty($row['medal'])): ?>
                  <?php $med = $row['medal'];
                        $medClass = $med === 'Best in Show' ? 'bg-indigo-100 text-indigo-700'
                                  : ($med === 'Platinum' ? 'bg-sky-100 text-sky-700' : 'bg-gray-100 text-gray-700'); ?>
                  <span class="text-xs px-2 py-1 rounded-full <?= $medClass ?>"><?= htmlspecialchars($med) ?></span>
                <?php endif; ?>
                <?php if (!empty($row['year'])): ?>
                  <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-700"><?= (int)$row['year'] ?></span>
                <?php endif; ?>
                  <?php if ($row['rank'] !== null && $row['rank'] !== ''): ?>
                      <span class="text-xs px-2 py-1 rounded-full text-[var(--text)]]"># <?= (int)$row['rank'] ?></span>
                  <?php endif; ?>
                <?php if (!empty($row['score'])): ?>
                  <span class="ml-2 text-sm text-amber-600 font-medium">★ <?= number_format((float)$row['score'], 2) ?></span>
                <?php endif; ?>

              </div>

              <div class="text-lg font-semibold">
                <?= htmlspecialchars($row['name'] ?? '') ?>
                <?php if ($row['vintage'] !== '' && $row['vintage'] !== null): ?>
                  <span class="text-gray-600">• <?= htmlspecialchars($row['vintage']) ?></span>
                <?php endif; ?>
              </div>
              <div class="text-sm text-gray-600">
                <?= htmlspecialchars($row['winery'] ?? '') ?>
                <?php if (!empty($row['region'])): ?> — <?= htmlspecialchars($row['region']) ?><?php endif; ?>
                <?php if (!empty($row['country'])): ?>, <?= htmlspecialchars($row['country']) ?><?php endif; ?>
              </div>
              <?php if (!empty($row['grapes'])): ?>
                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($row['grapes']) ?></div>
              <?php endif; ?>
              <?php if (!empty($row['notes'])): ?>
                <div class="text-sm text-gray-700 mt-2"><?= nl2br(htmlspecialchars($row['notes'])) ?></div>
              <?php endif; ?>
                <?php
                $aiKey = !empty($row['wine_id']) ? 'wine:'.(int)$row['wine_id']
                    : 'expert:'.sha1(($row['winery']??'')."|".($row['name']??'')."|".($row['vintage']??''));
                ?>
                <div class="mt-2">
                    <button type="button"
                            class="text-sm px-2 py-1 rounded bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
                            data-ai-btn
                            data-key="<?= htmlspecialchars($aiKey, ENT_QUOTES) ?>"
                            data-params='<?= htmlspecialchars(json_encode([
                                'wine_id' => $row['wine_id'] ?? null,
                                'name'    => $row['name']    ?? '',
                                'winery'  => $row['winery']  ?? '',
                                'vintage' => $row['vintage'] ?? '',
                                'region'  => $row['region']  ?? '',
                                'country' => $row['country'] ?? '',
                                'grapes'  => $row['grapes']  ?? '',
                            ], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'>
                        ✨ AI notes
                    </button>
                    <div class="prose prose-sm mt-2 hidden" data-ai-out="<?= htmlspecialchars($aiKey, ENT_QUOTES) ?>"></div>
                </div>

                <div class="mt-3">
                <?= action_buttons($row) ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<script>
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-ai-btn]');
        if (!btn) return;

        const key = btn.getAttribute('data-key');
        const out = document.querySelector(`[data-ai-out="${CSS.escape(key)}"]`);
        if (!out) return;

        if (!out.classList.contains('hidden')) { // toggle collapse
            out.classList.add('hidden');
            return;
        }

        // show loading state
        const oldText = btn.textContent;
        btn.textContent = 'Generating…';
        btn.disabled = true;

        const params = JSON.parse(btn.getAttribute('data-params') || '{}');
        const qs = new URLSearchParams(params).toString();
        try {
            const r = await fetch(`ai_desc.php?` + qs);
            const j = await r.json();
            if (j.ok && j.desc_md) {
                out.innerText = j.desc_md;
                out.classList.remove('hidden');
                btn.textContent = j.cached ? '✨ AI notes (cached)' : '✨ AI notes';
            } else {
                out.innerText = 'AI notes unavailable right now.';
                out.classList.remove('hidden');
                btn.textContent = '✨ AI notes';
            }
        } catch {
            out.innerText = 'AI notes unavailable.';
            out.classList.remove('hidden');
            btn.textContent = '✨ AI notes';
        } finally {
            btn.disabled = false;
        }
    });
</script>

</body>
</html>

