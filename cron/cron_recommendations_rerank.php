<?php
require_once __DIR__ . '/../db.php';

/**
 * Helper: cosine similarity between two dense vectors (assoc arrays of floats).
 */
function cosine_sim(array $a, array $b): float {
    $dot=0.0; $na=0.0; $nb=0.0;
    foreach ($a as $k=>$va) {
        if (!isset($b[$k])) continue;
        $vb=floatval($b[$k]);
        $dot += $va*$vb; $na += $va*$va; $nb += $vb*$vb;
    }
    if ($na==0||$nb==0) return 0.0;
    return $dot/(sqrt($na)*sqrt($nb));
}

/** Split varietals from wines.grapes */
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

/** Compact user taste summary for the prompt */
function summarize_user(array $profile): array {
    $vec = $profile['style_vector'] ? json_decode($profile['style_vector'], true) : [];
    // pick top-3 style dims by magnitude
    $topDims = [];
    foreach ($vec as $k=>$v) $topDims[] = [$k, floatval($v)];
    usort($topDims, fn($a,$b)=> abs($b[1])<=>abs($a[1]));
    $topDims = array_slice($topDims, 0, 3);

    return [
        'price_min' => $profile['avg_price_min'] !== null ? floatval($profile['avg_price_min']) : null,
        'price_max' => $profile['avg_price_max'] !== null ? floatval($profile['avg_price_max']) : null,
        'varietal_top3' => json_decode($profile['varietal_top3'] ?? '[]', true) ?: [],
        'region_top3'   => json_decode($profile['region_top3'] ?? '[]', true) ?: [],
        'style_top'     => array_map(fn($x)=> $x[0], $topDims),
    ];
}

/** Build the blended score (same logic as your API, but local here) */
function blended_score(array $userVec, array $userPref, array $wineRow): array {
    $score = 0.0; $reasons = [];

    // style
    if ($userVec && $wineRow['style_vector']) {
        $sim = cosine_sim($userVec, json_decode($wineRow['style_vector'], true) ?: []);
        $score += 0.55 * $sim;
        $reasons[] = "style ".round($sim,2);
    }

    // price
    $pmin = $userPref['pmin']; $pmax = $userPref['pmax'];
    $price = floatval($wineRow['price']);
    if ($price >= $pmin && $price <= $pmax) { $score += 0.20; $reasons[] = "price fit"; }

    // varietal
    $prefVar = $userPref['vars']; $hit = false;
    foreach (parse_varietals($wineRow['grapes'] ?? '') as $gv) {
        if (in_array($gv, $prefVar, true)) { $hit = true; break; }
    }
    if ($hit) { $score += 0.10; $reasons[] = "varietal"; }

    // region
    if (in_array(mb_strtolower(trim($wineRow['region'] ?? '')), $userPref['regs'], true)) {
        $score += 0.05; $reasons[] = "region";
    }

    // recency bump
    $score += 0.10;

    return [$score, implode(', ', $reasons)];
}

/** Call OpenAI to rerank top 60 into 24 curated picks with reasons */
function llm_rerank(array $userSummary, array $candidates): array {
    $key = $_ENV['OPENAI_API_KEY'] ?? null;
    if (!$key) return [];

    // Keep the payload compact: only what the model needs
    $payload = [
        'user' => $userSummary,
        'candidates' => array_map(function($w) {
            // shorten style vector to top 4 dims for tokens
            $vec = $w['style_vector'] ? json_decode($w['style_vector'], true) : [];
            uasort($vec, fn($a,$b)=> abs($b)<=>abs($a));
            $vec_short = array_slice($vec, 0, 4, true);
            return [
                'id'      => intval($w['id']),
                'name'    => $w['name'],
                'region'  => $w['region'],
                'grapes'  => $w['grapes'],
                'type'    => $w['type'],
                'price'   => floatval($w['price']),
                'score'   => floatval($w['pre_score']), // pre-LLM blended score (for context)
                'style'   => $vec_short,
            ];
        }, $candidates),
    ];

    $instructions = <<<PROMPT
You are a sommelier recommending wines to a user based on their taste, price range, and preferences.
Given the JSON with "user" and "candidates", select up to 24 wines, rerank them best to good, and
return strictly JSON with this shape:

{ "picks": [ {"wine_id": 123, "reason": "≤ 90 chars concise reason"}, ... ] }

Guidelines:
- Prefer matches to the user's top varietals and regions.
- Keep within the user's price range when possible.
- Use style hints (body, acidity, tannin, oak, etc.) to refine.
- Avoid repeating the same wine style too many times; include some variety.
- Reasons must be short, specific, and non-generic.
PROMPT;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$key,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS=>json_encode([
            'model'=>'gpt-4o-mini',
            'messages'=>[
                ['role'=>'system','content'=>'You output concise JSON only.'],
                ['role'=>'user','content'=>$instructions."\n\nINPUT:\n".json_encode($payload, JSON_UNESCAPED_SLASHES)]
            ],
            'temperature'=>0.2,
            'response_format'=>['type'=>'json_object']
        ])
    ]);
    $res = curl_exec($ch);
    if (!$res) return [];
    $json = json_decode($res,true);
    $content = $json['choices'][0]['message']['content'] ?? '{}';
    $data = json_decode($content,true);
    if (!is_array($data) || !isset($data['picks']) || !is_array($data['picks'])) return [];
    return $data['picks'];
}

/** MAIN: pick users, compute top 60, LLM rerank to top 24, store in user_recos */
try {
    // Find users to refresh; prefer recent actors first, else all with bottles
    $users = $pdo->query("
        SELECT DISTINCT user_id
        FROM bottles
        ORDER BY COALESCE(updated_at, added_on) DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!$users) exit(0);

    $candStmt = $winelist_pdo->prepare("
      SELECT w.id, w.name, w.winery, w.region, w.grapes, w.type, w.vintage, w.price, w.image_url, a.style_vector, w.created_at
      FROM wines w
      LEFT JOIN wines_ai a ON a.wine_id=w.id
      WHERE w.price IS NOT NULL
      ORDER BY w.created_at DESC
      LIMIT 1000
    ");

    $ins = $pdo->prepare("
      INSERT INTO user_recos(user_id, wine_id, score, reason, source, generated_at, expires_at)
      VALUES(?,?,?,?, 'rerank', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
      ON DUPLICATE KEY UPDATE score=VALUES(score), reason=VALUES(reason), source='rerank',
        generated_at=VALUES(generated_at), expires_at=VALUES(expires_at)
    ");

    foreach ($users as $uid) {
        // Profile for this user
        $st = $pdo->prepare("SELECT avg_price_min, avg_price_max, style_vector, varietal_top3, region_top3 FROM user_profiles WHERE user_id=?");
        $st->execute([$uid]);
        $prof = $st->fetch(PDO::FETCH_ASSOC);
        if (!$prof) continue;

        $userVec = $prof['style_vector'] ? json_decode($prof['style_vector'], true) : [];
        $userPref = [
            'pmin' => $prof['avg_price_min'] !== null ? floatval($prof['avg_price_min']) : 0,
            'pmax' => $prof['avg_price_max'] !== null ? floatval($prof['avg_price_max']) : 999999,
            'vars' => array_map('strtolower', json_decode($prof['varietal_top3'] ?? '[]', true) ?: []),
            'regs' => array_map('strtolower', json_decode($prof['region_top3'] ?? '[]', true) ?: []),
        ];

        // Candidate pool (catalog slice)
        $candStmt->execute();
        $candidates = $candStmt->fetchAll(PDO::FETCH_ASSOC);

        // Score and take top 60
        $scored = [];
        foreach ($candidates as $w) {
            [$s, $r] = blended_score($userVec, $userPref, $w);
            $w['pre_score'] = $s;
            $w['pre_reason'] = $r;
            $scored[] = $w;
        }
        usort($scored, fn($a,$b)=> $b['pre_score']<=>$a['pre_score']);
        $top60 = array_slice($scored, 0, 60);

        if (!$top60) continue;

        // Summarize the user and call LLM reranker
        $summary = summarize_user($prof);
        $picks = llm_rerank($summary, $top60);

        if (!$picks) {
            // fallback: store first 24 by blended score w/ pre_reason
            $fallback = array_slice($top60, 0, 24);
            foreach ($fallback as $rank=>$w) {
                $score = round(1.0 - $rank*0.02, 3); // simple rank-to-score
                $ins->execute([$uid, $w['id'], $score, $w['pre_reason'] ?: 'top by blended']);
            }
            continue;
        }

        // Clear previous rerank recos to avoid mixing sources
        $pdo->prepare("DELETE FROM user_recos WHERE user_id=? AND source='rerank'")->execute([$uid]);

        // Insert LLM picks (respect order; highest score for first)
        $rank = 0;
        foreach ($picks as $p) {
            $wid = intval($p['wine_id'] ?? 0);
            if (!$wid) continue;
            $reason = trim(mb_substr($p['reason'] ?? '', 0, 200));
            $score = round(1.0 - $rank*0.02, 3); // simple descending score by rank
            $ins->execute([$uid, $wid, $score, $reason]);
            $rank++;
            if ($rank >= 24) break;
        }
    }
} catch (Throwable $e) {
    error_log("[cron_recommendations_rerank] ".$e->getMessage());
    http_response_code(500);
    echo "error: ".$e->getMessage();
    exit(1);
}
