<?php
declare(strict_types=1);
@ini_set('display_errors','0'); header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';


header('Content-Type: application/json');

// ---------- helpers
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
function dot_safe(?array $a, ?array $b): float {
    if (!$a || !$b) return 0.0;
    $n = min(count($a), count($b));
    $s = 0.0;
    for ($i=0; $i<$n; $i++) $s += floatval($a[$i]) * floatval($b[$i]);
    return $s;
}
function env_catalog(): string {
    return preg_replace('/[^a-zA-Z0-9_]/','', $_ENV['WINELIST_DB'] ?? 'winelist');
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }

try {
    // ---- 1) Try curated (LLM rerank) cache first
    $catalogDb = env_catalog();
    $rr = $pdo->prepare("
    SELECT ur.wine_id, ur.score, ur.reason,
           w.name, w.winery, w.region, w.grapes, w.type, w.vintage, w.price, w.image_url
    FROM user_recos ur
    JOIN {$catalogDb}.wines w ON w.id=ur.wine_id
    WHERE ur.user_id=? AND ur.source='rerank' AND (ur.expires_at IS NULL OR ur.expires_at >= NOW())
    ORDER BY ur.score DESC
    LIMIT 24
  ");
    $rr->execute([$user_id]);
    $curated = $rr->fetchAll(PDO::FETCH_ASSOC);
    if ($curated && count($curated) >= 12) {
        echo json_encode(['recommendations' => array_map(function($r){
            $r['reason'] = $r['reason'] ?? '';
            return $r;
        }, $curated)]);
        exit;
    }

    // ---- Load user profile (may be empty for brand-new users)
    $st = $pdo->prepare("SELECT avg_price_min, avg_price_max, style_vector, varietal_top3, region_top3 FROM user_profiles WHERE user_id=?");
    $st->execute([$user_id]);
    $profile = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $userVec = $profile['style_vector'] ? json_decode($profile['style_vector'], true) : [];
    $prefVar = array_map('strtolower', json_decode($profile['varietal_top3'] ?? '[]', true) ?: []);
    $prefReg = array_map('strtolower', json_decode($profile['region_top3'] ?? '[]', true) ?: []);
    $hasProfileSignals = ($userVec && count($userVec) > 0) || $prefVar || $prefReg || $profile;

    $pmin = isset($profile['avg_price_min']) && $profile['avg_price_min'] !== null ? floatval($profile['avg_price_min']) : 0.0;
    $pmax = isset($profile['avg_price_max']) && $profile['avg_price_max'] !== null ? floatval($profile['avg_price_max']) : 999999.0;

    // Try to load CF user factors
    $uf = null;
    $rowUF = $pdo->prepare("SELECT factors FROM cf_user_factors WHERE user_id=?");
    $rowUF->execute([$user_id]);
    $rowUF = $rowUF->fetch(PDO::FETCH_ASSOC);
    if ($rowUF && $rowUF['factors']) $uf = json_decode($rowUF['factors'], true);

    // Pull candidate catalog slice
    $q = $winelist_pdo->prepare("
    SELECT w.id, w.name, w.winery, w.region, w.grapes, w.type, w.vintage, w.price, w.rating, w.investability_score,
           w.image_url, a.style_vector, w.created_at
    FROM wines w
    LEFT JOIN wines_ai a ON a.wine_id=w.id
    WHERE w.price IS NOT NULL
    ORDER BY w.created_at DESC
    LIMIT 600
  ");
    $q->execute();
    $candidates = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$candidates) { echo json_encode(['recommendations'=>[]]); exit; }

    // If CF is available, load item factors
    $cf_items = [];
    if ($uf) {
        $ids = array_map(fn($w)=> intval($w['id']), $candidates);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmtCF = $pdo->prepare("SELECT wine_id, factors FROM cf_wine_factors WHERE wine_id IN ($in)");
            $stmtCF->execute($ids);
            foreach ($stmtCF->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cf_items[intval($r['wine_id'])] = json_decode($r['factors'], true);
            }
        }
    }

    $now = new DateTimeImmutable('now');

    // ---------- 2) Personalized blended scoring (uses CF if present; otherwise pure content-based)
    if ($hasProfileSignals) {
        $scored = [];
        foreach ($candidates as $w) {
            $reasons = [];
            $score = 0.0;

            // CF boost (only if both user+item factors exist)
            if ($uf && isset($cf_items[intval($w['id'])])) {
                $cf_raw = dot_safe($uf, $cf_items[intval($w['id'])]);
                $cf_norm = tanh($cf_raw/10.0); // squash
                $score += 0.60 * max(0, $cf_norm);
                $reasons[] = 'cf';
            }

            // Style similarity
            if ($userVec && $w['style_vector']) {
                $sim = cosine_sim($userVec, json_decode($w['style_vector'],true) ?: []);
                $score += ($uf ? 0.25 : 0.55) * $sim; // if no CF, style carries more weight
                $reasons[] = "style ".round($sim,2);
            }

            // Price fit
            $price = floatval($w['price']);
            if ($price >= $pmin && $price <= $pmax) { $score += 0.10; $reasons[] = "price"; }

            // Varietal/Region bonuses
            $wineVars = parse_varietals($w['grapes'] ?? '');
            foreach ($wineVars as $gv) { if (in_array($gv, $prefVar, true)) { $score += 0.10; $reasons[]="varietal"; break; } }
            if (in_array(mb_strtolower(trim($w['region'] ?? '')), $prefReg, true)) { $score += 0.05; $reasons[]="region"; }

            // Recency bump
            $score += 0.10;

            $w['score']  = round($score, 3);
            $w['reason'] = implode('; ', $reasons);
            $scored[] = $w;
        }
        usort($scored, fn($a,$b)=> $b['score'] <=> $a['score']);
        echo json_encode(['recommendations' => array_slice($scored, 0, 24)]);
        exit;
    }

    // ---------- 3) Cold-start fallback (no profile yet)
    // Rank by catalog signals: rating + investability + recency (+ small price sanity)
    $scored = [];
    foreach ($candidates as $w) {
        $score = 0.0; $reasons = [];

        // Normalize rating to 0..1 whether 0–5 or 50–100 style
        $r = isset($w['rating']) ? floatval($w['rating']) : 0.0;
        if ($r > 10) $r = min(100.0, max(0.0, $r)) / 100.0;   // assume 50..100-ish → clamp to 0..1
        else         $r = min(5.0,   max(0.0, $r)) / 5.0;     // assume 0..5 → 0..1
        $score += 0.50 * $r;
        if ($r > 0) $reasons[] = "rating";

        // Investability (0..100) → 0..1
        $inv = isset($w['investability_score']) ? floatval($w['investability_score']) : 0.0;
        $inv01 = min(100.0, max(0.0, $inv)) / 100.0;
        $score += 0.30 * $inv01;
        if ($inv01 > 0) $reasons[] = "investability";

        // Recency: newer gets slight bump
        $rec = 0.20;
        $score += $rec; $reasons[] = "new";

        // Light price sanity (prefer mid-range over extremes)
        $price = floatval($w['price']);
        if ($price > 0 && $price < 1000) $score += 0.05;

        $w['score']  = round($score, 3);
        $w['reason'] = implode('; ', $reasons);
        $scored[] = $w;
    }
    usort($scored, fn($a,$b)=> $b['score'] <=> $a['score']);
    echo json_encode(['recommendations' => array_slice($scored, 0, 24)]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'server_error','message'=>$e->getMessage()]);
}
