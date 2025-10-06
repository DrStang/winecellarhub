<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Optional admin gate:
//if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_admin'])) { http_response_code(403); exit('Admins only'); }
// --- long-run hardening ---
@ignore_user_abort(true);          // keep running if client disconnects
@set_time_limit(0);                // or a big number like 900
ini_set('memory_limit', '512M');   // adjust for your CSV size

// Avoid session bloat/locks during long loops:
session_write_close();             // release lock so other pages (login, etc.) work

// Stream early so nginx gets headers quickly
//header('Content-Type: text/html; charset=utf-8');
//header('X-Accel-Buffering: no');   // ask nginx not to buffer
if (!ob_get_level()) ob_start();
echo "<pre>Starting import…\n";

if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
    http_response_code(500);
    exit("Catalog DB (\$winelist_pdo) not configured in db.php.");
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit("Upload failed.");
}
// Force everything to UTF-8
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// Read file and transcode to UTF-8 if needed (Excel often exports UTF-16 or Win-1252)
function to_utf8_file(string $path): string {
    $raw = file_get_contents($path);
    if ($raw === false) return $path;

    // Detect BOMs for UTF-16
    if (strncmp($raw, "\xFF\xFE", 2) === 0) {
        $raw = iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
    } elseif (strncmp($raw, "\xFE\xFF", 2) === 0) {
        $raw = iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
    } else {
        // If not valid UTF-8, try common legacy encodings
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        }
    }

    // Normalize Unicode form to NFC (needs intl extension)
    if (function_exists('normalizer_is_normalized') && function_exists('normalizer_normalize')) {
        if (!normalizer_is_normalized($raw, Normalizer::FORM_C)) {
            $raw = normalizer_normalize($raw, Normalizer::FORM_C);
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'csvu_');
    file_put_contents($tmp, $raw);
    return $tmp;
}

// Use the UTF-8 copy for parsing
$uploadTmp = $_FILES['file']['tmp_name'];
$fname = $_FILES['file']['name'];

$utf8Path  = to_utf8_file($uploadTmp);

$tmp   = $utf8Path;
$mime  = @mime_content_type($uploadTmp) ?: '';

$dryRun    = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
// when reading the posted delimiter
$delimiter = isset($_POST['delimiter']) ? ($_POST['delimiter'] === '\t' ? "\t" : $_POST['delimiter']) : ',';

/* ---------- helpers ---------- */
/* ------------------ rating normalization (5-star) ------------------ */
// Target: 0–5.00 in wines.rating (DECIMAL(3,2) works fine)
$RATING_TARGET_MAX = 5.0;
// Input scale detection: 'auto' (>=10 → 100-point), '100', '10', or '5'
$RATING_INPUT_SCALE = 'auto';
function normalize_rating($val, $input_scale = 'auto', $target_max = 5.0) {
    if ($val === null || $val === '') return null;
    $n = (float)$val;

    // decide input scale
    $inMax = 10.0;
    if ($input_scale === '100' || ($input_scale === 'auto' && $n > 10.0)) $inMax = 100.0;
    elseif ($input_scale === '5') $inMax = 5.0;

    // map [0..inMax] -> [0..target_max]
    if ($inMax != $target_max) $n = ($n / $inMax) * $target_max;

    // clamp + round
    if ($n < 0) $n = 0;
    if ($n > $target_max) $n = $target_max;
    return round($n, 2);
}
function norm_str(?string $s): ?string {
    if ($s === null) return null;
    $s = iconv('UTF-8', 'UTF-8//IGNORE', $s);
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s === '' ? null : $s;
}
function norm_lower(?string $s): ?string {
    $s = norm_str($s);
    return $s === null ? null : mb_strtolower($s);
}
function norm_year($v): ?int {
    $v = is_string($v) ? trim($v) : $v;
    if ($v === '' || $v === null) return null;
    if (preg_match('/^\d{4}$/', (string)$v)) return (int)$v;
    return null;
}
function norm_decimal($v): ?string {
    // return normalized decimal string or null; avoids invalid '' inserts
    if ($v === null) return null;
    $s = is_string($v) ? trim($v) : (string)$v;
    if ($s === '') return null;
    $s = preg_replace('/[^\d.,-]/', '', $s);
    // keep digits, dot, comma
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) return null;
    return $s;
}

/** Parse CSV into array of assoc rows (case-insensitive headers, robust to ragged rows). */
function parse_csv_rows(string $path, ?string $delimiter = ','): array {
    $rows = [];
    $fh = fopen($path, 'r');
    if ($fh === false) return $rows;

    // Read first line raw to handle BOM and auto-delimiter if needed
    $first = fgets($fh);
    if ($first === false) { fclose($fh); return $rows; }

    // Strip UTF-8 BOM
    if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) {
        $first = substr($first, 3);
    }

    // Auto-detect delimiter if none provided or suspicious
    if ($delimiter === null || $delimiter === '' || $delimiter === 'auto') {
        $candidates = [",", ";", "\t", "|"];
        $best = ",";
        $bestCount = -1;
        foreach ($candidates as $cand) {
            $cnt = substr_count($first, $cand);
            if ($cnt > $bestCount) { $best = $cand; $bestCount = $cnt; }
        }
        $delimiter = $bestCount > 0 ? $best : ",";
    }

    // Rewind to re-parse first line via fgetcsv
    rewind($fh);

    // PHP 8.1+ signature: fgetcsv(resource, length=0, separator=",", enclosure='"', escape="\\")
    $headers = fgetcsv($fh, 0, $delimiter, '"', '\\');
    if ($headers === false) { fclose($fh); return $rows; }

    // Normalize headers (lowercase + trim)
    $headers = array_map(static fn($x) => mb_strtolower(trim((string)$x)), $headers);
    // Drop any empty trailing headers
    while (!empty($headers) && end($headers) === '') array_pop($headers);
    $hCount = count($headers);

    $lineno = 1;
    while (($r = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $lineno++;

        // Skip completely empty lines
        if ($r === [null] || $r === [] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;

        // Trim each field to avoid stray spaces
        foreach ($r as $i => $v) { $r[$i] = is_string($v) ? trim($v) : $v; }

        // Handle ragged rows vs header width
        $c = count($r);
        if ($c < $hCount) {
            // Pad missing fields with empty strings
            $r = array_pad($r, $hCount, '');
        } elseif ($c > $hCount) {
            // Collapse extras into the last column (preserving delimiter between them)
            $head = array_slice($r, 0, $hCount - 1);
            $tail = array_slice($r, $hCount - 1);
            $r = array_merge($head, [implode(' ', $tail)]);
        }

        // Now lengths match
        $assoc = array_combine($headers, $r);
        if ($assoc === false) {
            // As a last resort, skip the line rather than fatal
            // You can also log it if you have a logger
            continue;
        }

        // Final tidy: drop empty-string values to null
        foreach ($assoc as $k => $v) {
            if (is_string($v)) {
                $v = trim($v);
                $assoc[$k] = ($v === '') ? null : $v;
            }
        }

        $rows[] = $assoc;
    }

    fclose($fh);
    return $rows;
}


/** Parse JSON: expect array of objects */
function parse_json_rows(string $path): array {
    $txt = file_get_contents($path);
    $data = json_decode($txt, true);
    return is_array($data) ? $data : [];
}

/* ---------- load file ---------- */
$records = [];
if (preg_match('/json/i', $mime) || str_ends_with(strtolower($fname), '.json')) {
    $records = parse_json_rows($tmp);
} else {
    $records = parse_csv_rows($tmp, $delimiter);
}
if (!$records) { http_response_code(400); exit('No rows found.'); }


/* ---------- prepare statements ---------- */
/* We’ll “upsert” by (name, winery, vintage). If your schema has a UNIQUE key on those,
 * you can switch to a single INSERT ... ON DUPLICATE KEY UPDATE. Here, we SELECT+UPDATE/INSERT.
 */
$selWine = $winelist_pdo->prepare("
  SELECT id FROM wines
  WHERE name = :name AND COALESCE(winery,'') = COALESCE(:winery,'') AND COALESCE(vintage,0) = COALESCE(:vintage,0)
  LIMIT 1
");

// fallback: ignore winery to allow filling it in later
$selWineNoWinery = $winelist_pdo->prepare("
  SELECT id FROM wines
  WHERE name = :name
    AND COALESCE(vintage,0) = COALESCE(:vintage,0)
  LIMIT 1
");
$insWine = $winelist_pdo->prepare("
  INSERT INTO wines (name, winery, region, country, grapes, type, vintage, rating, price, image_url, food_pairings)
  VALUES (:name, :winery, :region, :country, :grapes, :type, :vintage, :rating, :price, :image_url,:food_pairings)
");
$updWine = $winelist_pdo->prepare("
  UPDATE wines
     SET winery       = COALESCE(NULLIF(:winery, ''), winery),
         region       = COALESCE(NULLIF(:region, ''), region),
         country      = COALESCE(NULLIF(:country, ''), country),
         grapes       = COALESCE(NULLIF(:grapes, ''), grapes),
         type         = COALESCE(NULLIF(:type, ''), type),
         rating       = COALESCE(:rating, rating),
         price        = COALESCE(:price,  price),
         image_url    = COALESCE(NULLIF(:image_url, ''), image_url),
         food_pairings= COALESCE(NULLIF(:food_pairings, ''), food_pairings)
   WHERE id = :id
");


/* ---------- process ---------- */
$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];
$nonEmptyWinery = 0; $nonEmptyCountry = 0;


if (!$dryRun) $winelist_pdo->beginTransaction();

// Case-insensitive getter with alias support
// Drop-in: smarter alias getter (exact OR substring token match)
$getA = function($row, array $aliases, array $contains = []): ?string {
    if (!is_array($row) || !$row) return null;   // defensive guard
    // ...


    // normalize header key
    $normKey = function(string $s) {
        $s = mb_strtolower(trim($s));
        // remove accents and punctuation for matching
        $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
        $s = preg_replace('/[^a-z0-9 ]+/',' ', $s);
        $s = preg_replace('/\s+/',' ', $s);
        return trim($s);
    };

    // build a map of normalized header -> original value
    $map = [];
    foreach ($row as $k => $v) {
        $map[$normKey((string)$k)] = is_string($v) ? trim($v) : (string)$v;
    }

    // try exact alias list first
    foreach ($aliases as $alias) {
        $a = $normKey($alias);
        if (array_key_exists($a, $map) && $map[$a] !== '') return $map[$a];
    }

    // then try substring tokens (any token contained in the header)
    foreach ($contains as $tok) {
        $t = $normKey($tok);
        foreach ($map as $hk => $val) {
            if ($val === '') continue;
            if (strpos($hk, $t) !== false) return $val;
        }
    }
    return null;
};
// --- process rows ---
foreach ($records as $idx => $r) {
    if (!is_array($r)) { continue; } // extra guard

    // --- header aliases (expand as needed) ---
    $nameAliases   = ['name','wine','wine name','wine_name','title','label','wine title','cuvée','cuvee'];
    $wineryAliases = ['winery','producer','brand','estate','château','chateau','domain','domaine'];
    $regionAliases = ['region','appellation','ava'];
    $wineryContains= ['winery','producer','brand'];
    $countryAliases= ['country','origin','country of origin','nation'];
    $countryContains= ['country','origin'];
    $grapeAliases  = ['grapes','grape','varietal','variety','cépage','cepage'];
    $typeAliases   = ['type','style','colour','color','category'];
    $vintageAliases= ['vintage','year'];
    $ratingAliases = ['rating','score','points'];
    $priceAliases  = ['price','price_usd','retail','msrp'];
    $imageAliases  = ['image_url','image','image url','thumbnail','thumb','photo'];
    $foodAliases   = ['food_pairings','food','pairings','pairing'];

    // --- pull values using aliases ---
    $nameRaw      = $getA($r, $nameAliases);
    $winery       = norm_str($getA($r, $wineryAliases, $wineryContains));
    $country      = norm_str($getA($r, $countryAliases, $countryContains));
    $region       = norm_str($getA($r, $regionAliases));
    $grapesRaw    = norm_str($getA($r, $grapeAliases));
    $type         = norm_lower($getA($r, $typeAliases));
    $vintage      = norm_year($getA($r, $vintageAliases));
    $scoreRaw     = $getA($r, $ratingAliases);
    $price        = norm_decimal($getA($r, $priceAliases));
    $image_url    = norm_str($getA($r, $imageAliases));
    $food_pairings= norm_str($getA($r, $foodAliases));

    // ... (the rest of your per-row logic stays as-is)


// clean grapes (drop stray 4-digit years)
    $grapes = $grapesRaw ? trim(preg_replace('/\s+/', ' ', preg_replace('/\b\d{4}\b/', '', $grapesRaw))) : null;

// normalize rating to 0–5
    $scoreClean = $scoreRaw !== null ? preg_replace('/[^\d.]/', '', (string)$scoreRaw) : null;
    $normRating = normalize_rating($scoreClean, $RATING_INPUT_SCALE, $RATING_TARGET_MAX);

// Build a robust name
    $name = norm_str($nameRaw);
    if (!$name) {
        // Common Decanter pattern: Producer + Wine [+ Vintage]
        $parts = array_filter([ $winery, $getA($r, ['wine','wine name','title','label']) ], fn($x)=>$x!==null && trim($x)!=='');
        if ($parts) $name = norm_str(implode(' ', $parts));
    }
// As a last resort, use winery + type + vintage
    if (!$name) {
        $fallbackParts = array_filter([ $winery, $type, $vintage ? (string)$vintage : null ]);
        $name = $fallbackParts ? norm_str(implode(' ', $fallbackParts)) : null;
    }

    //if (!$name) { $skipped++; continue; } // must have at least a name

    try {
        // find existing
        $selWine->execute([
            ':name'    => $name,
            ':winery'  => $winery,
            ':vintage' => $vintage,
        ]);
        $existing = $selWine->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            // fallback ignores winery so we can update it
            $selWineNoWinery = $winelist_pdo->prepare("
      SELECT id FROM wines
       WHERE name = :name AND COALESCE(vintage,0) = COALESCE(:vintage,0)
       LIMIT 1
    ");
            $selWineNoWinery->execute([':name'=>$name, ':vintage'=>$vintage]);
            $existing = $selWineNoWinery->fetch(PDO::FETCH_ASSOC);
        }

      //  if ($dryRun) {
            // do nothing, just count how it would go
      //      if ($existing) { $updated++; } else { $inserted++; }
       //     continue;
       // }
        static $dbg = 0;
        if ($dbg < 5) {
            echo "DBG row ".($idx+1).": name=", ($name ?? 'NULL'),
            " | winery=", ($winery ?? 'NULL'),
            " | country=", ($country ?? 'NULL'), "\n";
            $dbg++;
        }
        if ($winery)  $nonEmptyWinery++;
        if ($country) $nonEmptyCountry++;


        if ($existing) {
            $updWine->execute([
                ':id'            => (int)$existing['id'],
                ':winery'        => $winery,
                ':region'        => $region,
                ':country'       => $country,
                ':grapes'        => $grapes,
                ':type'          => $type,
                ':rating'        => $normRating,
                ':price'         => $price,
                ':image_url'     => $image_url,
                ':food_pairings' => $food_pairings,
            ]);
            $updated++;
        } else {
            $insWine->execute([
                ':name'          => $name,
                ':winery'        => $winery,
                ':region'        => $region,
                ':country'       => $country,
                ':grapes'        => $grapes,
                ':type'          => $type,
                ':vintage'       => $vintage,
                ':rating'        => $normRating,
                ':price'         => $price,
                ':image_url'     => $image_url,
                ':food_pairings' => $food_pairings,
            ]);
            $inserted++;
        }
    } catch (Throwable $e) {
        $errors[] = "Row " . ($idx + 1) . ": " . $e->getMessage();

    }} // <-- end foreach ($records as $idx => $r)

if (!$dryRun) $winelist_pdo->commit();

/* ---------- report ---------- */
echo "File: {$fname}\n";
echo $dryRun ? "Mode: DRY RUN (no DB writes)\n" : "Mode: COMMIT (DB updated)\n";
echo "Total rows read: ".count($records)."\n";
echo "Inserted: {$inserted}\n";
echo "Updated:  {$updated}\n";
echo "Skipped (missing name): {$skipped}\n";
echo "Non-empty parsed winery:  {$nonEmptyWinery}\n";
echo "Non-empty parsed country: {$nonEmptyCountry}\n";
if ($errors) {
    echo "\nErrors (first 25):\n";
    foreach (array_slice($errors, 0, 25) as $err) echo " - {$err}\n";
    if (count($errors) > 25) echo " ... ".(count($errors)-25)." more\n";
}
echo "</pre>";

echo '<p><a href="home.php" style="
  display:inline-block;padding:8px 14px;background:#4CAF50;color:#fff;
  text-decoration:none;border-radius:6px;">🏠 Home</a></p>';
echo '<a class="btn secondary" href="admin_csv_import.php">Back</a>';

@ob_flush(); @flush();
ob_end_flush();
