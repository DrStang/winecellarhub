<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$wine_id = isset($_GET['wine_id']) ? intval($_GET['wine_id']) : 0;
if ($wine_id <= 0) { http_response_code(400); echo json_encode(['error'=>'wine_id required']); exit; }

// Pull current catalog price + metadata
$wq = $winelist_pdo->prepare("SELECT id, name, region, grapes, type, vintage, price, rating, investability_score FROM wines WHERE id=? LIMIT 1");
$wq->execute([$wine_id]);
$wine = $wq->fetch(PDO::FETCH_ASSOC);
if (!$wine) { echo json_encode(['error'=>'not_found']); exit; }

$now = isset($wine['price']) ? (float)$wine['price'] : null;

// Pull forecasts if present
$fq = $winelist_pdo->prepare("
  SELECT wine_id, horizon, forecast_price, confidence, lower_ci, upper_ci, explanation_md, asof_date
  FROM wine_price_forecasts
  WHERE wine_id=? AND horizon IN ('6m','1y','5y')
");
$fq->execute([$wine_id]);
$rows = $fq->fetchAll(PDO::FETCH_ASSOC);

$byH = [];
foreach ($rows as $r) {
    $h = $r['horizon'];
    $pred = (float)$r['forecast_price'];
    $pct  = ($now && $now>0) ? round((($pred-$now)/$now)*100, 1) : null;
    $byH[$h] = [
        'horizon'     => $h,
        'forecast'    => $pred,
        'pct'         => $pct,
        'confidence'  => isset($r['confidence']) ? (float)$r['confidence'] : null,
        'lower_ci'    => isset($r['lower_ci']) ? (float)$r['lower_ci'] : null,
        'upper_ci'    => isset($r['upper_ci']) ? (float)$r['upper_ci'] : null,
        'asof_date'   => $r['asof_date'] ?? null,
        'explanation' => $r['explanation_md'] ?? ''
    ];
}

// Sort in logical order
$ordered = [];
foreach (['6m','1y','5y'] as $h) if (isset($byH[$h])) $ordered[] = $byH[$h];

echo json_encode([
    'wine' => [
        'id'      => (int)$wine['id'],
        'name'    => $wine['name'],
        'region'  => $wine['region'],
        'grapes'  => $wine['grapes'],
        'type'    => $wine['type'],
        'vintage' => $wine['vintage'],
        'price'   => $now,
        'rating'  => isset($wine['rating']) ? (float)$wine['rating'] : null,
        'investability_score' => isset($wine['investability_score']) ? (int)$wine['investability_score'] : null,
    ],
    'forecasts' => $ordered
]);
