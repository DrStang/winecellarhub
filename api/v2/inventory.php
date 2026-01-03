<?php
/**
 * /api/v2/inventory.php — Inventory list API with JWT + session auth support
 *
 * This is a mobile-friendly version of inventory_list.php that supports both
 * JWT authentication (for mobile apps) and session auth (for web).
 *
 * GET /api/v2/inventory.php
 * Authorization: Bearer <token>
 *
 * Query params:
 *   - page (default: 1)
 *   - pageSize (default: 24, max: 60)
 *   - status: 'current' | 'past' | 'all' (default: 'current')
 *   - q / search: search term
 *   - type: wine type filter
 *   - sort: 'vintage' | 'name' | 'added' | 'rating' (default: 'vintage')
 *   - order: 'asc' | 'desc' (default: 'desc')
 *
 * Response:
 * {
 *   "ok": true,
 *   "page": 1,
 *   "pageSize": 24,
 *   "total": 42,
 *   "totalPages": 2,
 *   "items": [...]
 * }
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db.php';
require_once dirname(__DIR__) . '/api_auth_middleware.php';

add_cors_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'method_not_allowed', 'Only GET is allowed');
}

$userId = get_authenticated_user_id();

try {
    // Parse query parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = (int)($_GET['pageSize'] ?? $_GET['limit'] ?? 24);
    $pageSize = max(6, min(60, $pageSize));
    $offset = ($page - 1) * $pageSize;

    // Search term
    $searchAliases = ['search', 'q', 'query', 'term', 's'];
    $q = '';
    foreach ($searchAliases as $alias) {
        if (isset($_GET[$alias]) && trim((string)$_GET[$alias]) !== '') {
            $q = trim((string)$_GET[$alias]);
            break;
        }
    }

    // Status filter
    $status = $_GET['status'] ?? null;
    if ($q !== '' && $status === null) {
        $status = 'all'; // Search across all when query provided
    }
    if ($status === null) {
        $status = 'current';
    }

    // Type filter
    $typeFilter = trim((string)($_GET['type'] ?? ''));

    // Sorting
    $sortField = $_GET['sort'] ?? 'vintage';
    $sortOrder = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $sortMap = [
        'vintage' => 'b.vintage',
        'name' => 'b.name',
        'added' => 'b.added_on',
        'rating' => 'b.my_rating',
        'winery' => 'b.winery',
        'region' => 'b.region',
    ];
    $orderBy = ($sortMap[$sortField] ?? 'b.vintage') . ' ' . $sortOrder . ', b.name ASC';

    // Optional catalog connection
    $catalogConn = null;
    if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        $catalogConn = $winelist_pdo;
    }

    // Build catalog wine ID set for filtering by type/search
    $catalogWineIds = [];
    if ($catalogConn && ($q !== '' || $typeFilter !== '')) {
        if ($q !== '') {
            $stc = $catalogConn->prepare("
                SELECT id, wine_id FROM wines
                WHERE name LIKE :q OR winery LIKE :q OR region LIKE :q 
                   OR country LIKE :q OR grapes LIKE :q OR style LIKE :q OR type LIKE :q
                LIMIT 1000
            ");
            $stc->execute([':q' => '%' . $q . '%']);
            while ($row = $stc->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['id'])) $catalogWineIds[(int)$row['id']] = true;
                if (!empty($row['wine_id'])) $catalogWineIds[(int)$row['wine_id']] = true;
            }
        }
        if ($typeFilter !== '') {
            $stt = $catalogConn->prepare("SELECT id, wine_id FROM wines WHERE type = :t LIMIT 5000");
            $stt->execute([':t' => $typeFilter]);
            while ($row = $stt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['id'])) $catalogWineIds[(int)$row['id']] = true;
                if (!empty($row['wine_id'])) $catalogWineIds[(int)$row['wine_id']] = true;
            }
        }
    }

    // Build WHERE clause
    $where = "b.user_id = :uid";
    $params = [':uid' => $userId];

    if ($status === 'current') {
        $where .= " AND IFNULL(b.past, 0) = 0";
    } elseif ($status === 'past') {
        $where .= " AND IFNULL(b.past, 0) = 1";
    }

    if ($q !== '') {
        $where .= " AND (b.name LIKE :q OR b.winery LIKE :q OR b.region LIKE :q 
                   OR b.grapes LIKE :q OR b.location LIKE :q 
                   OR CAST(b.vintage AS CHAR) LIKE :q OR b.country LIKE :q OR b.style LIKE :q";
        $params[':q'] = '%' . $q . '%';

        if (!empty($catalogWineIds)) {
            $inPhs = [];
            $i = 0;
            foreach (array_keys($catalogWineIds) as $wid) {
                $ph = ":wid_$i";
                $inPhs[] = $ph;
                $params[$ph] = (int)$wid;
                if (++$i >= 1000) break;
            }
            if ($inPhs) {
                $where .= " OR b.wine_id IN (" . implode(',', $inPhs) . ")";
            }
        }
        $where .= ")";
    }

    if ($typeFilter !== '' && !empty($catalogWineIds)) {
        $inPhs = [];
        $j = 0;
        foreach (array_keys($catalogWineIds) as $wid) {
            $ph = ":twid_$j";
            $inPhs[] = $ph;
            $params[$ph] = (int)$wid;
            if (++$j >= 1000) break;
        }
        if ($inPhs) {
            $where .= " AND b.wine_id IN (" . implode(',', $inPhs) . ")";
        } else {
            $where .= " AND 1=0";
        }
    }

    // Count total
    $countSql = "SELECT COUNT(*) FROM bottles b WHERE $where";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch page
    $sql = "
        SELECT
            b.id AS bottle_id,
            b.wine_id,
            b.name,
            b.winery,
            b.region,
            b.country,
            b.grapes,
            b.vintage,
            b.photo_path,
            b.image_url AS bottle_image_url,
            b.price_paid,
            b.my_rating,
            b.location,
            IFNULL(b.past, 0) AS past
        FROM bottles b
        WHERE $where
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich from catalog
    $catalogMap = [];
    if ($catalogConn && $rows) {
        $wantIds = [];
        foreach ($rows as $r) {
            if (!empty($r['wine_id'])) {
                $wantIds[(int)$r['wine_id']] = true;
            }
        }

        if ($wantIds) {
            $phA = [];
            $bA = [];
            $i = 0;
            foreach (array_keys($wantIds) as $wid) {
                $k = ":id_$i";
                $phA[] = $k;
                $bA[$k] = (int)$wid;
                if (++$i >= 1000) break;
            }

            $sqlW = "SELECT id, wine_id, name, winery, region, grapes, vintage, type, image_url
                     FROM wines
                     WHERE id IN (" . implode(',', $phA) . ") OR wine_id IN (" . implode(',', $phA) . ")
                     ORDER BY wine_id IS NULL, id DESC";
            $stw = $catalogConn->prepare($sqlW);
            foreach ($bA as $k => $v) {
                $stw->bindValue($k, $v, PDO::PARAM_INT);
            }
            $stw->execute();

            while ($w = $stw->fetch(PDO::FETCH_ASSOC)) {
                $keyWID = !empty($w['wine_id']) ? (int)$w['wine_id'] : null;
                $keyID = (int)$w['id'];
                if ($keyWID !== null) {
                    $catalogMap[$keyWID] = $w;
                } elseif (!isset($catalogMap[$keyID])) {
                    $catalogMap[$keyID] = $w;
                }
            }
        }
    }

    // Build response items
    $items = [];
    foreach ($rows as $r) {
        $cat = null;
        if (!empty($r['wine_id']) && isset($catalogMap[(int)$r['wine_id']])) {
            $cat = $catalogMap[(int)$r['wine_id']];
        }

        // Best thumbnail
        $thumb = '';
        if ($cat && !empty($cat['image_url'])) {
            $thumb = $cat['image_url'];
        }
        if (!$thumb && !empty($r['photo_path'])) {
            $thumb = $r['photo_path'];
        }
        if (!$thumb && !empty($r['bottle_image_url'])) {
            $thumb = $r['bottle_image_url'];
        }

        // Backfill from catalog
        if ($cat) {
            foreach (['name', 'winery', 'region', 'grapes'] as $field) {
                if (empty($r[$field]) && !empty($cat[$field])) {
                    $r[$field] = $cat[$field];
                }
            }
            if ((empty($r['vintage']) || $r['vintage'] === '0') && !empty($cat['vintage'])) {
                $r['vintage'] = $cat['vintage'];
            }
        }

        $items[] = [
            'bottle_id' => (int)$r['bottle_id'],
            'wine_id' => $r['wine_id'] ? (int)$r['wine_id'] : null,
            'name' => $r['name'] ?? '',
            'winery' => $r['winery'] ?? '',
            'region' => $r['region'] ?? '',
            'country' => $r['country'] ?? '',
            'grapes' => $r['grapes'] ?? '',
            'vintage' => $r['vintage'] ? (int)$r['vintage'] : null,
            'type' => $cat['type'] ?? '',
            'thumb' => $thumb,
            'price_paid' => $r['price_paid'] !== null ? (float)$r['price_paid'] : null,
            'my_rating' => $r['my_rating'] !== null ? (float)$r['my_rating'] : null,
            'location' => $r['location'] ?? '',
            'past' => (bool)$r['past'],
        ];
    }

    api_success([
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => $total,
        'totalPages' => (int)ceil($total / $pageSize),
        'items' => $items,
    ]);

} catch (Throwable $e) {
    error_log('[api/v2/inventory] Error: ' . $e->getMessage());
    api_error(500, 'server_error', 'Failed to fetch inventory');
}