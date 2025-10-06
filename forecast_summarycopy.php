<?php
require_once __DIR__ . '/../db.php';
//require_once __DIR-- . '/../auth.php';

header('Content-Type: application/json');

//$user_id = $_SESSION['user_id'] ?? null;
//if (!$user_id) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }

// user's bottles and candidate current price (fallback to price_paid/my_price if catalog absent)
$bst = $pdo->prepare("
  SELECT b.wine_id, COALESCE(b.price_paid, b.my_price, b.price) AS acquired_price
  FROM bottles b WHERE b.user_id=?
");
$bst->execute([$user_id]);
$rows = $bst->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo json_encode(['one_year'=>null]); exit; }

$ids = array_values(array_unique(array_filter(array_map(fn($r)=> intval($r['wine_id']), $rows))));
$in  = implode(',', array_fill(0, count($ids), '?'));

$latest = []; $f1y = []; $conf = [];
if ($ids) {
    // current catalog price (winelist.wines.price)
    $stmtNow = $winelist_pdo->prepare("SELECT id, price FROM wines WHERE id IN ($in)");
    $stmtNow->execute($ids);
    foreach ($stmtNow->fetchAll(PDO::FETCH_ASSOC) as $r) $latest[intval($r['id'])] = (float)$r['price'];

    // 1y forecast (if present)
    $stmtF = $winelist_pdo->prepare("
    SELECT wine_id, forecast_price, confidence
    FROM wine_price_forecasts
    WHERE wine_id IN ($in) AND horizon='1y'
  ");
    $stmtF->execute($ids);
    foreach ($stmtF->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $wid=intval($r['wine_id']); $f1y[$wid]=(float)$r['forecast_price']; $conf[$wid]=(float)$r['confidence'];
    }
}

$total_now=0.0; $total_f=0.0; $conf_acc=0.0; $n=0;
foreach ($rows as $r) {
    $wid=intval($r['wine_id']);
    $now = $latest[$wid] ?? (float)($r['acquired_price'] ?? 0);
    if ($now <= 0) continue;
    $pred = $f1y[$wid] ?? $now;
    $total_now += $now;
    $total_f   += $pred;
    if (isset($conf[$wid])) { $conf_acc += $conf[$wid]; $n++; }
}
$pct = ($total_now>0) ? round((($total_f - $total_now)/$total_now)*100, 1) : null;
$avg_conf = $n>0 ? round($conf_acc/$n, 2) : null;

echo json_encode(['one_year'=>['pct'=>$pct, 'conf'=>$avg_conf]]);