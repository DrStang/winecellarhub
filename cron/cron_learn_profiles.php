<?php
require_once __DIR__ . '/../db.php';

/**
 * Tokenize varietals from the catalog's wines.grapes (TEXT).
 * Accepts commas, semicolons, slashes, ampersands as separators.
 */
function parse_varietals(?string $grapes): array {
    if (!$grapes) return [];
    $parts = preg_split('/[,&\/;]+/u', $grapes);
    $out = [];
    foreach ($parts as $p) {
        $v = trim(mb_strtolower($p));
        if ($v === '') continue;
        // normalize simple plurals/spacing
        $v = preg_replace('/\s+/', ' ', $v);
        $out[] = $v;
    }
    return $out;
}

/**
 * Learn from Wine.bottles (user's ratings/prices)
 * - Price sweet spot from bottles with my_rating >= 4
 * - Varietal and region preferences from catalog (wines.grapes / wines.region)
 * - User style vector = weighted avg of wines_ai.style_vector by my_rating
 */
function learn_user_profile(PDO $pdoWine, PDO $pdoCatalog, int $user_id) {
    // Price sweet spot (my_rating >= 4)
    $st = $pdoWine->prepare("
      SELECT AVG(COALESCE(price_paid, my_price, price)) AS avgp,
             STDDEV_SAMP(COALESCE(price_paid, my_price, price)) AS stdp
      FROM bottles
      WHERE user_id=? AND my_rating IS NOT NULL AND my_rating >= 4
            AND COALESCE(price_paid, my_price, price) IS NOT NULL
    ");
    $st->execute([$user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['avgp'=>null,'stdp'=>null];
    $avgp = $row['avgp'] !== null ? floatval($row['avgp']) : null;
    $stdp = $row['stdp'] !== null ? floatval($row['stdp']) : null;
    $pmin = $avgp !== null && $stdp !== null ? max(0, $avgp - 0.5*$stdp) : null;
    $pmax = $avgp !== null && $stdp !== null ? $avgp + 0.5*$stdp : null;

    // Pull rated wines
    $rated = $pdoWine->prepare("SELECT wine_id, my_rating FROM bottles WHERE user_id=? AND my_rating IS NOT NULL");
    $rated->execute([$user_id]);
    $byWine = $rated->fetchAll(PDO::FETCH_ASSOC);

    $sumVec = []; $sumWeight = 0.0;
    $varScores=[]; $varCount=[];
    $regionScores=[]; $regionCount=[];

    if ($byWine) {
        $ids = array_values(array_unique(array_filter(array_map(fn($r)=>$r['wine_id'], $byWine))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $meta = $pdoCatalog->prepare("
              SELECT w.id, w.region, w.grapes, a.style_vector
              FROM wines w
              LEFT JOIN wines_ai a ON a.wine_id=w.id
              WHERE w.id IN ($in)
            ");
            $meta->execute($ids);
            $m = [];
            foreach ($meta->fetchAll(PDO::FETCH_ASSOC) as $x) { $m[intval($x['id'])] = $x; }

            foreach ($byWine as $r) {
                $wid = intval($r['wine_id']);
                $mr  = floatval($r['my_rating']);
                if (!isset($m[$wid])) continue;
                $metaRow = $m[$wid];

                // Weighted style vector by my_rating
                if (!empty($metaRow['style_vector'])) {
                    $vec = json_decode($metaRow['style_vector'], true);
                    if (is_array($vec)) {
                        foreach ($vec as $k=>$v) $sumVec[$k] = ($sumVec[$k] ?? 0) + $mr*floatval($v);
                        $sumWeight += $mr;
                    }
                }

                // Varietals from grapes field
                foreach (parse_varietals($metaRow['grapes'] ?? '') as $var) {
                    $varScores[$var] = ($varScores[$var] ?? 0) + $mr;
                    $varCount[$var]  = ($varCount[$var]  ?? 0) + 1;
                }

                // Regions
                $reg = mb_strtolower(trim($metaRow['region'] ?? ''));
                if ($reg !== '') {
                    $regionScores[$reg] = ($regionScores[$reg] ?? 0) + $mr;
                    $regionCount[$reg]  = ($regionCount[$reg]  ?? 0) + 1;
                }
            }
        }
    }

    // User style centroid
    $userVec = [];
    if ($sumWeight > 0) foreach ($sumVec as $k=>$s) $userVec[$k] = $s/$sumWeight;

    // Top 3 varietals and regions (require at least 2 examples to avoid noise)
    $avgVar = [];
    foreach ($varScores as $k=>$s) if (($varCount[$k] ?? 0) >= 2) $avgVar[$k] = $s / $varCount[$k];
    arsort($avgVar); $varietal_top3 = array_slice(array_keys($avgVar), 0, 3);

    $avgReg = [];
    foreach ($regionScores as $k=>$s) if (($regionCount[$k] ?? 0) >= 2) $avgReg[$k] = $s / $regionCount[$k];
    arsort($avgReg); $region_top3 = array_slice(array_keys($avgReg), 0, 3);

    // Upsert profile
    $up = $pdoWine->prepare("
      INSERT INTO user_profiles(user_id, avg_price_min, avg_price_max, style_vector, varietal_top3, region_top3, updated_at)
      VALUES(?,?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE avg_price_min=VALUES(avg_price_min), avg_price_max=VALUES(avg_price_max),
        style_vector=VALUES(style_vector), varietal_top3=VALUES(varietal_top3), region_top3=VALUES(region_top3), updated_at=NOW()
    ");
    $up->execute([$user_id, $pmin, $pmax, json_encode($userVec), json_encode($varietal_top3), json_encode($region_top3)]);

    // (Optional) Precompute user_recos here if you want; the /api endpoint can compute on the fly too.
}

$users = $pdo->query("SELECT DISTINCT user_id FROM bottles")->fetchAll(PDO::FETCH_COLUMN);
foreach ($users as $uid) learn_user_profile($pdo, $winelist_pdo, intval($uid));
