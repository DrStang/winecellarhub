<?php
// /public/share_wine.php  (place in your public web root)
// SECURITY: expose only what’s needed; no user inventory details.

require __DIR__ . '/db.php';          // adjust path
//require __DIR__ . '/../auth_utils.php';  // optional, for helpers if you have them

header('Content-Type: text/html; charset=utf-8');

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';  // enable with ?debug=1

// 1) Input: tokenized public share (never use raw wine_id in public links)
$token = trim($_GET['t'] ?? '');
if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{24,64}$/', $token)) {
    http_response_code(404);
    echo "Not found";
    exit;
}

// 2) Lookup share + wine (read-only, minimal fields). We do NOT reveal owner.

$share = $winelist_pdo->prepare("
    SELECT s.id, s.token, s.wine_id, s.user_id, s.title, s.excerpt, s.is_indexable,
           s.og_image_url, s.status, s.created_at, s.expires_at
    FROM public_shares s
    WHERE s.token = :t
      AND s.status = 'active'
      AND (s.expires_at IS NULL OR s.expires_at > NOW())
    LIMIT 1
");
$share->execute([':t'=>$token]);
$S = $share->fetch(PDO::FETCH_ASSOC);

if (!$S) { http_response_code(404); echo "This share link is no longer available."; exit; }
// DEBUG: log the mapping (helps catch surprising behavior/caching)


// 3) Try to read the intended wine (assume s.wine_id = winelist.wines.id)

$wineStmt = $winelist_pdo->prepare("
    SELECT w.id, w.name AS wine_name, w.winery, w.vintage, w.region, w.country,
           w.grapes, w.type AS wine_type, w.image_url, w.rating
    FROM `winelist`.`wines` w
    WHERE w.id = :id
    LIMIT 1
");
$wineStmt->execute([':id' => (int)$S['wine_id']]);
$W = $wineStmt->fetch(PDO::FETCH_ASSOC);

if (!$W) {
    http_response_code(404);
    echo "Wine (catalog id ".htmlspecialchars((string)$S['wine_id']).") not found in winelist.wines";
    if ($DEBUG) {
        echo "<pre>DEBUG: share row:\n".htmlspecialchars(print_r($S,true))."</pre>";
    }
    exit;
}
// 3) Derive fields
$baseUrl   = (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

$siteName  = 'WineCellarHub';
$selfUrl   = $baseUrl . '/share_wine.php?t=' . urlencode($token);

$wineName = trim(
    ($W['vintage'] ? $W['vintage'].' ' : '') .
    ($W['winery'] ?: '') . ' ' . ($W['wine_name'] ?: '')
);
$h1   = $S['title'] ?: $wineName;
$desc = $S['excerpt'] ?: "Explore tasting insights, varietal details, and why this bottle made the cut on $siteName.";

$cover = $W['image_url'] ?: '';
if ($cover === '' || !preg_match('~^/covers/|^https?://~i', $cover)) {
    $type = strtolower($W['wine_type'] ?? '');
    if ($type === 'white')       $cover = '/covers/white_placeholder.jpg';
    elseif ($type === 'red')     $cover = '/covers/red_placeholder.jpg';
    else                         $cover = '/covers/placeholder.jpg';
}
$ogImage = $S['og_image_url'] ?: $cover;

$avgRating   = isset($W['avg_rating'])   && $W['avg_rating']   !== null ? (float)$W['avg_rating']   : null;

$indexableMeta = ($S['is_indexable'] ?? 1) ? 'index,follow' : 'noindex,nofollow';

$regionBits = array_filter([$W['region'] ?? null, $W['country'] ?? null]);
$jsonLd = [
    "@context" => "https://schema.org",
    "@type"    => "Product",
    "name"     => $wineName ?: $h1,
    "brand"    => $W['winery'] ? [ "@type"=>"Brand", "name"=>$W['winery'] ] : null,
    "category" => "Wine",
    "image"    => (strpos($ogImage, 'http') === 0) ? $ogImage : $baseUrl . $ogImage,
    "description" => $desc,
];
if ($DEBUG) {
    echo "<pre style='background:#111;color:#eee;padding:10px;border:1px solid #333;white-space:pre-wrap'>";
    echo "DEBUG MODE\n";
    echo "token: ".htmlspecialchars($token)."\n";
    echo "share_id: ".$S['id']." | wine_id: ".$S['wine_id']."\n";
    echo "TITLE(h1): ".htmlspecialchars($h1)."\n";
    echo "WINE ROW: ".htmlspecialchars(print_r($W, true))."\n";
    echo "</pre>";
}
?>
<!doctype html>
<html lang="en">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($h1) ?> • <?= htmlspecialchars($siteName) ?></title>
    <meta name="description" content="<?= htmlspecialchars($desc) ?>">
    <meta name="robots" content="<?= htmlspecialchars($indexableMeta) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($selfUrl) ?>"/>

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($h1) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($selfUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars(strpos($ogImage,'http')===0?$ogImage:$baseUrl.$ogImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($h1) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($desc) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars(strpos($ogImage,'http')===0?$ogImage:$baseUrl.$ogImage) ?>">

    <!-- JSON-LD -->
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>

    <style>
        :root{
            --bg:#0b0b0e; --card:#13131a; --text:#f6f6f7; --muted:#a1a1aa; --accent:#8a1538;
            --border:#22222b; --btn:#e5e7eb; --btntext:#0b0b0e;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
        .wrap{max-width:980px;margin:0 auto;padding:24px}
        .card{display:grid;grid-template-columns:1fr 1.2fr;gap:28px;background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px}
        .imgwrap{display:flex;align-items:center;justify-content:center;border-radius:12px;background:#0e0e14;overflow:hidden;min-height:320px}
        .imgwrap img{width:100%;height:auto;max-height:520px;object-fit:contain;display:block}
        h1{margin:0 0 8px;font-size:28px;line-height:1.2}
        .meta{color:var(--muted);font-size:14px;margin-bottom:14px}
        .desc{font-size:16px;line-height:1.6;margin:16px 0 24px}
        .badge{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border:1px solid var(--border);border-radius:999px;font-size:13px;color:var(--muted);margin-right:10px;margin-bottom:8px}
        .actions{display:flex;gap:12px;flex-wrap:wrap}
        .btn{appearance:none;border:0;border-radius:10px;padding:12px 16px;font-weight:600;cursor:pointer}
        .btn-cta{background:var(--btn);color:var(--btntext)}
        .btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
        .foot{margin-top:18px;color:var(--muted);font-size:12px}
        @media (max-width:860px){ .card{grid-template-columns:1fr} }
    </style>
</head>
<body class="bg-[var(--surface)] text-[var(--text)]">
<div class="wrap">
    <div class="card">
        <div class="imgwrap">
            <img alt="<?= htmlspecialchars($wineName) ?>" src="<?= htmlspecialchars($cover) ?>">
        </div>
        <div>
            <h1><?= htmlspecialchars($h1) ?></h1>

            <div class="meta">
                <?= htmlspecialchars($W['vintage'] ?: 'NV') ?> ·
                <?= htmlspecialchars($W['winery'] ?: 'Winery') ?> ·
                <?= htmlspecialchars(implode(' • ', $regionBits) ?: ($W['grapes'] ?: '')) ?>
                <?php if ($avgRating !== null): ?>
                    · ★ <?= htmlspecialchars(number_format($avgRating, 2)) ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($desc)): ?>
                <div class="desc"><?= nl2br(htmlspecialchars($desc)) ?></div>
            <?php endif; ?>

            <div class="badges">
                <?php if (!empty($W['grapes'])): ?>
                    <span class="badge">Varietal: <?= htmlspecialchars($W['grapes']) ?></span>
                <?php endif; ?>
                <?php if (!empty($W['wine_type'])): ?>
                    <span class="badge">Type: <?= htmlspecialchars(ucfirst($W['wine_type'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($W['country'])): ?>
                    <span class="badge">Country: <?= htmlspecialchars($W['country']) ?></span>
                <?php endif; ?>
            </div>

            <div class="actions" style="margin-top:16px">
                <a class="btn btn-cta" href="/register.php">Create your cellar</a>
                <a class="btn btn-outline" href="/features.php">See features</a>
            </div>

            <div class="foot">
                Shared via <?= htmlspecialchars($siteName) ?> •
                <a style="color:inherit" href="<?= htmlspecialchars($baseUrl) ?>"><?= htmlspecialchars(parse_url($baseUrl, PHP_URL_HOST)) ?></a>
            </div>
        </div>
    </div>
</div>
</body>
</html>