<?php
// /api/discover.php
// API endpoint for mobile app Discover tab features

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'stats';

try {
    switch ($action) {
        case 'stats':
            $response = [
                'trending' => getTrendingWines($winelist_pdo, 10),
                'new_arrivals' => getNewArrivals($winelist_pdo, 10),
                'staff_picks' => getStaffPicks($winelist_pdo, 10),
                'types' => getWineTypes($winelist_pdo),
                'regions' => getTopRegions($winelist_pdo, 20),
                'price_ranges' => getPriceRanges($winelist_pdo),
            ];
            echo json_encode($response);
            break;

        case 'trending':
            $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
            echo json_encode(['wines' => getTrendingWines($winelist_pdo, $limit)]);
            break;

        case 'new_arrivals':
            $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
            echo json_encode(['wines' => getNewArrivals($winelist_pdo, $limit)]);
            break;

        case 'staff_picks':
            $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
            echo json_encode(['wines' => getStaffPicks($winelist_pdo, $limit)]);
            break;

        case 'types':
            echo json_encode(['types' => getWineTypes($winelist_pdo)]);
            break;

        case 'regions':
            $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
            echo json_encode(['regions' => getTopRegions($winelist_pdo, $limit)]);
            break;

        case 'price_ranges':
            echo json_encode(['ranges' => getPriceRanges($winelist_pdo)]);
            break;

        case 'browse':
            $category = $_GET['category'] ?? '';
            $value = $_GET['value'] ?? '';
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            echo json_encode(browseByCategory($winelist_pdo, $category, $value, $limit, $offset));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('[discover API] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function getTrendingWines(PDO $pdo, int $limit): array {
    $sql = "
        SELECT 
            w.id, w.name, w.winery, w.region, w.country, w.type, 
            w.grapes, w.vintage, w.price, w.image_url,
            COUNT(DISTINCT b.bottle_id) AS add_count
        FROM wines w
        INNER JOIN bottles b ON b.wine_id = w.id
        WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY w.id
        ORDER BY add_count DESC, w.name ASC
        LIMIT :limit
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $results = [];
    }

    if (count($results) < $limit) {
        $existingIds = array_column($results, 'id');
        $needed = $limit - count($results);

        $fallbackSql = "
            SELECT id, name, winery, region, country, type, grapes, vintage, price, image_url
            FROM wines
            WHERE price IS NOT NULL AND image_url IS NOT NULL
        ";
        if ($existingIds) {
            $fallbackSql .= " AND id NOT IN (" . implode(',', array_map('intval', $existingIds)) . ")";
        }
        $fallbackSql .= " ORDER BY RAND() LIMIT :limit";

        $stmt2 = $pdo->prepare($fallbackSql);
        $stmt2->bindValue(':limit', $needed, PDO::PARAM_INT);
        $stmt2->execute();
        $results = array_merge($results, $stmt2->fetchAll(PDO::FETCH_ASSOC));
    }

    return $results;
}

function getNewArrivals(PDO $pdo, int $limit): array {
    $sql = "
        SELECT id, name, winery, region, country, type, grapes, vintage, price, image_url
        FROM wines
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND image_url IS NOT NULL
        ORDER BY created_at DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($results) < $limit) {
        $existingIds = array_column($results, 'id');
        $needed = $limit - count($results);

        $fallbackSql = "
            SELECT id, name, winery, region, country, type, grapes, vintage, price, image_url
            FROM wines
            WHERE image_url IS NOT NULL
        ";
        if ($existingIds) {
            $fallbackSql .= " AND id NOT IN (" . implode(',', array_map('intval', $existingIds)) . ")";
        }
        $fallbackSql .= " ORDER BY created_at DESC LIMIT :limit";

        $stmt2 = $pdo->prepare($fallbackSql);
        $stmt2->bindValue(':limit', $needed, PDO::PARAM_INT);
        $stmt2->execute();
        $results = array_merge($results, $stmt2->fetchAll(PDO::FETCH_ASSOC));
    }

    return $results;
}

function getStaffPicks(PDO $pdo, int $limit): array {
    $sql = "
        SELECT DISTINCT
            w.id, w.name, w.winery, w.region, w.country, w.type,
            w.grapes, w.vintage, w.price, w.image_url,
            ep.notes AS reason
        FROM wines w
        INNER JOIN expert_picks ep ON ep.wine_id = w.id
        WHERE ep.medal IN ('Best in Show', 'Platinum')
           OR ep.score >= 95
        ORDER BY RAND()
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reasons = [
        'Outstanding value for the quality',
        'Perfect for special occasions',
        'A staff favorite - always delivers',
        'Exceptional expression of the region',
        'Crowd-pleaser that impresses every time',
    ];
    foreach ($results as &$wine) {
        if (empty($wine['reason'])) {
            $wine['reason'] = $reasons[array_rand($reasons)];
        }
    }

    if (count($results) < $limit) {
        $existingIds = array_column($results, 'id');
        $needed = $limit - count($results);

        $fallbackSql = "
            SELECT id, name, winery, region, country, type, grapes, vintage, price, image_url,
                   'Highly recommended by our team' AS reason
            FROM wines
            WHERE price IS NOT NULL AND price >= 25 AND image_url IS NOT NULL
        ";
        if ($existingIds) {
            $fallbackSql .= " AND id NOT IN (" . implode(',', array_map('intval', $existingIds)) . ")";
        }
        $fallbackSql .= " ORDER BY RAND() LIMIT :limit";

        $stmt2 = $pdo->prepare($fallbackSql);
        $stmt2->bindValue(':limit', $needed, PDO::PARAM_INT);
        $stmt2->execute();
        $results = array_merge($results, $stmt2->fetchAll(PDO::FETCH_ASSOC));
    }

    return $results;
}

function getWineTypes(PDO $pdo): array {
    $sql = "
        SELECT 
            LOWER(TRIM(type)) AS raw_type,
            COUNT(*) AS count
        FROM wines
        WHERE type IS NOT NULL AND TRIM(type) != ''
        GROUP BY raw_type
    ";

    $stmt = $pdo->query($sql);
    $rawTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $normalized = [
        'Red' => 0,
        'White' => 0,
        'Rose' => 0,
        'Sparkling' => 0,
        'Dessert' => 0,
        'Fortified' => 0,
    ];

    foreach ($rawTypes as $row) {
        $type = $row['raw_type'];
        $count = (int) $row['count'];

        if (preg_match('/red|cabernet|merlot|pinot noir|syrah|shiraz|malbec|zinfandel|sangiovese|tempranillo|nebbiolo/i', $type)) {
            $normalized['Red'] += $count;
        } elseif (preg_match('/white|chardonnay|sauvignon blanc|riesling|pinot grigio|pinot gris|viognier|gewurz/i', $type)) {
            $normalized['White'] += $count;
        } elseif (preg_match('/rose|pink/i', $type)) {
            $normalized['Rose'] += $count;
        } elseif (preg_match('/sparkling|champagne|prosecco|cava|cremant|brut/i', $type)) {
            $normalized['Sparkling'] += $count;
        } elseif (preg_match('/dessert|sweet|ice wine|late harvest|sauternes|tokaj/i', $type)) {
            $normalized['Dessert'] += $count;
        } elseif (preg_match('/fortified|port|sherry|madeira|marsala|vermouth/i', $type)) {
            $normalized['Fortified'] += $count;
        }
    }

    $result = [];
    foreach ($normalized as $name => $count) {
        if ($count > 0) {
            $result[] = ['name' => $name, 'count' => $count];
        }
    }

    usort($result, fn($a, $b) => $b['count'] - $a['count']);

    return $result;
}

function getTopRegions(PDO $pdo, int $limit): array {
    $sql = "
        SELECT 
            country AS name,
            COUNT(*) AS count
        FROM wines
        WHERE country IS NOT NULL 
          AND TRIM(country) != ''
          AND LENGTH(country) > 2
        GROUP BY country
        ORDER BY count DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPriceRanges(PDO $pdo): array {
    $ranges = [
        ['label' => 'Under $20', 'min' => 0, 'max' => 20],
        ['label' => '$20 - $50', 'min' => 20, 'max' => 50],
        ['label' => '$50 - $100', 'min' => 50, 'max' => 100],
        ['label' => '$100+', 'min' => 100, 'max' => 99999],
    ];

    foreach ($ranges as &$range) {
        $sql = "SELECT COUNT(*) FROM wines WHERE price >= :min AND price < :max";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':min' => $range['min'], ':max' => $range['max']]);
        $range['count'] = (int) $stmt->fetchColumn();
    }

    return $ranges;
}

function browseByCategory(PDO $pdo, string $category, string $value, int $limit, int $offset): array {
    $where = "1=1";
    $params = [];

    switch ($category) {
        case 'type':
            $typeMap = [
                'Red' => 'red|cabernet|merlot|pinot noir|syrah|shiraz|malbec|zinfandel|sangiovese|tempranillo|nebbiolo',
                'White' => 'white|chardonnay|sauvignon blanc|riesling|pinot grigio|pinot gris|viognier|gewurz',
                'Rose' => 'rose|pink',
                'Sparkling' => 'sparkling|champagne|prosecco|cava|cremant|brut',
                'Dessert' => 'dessert|sweet|ice wine|late harvest|sauternes|tokaj',
                'Fortified' => 'fortified|port|sherry|madeira|marsala|vermouth',
            ];
            if (isset($typeMap[$value])) {
                $where = "LOWER(type) REGEXP :pattern";
                $params[':pattern'] = $typeMap[$value];
            } else {
                $where = "LOWER(type) = LOWER(:value)";
                $params[':value'] = $value;
            }
            break;

        case 'region':
            $where = "LOWER(country) = LOWER(:value)";
            $params[':value'] = $value;
            break;

        case 'price':
            $parts = explode('-', $value);
            if (count($parts) === 2) {
                $min = floatval($parts[0]);
                $max = floatval($parts[1]);
                $where = "price >= :min AND price < :max";
                $params[':min'] = $min;
                $params[':max'] = $max;
            }
            break;

        default:
            return ['wines' => [], 'total' => 0];
    }

    $countSql = "SELECT COUNT(*) FROM wines WHERE $where";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT id, name, winery, region, country, type, grapes, vintage, price, image_url
        FROM wines
        WHERE $where
        ORDER BY name ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'wines' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
    ];
}