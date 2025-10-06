<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';


header('Content-Type: application/json');

// ---- helpers
function openai_chat_json(string $prompt, float $temperature=0.1): array {
    $key = $_ENV['OPENAI_API_KEY'] ?? null;
    if (!$key) return [];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$key,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS=>json_encode([
            'model'=>'gpt-4o-mini',
            'temperature'=>$temperature,
            'response_format'=>['type'=>'json_object'],
            'messages'=>[
                ['role'=>'system','content'=>'Extract concise JSON filters from a wine search query.'],
                ['role'=>'user','content'=>$prompt]
            ]
        ])
    ]);
    $res = curl_exec($ch);
    if (!$res) return [];
    $json = json_decode($res, true);
    $txt = $json['choices'][0]['message']['content'] ?? '{}';
    $obj = json_decode($txt, true);
    return is_array($obj) ? $obj : [];
}

function openai_embed(string $text, string $model='text-embedding-3-small'): array {
    $key = $_ENV['OPENAI_API_KEY'] ?? null;
    if (!$key) return [];
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$key,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode(['input'=>$text, 'model'=>$model]),
    ]);
    $res = curl_exec($ch);
    if (!$res) return [];
    $json = json_decode($res, true);
    $vec = $json['data'][0]['embedding'] ?? null;
    return (is_array($vec) ? $vec : []);
}

function parse_varietals(?string $grapes): array {
    if (!$grapes) return [];
    $parts = preg_split('/[,&\/;]+/u', $grapes);
    $out = [];
    foreach ($parts as $p) {
        $v = trim(mb_strtolower($p));
        if ($v === '') continue;
        $v = preg_replace('/\s+/', ' ', $v);
        $out[] = $v;
    }
    return $out;
}

function cosine(array $a, array $b): float {
    $dot=0.0; $na=0.0; $nb=0.0;
    $n = min(count($a), count($b));
    if ($n==0) return 0.0;
    for ($i=0; $i<$n; $i++) { $va=$a[$i]; $vb=$b[$i]; $dot+=$va*$vb; $na+=$va*$va; $nb+=$vb*$vb; }
    if ($na==0||$nb==0) return 0.0;
    return $dot/(sqrt($na)*sqrt($nb));
}

// ---- input
$q = trim($_GET['q'] ?? '');
if ($q === '') { http_response_code(400); echo json_encode(['error'=>'q required']); exit; }

// 1) Extract filters from NLQ (cheap, schema-fixed)
$schema_prompt = <<<P
Query: "$q"

Return JSON with keys (omit if unknown):
{
  "max_price": number,
  "min_price": number,
  "varietals": ["pinot noir", "syrah", ...],         // normalized lowercase
  "regions": ["barossa", "napa valley", ...],        // lowercase
  "types": ["red","white","sparkling","rose"],       // optional
  "drink_window": "now|soon|fall|winter|summer|spring|aging"
}
Keep it short. No extra keys.
P;

$filters = openai_chat_json($schema_prompt) ?: [];
$max_price = isset($filters['max_price']) ? floatval($filters['max_price']) : null;
$min_price = isset($filters['min_price']) ? floatval($filters['min_price']) : null;
$f_vars = array_map('strtolower', $filters['varietals'] ?? []);
$f_regs = array_map('strtolower', $filters['regions'] ?? []);
$f_types= array_map('strtolower', $filters['types'] ?? []);
$drink  = isset($filters['drink_window']) ? strtolower($filters['drink_window']) : null;

// 2) Embed the user query
$qvec = openai_embed($q);
if (!$qvec) { http_response_code(500); echo json_encode(['error'=>'embed_failed']); exit; }

// 3) Pull a candidate slice from catalog (apply cheap SQL filters if present)
$sql = "
  SELECT w.id, w.name, w.winery, w.region, w.country, w.type, w.grapes, w.vintage, w.price, w.image_url,
         a.notes_md, a.drink_from, a.drink_to
  FROM wines w
  LEFT JOIN wines_ai a ON a.wine_id=w.id
  WHERE w.price IS NOT NULL
";
$params = [];
if (!is_null($max_price)) { $sql .= " AND w.price <= ?"; $params[] = $max_price; }
if (!is_null($min_price)) { $sql .= " AND w.price >= ?"; $params[] = $min_price; }
if ($f_types) { $sql .= " AND LOWER(w.type) IN (".implode(',', array_fill(0,count($f_types),'?')).")"; foreach ($f_types as $t) $params[]=$t; }
if ($f_regs)  { $sql .= " AND LOWER(w.region) IN (".implode(',', array_fill(0,count($f_regs),'?')).")"; foreach ($f_regs as $r) $params[]=$r; }
$sql .= " ORDER BY w.created_at DESC LIMIT 800";

$st = $winelist_pdo->prepare($sql);
$st->execute($params);
$candidates = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$candidates) { echo json_encode(['results'=>[]]); exit; }

// 4) Load embeddings for those candidates
$ids = array_map(fn($r)=> intval($r['id']), $candidates);
$in = implode(',', array_fill(0, count($ids), '?'));
$emb = [];
$stE = $winelist_pdo->prepare("SELECT wine_id, embedding FROM wine_embeddings WHERE wine_id IN ($in)");
$stE->execute($ids);
foreach ($stE->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $emb[intval($row['wine_id'])] = json_decode($row['embedding'], true);
}

// 5) Score: semantic cosine + filter bonuses (varietal/region/type/price) + drink-window fit
$scored = [];
foreach ($candidates as $w) {
    $wid = intval($w['id']);
    $vec = $emb[$wid] ?? null;
    if (!$vec) continue; // skip until embedding exists (cron will fill it soon)

    $score = cosine($qvec, $vec);           // 0..1 typically
    $reasons = ["semantic ".round($score,2)];

    // varietal bonus
    if ($f_vars) {
        $wineVars = parse_varietals($w['grapes'] ?? '');
        $hit = false; foreach ($wineVars as $v) { if (in_array($v, $f_vars, true)) { $hit=true; break; } }
        if ($hit) { $score += 0.12; $reasons[] = "varietal match"; }
    }
    // region bonus
    if ($f_regs && in_array(mb_strtolower(trim($w['region'] ?? '')), $f_regs, true)) {
        $score += 0.08; $reasons[] = "region match";
    }
    // type bonus
    if ($f_types && in_array(mb_strtolower(trim($w['type'] ?? '')), $f_types, true)) {
        $score += 0.05; $reasons[] = "type";
    }
    // price smooth-fit (within 15% of max/min if provided)
    $price = floatval($w['price']);
    if (!is_null($max_price) && $price <= $max_price*1.15) { $score += 0.05; $reasons[]="price fit"; }
    if (!is_null($min_price) && $price >= $min_price*0.85) { $score += 0.02; }

    // drink window
    if ($drink && ($w['drink_from'] || $w['drink_to'])) {
        $today = new DateTimeImmutable('today');
        $df = $w['drink_from'] ? new DateTimeImmutable($w['drink_from']) : null;
        $dt = $w['drink_to']   ? new DateTimeImmutable($w['drink_to'])   : null;
        $in_window = false; $soon = false;
        if ($df && $dt) {
            $in_window = ($today >= $df && $today <= $dt);
            $soon = (!$in_window && $df > $today && $df <= $today->modify('+90 days'));
        } elseif ($df) {
            $in_window = ($today >= $df);
            $soon = (!$in_window && $df <= $today->modify('+90 days'));
        }
        if ($drink === 'now' && $in_window) { $score += 0.08; $reasons[]='drink now'; }
        if ($drink === 'soon' && $soon)     { $score += 0.05; $reasons[]='nearing peak'; }
        // seasonal hint: only nudge (fall/winter/spring/summer)
        // (You could map styles to seasons if you want stronger effects)
    }

    $scored[] = [
        'id'=>$wid,
        'name'=>$w['name'],
        'winery'=>$w['winery'],
        'region'=>$w['region'],
        'country'=>$w['country'],
        'type'=>$w['type'],
        'grapes'=>$w['grapes'],
        'vintage'=>$w['vintage'],
        'price'=>$price,
        'image_url'=>$w['image_url'],
        'reason'=>implode('; ', $reasons),
        'score'=>round($score, 3)
    ];
}

// 6) Sort + return
usort($scored, fn($a,$b)=> $b['score']<=>$a['score']);
echo json_encode(['results'=> array_slice($scored, 0, 24)]);
