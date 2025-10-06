<?php
declare(strict_types=1);
@ini_set('display_errors','0');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require __DIR__.'/../analytics_track.php'; // <-- add this


$AI_DEBUG = [
    'env_key' => null,
    'model' => 'gpt-4o-mini',
    'prepared' => 0,
    'did_curl' => false,
    'http' => null,
    'curl_err' => null,
    'got_text' => null,
    'parsed_json' => null,
    'note_count' => null,
    'fallback' => null,
    'resp_body_head' => null,
];

// ---------- helpers ----------
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function val($arr, $key, $fallback=''){ return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $fallback; }
function parse_money($v): float {
    if ($v === null || $v === '') return 0.0;
    $s = preg_replace('/[^0-9\.,-]/', '', (string)$v);
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) $s = str_replace(',', '', $s);
    else $s = str_replace(',', '.', $s);
    return (float)$s;
}
function parse_int_sane($v): int {
    if ($v === null || $v === '') return 0;
    return (int)preg_replace('/[^0-9-]/', '', (string)$v);
}
function arr_any($a){ foreach($a as $v){ if($v!==null && $v!=='') return true; } return false; }
function normalize_img(?string $url): ?string { if (!$url) return null; if (strpos($url,'//')===0) return 'https:'.$url; return $url; }
function qty_number(?string $q): ?int { if ($q===null || $q==='') return null; if (preg_match('/([0-9]+)/', $q, $m)) return (int)$m[1]; return null; }
function norm_key(?string $winery, ?string $name, ?string $vintage): string { $w = strtolower(trim((string)$winery)); $n = strtolower(trim((string)$name)); $v = strtolower(trim((string)$vintage)); return $w.'|'.$n.'|'.$v; }

// ---------- legacy heuristic pairing prefs (kept as a base layer) ----------
function pairing_profile(string $dish_l, string $spice, string $occasion): array {
    $prefs = ['boost_types'=>[], 'sparkling_bias'=>($occasion==='celebration')];
    if (preg_match('/(burger|cheeseburger|beef|steak|short\s*rib|brisket)/', $dish_l)) {
        $prefs['boost_types'] = ['zinfandel','syrah','grenache','malbec','cabernet','rioja','tempranillo','cotes du rhone','chianti','merlot'];
    } elseif (preg_match('/(chicken|turkey|poultry|roast\s*chicken)/', $dish_l)) {
        $prefs['boost_types'] = ['chardonnay','pinot noir','sauvignon blanc','albarino','rose','chablis','beaujolais'];
    } elseif (preg_match('/(salmon|tuna|sushi|fish|seafood|shrimp|scallop)/', $dish_l)) {
        $prefs['boost_types'] = ['pinot noir','chardonnay','champagne','sparkling','rose','albarino','muscadet','vermentino','riesling'];
    } elseif (preg_match('/(pasta|pizza|marinara|bolognese|tomato)/', $dish_l)) {
        $prefs['boost_types'] = ['chianti','barbera','sangiovese','montepulciano','nero d avola','primitivo','lambrusco'];
    }
    if ($spice==='hot') {
        $prefs['boost_types'] = array_unique(array_merge($prefs['boost_types'], ['riesling','gewurztraminer','off-dry','mosel','gruner','rose','gamay','pinot noir','sparkling']));
    }
    return $prefs;
}
function protein_tokens_from(string $dish_l): array { $tokens = ['beef','beefsteak','steak','burger','cheeseburger','chicken','black chicken','pork','ham','lamb','duck','turkey','fish','salmon','tuna','shrimp']; $out = []; foreach ($tokens as $t) { if (strpos($dish_l, $t) !== false) $out[] = $t; } return array_unique($out); }

// ---------- env helper ----------
if (!function_exists('wch_env')) {
    function wch_env(string $key, ?string $default=null): ?string {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return $default;
    }
}

// ---------- AI helpers: /v1/responses JSON + dish profile + scoring + reasons ----------
function ai_responses_json(string $sys, string $user, array $schema, array &$AI_DEBUG = []): array {
    $apiKey = wch_env('OPENAI_API_KEY');
    $AI_DEBUG['env_key'] = $apiKey ? 'present' : 'missing';
    if (!$apiKey) return [];

    // Primary: Responses API (structured)
    $payload = [
        'model' => $AI_DEBUG['model'] ?? 'gpt-4o-mini',
        'input' => "SYSTEM:
".$sys."

USER:
".$user,
        'text' => [
          'format' => [
            'type' => 'json_schema',
             'name' => 'response_schema',
              'schema' => [
                'type'=>'object',
                  'properties'=>$schema,'required'=>array_keys($schema),'additionalProperties'=>false],
            ]

        ],
        'temperature' => 0.2,
        'max_output_tokens' => 800,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $AI_DEBUG['did_curl'] = true;
    $raw = curl_exec($ch);
    $AI_DEBUG['http'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $AI_DEBUG['curl_err'] = curl_error($ch) ?: null;
    curl_close($ch);

    if ($raw !== false && $AI_DEBUG['http'] && $AI_DEBUG['http'] < 400) {
        $jr = json_decode($raw, true);
        $json = null; $txt = null;
        if (!empty($jr['output'])) {
            foreach ($jr['output'] as $item) {
                if (!empty($item['content'])) {
                    foreach ($item['content'] as $c) {
                        if (isset($c['json'])) { $json = $c['json']; break 2; }
                        if (isset($c['text'])) { $txt  = $c['text']; }
                    }
                }
            }
        }
        if ($txt === null && isset($jr['output_text'])) $txt = $jr['output_text'];
        $AI_DEBUG['got_text'] = ($json!==null || $txt!==null) ? 'yes' : 'no';
        if ($json !== null) { $AI_DEBUG['parsed_json'] = 'yes'; return is_array($json) ? $json : []; }
        if ($txt)          { $maybe = json_decode($txt, true); $AI_DEBUG['parsed_json'] = is_array($maybe) ? 'yes' : 'no'; return is_array($maybe) ? $maybe : []; }
    }

    // Capture a snippet of the error body for debugging
    $AI_DEBUG['resp_body_head'] = is_string($raw) ? substr($raw, 0, 280) : null;

    // Fallback: Chat Completions returning a JSON object (no schema enforcement)
    $AI_DEBUG['fallback'] = 'chat';
    $chat = [
        'model' => $AI_DEBUG['model'] ?? 'gpt-4o-mini',
        'messages' => [
            ['role'=>'system','content'=>$sys.' Return only valid JSON matching the described fields. No commentary.'],
            ['role'=>'user','content'=>$user]
        ],
        'response_format' => ['type'=>'json_object'],
        'temperature' => 0.2
    ];
    $ch2 = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch2, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($chat)
    ]);
    $raw2 = curl_exec($ch2);
    $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $err2  = curl_error($ch2) ?: null;
    curl_close($ch2);

    if ($raw2 !== false && $http2 && $http2 < 400) {
        $jr2 = json_decode($raw2, true);
        $txt2 = $jr2['choices'][0]['message']['content'] ?? null;
        if ($txt2) {
            $maybe = json_decode($txt2, true);
            $AI_DEBUG['got_text'] = 'yes';
            $AI_DEBUG['parsed_json'] = is_array($maybe) ? 'yes' : 'no';
            return is_array($maybe) ? $maybe : [];
        }
    }

    // Give up
    return [];
}

function ai_dish_profile(string $dish, array &$AI_DEBUG): array {
    $dish = trim($dish);
    if ($dish === '') return ['cuisine'=>'','protein'=>'','cooking'=>'','sauce'=>'','spice_level'=>0,'richness'=>3,'acidity'=>3,'sweetness'=>0,'notes'=>[]];
    $schema = [
        'cuisine'     => ['type'=>'string'],
        'protein'     => ['type'=>'string'],
        'cooking'     => ['type'=>'string'],
        'sauce'       => ['type'=>'string'],
        'spice_level' => ['type'=>'integer','minimum'=>0,'maximum'=>3],
        'richness'    => ['type'=>'integer','minimum'=>1,'maximum'=>5],
        'acidity'     => ['type'=>'integer','minimum'=>1,'maximum'=>5],
        'sweetness'   => ['type'=>'integer','minimum'=>0,'maximum'=>5],
        'notes'       => ['type'=>'array','items'=>['type'=>'string']]
    ];
    $sys = 'You output compact JSON describing a dish for wine pairing.';
    $user = 'Dish: '.$dish."\nReturn fields per schema only.";
    $out = ai_responses_json($sys, $user, $schema, $AI_DEBUG);
    if (!$out) $out = ['cuisine'=>'','protein'=>'','cooking'=>'','sauce'=>'','spice_level'=>0,'richness'=>3,'acidity'=>3,'sweetness'=>0,'notes'=>[]];
    return $out;
}

function wine_vector_from_row(array $w): array {
    $type=strtolower($w['type']??''); $grapes=strtolower($w['grapes']??''); $style=strtolower($w['style']??'');
    $isRed  = str_contains($type,'red') || preg_match('/cabernet|syrah|nebbiolo|malbec|merlot|tempranillo|sangiovese|pinot noir|grenache|bordeaux|barolo|barbaresco/',$grapes);
    $isWhite= str_contains($type,'white')|| preg_match('/chardonnay|riesling|sauvignon|pinot gris|grüner|gruner|albarino|albariño|chenin|viognier/',$grapes);
    $isSpark= str_contains($type,'spark')|| str_contains($style,'champagne');
    $isSweet= preg_match('/(late harvest|sauternes|icewine|port|sherry|dessert|sweet)/',$style.$grapes.$type);
    $v = ['body'=>3,'acidity'=>3,'tannin'=>2,'sweetness'=>$isSweet?3:1,'oak'=>2];
    if ($isRed){$v['body']=3;$v['tannin']=3;}
    if (preg_match('/(cabernet|nebbiolo|barolo|barbaresco|bordeaux)/',$grapes)){$v['body']=4;$v['tannin']=4;$v['oak']=3;}
    if (preg_match('/(pinot noir|gamay)/',$grapes)){$v['body']=2;$v['tannin']=2;$v['oak']=1;}
    if ($isWhite){$v['tannin']=1;$v['acidity']=3;$v['oak']= str_contains($grapes,'chardonnay')?3:1;}
    if ($isSpark){$v['acidity']=4;$v['body']=2;$v['tannin']=1;}
    if (preg_match('/(riesling|moscato|gewürz|gewurz)/',$grapes)){$v['sweetness']=2;$v['acidity']=4;}
    return $v;
}

function score_row_pairing_ai(array $dish, array $vec, string $mood='classic'): float {
    $target = [
        'body'      => max(1,min(5,$dish['richness']??3)),
        'acidity'   => max(1,min(5,$dish['acidity']??3)),
        'tannin'    => ($dish['spice_level']??0)>=2 ? 1 : 3,
        'sweetness' => ($dish['sweetness']??0)>=2 ? 2 : 1,
        'oak'       => str_contains(strtolower($dish['sauce']??''),'cream') ? 3 : 2,
    ];
    $pen = 0.0;
    if (($dish['spice_level']??0)>=2 && ($vec['tannin']??3)>=3) $pen += 1.0;
    if (($dish['sweetness']??0)>=2 && ($vec['sweetness']??1)===0) $pen += 0.7;
    $dist=0.0; foreach($target as $k=>$t) $dist += abs(($vec[$k]??3)-$t);
    $base = 10 - $dist - $pen;
    if ($mood==='adventurous') $base += 0.3 * (($vec['oak']??2)+($vec['acidity']??3)-5);
    return $base;
}

function ai_pairing_reasons_batch(string $dish, array $wines, array &$AI_DEBUG): array {
    $n = count($wines); if ($n===0) return [];
    $apiKey = wch_env('OPENAI_API_KEY');
    $AI_DEBUG['env_key'] = $apiKey ? 'present' : 'missing';

    if (!$apiKey) {
        $fallbacks = [
            'Cuts the richness and lifts the flavors without overpowering the dish.',
            'Bright acidity refreshes the palate and syncs with the seasoning.',
            'Supple texture complements the dish’s weight and savory notes.',
            'Fruit and freshness echo the core flavors and keep things lively.',
            'Gentle structure supports the dish while staying graceful.'
        ];
        $out=[]; for($i=0;$i<$n;$i++) $out[]=$fallbacks[$i%count($fallbacks)];
        $AI_DEBUG['note_count']=$n; return $out;
    }

    // Compact rows for the prompt
    $rows=[]; foreach($wines as $w){ $rows[]=[
        'name'=>trim($w['name']??''), 'type'=>trim($w['type']??''), 'grapes'=>trim($w['grapes']??''), 'region'=>trim($w['region']??'')
    ]; }

    $sys = 'You generate one lively, 12–18 word sentence explaining why a wine pairs with a given dish. Avoid clichés.';
    $schema = [ 'reasons' => ['type'=>'array','items'=>['type'=>'string']] ];
    $user = "Dish: ".$dish."
For each wine row below, write ONE reason. Return JSON: {\"reasons\":[...]} in same order.
".json_encode($rows);

    $out = ai_responses_json($sys, $user, $schema, $AI_DEBUG);
    $list = isset($out['reasons']) && is_array($out['reasons']) ? $out['reasons'] : [];

    $reasons=[]; for($i=0;$i<$n;$i++){ $s=trim((string)($list[$i] ?? '')); if($s==='') $s='Balances the dish’s flavors with complementary body and refreshment.'; if(mb_strlen($s)>140)$s=mb_substr($s,0,137).'…'; $reasons[]=$s; }
    $AI_DEBUG['note_count'] = count($reasons);
    return $reasons;
}

// ---------- inputs ----------
$src = $_REQUEST ?? [];
$dish     = isset($src['dish']) ? trim((string)$src['dish']) : '';
$budget   = parse_money($src['budget'] ?? null);
$guests   = parse_int_sane($src['guests'] ?? null);
$regions  = isset($src['regions']) ? trim((string)$src['regions']) : '';
$spice    = isset($src['spice']) ? strtolower(trim((string)$src['spice'])) : '';
$occasion = isset($src['occasion']) ? strtolower(trim((string)$src['occasion'])) : '';
$mood     = val($src, 'mood', 'classic'); // classic | adventurous
$preferCellar = !empty($src['prefer_cellar']);
$email    = isset($src['email']) ? trim((string)$src['email']) : '';

$hasUserInput = arr_any([$dish,$budget,$guests,$regions,$spice,$occasion]);
$usedDemoDefaults = false;
if (!$hasUserInput || $budget<=0 || $guests<=0) {
    $budget = 100.0; $guests = 4;
    if ($regions==='') $regions = "California, Tuscany, Rioja";
    $usedDemoDefaults = true;
}

// bottles: 1 per 2–3 guests → midpoint 2.5
$bottlesNeeded = max(1, (int)ceil($guests / 2.5));

// Occasion-based per-bottle price multiplier
$priceMul = 1.0;
switch ($occasion) {
    case 'weeknight':   $priceMul = 0.9;  break;
    case 'date night':  $priceMul = 1.15; break;
    case 'friends over':$priceMul = 1.0;  break;
    case 'celebration': $priceMul = 1.3;  break;
    case 'holiday':     $priceMul = 1.2;  break;
}
$targetPerBottle = max(12.0, ($budget / $bottlesNeeded) * $priceMul);

// Heuristics from dish
$dish_l = strtolower($dish);
$prefs = pairing_profile($dish_l, $spice, $occasion);
$proteinTokens = protein_tokens_from($dish_l);

// Regions list
$regionWords = [];
if ($regions) {
    foreach (preg_split('/[,\|]+/u', (string)$regions) as $r) {
        $r = trim($r); if ($r!=='') $regionWords[] = strtolower($r);
    }
}

// ---------- DB guards (PDO only) ----------
$pdo_ok = isset($pdo) && ($pdo instanceof PDO);
$cat_ok = (isset($winelist_pdo) && ($winelist_pdo instanceof PDO)) || $pdo_ok;

// ---------- Inventory search ----------
$inv = [];
$had_qty_unknown = false;
if ($pdo_ok) {
    $where_or = []; $params = [];
    $i=0; foreach (array_unique($prefs['boost_types']) as $t) { $where_or[] = "(b.type LIKE :ptype$i OR b.style LIKE :pstyle$i OR b.grapes LIKE :pgrape$i)"; $params[":ptype$i"]="%$t%"; $params[":pstyle$i"]="%$t%"; $params[":pgrape$i"]="%$t%"; $i++; }
    $j=0; foreach ($regionWords as $r) { $where_or[] = "(b.region LIKE :r$j OR b.country LIKE :rc$j)"; $params[":r$j"]="%$r%"; $params[":rc$j"]="%$r%"; $j++; }
    if (!$where_or) $where_or[] = '1=1';

    // user scope (optional)
    $uid = null; if (isset($_SESSION['user']['id'])) $uid = (int)$_SESSION['user']['id']; elseif (isset($current_user['id'])) $uid = (int)$current_user['id'];

    $sql = "SELECT b.id, b.name, b.winery, b.vintage, b.region, b.type, b.style, b.grapes, b.image_url, b.photo_path,
                 b.quantity, b.price_paid, b.my_price, b.price, b.past, b.upc, b.barcode
          FROM bottles b
          WHERE (" . implode(" OR ", $where_or) . ")
            " . ($uid ? " AND (b.user_id = :uid)" : "") . "
            AND (b.past IS NULL OR b.past = 0)
          ORDER BY b.updated_at DESC, b.added_on DESC
          LIMIT 150";
    if ($uid) $params[':uid'] = $uid;

    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (trim((string)($r['name'] ?? ''))==='' && trim((string)($r['winery'] ?? ''))==='') continue;
            $qty_num = qty_number($r['quantity'] ?? null);
            if ($qty_num !== null && $qty_num < $bottlesNeeded) continue;
            if ($qty_num === null) $had_qty_unknown = true;
            $img = normalize_img($r['image_url'] ?: ($r['photo_path'] ?? null));
            $price = $r['price_paid'] ?? $r['my_price'] ?? $r['price'] ?? null;
            $inv[] = [
                'name'=>$r['name'] ?? '','winery'=>$r['winery'] ?? '','vintage'=> (string)($r['vintage'] ?? ''),
                'region'=>$r['region'] ?? '','type'=>$r['type'] ?? '','style'=>$r['style'] ?? '','grapes'=>$r['grapes'] ?? '',
                'image_url'=>$img, 'price'=> ($price!==null && $price!=='') ? (float)$price : null,
                'upc'=> strtolower(trim((string)($r['upc'] ?? ''))), 'barcode'=> strtolower(trim((string)($r['barcode'] ?? ''))),
                'source'=>'inventory'
            ];
        }
    } catch (Throwable $e) { /* ignore */ }
}

// Build sets to dedupe catalog against inventory
$invKeys = []; $invUPCs = []; $invBar  = [];
foreach ($inv as $r) {
    $invKeys[norm_key($r['winery'] ?? '', $r['name'] ?? '', $r['vintage'] ?? '')] = true;
    if (!empty($r['upc'])) $invUPCs[$r['upc']] = true;
    if (!empty($r['barcode'])) $invBar[$r['barcode']] = true;
}

// ---------- Catalog search ----------
$cat = [];
if ($cat_ok) {
    $dbh = isset($winelist_pdo) && ($winelist_pdo instanceof PDO) ? $winelist_pdo : $pdo;
    $where_or = []; $params = [];
    $i=0; foreach (array_unique($prefs['boost_types']) as $t) { $where_or[] = "(w.type LIKE :ptype$i OR w.style LIKE :pstyle$i OR w.grapes LIKE :pgrape$i OR w.region LIKE :preg$i)"; $params[":ptype$i"]="%$t%"; $params[":pstyle$i"]="%$t%"; $params[":pgrape$i"]="%$t%"; $params[":preg$i"]="%$t%"; $i++; }
    $j=0; foreach ($regionWords as $r) { $where_or[] = "(w.region LIKE :r$j OR w.country LIKE :rc$j)"; $params[":r$j"]="%$r%"; $params[":rc$j"]="%$r%"; $j++; }
    if (!$where_or) $where_or[] = '1=1';

    $sql = "SELECT w.name, w.winery, w.vintage, w.region, w.type, w.style, w.grapes, w.image_url, w.price, w.upc, w.barcode
          FROM wines w
          WHERE (" . implode(" OR ", $where_or) . ")
          ORDER BY w.created_at DESC
          LIMIT 300";
    try {
        $stmt = $dbh->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nm = trim((string)($r['name'] ?? '')); $wy = trim((string)($r['winery'] ?? ''));
            if ($nm==='' && $wy==='') continue;
            $key = norm_key($wy, $nm, (string)($r['vintage'] ?? ''));
            $u = strtolower(trim((string)($r['upc'] ?? ''))); $b = strtolower(trim((string)($r['barcode'] ?? '')));
            if (($u!=='' && isset($invUPCs[$u])) || ($b!=='' && isset($invBar[$b])) || isset($invKeys[$key])) continue;
            $cat[] = [
                'name'=>$nm,'winery'=>$wy,'vintage'=> (string)($r['vintage'] ?? ''),
                'region'=>$r['region'] ?? '','type'=>$r['type'] ?? '','style'=>$r['style'] ?? '','grapes'=>$r['grapes'] ?? '',
                'image_url'=> normalize_img($r['image_url'] ?? null), 'price'=> isset($r['price']) && $r['price']!=='' ? (float)$r['price'] : null,
                'upc'=>$u,'barcode'=>$b, 'source'=>'catalog'
            ];
        }
    } catch (Throwable $e) { /* ignore */ }

    if (!$cat) {
        try {
            $stmt = $dbh->query("SELECT name, winery, vintage, region, type, style, grapes, image_url, price, upc, barcode FROM wines ORDER BY created_at DESC LIMIT 50");
            if ($stmt) {
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $nm = trim((string)($r['name'] ?? '')); $wy = trim((string)($r['winery'] ?? ''));
                    if ($nm==='' && $wy==='') continue;
                    $key = norm_key($wy, $nm, (string)($r['vintage'] ?? ''));
                    $u = strtolower(trim((string)($r['upc'] ?? ''))); $b = strtolower(trim((string)($r['barcode'] ?? '')));
                    if (($u!=='' && isset($invUPCs[$u])) || ($b!=='' && isset($invBar[$b])) || isset($invKeys[$key])) continue;
                    $cat[] = [
                        'name'=>$nm,'winery'=>$wy,'vintage'=> (string)($r['vintage'] ?? ''),
                        'region'=>$r['region'] ?? '','type'=>$r['type'] ?? '','style'=>$r['style'] ?? '','grapes'=>$r['grapes'] ?? '',
                        'image_url'=> normalize_img($r['image_url'] ?? null), 'price'=> isset($r['price']) && $r['price']!=='' ? (float)$r['price'] : null,
                        'upc'=>$u,'barcode'=>$b, 'source'=>'catalog'
                    ];
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

// ---------- base scoring (heuristics) ----------
function score_row_pairing(array $r, float $targetPerBottle, string $spice, string $occasion, array $prefs, array $proteinTokens): float {
    $score = 0.0;
    $price = isset($r['price']) ? $r['price'] : null;
    if ($price !== null && $price > 0) { $diff = abs($price - $targetPerBottle); $score += max(0, 2.0 - ($diff / 10.0)); }
    else { $score += 0.6; }
    $type = strtolower(trim((string)($r['type'] ?? '')));
    $style= strtolower(trim((string)($r['style'] ?? '')));
    $gr   = strtolower(trim((string)($r['grapes'] ?? '')));
    $name = strtolower(trim((string)($r['name'] ?? '')));
    foreach ($prefs['boost_types'] as $bt) { if ($bt && (strpos($type,$bt)!==false || strpos($style,$bt)!==false || strpos($gr,$bt)!==false)) $score += 0.9; }
    if (!empty($prefs['sparkling_bias']) && (strpos($type,'sparkling')!==false || strpos($name,'champagne')!==false)) $score += 0.6;
    if ($occasion==='weeknight' && $price !== null && $price <= 20) $score += 0.4;
    if ($spice==='hot') {
        if (strpos($type,'white')!==false || strpos($type,'rose')!==false || strpos($type,'rosé')!==false || strpos($type,'sparkling')!==false) $score += 0.6;
        if (strpos($gr,'pinot noir')!==false || strpos($gr,'gamay')!==false) $score += 0.4;
    }
    foreach ($proteinTokens as $pt) if ($pt && strpos($name, $pt) !== false) $score -= 2.0;
    return $score;
}
foreach ($inv as &$r) { $r['_score_base'] = score_row_pairing($r, $targetPerBottle, $spice, $occasion, $prefs, $proteinTokens) + 2.0; } unset($r);
foreach ($cat as &$r) { $r['_score_base'] = score_row_pairing($r, $targetPerBottle, $spice, $occasion, $prefs, $proteinTokens); } unset($r);

// ---------- AI dish profile + AI scoring overlay ----------
$dp = ai_dish_profile($dish, $AI_DEBUG);
foreach ($inv as &$r) { $vec = wine_vector_from_row($r); $r['_score_ai'] = score_row_pairing_ai($dp, $vec, $mood); $r['_score_total'] = $r['_score_base'] + $r['_score_ai']; } unset($r);
foreach ($cat as &$r) { $vec = wine_vector_from_row($r); $r['_score_ai'] = score_row_pairing_ai($dp, $vec, $mood); $r['_score_total'] = $r['_score_base'] + $r['_score_ai']; } unset($r);

usort($inv, fn($a,$b)=> ($b['_score_total'] <=> $a['_score_total']));
usort($cat, fn($a,$b)=> ($b['_score_total'] <=> $a['_score_total']));

// ---------- selection ----------
$pickedInv = array_values(array_filter(array_slice($inv, 0, max(0, min(count($inv), $bottlesNeeded))), function($r){ return trim((string)($r['name'] ?? ''))!=='' || trim((string)($r['winery'] ?? ''))!==''; }));
$remaining = max(0, $bottlesNeeded - count($pickedInv));
$fillFromCat = $remaining > 0 ? array_slice($cat, 0, min(count($cat), $remaining)) : [];
$topCatalogShowcase = array_slice($cat, 0, min(10, count($cat)));

// ---------- AI pairing reasons (batch) ----------
$needReasons = array_merge($pickedInv, $fillFromCat, $topCatalogShowcase);
$reasons = ai_pairing_reasons_batch($dish, $needReasons, $AI_DEBUG);
$idx = 0;
$reasonsInv = []; for ($i=0; $i<count($pickedInv); $i++) { $reasonsInv[] = $reasons[$idx++] ?? ''; }
$reasonsFill = []; for ($i=0; $i<count($fillFromCat); $i++) { $reasonsFill[] = $reasons[$idx++] ?? ''; }
$reasonsShow = []; for ($i=0; $i<count($topCatalogShowcase); $i++) { $reasonsShow[] = $reasons[$idx++] ?? ''; }

// ---------- Optional mailer ----------
$CAN_SEND = false;
try { require_once __DIR__ . '/../mailer.php'; if (function_exists('send_mail') || function_exists('send_mail_with_overrides')) $CAN_SEND = true; } catch (Throwable $e) { $CAN_SEND = false; }

// ---------- email ----------
$sent = false; $sendErr = null;
$wantEmail = (isset($_POST['email_results']) && ($_POST['email_results']==='1' || $_POST['email_results']==='on'));
$sessionEmail = null;
if (isset($_SESSION['user']['email']) && filter_var($_SESSION['user']['email'], FILTER_VALIDATE_EMAIL)) { $sessionEmail = $_SESSION['user']['email']; }
elseif (isset($current_user['email']) && filter_var($current_user['email'], FILTER_VALIDATE_EMAIL)) { $sessionEmail = $current_user['email']; }
$emailTo = val($_REQUEST, 'email_to', ($email ?: $sessionEmail));
$bccEnv = wch_env('PAIRING_RESULTS_BCC') ?: (wch_env('MAIL_BCC') ?: null);
$fromEmail = wch_env('MAIL_FROM') ?: null; $fromName  = wch_env('MAIL_FROM_NAME') ?: null;

if ($wantEmail && $emailTo && filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
ob_start(); ?>
<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:#111; font-size:15px;">
    <h2 style="margin:0 0 8px 0;">Your pairing‑free picks</h2>
    <div style="margin-bottom:10px; color:#374151">
        <?php if ($dish): ?><b>Dish:</b> <?= h($dish) ?> &nbsp;•&nbsp;<?php endif; ?>
        <b>Budget:</b> $<?= number_format($budget,2) ?> &nbsp;•&nbsp;
        <b>Guests:</b> <?= (int)$guests ?> (≈ <?= (int)$bottlesNeeded ?> bottles) &nbsp;•&nbsp;
        <?php if ($regions): ?><b>Regions:</b> <?= h($regions) ?> &nbsp;•&nbsp;<?php endif; ?>
        <?php if ($spice): ?><b>Spice:</b> <?= h($spice) ?> &nbsp;•&nbsp;<?php endif; ?>
        <?php if ($occasion): ?><b>Occasion:</b> <?= h($occasion) ?><?php endif; ?>
    </div>
    <?php if ($pickedInv): ?>
        <h3>From your inventory</h3>
        <ol style="padding-left:18px; margin:0 0 8px 0;">
            <?php foreach ($pickedInv as $i=>$r): ?>
                <li style="margin:6px 0">
                    <b><?= h($r['name'] ?? '') ?></b><?= !empty($r['vintage']) ? ' · '.h((string)$r['vintage']) : '' ?> — <?= h($r['region'] ?? '') ?> · <?= h($r['type'] ?? '') ?><br>
                    <span style="color:#374151"><?= h($reasonsInv[$i] ?? '') ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <?php if ($fillFromCat): ?>
        <h3>Outside your inventory (to fill bottles)</h3>
        <ol style="padding-left:18px; margin:0 0 8px 0;">
            <?php foreach ($fillFromCat as $i=>$r): ?>
                <li style="margin:6px 0">
                    <b><?= h($r['name'] ?? '') ?></b><?= !empty($r['vintage']) ? ' · '.h((string)$r['vintage']) : '' ?> — <?= h($r['region'] ?? '') ?> · <?= h($r['type'] ?? '') ?><br>
                    <span style="color:#374151"><?= h($reasonsFill[$i] ?? '') ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>
<?php
$emailHtml = ob_get_clean();
$subject = "Your pairing‑free wine picks";
$text = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $emailHtml));
if ($CAN_SEND) {
    try {
        if (function_exists('send_mail_with_overrides')) {
            $sent = send_mail_with_overrides($emailTo, $subject, $emailHtml, $text, $bccEnv, ['fromEmail'=>$fromEmail,'fromName'=>$fromName]);
        } else {
            $sent = send_mail($emailTo, $subject, $emailHtml, $text, $bccEnv, $fromEmail, $fromName);
        }
    } catch (Throwable $e) { $sent = false; $sendErr = "Could not send email. Check mailer settings."; }
} else { $sendErr = "Email service unavailable on this server."; }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pairing‑Free Results</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root{ --bg:#f6f7fb; --text:#111827; --muted:#6b7280; --card:#ffffff; --accent:#7c3aed; --border:#e5e7eb; --ok:#16a34a; --warn:#b91c1c; }
        *{box-sizing:border-box}
        body{margin:0; font:16px/1.5 'Inter',system-ui,Segoe UI,Arial,sans-serif; background:var(--bg); color:var(--text);}
        .container{max-width:1000px; margin:40px auto; padding:0 16px;}
        .header{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;}
        .badge{display:inline-block; padding:2px 8px; border:1px solid var(--border); border-radius:999px; font-size:12px; color:var(--muted); background:#fff;}
        .card{background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px 18px; box-shadow:0 1px 2px rgba(0,0,0,.04);}
        h1{font-size:22px; margin:0 0 8px 0;}
        .grid{display:grid; grid-template-columns: 1fr; gap:12px;}
        @media (min-width: 780px){ .grid{ grid-template-columns: 1fr 1fr; } }
        .item{display:flex; gap:12px; align-items:flex-start;}
        .item img{width:72px; height:72px; object-fit:cover; border-radius:10px; border:1px solid var(--border); background:#fafafa;}
        .item .meta{flex:1}
        .item .name{font-weight:600}
        .item .muted{color:var(--muted); font-size:14px}
        .item .price{font-weight:600}
        .hr{height:1px; background:var(--border); margin:12px 0}
        .note{font-size:14px; color:#374151; background:#fafafa; border:1px dashed var(--border); border-radius:12px; padding:10px 12px; margin-top:6px;}
        .footer{margin-top:16px; font-size:14px; color:#6b7280;}
        .controls{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
        .controls input[type="email"]{border:1px solid var(--border); border-radius:10px; padding:8px 10px; font:inherit; min-width:220px;}
        .controls button{background:var(--accent); color:#fff; border:none; border-radius:10px; padding:8px 12px; font-weight:600; cursor:pointer;}
        .alert{margin-top:8px; font-size:14px;}
        .ok{color:var(--ok);} .warn{color:var(--warn);}
        .section-title{font-weight:600; margin:12px 0 6px 0;}
        .placeholder{background:#fafafa; border:1px dashed var(--border); width:72px; height:72px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:12px; color:#9ca3af;}
        .caveat{margin-top:8px; font-size:13px; color:#7c3aed;}
        .subtitle{font-size:13px; color:#6b7280; margin-top:0; margin-bottom:8px;}
        .pill{display:inline-block; padding:2px 8px; border:1px solid var(--border); border-radius:999px; font-size:12px; color:#6b7280; margin-right:6px;}
    </style>
    <?php if (!empty($_GET['debug'])): ?>
        <div style="font:12px/1.4 system-ui; background:#111; color:#0f0; padding:8px 10px; border-radius:10px;">
            <b>AI DEBUG</b> — key: <?=h($AI_DEBUG['env_key'])?>,
            prepared: <?=h((string)$AI_DEBUG['prepared'])?>,
            did_curl: <?=h($AI_DEBUG['did_curl']?'yes':'no')?>,
            http: <?=h((string)($AI_DEBUG['http'] ?? 'null'))?>,
            curl_err: <?=h((string)($AI_DEBUG['curl_err'] ?? ''))?>,
            got_text: <?=h((string)$AI_DEBUG['got_text'])?>,
            parsed_json: <?=h((string)$AI_DEBUG['parsed_json'])?>,
            reason_count: <?=h((string)$AI_DEBUG['note_count'])?>,
            fallback: <?= h((string)($AI_DEBUG['fallback'] ?? '')) ?>,
            resp_head: <?= h((string)($AI_DEBUG['resp_body_head'] ?? '')) ?>

        </div>
    <?php endif; ?>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Pairing‑Free Results</h1>
            <?php if (!empty($usedDemoDefaults)): ?><span class="badge">Demo mode</span><?php endif; ?>
            <?php if ($dish): ?><div class="pill">Dish: <?= h($dish) ?></div><?php endif; ?>
            <?php if ($mood): ?><div class="pill">Mood: <?= h($mood) ?></div><?php endif; ?>
        </div>
        <form class="card" method="post" style="display:inline-block">
            <!-- Preserve inputs for email postback -->
            <input type="hidden" name="dish" value="<?= h($dish) ?>">
            <input type="hidden" name="budget" value="<?= h((string)$budget) ?>">
            <input type="hidden" name="guests" value="<?= h((string)$guests) ?>">
            <input type="hidden" name="regions" value="<?= h($regions) ?>">
            <input type="hidden" name="spice" value="<?= h($spice) ?>">
            <input type="hidden" name="occasion" value="<?= h($occasion) ?>">
            <input type="hidden" name="mood" value="<?= h($mood) ?>">
            <input type="hidden" name="prefer_cellar" value="<?= $preferCellar ? '1':'0' ?>">
            <input type="hidden" name="email_results" value="1">
            <div class="controls">
                <label for="email_to" class="muted">Email these results:</label>
                <input type="email" id="email_to" name="email_to" placeholder="you@example.com" value="<?= h((string)($emailTo ?? $email ?? '')) ?>" required>
                <button type="submit">Send</button>
            </div>
            <?php if ($wantEmail): ?>
                <?php if ($sent): ?>
                    <div class="alert ok">✅ Sent to <?= h((string)$emailTo) ?></div>
                <?php elseif ($sendErr): ?>
                    <div class="alert warn">⚠️ <?= h($sendErr) ?></div>
                <?php else: ?>
                    <div class="alert warn">⚠️ Email not sent. Check mail settings.</div>
                <?php endif; ?>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($pickedInv): ?>
        <div class="section-title">From your inventory</div>
        <p class="subtitle">We’ll start with bottles you already own.</p>
        <div class="grid" style="margin-bottom: 8px;">
            <?php foreach ($pickedInv as $i=>$r): $imgUrl = $r['image_url'] ?? ''; ?>
                <div class="item card">
                    <?php if ($imgUrl): ?>
                        <img src="<?= h($imgUrl) ?>" alt="<?= h($r['name'] ?? '') ?>">
                    <?php else: ?>
                        <div class="placeholder">No image</div>
                    <?php endif; ?>
                    <div class="meta">
                        <div class="name"><?= h($r['name'] ?? '') ?> <?= !empty($r['vintage']) ? '· '.h((string)$r['vintage']) : '' ?></div>
                        <div class="muted"><?= h($r['winery'] ?? '') ?><?= !empty($r['winery']) ? ' · ' : '' ?><?= h($r['region'] ?? '') ?><?= !empty($r['type']) ? ' · '.h($r['type']) : '' ?></div>
                        <div class="hr"></div>
                        <div class="note"><b>AI pairing:</b> <?= h($reasonsInv[$i] ?? '') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($had_qty_unknown): ?>
        <div class="caveat">Heads up: some inventory picks don’t have a logged quantity. You may not have enough bottles—please check on‑hand stock.</div>
    <?php endif; ?>

    <?php if ($fillFromCat): ?>
        <div class="section-title">Outside your inventory (to fill bottles)</div>
        <p class="subtitle">Suggestions that match your dish and budget to round out your count.</p>
        <div class="grid" style="margin-bottom: 8px;">
            <?php foreach ($fillFromCat as $i=>$r): $imgUrl = $r['image_url'] ?? ''; $price  = isset($r['price']) ? '$'.number_format((float)$r['price'], 2) : '—'; ?>
                <div class="item card">
                    <?php if ($imgUrl): ?>
                        <img src="<?= h($imgUrl) ?>" alt="<?= h($r['name'] ?? '') ?>">
                    <?php else: ?>
                        <div class="placeholder">No image</div>
                    <?php endif; ?>
                    <div class="meta">
                        <div class="name"><?= h($r['name'] ?? '') ?> <?= !empty($r['vintage']) ? '· '.h((string)$r['vintage']) : '' ?></div>
                        <div class="muted"><?= h($r['winery'] ?? '') ?><?= !empty($r['winery']) ? ' · ' : '' ?><?= h($r['region'] ?? '') ?><?= !empty($r['type']) ? ' · '.h($r['type']) : '' ?></div>
                        <div class="hr"></div>
                        <div class="price"><?= $price ?></div>
                        <div class="note"><b>AI pairing:</b> <?= h($reasonsFill[$i] ?? '') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($topCatalogShowcase): ?>
        <div class="section-title">More picks (catalog)</div>
        <p class="subtitle">Up to 10 more ideas that fit the profile.</p>
        <div class="grid">
            <?php foreach ($topCatalogShowcase as $i=>$r): $imgUrl = $r['image_url'] ?? ''; $price  = isset($r['price']) ? '$'.number_format((float)$r['price'], 2) : '—'; ?>
                <div class="item card">
                    <?php if ($imgUrl): ?>
                        <img src="<?= h($imgUrl) ?>" alt="<?= h($r['name'] ?? '') ?>">
                    <?php else: ?>
                        <div class="placeholder">No image</div>
                    <?php endif; ?>
                    <div class="meta">
                        <div class="name"><?= h($r['name'] ?? '') ?> <?= !empty($r['vintage']) ? '· '.h((string)$r['vintage']) : '' ?></div>
                        <div class="muted"><?= h($r['winery'] ?? '') ?><?= !empty($r['winery']) ? ' · ' : '' ?><?= h($r['region'] ?? '') ?><?= !empty($r['type']) ? ' · '.h($r['type']) : '' ?></div>
                        <div class="hr"></div>
                        <div class="price"><?= $price ?></div>
                        <div class="note"><b>AI pairing:</b> <?= h($reasonsShow[$i] ?? '') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$pickedInv && !$fillFromCat && !$topCatalogShowcase): ?>
        <div class="card">No matches yet—try adding a region (e.g., "Napa, Rioja"), or set spice/occasion for better pairing signals.</div>
    <?php endif; ?>

    <div class="footer">
        Enjoy these? Grab the <a href="/store/journal-free.php">Wine Tasting Journal (free)</a> and keep notes like a pro.
    </div>
</div>
</body>
</html>
