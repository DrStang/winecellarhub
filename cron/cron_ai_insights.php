<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

/*
 * Enhancements:
 *  - CLI args:
 *      --wine-id=12345   only process that id (central winelist id)
 *      --force           ignore staleness and completeness checks
 *      --batch=500       batch size (default 200)
 *      --max-batches=10  pagination limit (default 1 for safety)
 *  - Completeness filter: (notes_md IS NULL/empty OR pairings_json IS NULL)
 *  - Pagination: loops batches until done or max-batches
 *  - Defensive normalization like before
 */

function get_catalog_db(): string {
    return preg_replace('/[^a-zA-Z0-9_]/', '', $_ENV['WINELIST_DB'] ?? 'winelist');
}
function col_exists(PDO $pdo, string $db, string $table, string $col): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db, $table, $col]);
    return (bool)$q->fetchColumn();
}
function ensure_schema(PDO $pdo, string $db): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS {$db}.wines_ai (
      wine_id INT PRIMARY KEY,
      notes_md TEXT NULL,
      pairings_json JSON NULL,
      drink_from DATE NULL,
      drink_to DATE NULL,
      investability_score TINYINT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
  ");
    // If JSON not supported, ensure TEXT exists
    try {
        $pdo->exec("ALTER TABLE {$db}.wines_ai ADD COLUMN pairings_json JSON");
    } catch (Throwable $e) {
        if (!col_exists($pdo, $db, 'wines_ai', 'pairings_json')) {
            $pdo->exec("ALTER TABLE {$db}.wines_ai ADD COLUMN pairings_json TEXT");
        }
    }
}

function to_scalar_string($v): string {
    if ($v === null) return '';
    if (is_string($v) || is_numeric($v)) return (string)$v;
    if (is_array($v)) return implode(', ', array_map('strval', $v));
    if (is_object($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
    return (string)$v;
}
function to_date_yyyy_mm_dd($v): ?string {
    if (!is_string($v) || trim($v)==='') return null;
    $s = substr($v, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function to_string_array($v): array {
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) {
        $out[] = is_array($x) ? implode(', ', array_map('strval', $x)) : (string)$x;
    }
    $out = array_values(array_filter(array_unique($out), fn($s)=>trim($s)!==''));
    return $out;
}

function openai_chat(string $prompt, float $temperature = 0.2): ?array {
    $key = $_ENV['OPENAI_API_KEY'] ?? '';
    if ($key === '') return null;

    $model    = $_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini';
    $maxOut   = (int)($_ENV['OPENAI_MAX_OUTPUT_TOKENS'] ?? 700);  // <- numeric cast
    if ($maxOut <= 0) $maxOut = 700;

    $payload = [
        'model' => $model,
        'temperature' => $temperature,
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => $maxOut,
        'messages' => [
            [
                'role' => 'system',
                'content' => "You are a master sommelier. Return STRICT minified JSON ONLY with keys:
  notes_md (markdown string),
  pairings (array of 3–8 concise food items),
  drink_from (YYYY-MM-DD or null),
  drink_to (YYYY-MM-DD or null),
  investability_score (integer 0–100 or null).

notes_md MUST include:
- One short paragraph of tasting notes (not bullets).
- A second line starting with '**Cellaring Guidance:** ' summarizing window & aging potential.
Do not include any extra keys or any text outside JSON."

        ],
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$key,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $res = curl_exec($ch);
    if ($res === false) return null;

    $json = json_decode($res, true);
    $txt  = $json['choices'][0]['message']['content'] ?? '{}';
    $obj  = json_decode($txt, true);
    return is_array($obj) ? $obj : null;
}

if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
    fwrite(STDERR, "No winelist PDO handle.\n"); exit(1);
}

$catalogDb = get_catalog_db();
ensure_schema($winelist_pdo, $catalogDb);

// -------- args
$wineIdArg = 0; $force = false; $batch = 200; $maxBatches = 1;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--wine-id=(\d+)$/', $arg, $m)) $wineIdArg = (int)$m[1];
    elseif ($arg === '--force') $force = true;
    elseif (preg_match('/^--batch=(\d+)$/', $arg, $m)) $batch = max(1,(int)$m[1]);
    elseif (preg_match('/^--max-batches=(\d+)$/', $arg, $m)) $maxBatches = max(1,(int)$m[1]);
}

// -------- selectors
$selSpecific = $winelist_pdo->prepare("
  SELECT w.id, w.name, w.winery, w.country, w.region, w.type, w.grapes, w.vintage, w.style, w.food_pairings, w.rating, w.price
  FROM {$catalogDb}.wines w
  WHERE w.id = ?
  LIMIT 1
");

$selBatch = function(int $batch) use ($winelist_pdo, $catalogDb, $force) {
    // process wines with missing or STALE or INCOMPLETE insights
    $where = "a.wine_id IS NULL OR a.notes_md IS NULL OR a.notes_md = '' OR a.pairings_json IS NULL";
    if (!$force) $where .= " OR a.updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)";
    $sql = "
    SELECT w.id, w.name, w.winery, w.country, w.region, w.type, w.grapes, w.vintage, w.style, w.food_pairings, w.rating, w.price
    FROM {$catalogDb}.wines w
    LEFT JOIN {$catalogDb}.wines_ai a ON a.wine_id = w.id
    WHERE {$where}
    ORDER BY w.created_at DESC
    LIMIT {$batch}
  ";
    return $winelist_pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
};

$ins = $winelist_pdo->prepare("
  INSERT INTO {$catalogDb}.wines_ai
    (wine_id, notes_md, pairings_json, drink_from, drink_to, investability_score, updated_at)
  VALUES (?,?,?,?,?,?,NOW())
  ON DUPLICATE KEY UPDATE
    notes_md=VALUES(notes_md),
    pairings_json=VALUES(pairings_json),
    drink_from=VALUES(drink_from),
    drink_to=VALUES(drink_to),
    investability_score=VALUES(investability_score),
    updated_at=NOW()
");

// -------- process
$total = 0;

$process = function(array $w) use ($ins, &$total) {
    $ctx = [
        'name'    => to_scalar_string($w['name']    ?? ''),
        'winery'  => to_scalar_string($w['winery']  ?? ''),
        'country' => to_scalar_string($w['country'] ?? ''),
        'region'  => to_scalar_string($w['region']  ?? ''),
        'type'    => to_scalar_string($w['type']    ?? ''),
        'grapes'  => to_scalar_string($w['grapes']  ?? ''),
        'vintage' => to_scalar_string($w['vintage'] ?? ''),
        'style'   => to_scalar_string($w['style']   ?? ''),
        'pairings_hint' => to_scalar_string($w['food_pairings'] ?? ''),
        'rating'  => to_scalar_string($w['rating']  ?? ''),
        'price'   => to_scalar_string($w['price']   ?? '')
    ];
    $prompt = "Create tasting notes and cellaring guidance for this wine context (JSON only):\n".json_encode($ctx, JSON_UNESCAPED_SLASHES);

    $out = openai_chat($prompt);
    if (!$out || !is_array($out)) return;

    $notes_md = isset($out['notes_md']) && is_string($out['notes_md'])
        ? trim($out['notes_md'])
        : (is_array($out['notes_md'] ?? null) ? ('- '.implode("\n- ", array_map('strval',$out['notes_md']))) : '');

    $pairings = to_string_array($out['pairings'] ?? []);
    $pairings_json = json_encode($pairings, JSON_UNESCAPED_SLASHES);

    $df = to_date_yyyy_mm_dd($out['drink_from'] ?? null);
    $dt = to_date_yyyy_mm_dd($out['drink_to']   ?? null);

    $inv = null;
    if (isset($out['investability_score'])) {
        $inv = (int)filter_var($out['investability_score'], FILTER_VALIDATE_INT, ['options'=>['default'=>null]]);
        if ($inv !== null) $inv = max(0, min(100, $inv));
    }

    $ins->execute([(int)$w['id'], $notes_md, $pairings_json, $df, $dt, $inv]);
    $total++;
};

// Specific id?
if ($wineIdArg > 0) {
    $selSpecific->execute([$wineIdArg]);
    if ($w = $selSpecific->fetch(PDO::FETCH_ASSOC)) $process($w);
    echo "Updated: {$total}\n"; exit(0);
}

// Batched mode (pagination)
for ($i=0; $i<$maxBatches; $i++) {
    $rows = $selBatch($batch);
    if (!$rows) break;
    foreach ($rows as $w) $process($w);
    if (count($rows) < $batch) break;
}
echo "Updated: {$total}\n";
