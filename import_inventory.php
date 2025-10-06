<?php

// import_inventory.php — CSV import for the current user's bottles (insert-only)
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo "Not authenticated";
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "DB not available";
    exit;
}

// Optional catalog connection (winelist)
$catalogConn = null;
if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
    $catalogConn = $winelist_pdo;
} elseif (isset($winelist_dsn, $winelist_user, $winelist_pass)) {
    try {
        $catalogConn = new PDO($winelist_dsn, $winelist_user, $winelist_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $catalogConn = null;
    }
}

function norm($s)
{
    return strtolower(trim((string)$s));
}

// Validate upload
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "POST required";
    exit;
}

if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['flash_err'] = 'Please choose a CSV file to import.';
    header('Location: /account.php');
    exit;
}

$mime = $_FILES['csv']['type'] ?? '';
$allowed = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'];
if (!$mime) $mime = 'text/plain'; // browsers vary—don’t be strict
$tmp = $_FILES['csv']['tmp_name'] ?? '';
if (!$tmp || !is_uploaded_file($tmp)) {
    $_SESSION['flash_err'] = 'Upload failed: no file received.';
    header('Location: /account.php');
    exit;
}

$fh = fopen($tmp, 'r');
if (!$fh) {
    $_SESSION['flash_err'] = 'Could not open uploaded file.';
    header('Location: /account.php');
    exit;
}

// Read header
$header = fgetcsv($fh);
if (!$header || !is_array($header)) {
    fclose($fh);
    $_SESSION['flash_err'] = 'CSV appears to be empty or invalid.';
    header('Location: /account.php');
    exit;
}

// Map columns (case-insensitive)
$colmap = [];
foreach ($header as $i => $label) {
    $colmap[norm($label)] = $i;
}
$idx = function (string $want) use ($colmap) {
    $aliases = [
        'bottle_id' => ['bottle_id', 'id'],
        'wine_id' => ['wine_id', 'catalog_id', 'catalog_wine_id'],
        'winery' => ['winery', 'producer'],
        'name' => ['name', 'wine', 'label', 'cuvee'],
        'vintage' => ['vintage', 'year'],
        'region' => ['region', 'appellation'],
        'grapes' => ['grapes', 'varietals', 'varietal'],
        'type' => ['type', 'style'],
        'price_paid' => ['price_paid', 'price', 'cost', 'purchase_price'],
        'my_rating' => ['my_rating', 'rating', 'score'],
        'location' => ['location', 'bin', 'shelf'],
        'past' => ['past', 'consumed', 'drank'],
        'photo_path' => ['photo_path', 'photo', 'local_photo'],
        'image_url' => ['image_url', 'cover', 'image'],
    ];
    foreach ($aliases[$want] ?? [$want] as $a) {
        if (isset($colmap[$a])) return $colmap[$a];
    }
    return null;
};

// Insert statement
$ins = $pdo->prepare("
    INSERT INTO bottles
        (user_id, wine_id, name, winery, region, grapes, vintage,
         photo_path, image_url, price_paid, my_rating, location, past)
    VALUES
        (:uid, :wine_id, :name, :winery, :region, :grapes, :vintage,
         :photo_path, :image_url, :price_paid, :my_rating, :location, :past)
");

// Simple catalog lookup by winery+name(+vintage)
$findCatalog = function ($winery, $name, $vintage) use ($catalogConn) {
    if (!$catalogConn) return null;
    $winery = trim((string)$winery);
    $name = trim((string)$name);
    $vintage = trim((string)$vintage);
    if ($winery === '' || $name === '') return null;

    // Prefer exact winery+name+vintage match if provided
    if ($vintage !== '') {
        $st = $catalogConn->prepare("
            SELECT id, wine_id FROM wines
            WHERE winery = :w AND name = :n AND (vintage = :v OR IFNULL(vintage,'')='')
            ORDER BY vintage DESC LIMIT 1
        ");
        $st->execute([':w' => $winery, ':n' => $name, ':v' => $vintage]);
        $row = $st->fetch();
        if ($row) return (int)($row['id'] ?: $row['wine_id'] ?: 0);
    }

    // Fallback: winery+name only
    $st = $catalogConn->prepare("
        SELECT id, wine_id FROM wines
        WHERE winery = :w AND name = :n
        ORDER BY vintage DESC LIMIT 1
    ");
    $st->execute([':w' => $winery, ':n' => $name]);
    $row = $st->fetch();
    if ($row) return (int)($row['id'] ?: $row['wine_id'] ?: 0);

    return null;
};

$inserted = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    while (($row = fgetcsv($fh)) !== false) {
        if (!is_array($row) || count($row) === 0) {
            $skipped++;
            continue;
        }

        $val = function (?int $i) use ($row) {
            if ($i === null) return '';
            return isset($row[$i]) ? trim((string)$row[$i]) : '';
        };

        $winery = $val($idx('winery'));
        $name = $val($idx('name'));
        $vintage = $val($idx('vintage'));
        $region = $val($idx('region'));
        $grapes = $val($idx('grapes'));
        $pricePaid = $val($idx('price_paid'));
        $rating = $val($idx('my_rating'));
        $location = $val($idx('location'));
        $past = $val($idx('past'));
        $photoPath = $val($idx('photo_path'));
        $imageUrl = $val($idx('image_url'));
        $wineIdCsv = $val($idx('wine_id'));

        // Minimal requirement: at least a name (and ideally winery)
        if ($name === '' && $winery === '') {
            $skipped++;
            continue;
        }

        // Coerce types
        $vintageNum = (is_numeric($vintage) ? (int)$vintage : null);
        $pricePaidNum = (is_numeric($pricePaid) ? (float)$pricePaid : null);
        $ratingNum = (is_numeric($rating) ? (float)$rating : null);
        $pastNum = in_array(strtolower((string)$past), ['1', 'yes', 'y', 'true', 't', 'consumed', 'drank'], true) ? 1 : 0;

        // Resolve wine_id
        $wineId = null;
        if ($wineIdCsv !== '' && ctype_digit($wineIdCsv)) {
            $wineId = (int)$wineIdCsv;
        } else {
            $wineId = $findCatalog($winery, $name, (string)$vintageNum);
        }

        $ins->execute([
            ':uid' => $userId,
            ':wine_id' => $wineId,
            ':name' => $name,
            ':winery' => $winery,
            ':region' => $region,
            ':grapes' => $grapes,
            ':vintage' => $vintageNum,
            ':photo_path' => $photoPath,
            ':image_url' => $imageUrl,
            ':price_paid' => $pricePaidNum,
            ':my_rating' => $ratingNum,
            ':location' => $location,
            ':past' => $pastNum,
        ]);
        $inserted++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fclose($fh);
    $_SESSION['flash_err'] = 'Import failed: ' . $e->getMessage();
    header('Location: /account.php');
    exit;
}
fclose($fh);

$_SESSION['flash'] = "Imported {$inserted} rows" . ($skipped ? " ({$skipped} skipped)" : "");
header('Location: /account.php');
