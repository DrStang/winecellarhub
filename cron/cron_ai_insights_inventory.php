<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

/**
 * Rebuilds AI insights prioritizing wines that appear in users' inventory (Wine.bottles),
 * mapping to the central winelist.wines by: UPC -> exact name+winery+vintage -> LIKE fallback.
 * Then generates/refreshes winelist.wines_ai for missing/empty/stale rows.
 *
 * CLI args:
 *   --batch=500        number of central ids to process per run (default 300)
 *   --force            ignore staleness; always (re)generate
 */

function get_catalog_db(): string {
    return preg_replace('/[^a-zA-Z0-9_]/', '', $_ENV['WINELIST_DB'] ?? 'winelist');
}

// ---------- helpers reused from the other cron ----------
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
    try { $pdo->exec("ALTER TABLE {$db}.wines_ai ADD COLUMN pairings_json JSON"); }
    catch (Throwable $e) {
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
    foreach ($v as $x) $out[] = is_array($x) ? implode(', ', array_map('strval',$x)) : (string)$x;
    return array_values(array_filter(array_unique($out), fn($s)=>trim($s)!==''));
}
function openai_chat(string $prompt, float $temperature = 0.2): ?array {
    $key = $_ENV['OPENAI_API_KEY'] ?? '';
    if ($key === '') return null;
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode([
            'model'=>'gpt-4o-mini',
            'temperature'=>$temperature,
            'response_format'=>['type'=>'json_object'],
            'messages'=>[
                ['role'=>'system','content' => "You are a master sommelier. Return STRICT minified JSON ONLY with keys:
  notes_md (markdown string),
  pairings (array of 3–8 concise food items),
  drink_from (YYYY-MM-DD or null),
  drink_to (YYYY-MM-DD or null),
  investability_score (integer 0–100 or null).

notes_md MUST include:
- One short paragraph of tasting notes (not bullets).
- A second line starting with '**Cellaring Guidance:** ' summarizing window & aging potential.
Do not include any extra keys or any text outside JSON."],

                ['role'=>'user','content'=>$prompt]
            ]
        ])
    ]);
    $res = curl_exec($ch);
    if ($res === false) return null;
    $json = json_decode($res, true);
    $txt  = $json['choices'][0]['message']['content'] ?? '{}';
    $obj  = json_decode($txt, true);
    return is_array($obj) ? $obj : null;
}
// ---------- end helpers ----------

if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR,"No user DB handle.\n"); exit(1); }
if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) { fwrite(STDERR,"No winelist DB handle.\n"); exit(1); }

$catalogDb = get_catalog_db();
ensure_schema($winelist_pdo, $catalogDb);

// args
$batch = 300; $force = false;
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--batch=(\d+)$/',$a,$m)) $batch = max(1,(int)$m[1]);
    elseif ($a === '--force') $force = true;
}
// 0) direct central ids from bottles.catalog_wine_id
$cands = [];
$sqlDirect = "SELECT DISTINCT wine_id AS id
              FROM bottles
              WHERE wine_id IS NOT NULL AND wine_id > 0
              LIMIT 50000";
$st = $pdo->query($sqlDirect);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)$r['id'];
    if ($id > 0) $cands[(int)$id]=true;
}

// 1) Gather distinct candidates from users' inventory (UPC preferred)
// a) UPC/barcode present
$sqlUPC = "SELECT DISTINCT TRIM(COALESCE(b.upc,b.barcode)) AS code
           FROM bottles b WHERE TRIM(COALESCE(b.upc,b.barcode)) <> '' LIMIT 5000";
foreach ($pdo->query($sqlUPC)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $c = $r['code']; if (!$c) continue;
    $q = $winelist_pdo->prepare("SELECT id FROM {$catalogDb}.wines WHERE upc = ? OR barcode = ? LIMIT 1");
    $q->execute([$c,$c]);
    if ($id = $q->fetchColumn()) $cands[(int)$id]=true;
}
// b) name+winery+vintage
$sqlN = "SELECT DISTINCT
           LOWER(TRIM(COALESCE(b.name,''))) AS nm,
           LOWER(TRIM(COALESCE(b.winery,''))) AS wn,
           b.vintage AS vt
         FROM bottles b
         WHERE (b.name <> '' OR b.winery <> '')
         LIMIT 5000";
foreach ($pdo->query($sqlN)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $nm = $r['nm']; $wn = $r['wn']; $vt = $r['vt'] ? (int)$r['vt'] : null;
    $sql = "SELECT id FROM {$catalogDb}.wines WHERE 1=1";
    $p = [];
    if ($nm!=='') { $sql.=" AND LOWER(name) LIKE ?"; $p[] = '%'.$nm.'%'; }
    if ($wn!=='') { $sql.=" AND LOWER(winery) LIKE ?"; $p[] = '%'.$wn.'%'; }
    if ($vt)     { $sql.=" AND vintage=?";            $p[] = $vt; }
    $sql.=" ORDER BY rating DESC, id DESC LIMIT 1";
    $st = $winelist_pdo->prepare($sql); $st->execute($p);
    if ($id = $st->fetchColumn()) $cands[(int)$id]=true;
}

// 2) Filter to those missing/empty/stale in wines_ai
$ids = array_keys($cands);
if (!$ids) { echo "Nothing to do.\n"; exit(0); }

$place = implode(',', array_fill(0, count($ids), '?'));
$needClause = $force ? '1=1' : "(ai.wine_id IS NULL
    OR ai.notes_md IS NULL OR ai.notes_md = ''
    OR ai.pairings_json IS NULL OR ai.pairings_json = ''
    OR ai.updated_at IS NULL OR ai.updated_at < (NOW() - INTERVAL 30 DAY))";

$sqlNeed = "
  SELECT w.id, w.name, w.winery, w.country, w.region, w.type, w.grapes, w.vintage, w.style, w.food_pairings, w.rating, w.price
  FROM {$catalogDb}.wines w
  LEFT JOIN {$catalogDb}.wines_ai ai ON ai.wine_id = w.id
  WHERE w.id IN ($place) AND $needClause
  ORDER BY w.rating DESC, w.id DESC
  LIMIT ".(int)$batch;

$st = $winelist_pdo->prepare($sqlNeed);
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "Nothing to update.\n"; exit(0); }


// 3) Upsert insights (same logic as the other cron)
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

$total = 0;
foreach ($rows as $w) {
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
    if (!$out || !is_array($out)) continue;

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
}

echo "Updated: {$total}\n";
