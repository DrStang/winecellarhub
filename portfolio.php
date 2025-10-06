<?php
declare(strict_types=1);
@ini_set('display_errors','0'); header('Content-Type: application/json');
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../auth.php';

$uid = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
if ($uid<=0){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Portfolio value = sum of price_paid (fallback to my_price), null-safe
$sum = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(price_paid, my_price)),0) FROM bottles WHERE user_id=:u");
$sum->execute([':u'=>$uid]); $portfolio = (float)$sum->fetchColumn();

// Near/past peak from drink_to
$near = $pdo->prepare("SELECT COUNT(*) FROM bottles WHERE user_id=:u AND drink_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)");
$near->execute([':u'=>$uid]); $near_peak = (int)$near->fetchColumn();
$past = $pdo->prepare("SELECT COUNT(*) FROM bottles WHERE user_id=:u AND drink_to < CURDATE()");
$past->execute([':u'=>$uid]); $past_peak = (int)$past->fetchColumn();

// YoY growth (calendar year on added_on)
$yThis = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(price_paid,my_price)),0) FROM bottles WHERE user_id=:u AND YEAR(added_on)=YEAR(CURDATE())");
$yPrev = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(price_paid,my_price)),0) FROM bottles WHERE user_id=:u AND YEAR(added_on)=YEAR(CURDATE())-1");
$yThis->execute([':u'=>$uid]); $t=(float)$yThis->fetchColumn();
$yPrev->execute([':u'=>$uid]); $p=(float)$yPrev->fetchColumn();
$yoy = $p>0 ? round((($t-$p)/$p)*100,1) : null;

echo json_encode(['ok'=>true,'kpis'=>[
    'portfolio_value'=>$portfolio,
    'yoy_growth_pct'=>$yoy,
    'near_peak'=>$near_peak,
    'past_peak'=>$past_peak
]]);
