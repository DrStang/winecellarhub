<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

function fail(int $code, string $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
        throw new RuntimeException('winelist DB handle missing');
    }

    $wine_id = isset($_GET['wine_id']) ? (int)$_GET['wine_id'] : 0;
    if ($wine_id <= 0) fail(400, 'wine_id required');

    // Ensure the wine exists (helps catch id mismatches quickly)
    $chk = $winelist_pdo->prepare("SELECT id FROM wines WHERE id=? LIMIT 1");
    $chk->execute([$wine_id]);
    if (!$chk->fetchColumn()) fail(404, 'not_found');

    // Pull insights if available
    $st = $winelist_pdo->prepare("
    SELECT
      COALESCE(notes_md,'')            AS notes_md,
      COALESCE(pairings_json,'[]')     AS pairings,
      drink_from, drink_to,
      investability_score
    FROM wines_ai
    WHERE wine_id = ?
    LIMIT 1
  ");
    $st->execute([$wine_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [
        'notes_md' => '',
        'pairings' => '[]',
        'drink_from' => null,
        'drink_to' => null,
        'investability_score' => null,
    ];

    // Decode pairings if stored as JSON text
    $pairings = json_decode((string)$row['pairings'], true);
    if (!is_array($pairings)) $pairings = [];

    echo json_encode([
        'insights' => [
            'notes_md'            => (string)$row['notes_md'],
            'pairings'            => $pairings,
            'drink_from'          => $row['drink_from'] ?: null,
            'drink_to'            => $row['drink_to'] ?: null,
            'investability_score' => isset($row['investability_score']) ? (int)$row['investability_score'] : null,
        ]
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log("[ai_insights] ".$e->getMessage());
    fail(500, 'server_error');
}
