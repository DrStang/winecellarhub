<?php
// search_wine83125.php — robust JSON mode + FULLTEXT (name, winery, grapes, region, country) + LIKE fallback

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/search_lib.php';
if (function_exists('require_login')) { require_login(); }


header('Content-Type: application/json');

// ------- Safety: always JSON errors, no HTML -------
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_error_handler(function($errno,$errstr,$errfile,$errline){
    http_response_code(500);
    echo json_encode(['error'=>"PHP error: $errstr at $errfile:$errline"]); exit;
});
set_exception_handler(function($ex){
    http_response_code(500);
    echo json_encode(['error'=>"Exception: ".$ex->getMessage()]); exit;
});

// ------- Basic env / deps -------
if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
    echo json_encode(['error'=>'Catalog DB ($winelist_pdo) not available']); exit;
}
if (!extension_loaded('curl')) {
    echo json_encode(['error'=>'PHP cURL extension is not enabled']); exit;
}
$OPENAI = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
if (!$OPENAI) {
    echo json_encode(['error'=>'Server is missing OPENAI_API_KEY']); exit;
}

// ------- Health checks -------
if (isset($_GET['ping'])) { echo json_encode(['ok'=>true]); exit; }
if (isset($_GET['selftest'])) {
    try {
        $winelist_pdo->query("SELECT 1");
        echo json_encode(['ok'=>true,'db'=>true,'curl'=>true]); exit;
    } catch (Throwable $t) {
        echo json_encode(['ok'=>false,'db_error'=>$t->getMessage()]); exit;
    }
}

// ------- Helpers -------
function bool_query_from_text($q){
    $q=trim($q); if($q==='') return '';
    $parts=preg_split('/\s+/', $q);
    $tokens=[];
    foreach($parts as $p){
        $p=preg_replace('/[+\-~*@()"><]/',' ',$p);
        $p=trim($p); if($p==='') continue;
        if(mb_strlen($p)>=3) $p.='*';
        $tokens[]='+'.$p;
    }
    return implode(' ',$tokens);
}

function db_candidates(PDO $pdo, $q){
    $q=trim($q); if($q==='') return [];
    $bool=bool_query_from_text($q);

    // EXACTLY the FT columns you said exist
    $ft_cols="name, winery, grapes, region, country";

    if($bool!==''){
        try{
            $sql="SELECT id, name, winery, grapes, vintage, region, country, upc,
                   price, rating, style, type, image_url,
                   MATCH($ft_cols) AGAINST (:bq IN BOOLEAN MODE) AS score
            FROM wines
            WHERE MATCH($ft_cols) AGAINST (:bq IN BOOLEAN MODE)
            ORDER BY score DESC, vintage DESC
            LIMIT 50";
            $st=$pdo->prepare($sql);
            $st->execute([':bq'=>$bool]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }catch(PDOException $e){
            // Fall through to LIKE
        }
    }

    // LIKE fallback (note: vintage is INT; cast to char for LIKE)
    $like='%'.$q.'%';
    $sql="SELECT id, name, winery, grapes, vintage, region, country, upc,
               price, rating, style, type, image_url
        FROM wines
        WHERE name LIKE :q OR winery LIKE :q OR grapes LIKE :q
           OR region LIKE :q OR country LIKE :q
           OR CAST(vintage AS CHAR) LIKE :q
        ORDER BY vintage DESC
        LIMIT 50";
    $st=$pdo->prepare($sql);
    $st->execute([':q'=>$like]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ai_extract_json($image_b64, $prompt, $apiKey){
    $payload = [
        "model" => "gpt-4o-mini",
        "response_format" => ["type" => "json_object"], // <-- FORCE pure JSON
        "messages" => [[
            "role" => "user",
            "content" => [
                ["type"=>"text","text"=>$prompt],
                ["type"=>"image_url","image_url"=>["url"=>$image_b64]]
            ]
        ]],
        "temperature" => 0.2
    ];
    $ch=curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER=>[
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>60
    ]);
    $res=curl_exec($ch);
    if($res===false){ $err=curl_error($ch); curl_close($ch); return ['error'=>"cURL failed: $err"]; }
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($code<200||$code>=300){ return ['error'=>"OpenAI HTTP $code", 'raw'=>substr($res,0,400)]; }
    $data=json_decode($res,true);
    $txt=$data['choices'][0]['message']['content'] ?? '';
    $json=json_decode($txt,true);
    if(!is_array($json)) return ['error'=>'Model did not return JSON','raw'=>$txt];
    return ['json'=>$json];
}

// ------- Routes -------
$raw=file_get_contents('php://input');
$inp=json_decode($raw,true); if(!$inp) $inp=$_POST;
$mode=$inp['mode'] ?? 'manual';

if($mode==='manual'){
    $q=trim($inp['query'] ?? '');
    echo json_encode(['candidates'=> db_candidates($winelist_pdo,$q) ]); exit;
}

if($mode==='label'){
    $image_b64=$inp['image_b64'] ?? null;
    if(!$image_b64){ echo json_encode(['error'=>'image_b64 required']); exit; }

    $prompt = "Return ONLY a JSON object with keys:
{name, winery, grapes, vintage, region, country, type, style, image_url, confidence}.
Unknowns must be empty strings. confidence is a float 0..1. No extra text.";

    $out=ai_extract_json($image_b64,$prompt,$OPENAI);
    if(!empty($out['error'])){ echo json_encode(['error'=>$out['error'],'raw'=>$out['raw']??null]); exit; }

    $extracted=$out['json'];
    // sanitize types a bit
    if(isset($extracted['confidence'])) $extracted['confidence']=floatval($extracted['confidence']);

    $rows=db_candidates($winelist_pdo,$extracted['name'] ?? '');
    echo json_encode(['extracted'=>$extracted,'candidates'=>$rows]); exit;
}

if($mode==='barcode'){
    $image_b64=$inp['image_b64'] ?? null;
    if(!$image_b64){ echo json_encode(['error'=>'image_b64 required']); exit; }

    $prompt='Return ONLY a JSON object like {"upc":"###########","confidence":0..1}. If unreadable, return {"upc":"","confidence":0}.';

    $out=ai_extract_json($image_b64,$prompt,$OPENAI);
    if(!empty($out['error'])){ echo json_encode(['error'=>$out['error'],'raw'=>$out['raw']??null]); exit; }

    $j=$out['json'];
    $upc=preg_replace('/[^0-9]/','', $j['upc'] ?? '');
    $confidence=isset($j['confidence']) ? floatval($j['confidence']) : 0.0;

    $rows=$upc ? db_candidates($winelist_pdo,$upc) : [];
    echo json_encode(['upc'=>$upc,'confidence'=>$confidence,'candidates'=>$rows]); exit;
}

echo json_encode(['error'=>'unknown mode']);
