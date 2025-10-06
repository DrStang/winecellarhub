<?php
// export_inventory.php — CSV export for the current user's bottles
declare(strict_types=1);
ini_set('display_errors','0');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo "Not authenticated";
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "DB not available";
    exit;
}

// Optional catalog connection (winelist) like inventory_list.php
$catalogConn = null;
if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
    $catalogConn = $winelist_pdo;
} elseif (isset($winelist_dsn, $winelist_user, $winelist_pass)) {
    try {
        $catalogConn = new PDO($winelist_dsn, $winelist_user, $winelist_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $catalogConn = null; // skip enrichment if fails
    }
}

// Pull bottles
$st = $pdo->prepare("
    SELECT
      b.id            AS bottle_id,
      b.wine_id       AS catalog_wine_id,
      b.name,
      b.winery,
      b.region,
      b.grapes,
      b.vintage,
      b.photo_path,
      b.image_url,
      b.price_paid,
      b.my_rating,
      b.location,
      IFNULL(b.past,0) AS past
    FROM bottles b
    WHERE b.user_id = :uid
    ORDER BY b.vintage DESC, b.name ASC
");
$st->execute([':uid'=>$userId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Enrich type from catalog (if available)
$typeByWineId = [];
if ($catalogConn) {
    // Collect IDs to look up
    $ids = [];
    foreach ($rows as $r) {
        if (!empty($r['catalog_wine_id'])) $ids[(int)$r['catalog_wine_id']] = true;
    }
    if ($ids) {
        // Query both 'id' and 'wine_id' (some catalogs use either)
        $ph = []; $bind = [];
        $i = 0;
        foreach (array_keys($ids) as $wid) {
            $i++; $key = ":w{$i}";
            $ph[] = $key; $bind[$key] = (int)$wid;
            if ($i >= 1000) break;
        }
        $sql = "
            SELECT id, wine_id, type
            FROM wines
            WHERE (id IN (".implode(',', $ph).") OR wine_id IN (".implode(',', $ph)."))
        ";
        $stw = $catalogConn->prepare($sql);
        foreach ($bind as $k=>$v) $stw->bindValue($k,$v,PDO::PARAM_INT);
        $stw->execute();
        while ($row = $stw->fetch(PDO::FETCH_ASSOC)) {
            $key = (int)($row['id'] ?: $row['wine_id'] ?: 0);
            if ($key > 0 && !empty($row['type'])) $typeByWineId[$key] = $row['type'];
        }
    }
}

// Send CSV
$filename = 'inventory_export_'.date('Ymd_His').'.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out = fopen('php://output', 'w');
// Header
fputcsv($out, [
    'bottle_id','wine_id','winery','name','vintage','region','grapes','type',
    'price_paid','my_rating','location','past','photo_path','image_url'
]);

foreach ($rows as $r) {
    $type = '';
    $wid  = (int)($r['catalog_wine_id'] ?? 0);
    if ($wid && isset($typeByWineId[$wid])) $type = $typeByWineId[$wid];

    fputcsv($out, [
        $r['bottle_id'] ?? '',
        $r['catalog_wine_id'] ?? '',
        $r['winery'] ?? '',
        $r['name'] ?? '',
        $r['vintage'] ?? '',
        $r['region'] ?? '',
        $r['grapes'] ?? '',
        $type,
        $r['price_paid'] ?? '',
        $r['my_rating'] ?? '',
        $r['location'] ?? '',
        (int)($r['past'] ?? 0),
        $r['photo_path'] ?? '',
        $r['image_url'] ?? '',
    ]);
}
fclose($out);
