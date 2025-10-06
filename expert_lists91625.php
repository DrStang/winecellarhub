<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require __DIR__.'/analytics_track.php'; // <-- add this
require __DIR__.'/covers_utils.php';
// ---------- helpers ----------
// Choose the best image for a wine row.
// Priority: real local cover (/covers/*.jpg|png|webp) > remote http(s) URL > placeholder

function best_img_src(array $row, string $coversRel = '/covers'): string {
    $wineId = isset($row['wine_id']) ? (int)$row['wine_id'] : 0;
    if ($wineId > 0) {
        $manifest = covers_manifest($coversRel);
        if (isset($manifest[$wineId])) {
            $ext = $manifest[$wineId];
            return "{$coversRel}/{$wineId}.{$ext}";
        }
    }

    // Fallback to DB URL if you store one:
    $url = trim((string)($row['image_url'] ?? ''));
    if ($url !== '') return htmlspecialchars($url, ENT_QUOTES);

    // Final placeholder (use your color/type if available)
    $type = strtolower(trim((string)($row['type'] ?? $row['color'] ?? '')));
    if ($type === 'white')  return "{$coversRel}/white_placeholder.jpg";
    if ($type === 'red')    return "{$coversRel}/red_placeholder.jpg";
    return "{$coversRel}/placeholder.jpg";
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

$keys = [];
$keysByIndex = []; // map index -> key for convenience
foreach ($rows as $i => $r) {
    $k = ai_cache_key_from_row($r);
    $keys[] = $k;
    $keysByIndex[$i] = $k;
}
$aiCacheMap = ai_cache_get_bulk($winelist_pdo, $keys);

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

                    <!-- expert_lists.php (inside your card render loop) -->
                    <?php foreach ($rows as $i => $row):
                    // Build the exact fields ai_cache_key_from_row uses + helpful hints

  // Build payload for ai_desc endpoint
  $payload = [
    'wine_id' => (int)($row['wine_id'] ?? 0),
    'winery'  => (string)($row['winery'] ?? ''),
    'name'    => (string)($row['name'] ?? ''),
    'vintage' => (string)($row['vintage'] ?? ''),
    'region'  => (string)($row['region'] ?? ''),
    'country' => (string)($row['country'] ?? ''),
    'grapes'  => (string)($row['grapes'] ?? ''),
  ];
  $payloadAttr = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_SLASHES), ENT_QUOTES);

  endforeach; ?>
                    <div class="mt-2 flex items-center gap-2">
                        <button
                                type="button"
                                class="ai-notes-btn px-3 py-1.5 rounded-lg bg-amber-600 text-white hover:bg-amber-700"
                                data-payload="<?= $payloadAttr ?>"
                        >✨ AI notes</button>

                        <!-- optional badge updated by preloader -->
                        <span class="ai-cached-badge text-xs text-slate-500">checking…</span>
                    </div>

                    <!-- where the notes go -->
                    <div class="ai-notes prose prose-sm mt-2 hidden"></div>

                </div>


                </div>

                <div class="mt-3">
                <?= action_buttons($row) ?>
              </div>
            </div>
          </div>

  </div>
<script>
    async function fetchAiNotes(payload, tries=0) {
        const res = await fetch('ajax/ai_desc.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload),
        });
        if (!res.ok) throw new Error('HTTP '+res.status);
        const data = await res.json();
        if (data.status === 'ok' && data.desc_md) return data.desc_md;
        if (data.status === 'pending' && tries < 3) {
            await new Promise(r => setTimeout(r, 2000));
            return fetchAiNotes(payload, tries+1);
        }
        return null;
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.ai-notes-btn');
        if (!btn) return;

        const card = btn.closest('.wine-card') || btn.parentElement;
        const out  = card.querySelector('.ai-notes');
        const payload = JSON.parse(btn.dataset.payload || '{}');

        const old = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Loading…';

        try {
            const md = await fetchAiNotes(payload);
            out.classList.remove('hidden');
            out.innerText = md || 'AI notes are being prepared. Try again in a bit.';
        } catch (err) {
            out.classList.remove('hidden');
            out.innerText = 'AI notes unavailable right now.';
        } finally {
            btn.disabled = false;
            btn.textContent = old;
        }
    });
</script>
<script>
    // --- CONFIG ---
    const AI_PRELOAD_CONCURRENCY = 3;       // max parallel preload requests
    const AI_PRELOAD_RETRIES     = 2;       // retry pending a couple times
    const AI_PRELOAD_DEBOUNCE_MS = 200;     // batch new visibles quickly
    const AI_ENDPOINT            = 'ajax/ai_desc.php';

    // Global in-page cache { cacheKey: desc_md }
    window.aiNotesCache = window.aiNotesCache || Object.create(null);

    // Build a stable key matching server-side ai_cache_key_from_row
    function cacheKeyFromPayload(p) {
        // must match fields & normalization the server uses
        // we can’t hash sha256 here; instead, use a JSON key that’s unique per row
        // and map it to server response on success.
        const norm = {
            wine_id: Number(p.wine_id||0),
            name:    String(p.name||'').trim().toLowerCase(),
            winery:  String(p.winery||'').trim().toLowerCase(),
            vintage: String(p.vintage||'').replace(/[^0-9]/g, ''), // digits only
            region:  String(p.region||'').trim().toLowerCase(),
            country: String(p.country||'').trim().toLowerCase(),
            grapes:  String(p.grapes||'').trim().toLowerCase(),
        };
        return JSON.stringify(norm);
    }

    async function callAiDesc(payload, tries=0) {
        const res = await fetch(AI_ENDPOINT, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload),
        });
        if (!res.ok) throw new Error('HTTP '+res.status);
        const data = await res.json();
        if (data.status === 'ok' && data.desc_md) return data.desc_md;
        if (data.status === 'pending' && tries < AI_PRELOAD_RETRIES) {
            await new Promise(r => setTimeout(r, 1200));
            return callAiDesc(payload, tries+1);
        }
        return null; // still pending or unavailable
    }

    // --- PRELOAD QUEUE WITH SMALL CONCURRENCY ---
    const preloadQueue = [];
    let active = 0;
    let scheduled = false;

    function scheduleRunQueue() {
        if (scheduled) return;
        scheduled = true;
        setTimeout(runQueue, AI_PRELOAD_DEBOUNCE_MS);
    }

    async function runQueue() {
        scheduled = false;
        while (active < AI_PRELOAD_CONCURRENCY && preloadQueue.length) {
            const task = preloadQueue.shift();
            active++;
            (async () => {
                try {
                    const key = cacheKeyFromPayload(task.payload);
                    if (window.aiNotesCache[key]) return; // already cached in memory

                    const md = await callAiDesc(task.payload);
                    if (md) {
                        window.aiNotesCache[key] = md;
                        // mark UI as cached if we can
                        if (task.badgeEl) {
                            task.badgeEl.textContent = 'cached';
                            task.badgeEl.className = 'text-xs text-emerald-700 bg-emerald-100 rounded px-2 py-0.5';
                        }
                    }
                } catch (_) {
                    // swallow; we’ll get it on demand if needed
                } finally {
                    active--;
                    // keep pulling while there’s capacity
                    if (preloadQueue.length) scheduleRunQueue();
                }
            })();
        }
    }

    // --- OBSERVE CARDS ENTERING VIEWPORT ---
    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const card = entry.target;

            const btn = card.querySelector('.ai-notes-btn');
            if (!btn) continue;

            let payload;
            try { payload = JSON.parse(btn.dataset.payload || '{}'); } catch { payload = {}; }
            const key = cacheKeyFromPayload(payload);
            if (window.aiNotesCache[key]) continue;

            const badgeEl = card.querySelector('.ai-cached-badge'); // optional
            preloadQueue.push({ payload, badgeEl });
            scheduleRunQueue();

            // We only need to queue once per card
            observer.unobserve(card);
        }
    }, { rootMargin: '200px 0px 200px 0px', threshold: 0.01 });

    // Start observing all wine cards
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.wine-card').forEach(card => observer.observe(card));
    });

    // --- BUTTON CLICK: now instant if preloaded ---
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.ai-notes-btn');
        if (!btn) return;

        const card = btn.closest('.wine-card') || btn.parentElement;
        const out  = card.querySelector('.ai-notes');
        let payload = {};
        try { payload = JSON.parse(btn.dataset.payload || '{}'); } catch {}

        const key = cacheKeyFromPayload(payload);
        const old = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Loading…';

        try {
            // 1) use in-page cache if we have it
            let md = window.aiNotesCache[key] || null;

            // 2) otherwise fetch now (ai_desc.php will read cache or quick-warm)
            if (!md) md = await callAiDesc(payload);

            // 3) update UI
            if (md) {
                window.aiNotesCache[key] = md;
                out.classList.remove('hidden');
                // (If you use a Markdown renderer, replace innerText with rendered HTML)
                out.innerText = md;
                // update badge if present
                const badgeEl = card.querySelector('.ai-cached-badge');
                if (badgeEl) {
                    badgeEl.textContent = 'cached';
                    badgeEl.className = 'text-xs text-emerald-700 bg-emerald-100 rounded px-2 py-0.5';
                }
            } else {
                out.classList.remove('hidden');
                out.innerText = 'AI notes are being prepared. Try again soon.';
            }
        } catch (_) {
            out.classList.remove('hidden');
            out.innerText = 'AI notes unavailable right now.';
        } finally {
            btn.disabled = false;
            btn.textContent = old;
        }
    });
</script>



</body>
</html>

