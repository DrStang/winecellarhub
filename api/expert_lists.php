<?php
// /api/expert_lists.php
// API endpoint for mobile app Expert Lists feature

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
$action = $_GET['action'] ?? 'wines';

try {
    if ($action === 'tabs') {
        // Return available expert list tabs
        echo json_encode(['tabs' => getExpertListTabs($winelist_pdo)]);
    } else {
        // Return wines for a specific list
        $listKey = $_GET['t'] ?? '';
        $typeFilter = $_GET['type'] ?? null;
        $limit = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : null;

        if (empty($listKey)) {
            http_response_code(400);
            echo json_encode(['error' => 'List key (t) required']);
            exit;
        }

        $result = getExpertListWines($winelist_pdo, $listKey, $typeFilter, $limit);
        echo json_encode($result);
    }
} catch (Throwable $e) {
    error_log('[expert_lists API] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get available expert list tabs
 */
function getExpertListTabs(PDO $pdo): array {
    $tabs = [];

    // Decanter World Wine Awards
    $decYears = [2024, 2023];
    foreach ($decYears as $year) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM expert_picks 
            WHERE (source LIKE 'Decanter%' OR list_name LIKE 'DWWA%')
              AND year = ?
              AND medal IN ('Best in Show', 'Platinum')
        ");
        $stmt->execute([$year]);
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            $tabs[] = [
                'key' => "dec_$year",
                'label' => "Decanter Wine Awards $year",
                'count' => $count,
            ];
        }
    }

    // Wine Spectator Top 100
    $wsYears = [2024, 2023];
    foreach ($wsYears as $year) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM expert_picks 
            WHERE (source LIKE 'Wine Spectator%' OR list_name LIKE '%Wine Spectator Top 100%')
              AND (list_name LIKE 'Top 100%' OR list_name LIKE '%Top 100%')
              AND year = ?
        ");
        $stmt->execute([$year]);
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            $tabs[] = [
                'key' => "ws_$year",
                'label' => "Wine Spectator Top 100 $year",
                'count' => $count,
            ];
        }
    }

    // Wine Enthusiast Best Wines
    $weYears = [2024, 2023];
    foreach ($weYears as $year) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM expert_picks 
            WHERE (source LIKE 'Wine Enthusiast%' OR list_name LIKE '%Wine Enthusiast%')
              AND year = ?
        ");
        $stmt->execute([$year]);
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            $tabs[] = [
                'key' => "we_$year",
                'label' => "Wine Enthusiast Best of $year",
                'count' => $count,
            ];
        }
    }

    return $tabs;
}

/**
 * Get wines for a specific expert list
 */
function getExpertListWines(PDO $pdo, string $listKey, ?string $typeFilter, ?int $limit): array {
    $wines = [];
    $subtitle = '';

    // Parse list key (format: source_year, e.g., "dec_2024")
    if (preg_match('/^dec_(\d{4})$/', $listKey, $m)) {
        $year = (int) $m[1];
        $subtitle = "Decanter DWWA $year — Best in Show & Platinum";

        $sql = "
            SELECT ep.id, ep.source, ep.year, ep.list_name, ep.medal, ep.score, ep.wine_id,
                   ep.name, ep.winery, ep.region, ep.type, ep.vintage, ep.country, 
                   ep.notes, ep.grapes, ep.rank, ep.image_url,
                   w.price, w.image_url AS wine_image_url
            FROM expert_picks ep
            LEFT JOIN wines w ON w.id = ep.wine_id
            WHERE (ep.source LIKE 'Decanter%' OR ep.list_name LIKE 'DWWA%')
              AND ep.year = :year
              AND ep.medal IN ('Best in Show', 'Platinum')
        ";

        if ($typeFilter) {
            $sql .= " AND LOWER(ep.type) = LOWER(:type)";
        }

        $sql .= " ORDER BY CASE ep.medal WHEN 'Best in Show' THEN 1 WHEN 'Platinum' THEN 2 ELSE 3 END,
                          ep.score DESC, ep.name ASC";

        if ($limit) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        if ($typeFilter) {
            $stmt->bindValue(':type', $typeFilter);
        }
        if ($limit) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $wines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (preg_match('/^ws_(\d{4})$/', $listKey, $m)) {
        $year = (int) $m[1];
        $subtitle = "Wine Spectator Top 100 $year";

        $sql = "
            SELECT ep.id, ep.source, ep.year, ep.list_name, ep.medal, ep.score, ep.wine_id,
                   ep.name, ep.winery, ep.region, ep.type, ep.vintage, ep.country, 
                   ep.notes, ep.grapes, ep.rank, ep.image_url,
                   w.price, w.image_url AS wine_image_url
            FROM expert_picks ep
            LEFT JOIN wines w ON w.id = ep.wine_id
            WHERE (ep.source LIKE 'Wine Spectator%' OR ep.list_name LIKE '%Wine Spectator Top 100%')
              AND (ep.list_name LIKE 'Top 100%' OR ep.list_name LIKE '%Top 100%')
              AND ep.year = :year
        ";

        if ($typeFilter) {
            $sql .= " AND LOWER(ep.type) = LOWER(:type)";
        }

        $sql .= " ORDER BY CAST(ep.rank AS UNSIGNED) ASC, ep.name ASC";

        if ($limit) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        if ($typeFilter) {
            $stmt->bindValue(':type', $typeFilter);
        }
        if ($limit) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $wines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (preg_match('/^we_(\d{4})$/', $listKey, $m)) {
        $year = (int) $m[1];
        $subtitle = "Wine Enthusiast Best Wines of $year";

        $sql = "
            SELECT ep.id, ep.source, ep.year, ep.list_name, ep.medal, ep.score, ep.wine_id,
                   ep.name, ep.winery, ep.region, ep.type, ep.vintage, ep.country, 
                   ep.notes, ep.grapes, ep.rank, ep.image_url,
                   w.price, w.image_url AS wine_image_url
            FROM expert_picks ep
            LEFT JOIN wines w ON w.id = ep.wine_id
            WHERE (ep.source LIKE 'Wine Enthusiast%' OR ep.list_name LIKE '%Wine Enthusiast%')
              AND ep.year = :year
        ";

        if ($typeFilter) {
            $sql .= " AND LOWER(ep.type) = LOWER(:type)";
        }

        $sql .= " ORDER BY CAST(ep.rank AS UNSIGNED) ASC, ep.name ASC";

        if ($limit) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        if ($typeFilter) {
            $stmt->bindValue(':type', $typeFilter);
        }
        if ($limit) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $wines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Ensure image_url fallback - try wines table if expert_picks doesn't have it
    foreach ($wines as &$wine) {
        if (empty($wine['image_url']) && !empty($wine['wine_image_url'])) {
            $wine['image_url'] = $wine['wine_image_url'];
        }
        if (empty($wine['image_url'])) {
            $wine['image_url'] = 'https://winecellarhub.com/assets/placeholder-bottle.png';
        }
        // Clean up the extra field
        unset($wine['wine_image_url']);
    }

    return [
        'wines' => $wines,
        'subtitle' => $subtitle,
    ];
}