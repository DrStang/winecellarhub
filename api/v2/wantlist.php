<?php
/**
 * /api/v2/wantlist.php — Wantlist API with JWT + session auth support
 *
 * GET /api/v2/wantlist.php — List wantlist items
 * POST /api/v2/wantlist.php — Add item to wantlist
 * DELETE /api/v2/wantlist.php?id=123 — Remove item
 * POST /api/v2/wantlist.php (action=move_to_inventory) — Move to inventory
 *
 * Authorization: Bearer <token>
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db.php';
require_once dirname(__DIR__) . '/api_auth_middleware.php';

add_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$userId = get_authenticated_user_id();

// GET — List wantlist
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, wine_id, name, winery, region, type, vintage, notes, added_on
            FROM wantlist
            WHERE user_id = :uid
            ORDER BY added_on DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Type cast
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['wine_id'] = $item['wine_id'] ? (int)$item['wine_id'] : null;
            $item['vintage'] = $item['vintage'] ? (int)$item['vintage'] : null;
        }

        api_success([
            'count' => count($items),
            'items' => $items,
        ]);

    } catch (Throwable $e) {
        error_log('[api/v2/wantlist GET] Error: ' . $e->getMessage());
        api_error(500, 'server_error', 'Failed to fetch wantlist');
    }
}

// POST — Add item or perform action
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? 'add';

    // Move to inventory
    if ($action === 'move_to_inventory') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            api_error(400, 'missing_id', 'Wantlist item ID is required');
        }

        try {
            // Fetch wantlist item
            $stmt = $pdo->prepare("SELECT * FROM wantlist WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                api_error(404, 'not_found', 'Wantlist item not found');
            }

            // Detect bottles table columns
            $cols = $pdo->query("SHOW COLUMNS FROM bottles")->fetchAll(PDO::FETCH_ASSOC);
            $fields = array_map(fn($c) => strtolower($c['Field']), $cols);

            $columns = ['user_id'];
            $values = [$userId];

            if (in_array('wine_id', $fields) && !empty($item['wine_id'])) {
                $columns[] = 'wine_id';
                $values[] = (int)$item['wine_id'];
            }
            if (in_array('name', $fields) && !empty($item['name'])) {
                $columns[] = 'name';
                $values[] = trim($item['name']);
            }
            if (in_array('winery', $fields) && !empty($item['winery'])) {
                $columns[] = 'winery';
                $values[] = trim($item['winery']);
            }
            if (in_array('region', $fields) && !empty($item['region'])) {
                $columns[] = 'region';
                $values[] = trim($item['region']);
            }
            if (in_array('type', $fields) && !empty($item['type'])) {
                $columns[] = 'type';
                $values[] = trim($item['type']);
            }
            if (in_array('vintage', $fields) && !empty($item['vintage'])) {
                $columns[] = 'vintage';
                $values[] = trim($item['vintage']);
            }

            $colsSql = implode(',', $columns);
            $placeholders = implode(',', array_fill(0, count($columns), '?'));

            $ins = $pdo->prepare("INSERT INTO bottles ($colsSql) VALUES ($placeholders)");
            $ins->execute($values);
            $bottleId = (int)$pdo->lastInsertId();

            // Remove from wantlist
            $del = $pdo->prepare("DELETE FROM wantlist WHERE id = :id AND user_id = :uid");
            $del->execute([':id' => $id, ':uid' => $userId]);

            api_success([
                'message' => 'Moved to inventory',
                'bottle_id' => $bottleId,
            ]);

        } catch (Throwable $e) {
            error_log('[api/v2/wantlist move] Error: ' . $e->getMessage());
            api_error(500, 'server_error', 'Failed to move to inventory');
        }
    }

    // Add new item
    if ($action === 'add' || $action === 'add_manual' || $action === 'add_from_catalog') {
        try {
            $wineId = !empty($input['wine_id']) ? (int)$input['wine_id'] : null;
            $name = trim((string)($input['name'] ?? ''));
            $winery = trim((string)($input['winery'] ?? ''));
            $region = trim((string)($input['region'] ?? ''));
            $type = trim((string)($input['type'] ?? ''));
            $vintage = trim((string)($input['vintage'] ?? ''));
            $notes = trim((string)($input['notes'] ?? ''));

            if ($name === '' && $wineId === null) {
                api_error(400, 'missing_data', 'Name or wine_id is required');
            }

            $stmt = $pdo->prepare("
                INSERT INTO wantlist (user_id, wine_id, name, winery, region, type, vintage, notes)
                VALUES (:uid, :wine_id, :name, :winery, :region, :type, :vintage, :notes)
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':wine_id' => $wineId,
                ':name' => $name,
                ':winery' => $winery,
                ':region' => $region,
                ':type' => $type,
                ':vintage' => $vintage,
                ':notes' => $notes,
            ]);

            $id = (int)$pdo->lastInsertId();

            api_success([
                'message' => 'Added to wantlist',
                'id' => $id,
            ]);

        } catch (Throwable $e) {
            error_log('[api/v2/wantlist add] Error: ' . $e->getMessage());
            api_error(500, 'server_error', 'Failed to add to wantlist');
        }
    }

    api_error(400, 'unknown_action', 'Unknown action');
}

// DELETE — Remove item
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);

    // Also check body for id
    if ($id <= 0) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($input['id'] ?? 0);
    }

    if ($id <= 0) {
        api_error(400, 'missing_id', 'Wantlist item ID is required');
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM wantlist WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        if ($stmt->rowCount() === 0) {
            api_error(404, 'not_found', 'Wantlist item not found');
        }

        api_success(['message' => 'Removed from wantlist']);

    } catch (Throwable $e) {
        error_log('[api/v2/wantlist delete] Error: ' . $e->getMessage());
        api_error(500, 'server_error', 'Failed to remove from wantlist');
    }
}

api_error(405, 'method_not_allowed', 'Method not allowed');
