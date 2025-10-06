<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }

$wine_id = isset($input['wine_id']) ? intval($input['wine_id']) : 0;
if (!$wine_id) { http_response_code(400); echo json_encode(['error'=>'wine_id required']); exit; }

// Check if already in cellar
$chk = $pdo->prepare("SELECT id FROM bottles WHERE user_id=? AND wine_id=? LIMIT 1");
$chk->execute([$user_id, $wine_id]);
$exists = $chk->fetchColumn();
if ($exists) { echo json_encode(['ok'=>true, 'message'=>'already added']); exit; }

$fields = ['name','winery','region','grapes','type','vintage','image_url','price'];
$data = [];
foreach ($fields as $f) { $data[$f] = isset($input[$f]) ? $input[$f] : null; }

$ins = $pdo->prepare("
  INSERT INTO bottles (user_id, wine_id, name, winery, region, grapes, type, vintage, image_url, price, added_on)
  VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
");
$ins->execute([
    $user_id, $wine_id,
    $data['name'], $data['winery'], $data['region'], $data['grapes'],
    $data['type'], $data['vintage'], $data['image_url'], $data['price']
]);

echo json_encode(['ok'=>true, 'bottle_id'=>$pdo->lastInsertId()]);

