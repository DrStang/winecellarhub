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

// Get action parameter
$action = $_GET['action'] ?? 'stats';

try {
    switch ($action) {
        case 'stats':
            // Return all discover stats in one call (for initial load)
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

            $result = browseByCategory($winelist_pdo, $category, $value, $limit, $offset);
            echo json_encode($result);
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

/**
 * Get trending wines (most added to cellars recently)
 */
function getTrendingWines(PDO $pdo, int $limit): array {
    // Wines most frequently added to user inventories in the last 30 days
    $sql = "
        SELECT 
            w.id, w.name, w.winery, w.region, w.country, w.type, 
            w.grapes, w.vintage, w.price, w.image_url,
            COUNT(DISTINCT i.id) AS add_count
        FROM wines w
        INNER JOIN inventory i ON i.wine_id = w.id
        WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY w.id
        ORDER BY add_count DESC, w.name ASC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If not enough trending, supplement with popular wines
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

/**
 * Get new arrivals (recently added to catalog)
 */
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

    // If not enough new arrivals, get any recent wines
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

/**
 * Get staff picks (curated selection)
 * Uses wines from expert lists or high-rated wines
 */
function getStaffPicks(PDO $pdo, int $limit): array {
    // First try to get wines from expert picks
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

    // Add a staff pick reason if missing
    foreach ($results as &$wine) {
        if (empty($wine['reason'])) {
            $reasons = [
                'Outstanding value for the quality',
                'Perfect for special occasions',
                'A staff favorite - always delivers',
                'Exceptional expression of the region',
                'Crowd-pleaser that impresses every time',
            ];
            $wine['reason'] = $reasons[array_rand($reasons)];
        }
    }

    // If not enough, supplement with highly-rated wines
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

/**
 * Get wine types with counts
 */
function getWineTypes(PDO $pdo): array {
    $sql = "
        SELECT 
            COALESCE(NULLIF(TRIM(type), ''), 'Other') AS name,
            COUNT(*) AS count
        FROM wines
        WHERE type IS NOT NULL AND TRIM(type) != ''
        GROUP BY name
        ORDER BY count DESC
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get top regions with counts
 */
function getTopRegions(PDO $pdo, int $limit): array {
    $sql = "
        SELECT 
            region AS name,
            COUNT(*) AS count
        FROM wines
        WHERE region IS NOT NULL AND TRIM(region) != ''
        GROUP BY region
        ORDER BY count DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get price range distribution
 */
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

/**
 * Browse wines by category (type, region, or price)
 */
function browseByCategory(PDO $pdo, string $category, string $value, int $limit, int $offset): array {
    $where = "1=1";
    $params = [];

    switch ($category) {
        case 'type':
            $where = "LOWER(type) = LOWER(:value)";
            $params[':value'] = $value;
            break;

        case 'region':
            $where = "LOWER(region) = LOWER(:value)";
            $params[':value'] = $value;
            break;

        case 'price':
            // Value format: "min-max" e.g., "20-50"
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

    // Get total count
    $countSql = "SELECT COUNT(*) FROM wines WHERE $where";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Get wines
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
