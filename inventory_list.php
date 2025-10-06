<?php
// /api/inventory_list.php — inventory data + actions (toggle past / delete) + filters
declare(strict_types=1);
@ini_set('display_errors','0');
@error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

try {
    $root = __DIR__ === '.' ? dirname(__DIR__) : dirname(__DIR__);
    require_once $root . '/db.php';
    @require_once $root . '/auth.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Missing $pdo (Wine DB).');
    }

    // Optional separate connection to catalog (winelist)
    $catalogConn = null;
    if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        $catalogConn = $winelist_pdo;
    } elseif (isset($winelist_dsn, $winelist_user, $winelist_pass)) {
        $catalogConn = new PDO($winelist_dsn, $winelist_user, $winelist_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    $userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
        exit;
    }

    // -------- ACTIONS (POST) --------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $bid    = (int)($_POST['bottle_id'] ?? 0);

        if ($bid <= 0) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'Invalid bottle id']);
            exit;
        }

        if ($action === 'toggle_past') {
            $st = $pdo->prepare("UPDATE bottles SET past = 1 - IFNULL(past,0) WHERE id = :id AND user_id = :uid");
            $st->execute([':id'=>$bid, ':uid'=>$userId]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        if ($action === 'delete') {
            $st = $pdo->prepare("DELETE FROM bottles WHERE id = :id AND user_id = :uid");
            $st->execute([':id'=>$bid, ':uid'=>$userId]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unknown action']);
        exit;
    }

    // -------- META (types) --------
    if (isset($_GET['meta']) && $_GET['meta'] === 'types') {
        $types = [];
        if ($catalogConn) {
            $wineIds = [];
            $st = $pdo->prepare("SELECT DISTINCT wine_id FROM bottles WHERE user_id = :uid AND wine_id IS NOT NULL");
            $st->execute([':uid'=>$userId]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($r['wine_id'])) $wineIds[(int)$r['wine_id']] = true;
            }
            if ($wineIds) {
                $ph = [];
                $bind = [];
                $i=0;
                foreach (array_keys($wineIds) as $wid) {
                    $k=":id$i"; $i++;
                    $ph[]=$k; $bind[$k]=(int)$wid;
                    if ($i>=1000) break;
                }
                $sql = "SELECT DISTINCT type FROM wines
                        WHERE (id IN (".implode(',', $ph).") OR wine_id IN (".implode(',', $ph)."))
                          AND type IS NOT NULL AND type <> ''
                        ORDER BY type";
                $stw = $catalogConn->prepare($sql);
                foreach ($bind as $k=>$v) $stw->bindValue($k,$v,PDO::PARAM_INT);
                $stw->execute();
                while ($row = $stw->fetch(PDO::FETCH_ASSOC)) $types[] = $row['type'];
            }
        }
        echo json_encode(['ok'=>true, 'types'=>$types]);
        exit;
    }

    // -------- LIST (GET) --------
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = (int)($_GET['pageSize'] ?? 24);
    if ($pageSize < 6)  $pageSize = 6;
    if ($pageSize > 60) $pageSize = 60;

    $aliases = ['search','q','query','term','s'];
    $q = '';
    foreach ($aliases as $k) {
        if (isset($_GET[$k]) && trim((string)$_GET[$k]) !== '') {
            $q = trim((string)$_GET[$k]);
            break;
        }
    }

    $status   = $_GET['status'] ?? null;   // current | past | all | null
    $typeFilt = trim((string)($_GET['type'] ?? ''));
    // If user is searching but didn't explicitly pass status, search across all
    if ($q !== '' && $status === null) {
        $status = 'all';
    }
    if ($status === null) $status = 'current';

    $offset = ($page - 1) * $pageSize;
    // Build a set of catalog IDs to filter by (search/type)
    $catalogWineIds = [];
    if ($catalogConn) {
        if ($q !== '') {
            $stc = $catalogConn->prepare("
                SELECT id, wine_id
                FROM wines
                WHERE name   LIKE :q
                   OR winery LIKE :q
                   OR region LIKE :q
                   OR country LIKE :q
                   OR grapes LIKE :q
                   OR style  LIKE :q
                   OR type   LIKE :q
                LIMIT 1000
            ");
            $stc->execute([':q'=>'%'.$q.'%']);
            while ($row = $stc->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['id']))      $catalogWineIds[(int)$row['id']] = true;
                if (!empty($row['wine_id'])) $catalogWineIds[(int)$row['wine_id']] = true;
            }
        }
        if ($typeFilt !== '') {
            $stt = $catalogConn->prepare("SELECT id, wine_id FROM wines WHERE type = :t LIMIT 5000");
            $stt->execute([':t'=>$typeFilt]);
            while ($row = $stt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['id']))      $catalogWineIds[(int)$row['id']] = true;
                if (!empty($row['wine_id'])) $catalogWineIds[(int)$row['wine_id']] = true;
            }
        }
    }

    // WHERE for bottles
    $where = "b.user_id = :uid";
    $params = [':uid'=>$userId];

    if ($status === 'current') {
        $where .= " AND IFNULL(b.past,0) = 0";
    } elseif ($status === 'past') {
        $where .= " AND IFNULL(b.past,0) = 1";
    }

    if ($q !== '') {
        $where .= " AND ( b.name LIKE :q OR b.winery LIKE :q OR b.region LIKE :q OR b.grapes LIKE :q OR b.location LIKE :q OR CAST(b.vintage AS CHAR) LIKE :q OR b.country LIKE :q OR b.style LIKE :q";
        $params[':q'] = '%'.$q.'%';
        if (!empty($catalogWineIds)) {
            $inPhs = [];
            $i=0;
            foreach (array_keys($catalogWineIds) as $wid) {
                $ph=":wid_$i"; $i++;
                $inPhs[]=$ph; $params[$ph]=(int)$wid;
                if ($i>=1000) break;
            }
            if ($inPhs) $where .= " OR b.wine_id IN (".implode(',', $inPhs).")";
        }
        $where .= " )";
    }

    if ($typeFilt !== '' && !empty($catalogWineIds)) {
        $inPhs = [];
        $j=0;
        foreach (array_keys($catalogWineIds) as $wid) {
            $ph=":twid_$j"; $j++;
            $inPhs[]=$ph; $params[$ph]=(int)$wid;
            if ($j>=1000) break;
        }
        if ($inPhs) {
            $where .= " AND b.wine_id IN (".implode(',', $inPhs).")";
        } else {
            $where .= " AND 1=0";
        }
    }

    // COUNT
    $sqlCount = "SELECT COUNT(*) FROM bottles b WHERE $where";
    $stCount = $pdo->prepare($sqlCount);
    $stCount->execute($params);
    $total = (int)$stCount->fetchColumn();

    // PAGE
    $sql = "
        SELECT
          b.id AS bottle_id,
          b.wine_id,
          b.name, b.winery, b.region, b.grapes, b.vintage,
          b.photo_path, b.image_url AS bottle_image_url,
          b.price_paid, b.my_rating, b.location,
          IFNULL(b.past,0) AS past
        FROM bottles b
        WHERE $where
        ORDER BY b.vintage DESC, b.name ASC
        LIMIT :limit OFFSET :offset
    ";
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) { $st->bindValue($k,$v); }
    $st->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // -------- ENRICH FROM CATALOG (now includes text fields) --------
    // Build a map from catalog keyed by the *bottle's* wine_id, preferring exact matches on wines.wine_id
    $catalogMap = []; // wine_id => row
    if ($catalogConn && $rows) {
        $wantIds = [];
        foreach ($rows as $r) {
            if (!empty($r['wine_id'])) $wantIds[(int)$r['wine_id']] = true;
        }
        if ($wantIds) {
            $phA=[]; $bA=[]; $i=0;
            foreach (array_keys($wantIds) as $wid) { $k=":id_$i"; $i++; $phA[]=$k; $bA[$k]=(int)$wid; if ($i>=1000) break; }

            // Pull rich fields to backfill bottle data when blank
            $sqlW = "SELECT id, wine_id, name, winery, region, grapes, vintage, type, image_url
                     FROM wines
                     WHERE id IN (".implode(',', $phA).") OR wine_id IN (".implode(',', $phA).")
                     ORDER BY wine_id IS NULL, id DESC";
            $stw = $catalogConn->prepare($sqlW);
            foreach ($bA as $k=>$v) $stw->bindValue($k,$v,PDO::PARAM_INT);
            $stw->execute();

            // Prefer mapping by wines.wine_id when it exists; fall back to wines.id
            while ($w = $stw->fetch(PDO::FETCH_ASSOC)) {
                $keyWID = !empty($w['wine_id']) ? (int)$w['wine_id'] : null;
                $keyID  = (int)$w['id'];
                if ($keyWID !== null) {
                    // exact wine_id mapping wins
                    $catalogMap[$keyWID] = $w;
                } else {
                    // Only set by id if not already present
                    if (!isset($catalogMap[$keyID])) $catalogMap[$keyID] = $w;
                }
            }
        }
    }

    // Build thumb + type + BACKFILL TEXT FIELDS when bottle has blanks
    foreach ($rows as &$r) {
        $cat = null;
        if (!empty($r['wine_id']) && isset($catalogMap[(int)$r['wine_id']])) {
            $cat = $catalogMap[(int)$r['wine_id']];
        }

        // Preferred image: catalog image -> local photo -> bottle.image_url
        $thumb = '';
        if ($cat && !empty($cat['image_url'])) {
            $thumb = $cat['image_url'];
        }
        if (!$thumb && !empty($r['photo_path'])) {
            $pp = $r['photo_path'];
            $fs = ($pp[0] === '/') ? ($root . $pp) : ($root . '/' . ltrim($pp,'/'));
            if (is_file($fs)) $thumb = $pp;
        }
        if (!$thumb && !empty($r['bottle_image_url'])) {
            $thumb = $r['bottle_image_url'];
        }
        $r['thumb'] = $thumb;


        // Type
        $r['type'] = $cat['type'] ?? '';

        // BACKFILL name/winery/region/grapes/vintage if bottle fields are empty
        // BACKFILL name/winery/region/grapes/vintage/country/style if bottle fields are empty
        if ($cat) {
            if (empty($r['name'])    && !empty($cat['name']))    $r['name']    = $cat['name'];
            if (empty($r['winery'])  && !empty($cat['winery']))  $r['winery']  = $cat['winery'];
            if (empty($r['region'])  && !empty($cat['region']))  $r['region']  = $cat['region'];
            if (empty($r['grapes'])  && !empty($cat['grapes']))  $r['grapes']  = $cat['grapes'];
            if ((empty($r['vintage']) || $r['vintage'] === '0') && !empty($cat['vintage'])) {
                $r['vintage'] = $cat['vintage'];
            }
            if (empty($r['country']) && !empty($cat['country'])) $r['country'] = $cat['country'];
            if (empty($r['style'])   && !empty($cat['style']))   $r['style']   = $cat['style'];
        }

    }
    unset($r);

    echo json_encode([
        'ok'    => true,
        'page'  => $page,
        'size'  => $pageSize,
        'total' => $total,
        'items' => $rows,
        'debug' => $debug ? ['catalog_used' => (bool)$catalogConn] : null
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    $out = ['ok'=>false,'error'=>'Server error'];
    if ($debug) $out['debug'] = $e->getMessage();
    echo json_encode($out);
}
