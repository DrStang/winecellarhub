<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';      // defines $winelist_pdo
require_once __DIR__ . '/ai_lib.php';  // helpers that accept $winelist_pdo

$p = [
    'wine_id' => isset($_GET['wine_id']) ? (int)$_GET['wine_id'] : null,
    'name'    => trim((string)($_GET['name'] ?? '')),
    'winery'  => trim((string)($_GET['winery'] ?? '')),
    'vintage' => trim((string)($_GET['vintage'] ?? '')),
    'region'  => trim((string)($_GET['region'] ?? '')),
    'country' => trim((string)($_GET['country'] ?? '')),
    'grapes'  => trim((string)($_GET['grapes'] ?? '')),
    'type'    => trim((string)($_GET['type'] ?? '')),
];

$ck = ai_cache_key_from_row($p);
$desc = ai_get_cached($winelist_pdo, $ck);
$wasCached = $desc !== null;

if ($desc === null) {
    $desc = ai_warm_one($winelist_pdo, $p); // generates + stores via winelist DB
    if ($desc === null) { echo json_encode(['ok'=>false, 'error'=>'llm']); exit; }
}

echo json_encode(['ok'=>true, 'desc_md'=>$desc, 'cached'=>$wasCached, 'key'=>$ck]);

