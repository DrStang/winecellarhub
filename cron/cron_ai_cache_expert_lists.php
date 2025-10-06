<?php // cron_ai_cache_expert_lists.php
require_once __DIR__.'/db.php';
require_once __DIR__.'/ai_lib.php';

// 1) Select the wines that appear on expert_lists (adjust query to your schema)
$sql = "
SELECT e.id as expert_id, w.id AS wine_id, w.name, w.winery, w.vintage, w.region, w.country, w.grapes
FROM expert_list_items e
JOIN wines w ON w.id = e.wine_id
ORDER BY e.id DESC
LIMIT 500
";
$rows = $winelist_pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 2) Compute cache keys, fetch existing in bulk
$keys = [];
$byKey = [];
foreach ($rows as $r) {
    $k = ai_cache_key_from_row(['wine_id' => $r['wine_id'], 'name'=>$r['name'], 'winery'=>$r['winery'], 'vintage'=>$r['vintage']]);
    $keys[]     = $k;
    $byKey[$k]  = $r;
}
$have = ai_cache_get_bulk($winelist_pdo, $keys);

// 3) Generate only the missing ones (rate-limit yourself as needed)
$missing = array_diff_key($byKey, $have);
$counter = 0;
foreach ($missing as $k => $r) {
    // Strictly generate AND store, but never block the UI
    $desc = ai_warm_one($winelist_pdo, [
        'wine_id' => $r['wine_id'],
        'name'    => $r['name'],
        'winery'  => $r['winery'],
        'vintage' => $r['vintage'],
        'region'  => $r['region'] ?? null,
        'country' => $r['country'] ?? null,
        'grapes'  => $r['grapes'] ?? null,
    ]);
    $counter++;
    // basic throttle
    usleep(200000); // 0.2s between calls; tune to your limits
}
echo "Warmed $counter items.\n";
