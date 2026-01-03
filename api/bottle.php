<?php
/**
 * /api/bottle.php — Get single bottle details with AI insights
 *
 * GET /api/bottle.php?id=123
 * Authorization: Bearer <token> (or session)
 *
 * Response:
 * {
 *   "ok": true,
 *   "bottle": {
 *     "id": 123,
 *     "wine_id": 456,
 *     "name": "Opus One",
 *     "winery": "Opus One Winery",
 *     "vintage": 2019,
 *     ...
 *     "ai_insights": {
 *       "notes_md": "Deep ruby color...",
 *       "pairings": ["beef", "lamb"],
 *       "drink_from": "2024",
 *       "drink_to": "2035"
 *     }
 *   }
 * }
 *
 * PUT /api/bottle.php?id=123 — Update bottle
 * DELETE /api/bottle.php?id=123 — Delete bottle
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/db.php';
require_once __DIR__ . '/api_auth_middleware.php';

add_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$bottleId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($bottleId <= 0) {
    api_error(400, 'missing_id', 'Bottle ID is required');
}

$userId = get_authenticated_user_id();

// GET — Fetch bottle details
if ($method === 'GET') {
    try {
        // Fetch bottle
        $stmt = $pdo->prepare("
            SELECT 
                b.id AS bottle_id,
                b.wine_id,
                b.name,
                b.winery,
                b.region,
                b.country,
                b.grapes,
                b.vintage,
                b.type,
                b.style,
                b.photo_path,
                b.image_url,
                b.price_paid,
                b.my_rating,
                b.my_review,
                b.location,
                b.purchase_date,
                b.drink_to,
                IFNULL(b.past, 0) AS past,
                b.added_on,
                b.updated_at
            FROM bottles b
            WHERE b.id = :id AND b.user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':id' => $bottleId, ':uid' => $userId]);
        $bottle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bottle) {
            api_error(404, 'not_found', 'Bottle not found');
        }

        // Determine best image
        $thumb = '';
        if (!empty($bottle['photo_path'])) {
            $thumb = $bottle['photo_path'];
        } elseif (!empty($bottle['image_url'])) {
            $thumb = $bottle['image_url'];
        }
        $bottle['thumb'] = $thumb;

        // Enrich from catalog if we have wine_id
        $catalogData = null;
        if (!empty($bottle['wine_id']) && isset($winelist_pdo)) {
            try {
                $catStmt = $winelist_pdo->prepare("
                    SELECT 
                        w.name, w.winery, w.region, w.country, w.grapes, 
                        w.vintage, w.type, w.style, w.image_url, w.rating, w.price
                    FROM wines w
                    WHERE w.id = :wid OR w.wine_id = :wid
                    LIMIT 1
                ");
                $catStmt->execute([':wid' => $bottle['wine_id']]);
                $catalogData = $catStmt->fetch(PDO::FETCH_ASSOC);

                // Backfill empty fields from catalog
                if ($catalogData) {
                    foreach (['name', 'winery', 'region', 'country', 'grapes', 'vintage', 'type', 'style'] as $field) {
                        if (empty($bottle[$field]) && !empty($catalogData[$field])) {
                            $bottle[$field] = $catalogData[$field];
                        }
                    }
                    if (empty($bottle['thumb']) && !empty($catalogData['image_url'])) {
                        $bottle['thumb'] = $catalogData['image_url'];
                    }
                    $bottle['catalog_rating'] = $catalogData['rating'] ?? null;
                    $bottle['catalog_price'] = $catalogData['price'] ?? null;
                }
            } catch (PDOException $e) {
                // Catalog enrichment is optional
            }
        }

        // Fetch AI insights
        $aiInsights = null;
        if (!empty($bottle['wine_id']) && isset($winelist_pdo)) {
            try {
                $aiStmt = $winelist_pdo->prepare("
                    SELECT notes_md, pairings_json, drink_from, drink_to, investability_score
                    FROM wines_ai
                    WHERE wine_id = :wid
                    LIMIT 1
                ");
                $aiStmt->execute([':wid' => $bottle['wine_id']]);
                $ai = $aiStmt->fetch(PDO::FETCH_ASSOC);

                if ($ai) {
                    $pairings = [];
                    if (!empty($ai['pairings_json'])) {
                        $decoded = json_decode($ai['pairings_json'], true);
                        if (is_array($decoded)) {
                            $pairings = $decoded;
                        }
                    }

                    $aiInsights = [
                        'notes_md' => $ai['notes_md'] ?? null,
                        'pairings' => $pairings,
                        'drink_from' => $ai['drink_from'] ?? null,
                        'drink_to' => $ai['drink_to'] ?? null,
                        'investability_score' => $ai['investability_score'] ?? null,
                    ];
                }
            } catch (PDOException $e) {
                // AI insights are optional
            }
        }

        $bottle['ai_insights'] = $aiInsights;

        // Clean up internal fields
        unset($bottle['photo_path'], $bottle['image_url']);

        // Cast types
        $bottle['bottle_id'] = (int)$bottle['bottle_id'];
        $bottle['wine_id'] = $bottle['wine_id'] ? (int)$bottle['wine_id'] : null;
        $bottle['vintage'] = $bottle['vintage'] ? (int)$bottle['vintage'] : null;
        $bottle['price_paid'] = $bottle['price_paid'] !== null ? (float)$bottle['price_paid'] : null;
        $bottle['my_rating'] = $bottle['my_rating'] !== null ? (float)$bottle['my_rating'] : null;
        $bottle['past'] = (bool)$bottle['past'];

        api_success(['bottle' => $bottle]);

    } catch (Throwable $e) {
        error_log('[api/bottle GET] Error: ' . $e->getMessage());
        api_error(500, 'server_error', 'Failed to fetch bottle');
    }
}

// PUT — Update bottle
if ($method === 'PUT') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        // Verify ownership
        $check = $pdo->prepare("SELECT id FROM bottles WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $bottleId, ':uid' => $userId]);
        if (!$check->fetch()) {
            api_error(404, 'not_found', 'Bottle not found');
        }

        // Allowed fields to update
        $allowed = [
            'name', 'winery', 'region', 'country', 'grapes', 'vintage', 'type', 'style',
            'price_paid', 'my_rating', 'my_review', 'location', 'purchase_date', 'drink_to', 'past'
        ];

        $updates = [];
        $params = [':id' => $bottleId, ':uid' => $userId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = "`$field` = :$field";
                $params[":$field"] = $input[$field];
            }
        }

        if (empty($updates)) {
            api_error(400, 'no_changes', 'No valid fields to update');
        }

        $updates[] = "updated_at = NOW()";
        $sql = "UPDATE bottles SET " . implode(', ', $updates) . " WHERE id = :id AND user_id = :uid";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        api_success(['message' => 'Bottle updated', 'updated_fields' => array_keys(array_intersect_key($input, array_flip($allowed)))]);

    } catch (Throwable $e) {
        error_log('[api/bottle PUT] Error: ' . $e->getMessage());
        api_error(500, 'server_error', 'Failed to update bottle');
    }
}

// DELETE — Delete bottle
if ($method === 'DELETE') {
    try {
        $stmt = $pdo->prepare("DELETE FROM bottles WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $bottleId, ':uid' => $userId]);

        if ($stmt->rowCount() === 0) {
            api_error(404, 'not_found', 'Bottle not found');
        }

        api_success(['message' => 'Bottle deleted']);

    } catch (Throwable $e) {
        error_log('[api/bottle DELETE] Error: ' . $e->getMessage());
        api_error(500, 'server_error', 'Failed to delete bottle');
    }
}

// POST — Toggle past status (legacy support)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    if ($action === 'toggle_past') {
        try {
            $stmt = $pdo->prepare("UPDATE bottles SET past = 1 - IFNULL(past, 0) WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $bottleId, ':uid' => $userId]);
            api_success(['message' => 'Past status toggled']);
        } catch (Throwable $e) {
            api_error(500, 'server_error', 'Failed to toggle status');
        }
    } else {
        api_error(400, 'unknown_action', 'Unknown action');
    }
}

api_error(405, 'method_not_allowed', 'Method not allowed');