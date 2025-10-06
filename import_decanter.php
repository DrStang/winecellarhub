<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Optional admin gate:
if (empty($_SESSION['is_admin'])) { http_response_code(403); exit('Admins only'); }

if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
    http_response_code(500);
    exit("Catalog DB (\$winelist_pdo) not configured in db.php.");
}

$has_expert = $winelist_pdo->query("SHOW TABLES LIKE 'expert_picks'")->fetchColumn();
if (!$has_expert) {
    http_response_code(500);
    exit("Create expert_picks table first (with UNIQUE key on (source,year,list_name,name(191),winery(191),vintage)).");
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit("Upload failed.");
}

$default_source = trim($_POST['default_source'] ?? 'Decanter DWWA');
$default_year   = (int)($_POST['default_year'] ?? 0);
$default_list   = trim($_POST['default_list_name'] ?? '');
$default_medal  = trim($_POST['default_medal'] ?? '');
$writeRating   = !empty($_POST['update_rating']);   // checkbox name in your admin form
$updateOnly     = !empty($_POST['update_only']);      // update existing expert_picks only (no new rows)

$tmp   = $_FILES['file']['tmp_name'];
$fname = $_FILES['file']['name'];
$mime  = @mime_content_type($tmp);

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

/* ------------------ helpers ------------------ */
function parse_csv($path, ?string $delimiter = null) {
    // Read raw -> make sure it's UTF-8 (handles UTF-16 exports from Excel)
    $raw = file_get_contents($path);
    if ($raw === false) return [];

    if (strncmp($raw, "\xFF\xFE", 2) === 0) {
        $raw = iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
    } elseif (strncmp($raw, "\xFE\xFF", 2) === 0) {
        $raw = iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xp_');
    file_put_contents($tmp, $raw);
    $h = fopen($tmp, 'r');
    if ($h === false) return [];

    // peek first line for delimiter auto-detect if not supplied
    $first = fgets($h);
    if ($first === false) { fclose($h); return []; }
    if ($delimiter === null) {
        $cands = [",", "\t", ";", "|"];
        $best = ","; $bestCount = -1;
        foreach ($cands as $d) {
            $cnt = substr_count($first, $d);
            if ($cnt > $bestCount) { $best = $d; $bestCount = $cnt; }
        }
        $delimiter = $bestCount > 0 ? $best : ",";
    }
    rewind($h);

    // PHP 8.4: pass escape char
    $headers = fgetcsv($h, 0, $delimiter, '"', '\\');
    if ($headers === false) { fclose($h); return []; }

    // normalize headers
    $headers = array_map(static fn($x) => mb_strtolower(trim((string)$x)), $headers);
    while (!empty($headers) && end($headers) === '') array_pop($headers);
    $H = count($headers);

    $rows = [];
    while (($r = fgetcsv($h, 0, $delimiter, '"', '\\')) !== false) {
        if ($r === [null] || $r === [] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
        foreach ($r as $i => $v) { $r[$i] = is_string($v) ? trim($v) : $v; }
        $c = count($r);
        if ($c < $H) $r = array_pad($r, $H, '');
        elseif ($c > $H) {
            $head = array_slice($r, 0, $H - 1);
            $tail = array_slice($r, $H - 1);
            $r = array_merge($head, [implode(' ', $tail)]);
        }
        $assoc = array_combine($headers, $r);
        if ($assoc === false) continue;
        $rows[] = $assoc;
    }
    fclose($h);
    return $rows;
}
$records = (preg_match('/json/', (string)$mime) || str_ends_with(strtolower($fname), '.json'))
    ? parse_json($tmp)
    : parse_csv($tmp, isset($_POST['delimiter']) && $_POST['delimiter'] === '\t' ? "\t" : null);

function parse_json($path) {
    $txt = file_get_contents($path);
    $data = json_decode($txt, true);
    return is_array($data) ? $data : [];
}
function norm($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}
function normalize_for_match($s) {
    $s = mb_strtolower($s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s\-\']/u', '', $s);
    return trim($s);
}
function try_match_wine(PDO $pdo, $name, $winery = '', $vintage = '') {
    if (!$name) return null;

    // exact-ish on name + optional winery + optional vintage
    $params = [':n' => $name];
    $sql = "SELECT id, name, winery, vintage FROM wines WHERE name = :n";
    if ($winery) { $sql .= " AND winery = :w"; $params[':w'] = $winery; }
    if ($vintage) { $sql .= " AND vintage = :v"; $params[':v'] = $vintage; }
    $sql .= " LIMIT 1";
    $st = $pdo->prepare($sql); $st->execute($params);
    $hit = $st->fetch(PDO::FETCH_ASSOC);
    if ($hit) return (int)$hit['id'];

    // LIKE candidates
    $q = '%'.str_replace(' ', '%', $name).'%';
    if ($winery) {
        $st = $pdo->prepare("SELECT id, name, winery, vintage FROM wines 
                         WHERE name LIKE :q AND winery LIKE :w 
                         ORDER BY vintage DESC LIMIT 50");
        $st->execute([':q'=>$q, ':w'=>'%'.str_replace(' ','%',$winery).'%']);
    } else {
        $st = $pdo->prepare("SELECT id, name, winery, vintage FROM wines 
                         WHERE name LIKE :q 
                         ORDER BY vintage DESC LIMIT 50");
        $st->execute([':q'=>$q]);
    }
    $cands = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$cands) return null;

    // pick nearest by levenshtein
    $nameN = normalize_for_match($name);
    $winN  = $winery ? normalize_for_match($winery) : '';
    $best = null; $bestDist = PHP_INT_MAX;
    foreach ($cands as $c) {
        $dn = levenshtein($nameN, normalize_for_match($c['name']));
        $dw = $winN ? levenshtein($winN, normalize_for_match($c['winery'] ?? '')) : 0;
        $dv = ($vintage && !empty($c['vintage']) && $vintage !== $c['vintage']) ? 2 : 0;
        $dist = $dn + max(0, $dw - 1) + $dv;
        if ($dist < $bestDist) { $bestDist = $dist; $best = $c; }
    }
    return $best ? (int)$best['id'] : null;
}
// alias-aware getter
$geta = function(array $row, array $aliases): ?string {
    foreach ($row as $k => $v) {
        $kl = mb_strtolower(trim((string)$k));
        foreach ($aliases as $want) {
            if ($kl === mb_strtolower($want)) {
                return is_string($v) ? trim($v) : (string)$v;
            }
        }
    }
    return null;
};
$ALIAS = [
    'source'    => ['source','award source','publication'],
    'year'      => ['year','award year','list year'],
    'list_name' => ['list_name','list','category','tier','award list'],
    'medal'     => ['medal','award','tier','class'],
    'score'     => ['score','points','rating'],
    'name'      => ['name','wine','wine name','wine title','title','label','cuvée','cuvee'],
    'winery'    => ['winery','producer','brand','estate','château','chateau','domaine','domain'],
    'region'    => ['region','appellation','ava'],
    'country'   => ['country','origin'],
    'grapes'    => ['grapes','grape','varietal','variety','cépage','cepage'],
    'type'      => ['type','style','colour','color','category'],
    'vintage'   => ['vintage','year'],
    'image_url' => ['image_url','image','image url','thumbnail','thumb','photo'],
    'notes'     => ['notes','blurb','description','review'],
    'rank'      => ['rank','Rank'],

];

/* ------------------ parse input ------------------ */
$records = [];
if (preg_match('/json/', (string)$mime) || str_ends_with(strtolower($fname), '.json')) {
    $records = parse_json($tmp);
} else {
    $records = parse_csv($tmp);
}
if (!$records) { http_response_code(400); exit("No rows found."); }

/*
Accepted fields (case-insensitive for CSV):
source, year, list_name, medal, score, ratings, name, winery, region,
country, grapes, type, vintage, image_url, notes

We store:
- expert_picks.score  = raw CSV score/ratings (no scaling; e.g., 97)
- wines.rating        = normalized to 0–5.00 if 'update_ratings' checked
- country, grapes, image_url mirrored into both tables
*/

/* ------------------ prepared statements ------------------ */
$insWine = $winelist_pdo->prepare("
  INSERT INTO wines (name, winery, region, country, grapes, type, vintage, rating, image_url)
  VALUES (:name, :winery, :region, :country, :grapes, :type, :vintage, :rating, :image_url)
");

$updWineRating = $winelist_pdo->prepare("
  UPDATE wines SET rating = :rating WHERE id = :id
");

$updWineMeta = $winelist_pdo->prepare("
  UPDATE wines SET
    region    = COALESCE(:region, region),
    country   = COALESCE(:country, country),
    grapes    = COALESCE(:grapes, grapes),
    type      = COALESCE(:type, type),
    vintage   = COALESCE(:vintage, vintage),
    image_url = COALESCE(:image_url, image_url)
  WHERE id = :id
");

$updPick = $winelist_pdo->prepare("
  UPDATE expert_picks
     SET wine_id   = :wine_id,
         score     = COALESCE(:score, score),
         medal     = COALESCE(:medal, medal),
         region    = COALESCE(:region, region),
         country   = COALESCE(:country, country),
         grapes    = COALESCE(:grapes, grapes),
         type      = COALESCE(:type, type),
         image_url = COALESCE(:image_url, image_url),
         rank       = COALESCE(:rank, rank),
         notes     = COALESCE(:notes, notes)
   WHERE source = :source AND year = :year AND list_name = :list_name
     AND name = :name AND winery = :winery AND vintage = :vintage
");

$insPickUpsert = $winelist_pdo->prepare("
  INSERT INTO expert_picks
    (source, year, list_name, medal, score, wine_id, name, winery, region, country, grapes, type, vintage, image_url, rank, notes)
  VALUES
    (:source, :year, :list_name, :medal, :score, :wine_id, :name, :winery, :region, :country, :grapes, :type, :vintage, :image_url, :rank, :notes)
  ON DUPLICATE KEY UPDATE
    wine_id   = VALUES(wine_id),
    score     = COALESCE(VALUES(score), score),
    medal     = COALESCE(VALUES(medal), medal),
    region    = COALESCE(VALUES(region), region),
    country   = COALESCE(VALUES(country), country),
    grapes    = COALESCE(VALUES(grapes), grapes),
    type      = COALESCE(VALUES(type), type),
    image_url = COALESCE(VALUES(image_url), image_url),
    rank       = COALESCE(VALUES(rank), rank),
    notes     = COALESCE(VALUES(notes), notes)
");

/* ------------------ process ------------------ */
$processed = 0;
$insertedWines = 0;
$matchedWines  = 0;
$updatedPicks  = 0;
$upsertedPicks = 0;
$skipped = 0; $reasons = []; $showN = 10;

foreach ($records as $r) {
    // header-agnostic getter
    $get = function($k) use ($r) {
        if (isset($r[$k])) return $r[$k];
        $kl = strtolower($k);
        foreach ($r as $kk=>$vv) { if (strtolower($kk) === $kl) return $vv; }
        return null;
    };

    $source  = norm($get('source') ?? $default_source);
    $year    = (int)($get('year') ?? $default_year);
    $list    = norm($get('list_name') ?? $default_list);
    $medal   = norm($get('medal') ?? $default_medal);

    // raw score from CSV (keep for expert_picks.score)
    $rawScore = null;
    $csvRating = $get('rating');
    $csvScore   = $get('score');
    if ($csvRating !== null && $csvRating !== '') {
        $rawScore = (float)$csvRating;
    } elseif ($csvScore !== null && $csvScore !== '') {
        $rawScore = (float)$csvScore;
    }

    // normalized for wines.rating (0..5)
    $source  = norm($geta($r, $ALIAS['source']) ?? $default_source);
    $year    = (int)($geta($r, $ALIAS['year']) ?? $default_year);
    $list    = norm($geta($r, $ALIAS['list_name']) ?? $default_list);
    $medal   = norm($geta($r, $ALIAS['medal']) ?? $default_medal);

    $csvScore = $geta($r, $ALIAS['score']);
    $rawScore = $csvScore !== null && $csvScore !== '' ? (float)preg_replace('/[^\d.]/', '', $csvScore) : null;
    $normRating = normalize_rating($rawScore, $RATING_INPUT_SCALE, $RATING_TARGET_MAX);

    $nameRaw = $geta($r, $ALIAS['name']);
    $winery  = norm($geta($r, $ALIAS['winery']) ?? '');
    $region  = norm($geta($r, $ALIAS['region']) ?? '');
    $country = norm($geta($r, $ALIAS['country']) ?? '');
    $grapesR = norm($geta($r, $ALIAS['grapes']) ?? '');
    $grapes  = $grapesR ? trim(preg_replace('/\s+/', ' ', preg_replace('/\b\d{4}\b/','',$grapesR))) : '';
    $type    = strtolower(norm($geta($r, $ALIAS['type']) ?? ''));
    $vint    = trim((string)($geta($r, $ALIAS['vintage']) ?? ''));
    $image   = norm($geta($r, $ALIAS['image_url']) ?? '');
    $rank   =norm($geta($r, $ALIAS['rank']) ?? '');
    $notes   = norm($geta($r, $ALIAS['notes']) ?? '');

// Build a robust name if 'name' is missing/split
    $name = norm($nameRaw ?? '');
    if (!$name) {
        $parts = array_filter([$winery, $nameRaw], fn($x)=>$x && trim($x)!=='');
        if ($parts) $name = norm(implode(' ', $parts));
    }
    if (!$name) {
        $fallback = array_filter([$winery, $type, $vint ?: null]);
        $name = $fallback ? norm(implode(' ', $fallback)) : '';
    }

// Guard (same as yours, but now far fewer will be skipped)
    if (!$source || !$year || !$list || !$name) {
        $skipped++;
        if (count($reasons) < $showN) {
            $reasons[] = "Skip row: missing ".implode('/', array_filter([
                    !$source ? 'source' : null,
                    !$year   ? 'year'   : null,
                    !$list   ? 'list'   : null,
                    !$name   ? 'name'   : null,
                ]))." | keys=".implode(', ', array_keys($r));
        }
        continue;
    }



    // find or insert wine
    $wine_id = try_match_wine($winelist_pdo, $name, $winery, $vint);
    if ($wine_id) {
        $matchedWines++;

        if ($writeRating && $normRating !== null) {
            $updWineRating->execute([':rating'=>$normRating, ':id'=>$wine_id]);
        }
        // fill metadata if provided
        $updWineMeta->execute([
            ':region'    => $region ?: null,
            ':country'   => $country ?: null,
            ':grapes'    => $grapes ?: null,
            ':type'      => $type ?: null,
            ':vintage'   => $vint ?: null,
            ':image_url' => $image ?: null,
            ':id'        => $wine_id
        ]);

    } else {
        // insert new wine
        $insWine->execute([
            ':name'      => $name,
            ':winery'    => $winery,
            ':region'    => $region ?: null,
            ':country'   => $country ?: null,
            ':grapes'    => $grapes ?: null,
            ':type'      => $type ?: null,
            ':vintage'   => $vint ?: null,
            ':rating'    => $writeRating ? $normRating : null,
            ':image_url' => $image ?: null,
        ]);
        $wine_id = (int)$winelist_pdo->lastInsertId();
        $insertedWines++;
    }

    if ($updateOnly) {
        // update existing expert_picks only
        $updPick->execute([
            ':wine_id'   => $wine_id,
            ':score'     => $rawScore,          // keep raw Decanter score
            ':medal'     => $medal ?: null,
            ':region'    => $region ?: null,
            ':country'   => $country ?: null,
            ':grapes'    => $grapes ?: null,
            ':type'      => $type ?: null,
            ':image_url' => $image ?: null,
            ':notes'     => $notes ?: null,
            ':source'    => $source,
            ':year'      => $year ?: null,
            ':list_name' => $list,
            ':name'      => $name,
            ':winery'    => $winery,
            ':rank'      => $rank ?: null,
            ':vintage'   => $vint,
        ]);
        if ($updPick->rowCount() > 0) { $updatedPicks++; }

    } else {
        // upsert expert_picks
        $insPickUpsert->execute([
            ':source'    => $source,
            ':year'      => $year ?: null,
            ':list_name' => $list,
            ':medal'     => $medal ?: null,
            ':score'     => $rawScore,          // store raw
            ':wine_id'   => $wine_id,
            ':name'      => $name,
            ':winery'    => $winery,
            ':region'    => $region ?: null,
            ':country'   => $country ?: null,
            ':grapes'    => $grapes ?: null,
            ':type'      => $type ?: null,
            ':vintage'   => $vint,
            ':image_url' => $image ?: null,
            ':rank'      => $rank ?: null,
            ':notes'     => $notes ?: null,
        ]);
        $upsertedPicks++;
    }

    $processed++;
}

/* ------------------ report ------------------ */
header('Content-Type: text/html; charset=utf-8'); // changed from text/plain so we can use HTML
echo "<pre>";
echo "Processed records: {$processed}\n";
echo "Matched existing wines: {$matchedWines}\n";
echo "Inserted new wines: {$insertedWines}\n";
if ($updateOnly) {
    echo "Updated existing expert picks: {$updatedPicks}\n";
} else {
    echo "Upserted expert picks (inserted/updated): {$upsertedPicks}\n";
}
echo "Skipped rows: {$skipped}\n";
if ($reasons) {
    echo "\nSkip reasons (first ".count($reasons)."):\n - ".implode("\n - ", $reasons)."\n";
}

echo "Done.\n";
echo "</pre>";

// Add a 'Home' button
echo '<p><a href="home.php" style="
    display:inline-block;
    padding:8px 14px;
    background:#4CAF50;
    color:#fff;
    text-decoration:none;
    border-radius:5px;
">🏠 Home</a></p>';

