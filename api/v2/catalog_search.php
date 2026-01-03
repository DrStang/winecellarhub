<?php
/**
 * /api/v2/catalog_search.php — Search wine catalog
 *
 * GET /api/v2/catalog_search.php?q=opus+one
 * GET /api/v2/catalog_search.php?name=opus&winery=mondavi&vintage=2019
 *
 * Authorization: Bearer <token> (or session)
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

// Auth is optional for catalog search, but we still check
try {
    $userId = get_authenticated_user_id();
} catch (Throwable $e) {
    // Allow unauthenticated catalog search
    $userId = null;
}

// Check if catalog connection exists
if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
    api_error(503, 'catalog_unavailable', 'Wine catalog is not available');
}

try {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 25)));

    // Get search parameters
    $q = trim((string)($_GET['q'] ?? ''));
    $name = trim((string)($_GET['name'] ?? ''));
    $winery = trim((string)($_GET['winery'] ?? ''));
    $vintage = trim((string)($_GET['vintage'] ?? ''));
    $region = trim((string)($_GET['region'] ?? ''));
    $grapes = trim((string)($_GET['grapes'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));

    $wines = [];

    // If simple query provided, use it
    if ($q !== '') {
        // Try fulltext search first, fall back to LIKE
        try {
            $sql = "
                SELECT id, wine_id, name, winery, region, country, grapes, 
                       vintage, type, style, image_url, rating, price
                FROM wines
                WHERE MATCH(name, winery, grapes, region) AGAINST(:q IN NATURAL LANGUAGE MODE)
                   OR name LIKE :qlike OR winery LIKE :qlike OR region LIKE :qlike
                ORDER BY 
                    CASE WHEN name LIKE :qstart THEN 0 ELSE 1 END,
                    rating DESC, vintage DESC
                LIMIT :lim
            ";
            $stmt = $winelist_pdo->prepare($sql);
            $stmt->bindValue(':q', $q, PDO::PARAM_STR);
            $stmt->bindValue(':qlike', '%' . $q . '%', PDO::PARAM_STR);
            $stmt->bindValue(':qstart', $q . '%', PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $wines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fulltext might not be available, use LIKE only
            $sql = "
                SELECT id, wine_id, name, winery, region, country, grapes,
                       vintage, type, style, image_url, rating, price
                FROM wines
                WHERE name LIKE :qlike OR winery LIKE :qlike OR region LIKE :qlike OR grapes LIKE :qlike
                ORDER BY rating DESC, vintage DESC
                LIMIT :lim
            ";
            $stmt = $winelist_pdo->prepare($sql);
            $stmt->bindValue(':qlike', '%' . $q . '%', PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $wines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    // Multi-field search
    elseif ($name !== '' || $winery !== '' || $vintage !== '' || $region !== '' || $grapes !== '') {
        $where = [];
        $params = [];

        if ($name !== '') {
            $where[] = "(name LIKE :name OR name LIKE :name_start)";
            $params[':name'] = '%' . $name . '%';
            $params[':name_start'] = $name . '%';
        }
        if ($winery !== '') {
            $where[] = "winery LIKE :winery";
            $params[':winery'] = '%' . $winery . '%';
        }
        if ($vintage !== '') {
            $where[] = "vintage = :vintage";
            $params[':vintage'] = $vintage;
        }
        if ($region !== '') {
            $where[] = "region LIKE :region";
            $params[':region'] = '%' . $region . '%';
        }
        if ($grapes !== '') {
            $where[] = "grapes LIKE :grapes";
            $params[':grapes'] = '%' . $grapes . '%';
        }
        if ($type !== '') {
            $where[] = "type = :type";
            $params[':type'] = $type;
        }

        $sql = "
            SELECT id, wine_id, name, winery, region, country, grapes,
                   vintage, type, style, image_url, rating, price
            FROM wines
            WHERE " . implode(' AND ', $where) . "
            ORDER BY rating DESC, vintage DESC
            LIMIT :lim
        ";

        $stmt = $winelist_pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $wines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        api_error(400, 'missing_query', 'Please provide a search query (q) or field parameters (name, winery, etc.)');
    }

    // Format results
    $results = [];
    foreach ($wines as $w) {
        $results[] = [
            'id' => (int)$w['id'],
            'wine_id' => $w['wine_id'] ? (int)$w['wine_id'] : null,
            'name' => $w['name'] ?? '',
            'winery' => $w['winery'] ?? '',
            'region' => $w['region'] ?? '',
            'country' => $w['country'] ?? '',
            'grapes' => $w['grapes'] ?? '',
            'vintage' => $w['vintage'] ? (int)$w['vintage'] : null,
            'type' => $w['type'] ?? '',
            'style' => $w['style'] ?? '',
            'image_url' => $w['image_url'] ?? '',
            'rating' => $w['rating'] !== null ? (float)$w['rating'] : null,
            'price' => $w['price'] !== null ? (float)$w['price'] : null,
        ];
    }

    api_success([
        'count' => count($results),
        'wines' => $results,
    ]);

} catch (Throwable $e) {
    error_log('[api/v2/catalog_search] Error: ' . $e->getMessage());
    api_error(500, 'server_error', 'Failed to search catalog');
}