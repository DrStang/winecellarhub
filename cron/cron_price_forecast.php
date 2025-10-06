<?php
require_once __DIR__ . '/../db.php';

/**
 * Lightweight heuristic forecaster using current catalog price and optional signals.
 * If you later add a wine_prices history table, we can switch back to time-series forecasts.
 */

function clamp($x, $lo, $hi){ return max($lo, min($hi, $x)); }

function explain_with_llm(array $ctx): string {
    $key = $_ENV['OPENAI_API_KEY'] ?? null; if (!$key) return '';
    $prompt = "Write one investor-style sentence (<=160 chars) explaining the price outlook. Context:\n"
        . json_encode($ctx);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode([
            'model'=>'gpt-4o-mini',
            'temperature'=>0.2,
            'messages'=>[['role'=>'user','content'=>$prompt]]
        ])
    ]);
    $res = curl_exec($ch); if (!$res) return '';
    $json = json_decode($res,true); return trim($json['choices'][0]['message']['content'] ?? '');
}

$selW = $winelist_pdo->query("
  SELECT id, name, region, type, grapes, vintage, price, rating, investability_score
  FROM wines
  WHERE price IS NOT NULL AND price > 0
  ORDER BY created_at DESC
  LIMIT 8000
");

$insF = $winelist_pdo->prepare("
  INSERT INTO wine_price_forecasts (wine_id, horizon, forecast_price, confidence, lower_ci, upper_ci, asof_date, method, explanation_md)
  VALUES (?,?,?,?,?,?,CURDATE(),'HEUR_v1',?)
  ON DUPLICATE KEY UPDATE forecast_price=VALUES(forecast_price),
    confidence=VALUES(confidence), lower_ci=VALUES(lower_ci), upper_ci=VALUES(upper_ci),
    asof_date=CURDATE(), method=VALUES(method), explanation_md=VALUES(explanation_md)
");

while ($w = $selW->fetch(PDO::FETCH_ASSOC)) {
    $p0 = (float)$w['price'];
    if ($p0 <= 0) continue;

    // Heuristic uplift derived from rating / investability (if present)
    $rating = isset($w['rating']) ? (float)$w['rating'] : null;        // e.g., 3.7 out of 5 or 92/100—adjust if your scale differs
    $invest = isset($w['investability_score']) ? (float)$w['investability_score'] : null; // 0..100?
    $base_uplift = 0.02; // +2% baseline 1y drift

    if ($rating !== null) {
        // Map rating into ±3% around base
        // If your rating is 0–5: use (rating-3)*0.01; If 50–100: rescale accordingly.
        $r = $rating;
        if ($r > 10) { $r = ($rating - 85) * 0.002; }     // crude: 92 => +1.4%
        else         { $r = ($rating - 3.5) * 0.01; }     // crude: 4.2 => +0.7%
        $base_uplift += clamp($r, -0.02, 0.03);
    }
    if ($invest !== null) {
        $base_uplift += clamp(($invest - 50) * 0.0005, -0.02, 0.04); // investability 70 => +1%
    }

    // Horizons with conservative multipliers
    $u6  = clamp($base_uplift/2,  -0.05, 0.05);  // ±5% cap
    $u1  = clamp($base_uplift,    -0.10, 0.12);  // ±10–12%
    $u5  = clamp($base_uplift*5,  -0.25, 0.60);  // long tail, cap wide

    $f6m = round($p0 * (1+$u6), 2);
    $f1y = round($p0 * (1+$u1), 2);
    $f5y = round($p0 * (1+$u5), 2);

    // simple confidences & CIs
    $conf = clamp(0.50 + ($base_uplift>=0 ? 0.05 : -0.05), 0.30, 0.75);
    $ci6 = 0.08; $ci1 = 0.10; $ci5 = 0.20;

    $ctx = [
        'name'=>$w['name'], 'region'=>$w['region'], 'type'=>$w['type'],
        'grapes'=>$w['grapes'], 'vintage'=>$w['vintage'],
        'price_now'=>$p0, 'uplift'=>$base_uplift, 'rating'=>$w['rating'] ?? null, 'invest'=>$invest
    ];
    $why = explain_with_llm($ctx);

    $insF->execute([$w['id'],'6m', $f6m, $conf, round($f6m*(1-$ci6),2), round($f6m*(1+$ci6),2), $why]);
    $insF->execute([$w['id'],'1y', $f1y, $conf, round($f1y*(1-$ci1),2), round($f1y*(1+$ci1),2), $why]);
    $insF->execute([$w['id'],'5y', $f5y, max(0.30,$conf-0.05), round($f5y*(1-$ci5),2), round($f5y*(1+$ci5),2), $why]);
}

echo "ok\n";
