<?php
declare(strict_types=1);
@ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

// --- CONFIG ---
// Set this to the schema that contains wine_price_forecasts:
$FORECAST_DB = 'winelist';   // change to 'Wine' if you actually store it there

$uid = function_exists('current_user_id') ? (int) current_user_id() : (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['one_year' => null]); exit; }

// $pdo  should be your "Wine" DB (bottles).
// $winelist_pdo should be your "winelist" DB (wines).
// If your db.php exposes different variables, adjust below accordingly.

try {
    // 1) Pull user's active bottles + baseline price (prefer user prices, else catalog)
    $sql = "
      SELECT 
        b.wine_id,
        COALESCE(b.price_paid, b.my_price, b.price, w.price) AS baseline
      FROM bottles b
      LEFT JOIN {$winelist_pdo->query('SELECT DATABASE()')->fetchColumn()}.wines w
        ON w.id = b.wine_id
      WHERE b.user_id = :u AND (b.past IS NULL OR b.past = 0)
        AND b.wine_id IS NOT NULL
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':u' => $uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) { echo json_encode(['one_year' => null]); exit; }

    // Build list of wine_ids with usable baseline
    $wineIds = [];
    $baselineByWine = [];
    foreach ($rows as $r) {
        $wid = (int)$r['wine_id'];
        $base = is_null($r['baseline']) ? null : (float)$r['baseline'];
        if ($wid > 0 && $base !== null && $base > 0.0) {
            $wineIds[] = $wid;
            $baselineByWine[$wid] = $base;
        }
    }
    if (!$wineIds) { echo json_encode(['one_year' => null]); exit; }

    // 2) Fetch the latest horizon=1y forecast for those wine_ids
    // latest by asof_date; if multiple, prefer newest asof_date then newest id
    $placeholders = implode(',', array_fill(0, count($wineIds), '?'));
    $fq = "
      SELECT f.wine_id, f.forecast_price, f.confidence
      FROM {$FORECAST_DB}.wine_price_forecasts f
      JOIN (
        SELECT wine_id, MAX(asof_date) AS max_asof
        FROM {$FORECAST_DB}.wine_price_forecasts
        WHERE wine_id IN ($placeholders) AND horizon = '1y'
        GROUP BY wine_id
      ) last ON last.wine_id = f.wine_id AND last.max_asof = f.asof_date
      WHERE f.horizon = '1y'
    ";
    // Note: if you sometimes have NULL asof_date, you can adapt this to use id DESC instead.

    // choose connection that can see the forecast table (often winelist)
    $fc = $winelist_pdo; // if forecasts live in Wine, use $pdo instead
    $fst = $fc->prepare($fq);
    $fst->execute($wineIds);
    $forecasts = $fst->fetchAll(PDO::FETCH_ASSOC);

    if (!$forecasts) { echo json_encode(['one_year' => null]); exit; }

    // 3) Compute portfolio-level weighted pct and confidence
    $w_sum = 0.0;
    $pct_wsum = 0.0;
    $conf_wsum = 0.0;

    foreach ($forecasts as $f) {
        $wid = (int)$f['wine_id'];
        if (!isset($baselineByWine[$wid])) continue;
        $base = (float)$baselineByWine[$wid];
        $fprice = isset($f['forecast_price']) ? (float)$f['forecast_price'] : null;
        if ($fprice === null || $base <= 0.0) continue;

        $weight = $base; // value-weighted by baseline
        $pct = (($fprice - $base) / $base) * 100.0;

        $w_sum += $weight;
        $pct_wsum += $pct * $weight;

        if (isset($f['confidence']) && $f['confidence'] !== null) {
            $conf = (float)$f['confidence']; // expected 0..1
            $conf_wsum += $conf * $weight;
        }
    }

    if ($w_sum <= 0.0) { echo json_encode(['one_year' => null]); exit; }

    $portfolio_pct = round($pct_wsum / $w_sum, 1);
    $portfolio_conf = $conf_wsum > 0 ? round($conf_wsum / $w_sum, 3) : null;

    echo json_encode(['one_year' => ['pct' => $portfolio_pct, 'conf' => $portfolio_conf]]);
} catch (Throwable $e) {
    // Fail soft: hide card
    echo json_encode(['one_year' => null]);
}
