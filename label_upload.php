<?php
// label_upload.php — AI label analyzer + catalog match (stable handoff)
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';          // $winelist_pdo
require_once __DIR__ . '/search_lib.php';  // best_catalog_match(), slib_normalize_vintage()

function out(int $code, array $payload){ http_response_code($code); echo json_encode($payload, JSON_UNESCAPED_SLASHES); exit; }
function same_origin_ok(): bool {
    $host    = $_SERVER['HTTP_HOST'] ?? '';
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $xhr     = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $oh = $origin ? parse_url($origin, PHP_URL_HOST) : '';
    $rh = $referer ? parse_url($referer, PHP_URL_HOST) : '';
    return $xhr && (($oh && $oh === $host) || ($rh && $rh === $host));
}
function csrf_gate(): void {
    $expected = (string)($_SESSION['csrf'] ?? '');
    $got = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
    if ($expected !== '') {
        if ($got !== '' && hash_equals($expected, $got)) return;
        error_log("label_upload: CSRF mismatch exp=".substr($expected,0,6)." got=".substr($got,0,6));
        out(403, ['error'=>'forbidden','detail'=>'csrf']);
    }
    if (same_origin_ok()) { if ($got!=='') $_SESSION['csrf']=$got; return; }
    error_log("label_upload: CSRF missing and same-origin check failed"); out(403, ['error'=>'forbidden','detail'=>'csrf']);
}
function pick_file_key(): string { foreach (['photo','label_image','image'] as $k) if (!empty($_FILES[$k])) return $k; out(400, ['error'=>'no_file']); }
function save_cover(string $tmp, ?string $origName): string {
    $covers = __DIR__.'/covers'; if (!is_dir($covers)) @mkdir($covers, 0775, true);
    $basename = 'label_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.jpg';
    $bin = @file_get_contents($tmp); $im  = $bin !== false ? @imagecreatefromstring($bin) : false;
    if (!$im) throw new RuntimeException('Unsupported image data.');
    imageinterlace($im, true); imagejpeg($im, $covers.'/'.$basename, 82); imagedestroy($im);
    return '/covers/'.$basename;
}
function public_url(string $rel): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost'; return $scheme.$host.$rel;
}
function coalesce_type(?string $raw): ?string {
    if ($raw===null) return null; $t=strtolower(trim($raw));
    $map=['red'=>'Red','white'=>'White','rose'=>'Rosé','rosé'=>'Rosé','sparkling'=>'Sparkling','orange'=>'Orange','dessert'=>'Dessert','fortified'=>'Fortified'];
    return $map[$t] ?? ($t ? ucfirst($t) : null);
}
function clean_json_text(string $txt): ?array { $s=strpos($txt,'{'); $e=strrpos($txt,'}'); if($s===false||$e===false||$e<=$s) return null; $slice=substr($txt,$s,$e-$s+1); $j=json_decode($slice,true); return is_array($j)?$j:null; }

csrf_gate();

// file
$key = pick_file_key(); $err = (int)($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) { out(400, ['error'=>'upload','detail'=>$err]); }
$tmp = $_FILES[$key]['tmp_name']; $orig= (string)($_FILES[$key]['name'] ?? 'photo.jpg');
try { $rel = save_cover($tmp, $orig); $image_url = public_url($rel); } catch (Throwable $e) { out(400, ['error'=>'save_cover','detail'=>$e->getMessage()]); }

// OpenAI
$candidates=[]; $ai_error=null;
try {
    $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? ($_SERVER['OPENAI_API_KEY'] ?? ''));
    if (!$apiKey) throw new RuntimeException('OPENAI_API_KEY not set');

    $prompt = <<<PROMPT
You are a wine label parser. Return a single JSON object with these keys (empty string if unknown):
name, winery, vintage, grapes, region, country, type, style, barcode, upc, sub_region
Rules:
- "vintage" should be a 4-digit year or "" for NV.
- "type" one of: Red, White, Rosé, Sparkling, Orange, Dessert, Fortified (or "" if unsure).
- Output JSON only (no prose).
PROMPT;

    $payload = [
        "model" => "gpt-4o-mini",
        "input" => [[ "role"=>"user", "content"=>[
            ["type"=>"input_text","text"=>$prompt],
            ["type"=>"input_image","image_url"=>$image_url],
        ]]],
        "max_output_tokens" => 600,
        "temperature" => 0.1,
    ];
    $ch = curl_init("https://api.openai.com/v1/responses");
    curl_setopt_array($ch, [ CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>["Authorization: Bearer {$apiKey}", "Content-Type: application/json","Accept: application/json"],
        CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_TIMEOUT=>45 ]);
    $raw  = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($raw === false || $http >= 400) throw new RuntimeException("OpenAI HTTP {$http}: {$cerr} ".substr((string)$raw,0,300));

    $jr    = json_decode($raw, true);
    $otxt  = $jr['output_text'] ?? ($jr['output'][0]['content'][0]['text'] ?? ($jr['choices'][0]['message']['content'] ?? null));
    if (!$otxt) throw new RuntimeException('OpenAI returned no text');
    $obj = clean_json_text($otxt); if (!$obj) throw new RuntimeException('OpenAI returned non-JSON');

    $ai = [
        'name'       => trim((string)($obj['name'] ?? '')),
        'winery'     => trim((string)($obj['winery'] ?? '')),
        'vintage'    => normalize_vintage($obj['vintage'] ?? null),
        'grapes'     => trim((string)($obj['grapes'] ?? '')),
        'region'     => trim((string)($obj['region'] ?? '')),
        'country'    => trim((string)($obj['country'] ?? '')),
        'type'       => coalesce_type($obj['type'] ?? ''),
        'style'      => trim((string)($obj['style'] ?? '')),
        'barcode'    => trim((string)($obj['barcode'] ?? ($obj['upc'] ?? ''))),
        'sub_region' => trim((string)($obj['sub_region'] ?? '')),
        'image_url'  => $image_url,
    ];

    // one stable server-side match
    $match = best_catalog_match($winelist_pdo, $ai); // should return a row or null

    $candidates[] = [
        'name'            => $ai['name'],
        'winery'          => $ai['winery'],
        'vintage'         => $ai['vintage'],
        'grapes'          => $ai['grapes'] ?: null,
        'region'          => $ai['region'] ?: null,
        'country'         => $ai['country'] ?: null,
        'type'            => $ai['type'] ?: null,
        'style'           => $ai['style'] ?: null,
        'barcode'         => $ai['barcode'] ?: null,
        'image_url'       => $image_url,
        'matched_catalog' => (bool)$match,
        'catalog_wine_id' => $match['id'] ?? null,
        'catalog_row'     => $match ?: null,
    ];
} catch (Throwable $e) {
    $ai_error = $e->getMessage();
    $candidates[] = [
        'name'=> '', 'winery'=>'', 'vintage'=>null, 'grapes'=>null, 'region'=>null, 'country'=>null, 'type'=>null, 'style'=>null, 'barcode'=>null,
        'image_url'=>$image_url, 'matched_catalog'=>false, 'catalog_wine_id'=>null, 'catalog_row'=>null, 'ai_error'=>$ai_error,
    ];
}

out(200, ['candidates'=>$candidates]);
