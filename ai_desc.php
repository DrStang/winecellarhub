<?php
// ajax/ai_desc.php
declare(strict_types=1);
require_once __DIR__.'/../db.php';      // must set $winelist_pdo (Winelist DB)
require_once __DIR__.'/../ai_lib.php';  // functions above

header('Content-Type: application/json; charset=utf-8');
// after header(...)
$debugLog = '/tmp/ai_desc_debug.log';
function dbg($msg) {
    @file_put_contents('/tmp/ai_desc_debug.log', date('c').' '.$msg."\n", FILE_APPEND);
}

// Verify DB handle (catalog)
if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
    dbg('ERR no $winelist_pdo');
    http_response_code(500);
    echo json_encode(['status'=>'error','reason'=>'winelist_pdo missing']);
    exit;
}

// Read payload (JSON only in your current JS)
$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true) ?: [];
dbg('IN '.json_encode($payload));
// --- accept both POST JSON and GET query params ---

if ($raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) $payload = $j;
}
if (!$payload) {
    // fallback to GET
    $payload = $_GET;
}

$row = [
    'wine_id' => (int)($payload['wine_id'] ?? 0),
    'winery'  => trim((string)($payload['winery']  ?? '')),
    'name'    => trim((string)($payload['name']    ?? '')),
    'vintage' => trim((string)($payload['vintage'] ?? '')),
    'region'  => trim((string)($payload['region']  ?? '')),
    'country' => trim((string)($payload['country'] ?? '')),
    'grapes'  => trim((string)($payload['grapes']  ?? '')),
];

$key = ai_cache_key_from_row($row);

// cache-first
$cachedVal = ai_get_cached($winelist_pdo, $key);
if ($cachedVal !== null && $cachedVal !== '') {
    echo json_encode(['ok' => true, 'cached' => true, 'desc_md' => $cachedVal]);
    exit;
}

// one quick warm attempt (non-blocking if your ai_warm_one is quick)
$timeoutSec = 8;
$old = ini_get('max_execution_time');
@set_time_limit($timeoutSec + 2);

$desc = null;
try {
    $desc = ai_warm_one($winelist_pdo, $row); // should ai_put_cache() internally
} catch (Throwable $e) {
    // ignore → will fall through to pending
} finally {
    if ($old !== false) @ini_set('max_execution_time', (string)$old);
}

// re-check
$final = $desc ?: ai_get_cached($winelist_pdo, $key);
if ($final !== null && $final !== '') {
    echo json_encode(['ok' => true, 'cached' => false, 'desc_md' => $final]);
    exit;
}

echo json_encode(['ok' => false, 'reason' => 'pending']);