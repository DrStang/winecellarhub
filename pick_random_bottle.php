<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require __DIR__.'/analytics_track.php'; // <-- add this

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$type = trim(strtolower($input['type'] ?? ''));

try {
    // Detect if bottles.type exists
    $hasType = false;
    $cols = $pdo->query("SHOW COLUMNS FROM bottles")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (strtolower($c['Field']) === 'type') { $hasType = true; break; }
    }

    $row = null;

    if ($type) {
        if ($hasType) {
            $stmt = $pdo->prepare("
                SELECT b.id AS bottle_id, b.wine_id, b.type, b.vintage, b.location
                FROM bottles b
                WHERE b.user_id = ? AND LOWER(b.type) = ?
                ORDER BY RAND() LIMIT 1
            ");
            $stmt->execute([$user_id, $type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
            // Step 1: fetch wine_ids of this type from central catalog
            $ids = [];
            $s = $winelist_pdo->prepare("SELECT id FROM wines WHERE LOWER(type) = ? LIMIT 500");
            $s->execute([$type]);
            $ids = array_map(fn($r)=> (int)$r['id'], $s->fetchAll(PDO::FETCH_ASSOC));
            if ($ids) {
                // Step 2: pick random bottle among those wine_ids
                $in = implode(',', array_fill(0, count($ids), '?'));
                $p = $pdo->prepare("
                    SELECT b.id AS bottle_id, b.wine_id, b.vintage, b.location
                    FROM bottles b
                    WHERE b.user_id = ? AND b.wine_id IN ($in)
                    ORDER BY RAND() LIMIT 1
                ");
                $p->execute(array_merge([$user_id], $ids));
                $row = $p->fetch(PDO::FETCH_ASSOC);
            }
        }
    }

    if (!$row) {
        // Fallback: any bottle
        $stmt = $pdo->prepare("
            SELECT b.id AS bottle_id, b.wine_id, b.vintage, b.location
            FROM bottles b
            WHERE b.user_id = ?
            ORDER BY RAND() LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) { echo json_encode(['bottle'=>null]); exit; }

    // Enrich with wine details if we can
    if (!empty($row['wine_id']) && isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        $w = $winelist_pdo->prepare("SELECT name, winery, region, type, vintage FROM wines WHERE id = ?");
        $w->execute([$row['wine_id']]);
        if ($info = $w->fetch(PDO::FETCH_ASSOC)) {
            $row = array_merge($row, $info);
        }
    }

    echo json_encode(['bottle'=>$row]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Failed to pick a bottle','detail'=>$e->getMessage()]);
}
