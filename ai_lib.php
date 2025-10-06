<?php // ai_lib.php
declare(strict_types=1);

/* ---------- cache key + prompt ---------- */

function ai_cache_key_from_row(array $row): string {
    // Normalize to avoid duplicate keys
    $wine_id = (int)($row['wine_id'] ?? 0);
    $name    = mb_strtolower(trim((string)($row['name'] ?? '')));
    $winery  = mb_strtolower(trim((string)($row['winery'] ?? '')));
    $vintage = preg_replace('/\D+/', '', (string)($row['vintage'] ?? '')) ?: '';
    $region  = mb_strtolower(trim((string)($row['region'] ?? '')));
    $country = mb_strtolower(trim((string)($row['country'] ?? '')));
    $grapes  = mb_strtolower(trim((string)($row['grapes'] ?? '')));

    $basis = json_encode([$wine_id, $name, $winery, $vintage, $region, $country, $grapes], JSON_UNESCAPED_UNICODE);
    // Short, stable key (<=128)
    return 'ai:' . substr(hash('sha256', $basis), 0, 64);
}

function ai_prompt_from_row(array $row): string {
    $bits = [];
    foreach (['name','winery','vintage','region','country','grapes','type'] as $k) {
        if (!empty($row[$k])) $bits[] = strtoupper($k) . ': ' . $row[$k];
    }
    $ctx = implode("\n", $bits);
    return <<<TXT
You are a concise wine expert. Write a tight 2–3 sentence tasting blurb (≤80 words), no fluff or emojis.
Mention style, 2–4 key aromas/flavors, structure, and a quick food/serving hint if relevant.

$ctx
TXT;
}

/* ---------- LLM calls (OpenAI first, Ollama fallback) ---------- */

function ai_call_openai(string $prompt): ?string {
    $key = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
    if (!$key) return null;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$key,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'temperature' => 0.5,
            'messages' => [
                ['role'=>'system','content'=>'You are a sommelier writing concise, specific tasting blurbs.'],
                ['role'=>'user','content'=>$prompt],
            ],
        ], JSON_UNESCAPED_SLASHES),
    ]);
    $res = curl_exec($ch);
    if ($res === false) return null;
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code < 200 || $code >= 300) return null;
    $j = json_decode($res, true);
    $txt = $j['choices'][0]['message']['content'] ?? null;
    return $txt ? trim($txt) : null;
}

function ai_call_ollama(string $prompt): ?string {
    $url   = getenv('OLLAMA_URL')   ?: 'http://localhost:11434/api/generate';
    $model = getenv('OLLAMA_MODEL') ?: 'llama3.1';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model'=>$model, 'prompt'=>$prompt, 'stream'=>false]),
    ]);
    $res = curl_exec($ch);
    if ($res === false) return null;
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code < 200 || $code >= 300) return null;
    $j = json_decode($res, true);
    $txt = $j['response'] ?? null;
    return $txt ? trim($txt) : null;
}

/* ---------- cache ops (ALWAYS pass $winelist_pdo) ---------- */

function ai_get_cached(PDO $winelist_pdo, string $cacheKey): ?string {
    $st = $winelist_pdo->prepare("SELECT desc_md FROM wine_ai_cache WHERE cache_key = ? LIMIT 1");
    $st->execute([$cacheKey]);
    $val = $st->fetchColumn();
    return ($val !== false && $val !== '') ? (string)$val : null;
}
function ai_put_cache(PDO $winelist_pdo, string $cacheKey, array $row, string $descMd): void {
    $sql = "INSERT INTO wine_ai_cache
            (cache_key, wine_id, name, winery, vintage, region, country, grapes, desc_md, updated_at)
            VALUES (:cache_key, :wine_id, :name, :winery, :vintage, :region, :country, :grapes, :desc_md, NOW())
            ON DUPLICATE KEY UPDATE
              wine_id = VALUES(wine_id),
              name    = VALUES(name),
              winery  = VALUES(winery),
              vintage = VALUES(vintage),
              region  = VALUES(region),
              country = VALUES(country),
              grapes  = VALUES(grapes),
              desc_md = VALUES(desc_md),
              updated_at = NOW()";
    $winelist_pdo->prepare($sql)->execute([
        ':cache_key' => $cacheKey,
        ':wine_id'   => (int)($row['wine_id'] ?? 0),
        ':name'      => (string)($row['name'] ?? ''),
        ':winery'    => (string)($row['winery'] ?? ''),
        ':vintage'   => (string)($row['vintage'] ?? ''),
        ':region'    => (string)($row['region'] ?? ''),
        ':country'   => (string)($row['country'] ?? ''),
        ':grapes'    => (string)($row['grapes'] ?? ''),
        ':desc_md'   => $descMd,
    ]);
}

function ai_cache_get_bulk(PDO $winelist_pdo, array $cacheKeys): array {
    $cacheKeys = array_values(array_unique(array_filter($cacheKeys, 'strlen')));
    if (!$cacheKeys) return [];
    $in  = implode(',', array_fill(0, count($cacheKeys), '?'));
    $sql = "SELECT cache_key, desc_md FROM wine_ai_cache WHERE cache_key IN ($in)";
    $st  = $winelist_pdo->prepare($sql);
    $st->execute($cacheKeys);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[$r['cache_key']] = $r['desc_md'];
    }
    return $out;
}
/**
 * Generate & cache if missing. Returns the description (cached or newly generated), or null on failure.
 * NOTE: pass $winelist_pdo from db.php (the catalog DB), not $pdo.
 */
function ai_warm_one(PDO $winelist_pdo, array $row): ?string {
    $key = ai_cache_key_from_row($row);
    if (($cached = ai_get_cached($winelist_pdo, $key)) !== null) return $cached;

    $prompt = ai_prompt_from_row($row);
    $desc = ai_call_openai($prompt);
    if ($desc === null) $desc = ai_call_ollama($prompt);
    if ($desc === null) return null;

    ai_put_cache($winelist_pdo, $key, $row, $desc);
    return $desc;
}
