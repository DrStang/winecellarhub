<?php
require_once __DIR__ . '/../db.php';

$sel = $pdo->query("
  SELECT b.id AS bottle_id, b.user_id, b.drink_from, b.drink_to
  FROM bottles b
  WHERE (b.drink_from IS NOT NULL AND DATEDIFF(b.drink_from, CURDATE()) BETWEEN 0 AND 60)
     OR (b.drink_to IS NOT NULL AND CURDATE() > b.drink_to)
");
$ins = $pdo->prepare("INSERT INTO cellar_alerts(user_id,bottle_id,alert_type,created_at) VALUES(?,?,?,NOW())");

while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
    $type = null;
    if (!empty($r['drink_from']) && (strtotime($r['drink_from']) - time())/(86400) <= 60) $type='peak_nearing';
    if (!empty($r['drink_to']) && date('Y-m-d') > $r['drink_to']) $type='past_peak';
    if (!$type) continue;
    $chk = $pdo->prepare("SELECT 1 FROM cellar_alerts WHERE user_id=? AND bottle_id=? AND alert_type=? AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 1");
    $chk->execute([$r['user_id'],$r['bottle_id'],$type]);
    if (!$chk->fetch()) $ins->execute([$r['user_id'],$r['bottle_id'],$type]);
}
