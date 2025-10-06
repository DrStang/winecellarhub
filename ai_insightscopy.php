<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$wine_id = isset($_GET['wine_id']) ? intval($_GET['wine_id']) : 0;
if (!$wine_id) { http_response_code(400); echo json_encode(['error'=>'wine_id required']); exit; }

try {
    // Read from central catalog DB: winelist.wines_ai + wines
    $stmt = $winelist_pdo->prepare("
        SELECT wa.*, w.name, w.winery, w.region, w.type, w.vintage
        FROM wines_ai wa
        JOIN wines w ON w.id = wa.wine_id
        WHERE wa.wine_id = ?
    ");
    $stmt->execute([$wine_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['insights' => $row ?: null]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'server_error','message'=>$e->getMessage()]);
}