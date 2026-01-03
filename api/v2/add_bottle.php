<?php
/**
 * /api/v2/add_bottle.php — Add bottle to inventory with catalog upsert
 *
 * POST /api/v2/add_bottle.php
 * Authorization: Bearer <token> (or session)
 *
 * If user already has a bottle with same wine_id (and same vintage if applicable),
 * increments the quantity instead of creating a duplicate entry.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db.php';
require_once dirname(__DIR__) . '/api_auth_middleware.php';

add_cors_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'method_not_allowed', 'Only POST is allowed');
}

$userId = get_authenticated_user_id();

// Helper functions
function str_or_null($v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function dec_or_null($v): ?float {
    if ($v === null || $v === '') return null;
    $f = (float)$v;
    return $f > 0 ? $f : null;
}

function int_or_null($v): ?int {
    if ($v === null || $v === '') return null;
    $i = (int)$v;
    return $i > 0 ? $i : null;
}

function normalize_vintage($v): ?int {
    if ($v === null || $v === '' || $v === 'NV') return null;
    $v = preg_replace('/[^0-9]/', '', (string)$v);
    if (strlen($v) === 4 && $v >= 1900 && $v <= 2100) {
        return (int)$v;
    }
    return null;
}

/**
 * Find existing bottle for user with same wine_id and vintage
 * Returns bottle_id and current quantity if found, null otherwise
 */
function find_existing_bottle(PDO $pdo, int $userId, int $wineId, ?int $vintage): ?array {
    // Match by wine_id and vintage (both must match, including NULL vintage)
    if ($vintage !== null) {
        $sql = "SELECT id, COALESCE(quantity, 1) as quantity 
                FROM bottles 
                WHERE user_id = ? AND wine_id = ? AND vintage = ? AND IFNULL(past, 0) = 0
                ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $wineId, $vintage]);
    } else {
        $sql = "SELECT id, COALESCE(quantity, 1) as quantity 
                FROM bottles 
                WHERE user_id = ? AND wine_id = ? AND (vintage IS NULL OR vintage = 0) AND IFNULL(past, 0) = 0
                ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $wineId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'quantity' => (int)$row['quantity']] : null;
}

/**
 * Increment quantity of existing bottle
 */
function increment_bottle_quantity(PDO $pdo, int $bottleId, int $addQty): int {
    $stmt = $pdo->prepare("UPDATE bottles SET quantity = COALESCE(quantity, 1) + ? WHERE id = ?");
    $stmt->execute([$addQty, $bottleId]);

    // Return new quantity
    $stmt = $pdo->prepare("SELECT quantity FROM bottles WHERE id = ?");
    $stmt->execute([$bottleId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Find or create wine in catalog, return wine_id
 */
function upsert_catalog(PDO $catalog, array $data): array {
    $name = str_or_null($data['name'] ?? '');
    $winery = str_or_null($data['winery'] ?? '');
    $vintage = normalize_vintage($data['vintage'] ?? null);
    $region = str_or_null($data['region'] ?? '');
    $country = str_or_null($data['country'] ?? '');
    $grapes = str_or_null($data['grapes'] ?? '');
    $type = str_or_null($data['type'] ?? '');
    $style = str_or_null($data['style'] ?? '');
    $imageUrl = str_or_null($data['image_url'] ?? '');

    $hadImage = false;

    // If wine_id provided, verify it exists
    if (!empty($data['wine_id'])) {
        $wineId = (int)$data['wine_id'];
        $check = $catalog->prepare("SELECT id, (image_url IS NOT NULL AND image_url <> '') AS has_img FROM wines WHERE id = ? LIMIT 1");
        $check->execute([$wineId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $hadImage = (bool)$row['has_img'];
            update_catalog_missing_fields($catalog, $wineId, [
                'name' => $name, 'winery' => $winery, 'vintage' => $vintage,
                'region' => $region, 'country' => $country, 'grapes' => $grapes,
                'type' => $type, 'style' => $style, 'image_url' => $imageUrl,
            ]);
            return ['wine_id' => $wineId, 'had_image' => $hadImage, 'created' => false];
        }
    }

    // Try to find existing wine by name + winery + vintage
    if ($name && $winery) {
        $sql = "SELECT id, (image_url IS NOT NULL AND image_url <> '') AS has_img 
                FROM wines 
                WHERE LOWER(name) = LOWER(:name) 
                  AND LOWER(winery) = LOWER(:winery)";
        $params = [':name' => $name, ':winery' => $winery];

        if ($vintage !== null) {
            $sql .= " AND (vintage = :vintage OR vintage IS NULL)";
            $params[':vintage'] = $vintage;
        }

        $sql .= " ORDER BY CASE WHEN vintage = :vintage2 THEN 0 ELSE 1 END LIMIT 1";
        $params[':vintage2'] = $vintage;

        $stmt = $catalog->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $wineId = (int)$existing['id'];
            $hadImage = (bool)$existing['has_img'];
            update_catalog_missing_fields($catalog, $wineId, [
                'vintage' => $vintage, 'region' => $region, 'country' => $country,
                'grapes' => $grapes, 'type' => $type, 'style' => $style, 'image_url' => $imageUrl,
            ]);
            return ['wine_id' => $wineId, 'had_image' => $hadImage, 'created' => false];
        }
    }

    // Create new catalog entry
    $cols = ['name', 'winery'];
    $vals = [$name, $winery];
    $placeholders = ['?', '?'];

    $optionalCols = [
        'vintage' => $vintage, 'region' => $region, 'country' => $country,
        'grapes' => $grapes, 'type' => $type, 'style' => $style, 'image_url' => $imageUrl
    ];

    foreach ($optionalCols as $col => $val) {
        if ($val !== null) {
            $cols[] = $col;
            $vals[] = $val;
            $placeholders[] = '?';
        }
    }

    $sql = "INSERT INTO wines (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $catalog->prepare($sql);
    $stmt->execute($vals);

    return [
        'wine_id' => (int)$catalog->lastInsertId(),
        'had_image' => false,
        'created' => true,
    ];
}

/**
 * Update missing fields in catalog entry
 */
function update_catalog_missing_fields(PDO $catalog, int $wineId, array $data): void {
    $stmt = $catalog->prepare("SELECT * FROM wines WHERE id = ? LIMIT 1");
    $stmt->execute([$wineId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) return;

    $updates = [];
    $params = [':id' => $wineId];

    foreach ($data as $col => $val) {
        if ($val === null || $val === '') continue;
        $currentVal = $current[$col] ?? null;
        $isEmpty = $currentVal === null || $currentVal === '' || $currentVal === 0;
        if ($isEmpty) {
            $updates[] = "`$col` = :$col";
            $params[":$col"] = $val;
        }
    }

    if (!empty($updates)) {
        $sql = "UPDATE wines SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $catalog->prepare($sql);
        $stmt->execute($params);
    }
}

/**
 * Insert a new bottle
 */
function insert_bottle(PDO $pdo, int $userId, array $data, ?int $wineId): int {
    $cols = ['user_id', 'name', 'quantity'];
    $vals = [$userId, $data['name'], $data['quantity'] ?? 1];
    $placeholders = ['?', '?', '?'];

    if ($wineId !== null) {
        $cols[] = 'wine_id';
        $vals[] = $wineId;
        $placeholders[] = '?';
    }

    $optionalFields = [
        'winery', 'vintage', 'region', 'country', 'grapes', 'type', 'style',
        'price_paid', 'location', 'notes', 'purchase_date', 'image_url'
    ];

    foreach ($optionalFields as $col) {
        $val = $data[$col] ?? null;
        if ($val !== null && $val !== '') {
            $cols[] = $col;
            $vals[] = $val;
            $placeholders[] = '?';
        }
    }

    $sql = "INSERT INTO bottles (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);

    return (int)$pdo->lastInsertId();
}

// Main handler
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $name = str_or_null($input['name'] ?? '');
    $winery = str_or_null($input['winery'] ?? '');

    if (!$name) {
        api_error(400, 'missing_name', 'Wine name is required');
    }

    // Parse input
    $vintage = normalize_vintage($input['vintage'] ?? null);
    $region = str_or_null($input['region'] ?? '');
    $country = str_or_null($input['country'] ?? '');
    $grapes = str_or_null($input['grapes'] ?? '');
    $type = str_or_null($input['type'] ?? '');
    $style = str_or_null($input['style'] ?? '');
    $pricePaid = dec_or_null($input['price_paid'] ?? null);
    $location = str_or_null($input['location'] ?? '');
    $quantity = int_or_null($input['quantity'] ?? 1) ?? 1;
    $wineId = int_or_null($input['wine_id'] ?? null);
    $imageUrl = str_or_null($input['image_url'] ?? '');
    $notes = str_or_null($input['notes'] ?? '');
    $purchaseDate = str_or_null($input['purchase_date'] ?? '');

    // Upsert to catalog
    $catalogWineId = $wineId;
    $catalogImageUrl = $imageUrl;

    if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        $upsertResult = upsert_catalog($winelist_pdo, [
            'name' => $name, 'winery' => $winery, 'vintage' => $vintage,
            'region' => $region, 'country' => $country, 'grapes' => $grapes,
            'type' => $type, 'style' => $style, 'image_url' => $imageUrl,
            'wine_id' => $wineId,
        ]);

        $catalogWineId = $upsertResult['wine_id'];

        // Update catalog image if needed
        if (!$upsertResult['had_image'] && $imageUrl) {
            $upImg = $winelist_pdo->prepare("UPDATE wines SET image_url = ? WHERE id = ? AND (image_url IS NULL OR image_url = '')");
            $upImg->execute([$imageUrl, $catalogWineId]);
        }

        // Get catalog image if we don't have one
        if (!$imageUrl && $catalogWineId) {
            $imgStmt = $winelist_pdo->prepare("SELECT image_url FROM wines WHERE id = ? LIMIT 1");
            $imgStmt->execute([$catalogWineId]);
            $catalogImageUrl = $imgStmt->fetchColumn() ?: null;
        }
    }

    // Check if user already has this wine (same wine_id + vintage)
    $existingBottle = null;
    if ($catalogWineId) {
        $existingBottle = find_existing_bottle($pdo, $userId, $catalogWineId, $vintage);
    }

    if ($existingBottle) {
        // Increment quantity of existing bottle
        $newQuantity = increment_bottle_quantity($pdo, $existingBottle['id'], $quantity);

        api_success([
            'message' => "Updated quantity to $newQuantity",
            'bottle_id' => $existingBottle['id'],
            'wine_id' => $catalogWineId,
            'quantity' => $newQuantity,
            'action' => 'incremented',
        ]);
    } else {
        // Create new bottle entry
        $bottleData = [
            'name' => $name,
            'winery' => $winery,
            'vintage' => $vintage,
            'region' => $region,
            'country' => $country,
            'grapes' => $grapes,
            'type' => $type,
            'style' => $style,
            'price_paid' => $pricePaid,
            'location' => $location,
            'notes' => $notes,
            'purchase_date' => $purchaseDate,
            'image_url' => $imageUrl ?: $catalogImageUrl,
            'quantity' => $quantity,
        ];

        $bottleId = insert_bottle($pdo, $userId, $bottleData, $catalogWineId);

        api_success([
            'message' => $quantity > 1 ? "Added $quantity bottles" : 'Bottle added',
            'bottle_id' => $bottleId,
            'wine_id' => $catalogWineId,
            'quantity' => $quantity,
            'action' => 'created',
        ]);
    }

} catch (Throwable $e) {
    error_log('[api/v2/add_bottle] Error: ' . $e->getMessage());
    api_error(500, 'server_error', 'Failed to add bottle: ' . $e->getMessage());
}