<?php
// CLI sync: copy catalog AI windows -> per-bottle insights (idempotent).
// Works whether Winelist is the same MySQL server (cross-schema JOIN) or a different server (two-connection batch).

require __DIR__ . '/../db.php'; // must provide $pdo (Wine DB) and $winelist_pdo (Winelist DB)

// Optional: limit to a single user: php refresh_bottle_ai.php --user-id=123
$onlyUserId = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--user-id=(\d+)$/', $arg, $m)) $onlyUserId = (int)$m[1];
}

function tryCrossSchema(PDO $pdo, string $winelistSchema, ?int $onlyUserId): bool {
    $where = $onlyUserId ? "WHERE b.user_id = " . (int)$onlyUserId : "";
    $sql = "
        INSERT INTO bottle_ai_insights
            (bottle_id, notes_md, pairings_json, drink_from, drink_to, investability_score, updated_at)
        SELECT b.id, wa.notes_md, wa.pairings_json, wa.drink_from, wa.drink_to, wa.investability_score, NOW()
        FROM bottles b
        JOIN {$winelistSchema}.wines_ai wa ON wa.wine_id = b.wine_id
        {$where}
        ON DUPLICATE KEY UPDATE
            notes_md = VALUES(notes_md),
            pairings_json = VALUES(pairings_json),
            drink_from = VALUES(drink_from),
            drink_to = VALUES(drink_to),
            investability_score = VALUES(investability_score),
            updated_at = NOW()
    ";
    $pdo->exec($sql);
    return true;
}

function twoConnBatch(PDO $pdo, PDO $winelist_pdo, ?int $onlyUserId, int $chunk = 500): void {
    // Map wine_id -> [bottle_id...]
    $where = $onlyUserId ? "WHERE b.user_id = " . (int)$onlyUserId : "";
    $map = [];  // wine_id => bottle_id[]
    $wineIds = [];
    $stmt = $pdo->query("SELECT b.id AS bottle_id, b.wine_id FROM bottles b {$where}");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $wid = (int)$row['wine_id'];
        $bid = (int)$row['bottle_id'];
        if ($wid <= 0) continue;
        $map[$wid][] = $bid;
    }
    $wineIds = array_keys($map);
    if (!$wineIds) return;

    // Prepared upsert on Wine DB
    $up = $pdo->prepare("
        INSERT INTO bottle_ai_insights
            (bottle_id, notes_md, pairings_json, drink_from, drink_to, investability_score, updated_at)
        VALUES (?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            notes_md = VALUES(notes_md),
            pairings_json = VALUES(pairings_json),
            drink_from = VALUES(drink_from),
            drink_to = VALUES(drink_to),
            investability_score = VALUES(investability_score),
            updated_at = NOW()
    ");

    // Fetch catalog AI in chunks from Winelist DB, then upsert per bottle_id
    for ($i = 0; $i < count($wineIds); $i += $chunk) {
        $slice = array_slice($wineIds, $i, $chunk);
        $in = implode(',', array_fill(0, count($slice), '?'));
        $q = $winelist_pdo->prepare("SELECT wine_id, notes_md, pairings_json, drink_from, drink_to, investability_score FROM wines_ai WHERE wine_id IN ($in)");
        $q->execute($slice);
        while ($ai = $q->fetch(PDO::FETCH_ASSOC)) {
            $wid = (int)$ai['wine_id'];
            foreach ($map[$wid] as $bottleId) {
                $up->execute([
                    $bottleId,
                    $ai['notes_md'] ?? null,
                    $ai['pairings_json'] ?? null,
                    $ai['drink_from'] ?? null,
                    $ai['drink_to'] ?? null,
                    isset($ai['investability_score']) ? (int)$ai['investability_score'] : null,
                ]);
            }
        }
    }
}

try {
    // Get the schema name selected on the Winelist connection (works even if different DB name)
    $winelistSchema = null;
    try {
        $winelistSchema = $winelist_pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($winelistSchema) $winelistSchema = preg_replace('/[^a-zA-Z0-9_]/', '', $winelistSchema);
    } catch (Throwable $e) {}

    $did = false;
    if ($winelistSchema) {
        // If both schemas are on the same MySQL server and the Wine user has permission, this is fastest.
        try {
            $did = tryCrossSchema($pdo, $winelistSchema, $onlyUserId);
        } catch (Throwable $e) {
            error_log("[refresh_bottle_ai] cross-schema JOIN failed: ".$e->getMessage()." — falling back to two-connection batch.");
        }
    }
    if (!$did) {
        twoConnBatch($pdo, $winelist_pdo, $onlyUserId);
    }
    echo "[refresh_bottle_ai] OK\n";
} catch (Throwable $e) {
    error_log("[refresh_bottle_ai] ERROR: ".$e->getMessage());
    http_response_code(500);
    echo "[refresh_bottle_ai] ERROR\n";
}
