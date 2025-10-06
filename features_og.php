<?php
/**
 * /features_og.php
 * Dynamic 1200x630 PNG for social previews (OG/Twitter).
 *
 * Supports:
 *  A) ?t=TOKEN     -> looks up public_shares + winelist.wines to build title/subtitle
 *  B) ?title=... [&subtitle=...] -> manual text (for /features.php or other pages)
 *
 * Includes:
 *  - Disk cache (./cache/og/<md5>.png), 24h TTL
 *  - ETag + 304 Not Modified
 *  - Imagick renderer w/ text wrapping + vector bottle silhouette
 *  - GD fallback renderer
 */

///////////////////////////////
// Bootstrap & configuration //
///////////////////////////////

declare(strict_types=1);

// If db.php lives elsewhere, adjust path accordingly.
require __DIR__ . '/db.php'; // must expose db(): PDO

$W = 1200;                 // Image width
$H = 630;                  // Image height
$PADDING = 60;             // Left/top padding for text layout
$SITE_NAME = 'WineCellarHub';

// Brand/color palette
$BG_TOP   = [11, 11, 14];    // rgb()
$BG_BOT   = [26, 18, 24];
$ACCENT   = [138, 21, 56];
$TEXT     = [245, 245, 246];
$MUTED    = [185, 185, 192];
// Bottle silhouette RGBA (alpha 0..127 for GD, 0..100% for Imagick helper below)
$BOTTLE_RGBA_GD = [255, 255, 255, 110]; // (R,G,B, alpha 0 opaque..127 fully transparent)
$BOTTLE_ALPHA_IM = 12; // percent (0..100) for Imagick "white with opacity"

// Font candidates (TTF). Add your shipped font to /assets/fonts if needed.
$FONT_CANDIDATES = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/truetype/msttcorefonts/Arial.ttf',
    __DIR__ . '/assets/fonts/DejaVuSans.ttf',
];

/////////////////
// Input parse //
/////////////////

$token   = trim($_GET['t'] ?? '');
$titleIn = trim($_GET['title'] ?? '');
$subIn   = trim($_GET['subtitle'] ?? '');

$title = $titleIn;
$subtitle = $subIn;

// If token provided, fetch share + wine to derive title/subtitle
if ($token !== '') {
    try {
        $sql = "SELECT s.title, s.excerpt, s.status, s.expires_at,
                       w.name AS wine_name, w.winery, w.vintage, w.region, w.country
                FROM public_shares s
                JOIN winelist.wines w ON w.id = s.wine_id
                WHERE s.token = :t
                  AND s.status = 'active'
                  AND (s.expires_at IS NULL OR s.expires_at > NOW())
                LIMIT 1";
        $stmt = $winelist_pdo->prepare($sql);
        $stmt->execute([':t' => $token]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $title = $row['title']
                ? trim((string)$row['title'])
                : trim(($row['vintage'] ? ($row['vintage'] . ' ') : '') . ($row['winery'] ?: '') . ' ' . ($row['wine_name'] ?: ''));
            $subtitle = $row['excerpt']
                ? trim((string)$row['excerpt'])
                : trim(implode(' • ', array_filter([ $row['region'] ?? null, $row['country'] ?? null ])));
        } else {
            // Token invalid or expired
            $title = 'Shared Wine';
            $subtitle = 'This public link is no longer available';
        }
    } catch (\Throwable $e) {
        // On DB errors, fall back to generic messaging (don’t leak details)
        $title = 'Modern wine inventory & AI insights';
        $subtitle = 'Track, pair, and drink at the optimal time';
    }
}

// Final fallbacks (for plain /features.php or if inputs empty)
if ($title === '')   $title   = 'Modern wine inventory & AI insights';
if ($subtitle === '') $subtitle = 'Track, pair, and drink at the optimal time';

// Pick a TTF font if available
$TTF = null;
foreach ($FONT_CANDIDATES as $f) {
    if (is_file($f)) { $TTF = $f; break; }
}

/////////////////////
// Disk Cache init //
/////////////////////

$CACHE_DIR = __DIR__ . '/cache/og';
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0775, true);

$qstr      = $_SERVER['QUERY_STRING'] ?? '';
$cacheKey  = md5($qstr ?: 'noquery');
$cachePath = $CACHE_DIR . "/{$cacheKey}.png";

// 24h TTL
$cacheTtlSeconds = 86400;
$now   = time();
$etag  = '"' . $cacheKey . '"';

// Serve cached if present & fresh
if (is_file($cachePath) && (filemtime($cachePath) > ($now - $cacheTtlSeconds))) {
    // Conditional GET
    $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($clientEtag === $etag) {
        header('HTTP/1.1 304 Not Modified');
        header('Cache-Control: public, max-age=86400, immutable');
        header("ETag: $etag");
        exit;
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400, immutable');
    header("ETag: $etag");
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($cachePath)).' GMT');
    readfile($cachePath);
    exit;
}

/////////////////////////
// Rendering functions //
/////////////////////////

/**
 * Render PNG using Imagick. Returns binary PNG string.
 */
function render_with_imagick(
    int $W, int $H, int $PADDING, string $title, string $subtitle, string $SITE_NAME,
    array $BG_TOP, array $BG_BOT, array $TEXT, array $MUTED, array $ACCENT,
    int $bottleAlphaPercent, ?string $TTF
): string {
    $img = new Imagick();
    $img->newImage($W, $H, new ImagickPixel('rgb('.implode(',', $BG_TOP).')'));
    $img->setImageFormat('png');

    // Gradient BG
    $grad = new Imagick();
    $grad->newPseudoImage($W, $H, "gradient:rgb(".implode(',',$BG_TOP).")-rgb(".implode(',',$BG_BOT).")");
    $img->compositeImage($grad, Imagick::COMPOSITE_OVER, 0, 0);
    $grad->clear();

    // Bottle silhouette (right side)
    $shape = new ImagickDraw();
    $shape->setFillColor(new ImagickPixel("rgba(255,255,255,".($bottleAlphaPercent/100).")"));
    $shape->translate($W - 260, 50);
    $shape->scale(1.2, 1.2);
    $shape->pathStart();
    $shape->pathMoveToAbsolute(100, 0);
    $shape->pathLineToAbsolute(140, 0);
    $shape->pathLineToAbsolute(140, 50);
    $shape->pathLineToAbsolute(150, 80);
    $shape->pathLineToAbsolute(150, 480);
    $shape->pathCurveToAbsolute(150, 560, 90, 560, 90, 480);
    $shape->pathLineToAbsolute(90, 80);
    $shape->pathLineToAbsolute(100, 50);
    $shape->pathClose();
    $shape->pathFinish();
    $img->drawImage($shape);

    // Text wrap helper
    $wrap = function(Imagick $im, ImagickDraw $draw, string $text, int $maxWidth): array {
        $words = preg_split('/\s+/', $text);
        $lines = []; $line = '';
        foreach ($words as $w) {
            $test = trim($line === '' ? $w : "$line $w");
            $metrics = $im->queryFontMetrics($draw, $test);
            if ($metrics['textWidth'] > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $w;
            } else {
                $line = $test;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    };

    // Title
    $drawT = new ImagickDraw();
    if ($TTF) $drawT->setFont($TTF);
    $drawT->setFillColor(new ImagickPixel('rgb('.implode(',',$TEXT).')'));
    $drawT->setFontSize(64);
    $drawT->setFontWeight(700);
    $drawT->setTextInterlineSpacing(6);

    $maxTextWidth = $W - 2 * $PADDING - 260; // leave space for bottle
    $titleLines = $wrap($img, $drawT, $title, $maxTextWidth);

    $y = $PADDING + 40;
    foreach ($titleLines as $ln) {
        $img->annotateImage($drawT, $PADDING, $y, 0, $ln);
        $y += 72;
    }

    // Subtitle
    $drawS = new ImagickDraw();
    if ($TTF) $drawS->setFont($TTF);
    $drawS->setFillColor(new ImagickPixel('rgb('.implode(',',$MUTED).')'));
    $drawS->setFontSize(34);
    $drawS->setTextInterlineSpacing(4);

    $subLines = $wrap($img, $drawS, $subtitle, $maxTextWidth);
    foreach ($subLines as $ln) {
        $img->annotateImage($drawS, $PADDING, $y, 0, $ln);
        $y += 46;
    }

    // Site badge (bottom-right)
    $badge = new ImagickDraw();
    if ($TTF) $badge->setFont($TTF);
    $badge->setFontSize(26);
    $badge->setFillColor(new ImagickPixel('white'));
    $img->annotateImage($badge, $W - $PADDING - 260, $H - $PADDING, 0, $SITE_NAME);

    // Output to string
    return (string)$img;
}

/**
 * Render PNG using GD. Returns binary PNG string.
 */
function render_with_gd(
    int $W, int $H, int $PADDING, string $title, string $subtitle, string $SITE_NAME,
    array $BG_TOP, array $BG_BOT, array $TEXT, array $MUTED, array $ACCENT,
    array $BOTTLE_RGBA_GD, ?string $TTF
): string {
    $im = imagecreatetruecolor($W, $H);
    imagesavealpha($im, true);

    // Gradient BG
    for ($y = 0; $y < $H; $y++) {
        $ratio = $y / ($H - 1);
        $r = (int)($BG_TOP[0] * (1 - $ratio) + $BG_BOT[0] * $ratio);
        $g = (int)($BG_TOP[1] * (1 - $ratio) + $BG_BOT[1] * $ratio);
        $b = (int)($BG_TOP[2] * (1 - $ratio) + $BG_BOT[2] * $ratio);
        $col = imagecolorallocate($im, $r, $g, $b);
        imageline($im, 0, $y, $W, $y, $col);
    }

    // Bottle silhouette
    $whiteT = imagecolorallocatealpha($im, $BOTTLE_RGBA_GD[0], $BOTTLE_RGBA_GD[1], $BOTTLE_RGBA_GD[2], $BOTTLE_RGBA_GD[3]);
    $offsetX = $W - 260; $offsetY = 50;
    imagefilledpolygon($im, [
        $offsetX+100,$offsetY+0,
        $offsetX+140,$offsetY+0,
        $offsetX+140,$offsetY+50,
        $offsetX+150,$offsetY+80,
        $offsetX+150,$offsetY+480,
        $offsetX+90, $offsetY+480,
        $offsetX+90, $offsetY+80,
        $offsetX+100,$offsetY+50
    ], 8, $whiteT);

    // Colors
    $colText = imagecolorallocate($im, $TEXT[0], $TEXT[1], $TEXT[2]);
    $colMuted = imagecolorallocate($im, $MUTED[0], $MUTED[1], $MUTED[2]);

    // Wrap helper
    $useTTF = ($TTF && function_exists('imagettftext'));
    $wrapGD = function($text, $size, $font, $maxWidth) use ($useTTF) {
        $words = preg_split('/\s+/', $text);
        $lines = []; $line = '';
        foreach ($words as $w) {
            $test = trim($line === '' ? $w : "$line $w");
            if ($useTTF) {
                $box = imagettfbbox($size, 0, $font, $test);
                $wpx = $box[2] - $box[0];
            } else {
                // crude fallback estimate for built-in fonts
                $wpx = 10 * strlen($test);
            }
            if ($wpx > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $w;
            } else {
                $line = $test;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    };

    // Title
    $x = $PADDING; $y = $PADDING + 70;
    $maxTextWidth = $W - 2 * $PADDING - 260;

    $titleSize = 48;  // px
    $subSize   = 26;  // px

    $titleLines = $wrapGD($title, $titleSize, (string)$TTF, $maxTextWidth);
    foreach ($titleLines as $ln) {
        if ($useTTF) imagettftext($im, $titleSize, 0, $x, $y, $colText, (string)$TTF, $ln);
        else imagestring($im, 5, $x, $y - 14, $ln, $colText);
        $y += 64;
    }

    // Subtitle
    $subLines = $wrapGD($subtitle, $subSize, (string)$TTF, $maxTextWidth);
    foreach ($subLines as $ln) {
        if ($useTTF) imagettftext($im, $subSize, 0, $x, $y, $colMuted, (string)$TTF, $ln);
        else imagestring($im, 4, $x, $y - 12, $ln, $colMuted);
        $y += 40;
    }

    // Site badge (bottom-right)
    if ($useTTF) {
        imagettftext($im, 20, 0, $W - $PADDING - 260, $H - $PADDING, $colText, (string)$TTF, $SITE_NAME);
    } else {
        imagestring($im, 5, $W - $PADDING - 160, $H - $PADDING - 14, $SITE_NAME, $colText);
    }

    ob_start();
    imagepng($im);
    imagedestroy($im);
    return ob_get_clean();
}

/////////////////////
// Render to cache //
/////////////////////

// Render using Imagick if available, otherwise GD
$png = '';
try {
    if (class_exists('Imagick') && class_exists('ImagickDraw')) {
        $png = render_with_imagick(
            $W, $H, $PADDING, $title, $subtitle, $SITE_NAME,
            $BG_TOP, $BG_BOT, $TEXT, $MUTED, $ACCENT,
            $BOTTLE_ALPHA_IM, $TTF
        );
        // Imagick's ->__toString() returns the binary
    } else {
        $png = render_with_gd(
            $W, $H, $PADDING, $title, $subtitle, $SITE_NAME,
            $BG_TOP, $BG_BOT, $TEXT, $MUTED, $ACCENT,
            $BOTTLE_RGBA_GD, $TTF
        );
    }
} catch (\Throwable $e) {
    // As a last resort, return a tiny 1x1 PNG to avoid 500s on crawlers
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
}

// Save to cache (best effort)
if ($png !== '' && is_dir($CACHE_DIR) && is_writable($CACHE_DIR)) {
    @file_put_contents($cachePath, $png);
}

// Emit response with caching headers
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400, immutable');
header("ETag: $etag");
header('Last-Modified: '.gmdate('D, d M Y H:i:s', time()).' GMT');
echo $png;
exit;
