#!/usr/bin/env php
<?php
/**
 * cache_images.php
 *
 * CLI tool to download and cache remote images found in a DB column, and store a local relative path in another column.
 *
 * Usage examples:
 *   php cache_images.php --table=bottles --id=id --url=image_url --destcol=photo_path --destdir=/var/www/html/wine/covers --baseurl=/covers
 *   php cache_images.php --table=winelist --id=id --url=image_url --destcol=cached_image --destdir=/var/www/html/wine/covers --baseurl=/covers
 *
 * Notes:
 * - If your project has db.php with $pdo, this script will require it automatically (place this file in the same app root).
 * - Otherwise, set DB_* env vars or hardcode DSN/credentials below.
 * - Safe to re-run; it skips rows that already have a local path in dest column.
 */

ini_set('memory_limit', '1024M');
date_default_timezone_set('UTC');

// ---------- Config (overridable by CLI flags) ----------
$config = [
    'table'        => 'wines',        // e.g., 'bottles' or 'winelist'
    'id_col'       => 'id',             // primary key column
    'url_col'      => 'image_url',      // column that contains remote image URL
    'dest_col'     => 'image_url',     // column to store local relative path (e.g., '/covers/123.jpg')
    'dest_dir'     => __DIR__ . '/covers', // absolute directory to save images
    'base_url'     => '/covers',        // relative URL prefix to store in dest_col
    'batch'        => 250,              // process N rows per run
    'sleep_ms'     => 150,              // pause between downloads (helps avoid rate limits)
    'user_agent'   => 'ImageCacher/1.0 (+yourdomain)',
    'timeout'      => 25,               // seconds per request
    'connect_timeout' => 12,
    'follow_redirects' => true,
    'max_redirects' => 5,
    'allow_insecure' => false,          // set true only if you have bad/old TLS endpoints
    'only_if_empty' => true,            // skip rows where dest_col already set (recommended)
    'verbose'      => true,             // print progress
];

// ---------- CLI flag parsing ----------
foreach ($argv as $arg) {
    if (preg_match('/^--table=(.+)$/', $arg, $m))        $config['table'] = $m[1];
    elseif (preg_match('/^--id=(.+)$/', $arg, $m))       $config['id_col'] = $m[1];
    elseif (preg_match('/^--url=(.+)$/', $arg, $m))      $config['url_col'] = $m[1];
    elseif (preg_match('/^--destcol=(.+)$/', $arg, $m))  $config['dest_col'] = $m[1];
    elseif (preg_match('/^--destdir=(.+)$/', $arg, $m))  $config['dest_dir'] = rtrim($m[1], '/');
    elseif (preg_match('/^--baseurl=(.+)$/', $arg, $m))  $config['base_url'] = rtrim($m[1], '/');
    elseif (preg_match('/^--batch=(\d+)$/', $arg, $m))   $config['batch'] = (int)$m[1];
    elseif (preg_match('/^--only_if_empty=(0|1)$/', $arg, $m)) $config['only_if_empty'] = (bool)$m[1];
    elseif (preg_match('/^--verbose=(0|1)$/', $arg, $m)) $config['verbose'] = (bool)$m[1];
    elseif (preg_match('/^--allow_insecure=(0|1)$/', $arg, $m)) $config['allow_insecure'] = (bool)$m[1];
}

// ---------- DB bootstrap ----------
$winelist_pdo = null;
$usedAppDb = false;
$tryAppDb = __DIR__ . '/db.php';
if (is_file($tryAppDb)) {
    require_once $tryAppDb;
    if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        $usedAppDb = true;
    }
}
if (!$usedAppDb) {
    $dsn  = getenv('DB_DSN')  ?: 'mysql:host=127.0.0.1;dbname=winelist;charset=utf8mb4';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $winelist_pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// ---------- Ensure dest dir ----------
if (!is_dir($config['dest_dir'])) {
    if (!mkdir($config['dest_dir'], 0755, true) && !is_dir($config['dest_dir'])) {
        fwrite(STDERR, "ERROR: Failed to create {$config['dest_dir']}\n");
        exit(1);
    }
}

// ---------- Helpers ----------
function logv($msg, $cfg) {
    if ($cfg['verbose']) {
        echo '[' . date('H:i:s') . "] $msg\n";
    }
}

function http_get_bytes($url, $cfg) {
    // Prefer cURL if available; otherwise fall back to streams
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $cfg['follow_redirects'],
            CURLOPT_MAXREDIRS      => $cfg['max_redirects'],
            CURLOPT_CONNECTTIMEOUT => $cfg['connect_timeout'],
            CURLOPT_TIMEOUT        => $cfg['timeout'],
            CURLOPT_USERAGENT      => $cfg['user_agent'],
            CURLOPT_SSL_VERIFYPEER => !$cfg['allow_insecure'],
            CURLOPT_SSL_VERIFYHOST => $cfg['allow_insecure'] ? 0 : 2,
            CURLOPT_HTTPHEADER     => ['Accept: image/*,*/*;q=0.8'],
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($data === false || $code >= 400) {
            return [null, $code ?: 0, $err ?: "HTTP $code"];
        }
        return [$data, $code, null];
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: {$cfg['user_agent']}\r\nAccept: image/*,*/*;q=0.8\r\n",
                'timeout' => $cfg['timeout'],
            ],
            'ssl' => [
                'verify_peer'      => !$cfg['allow_insecure'],
                'verify_peer_name' => !$cfg['allow_insecure'],
                'allow_self_signed'=> $cfg['allow_insecure'],
                'SNI_enabled'      => true,
                'capture_peer_cert'=> false,
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $meta = isset($http_response_header) ? implode(' | ', $http_response_header) : 'no response headers';
            return [null, 0, "stream error ($meta)"];
        }
        return [$data, 200, null];
    }
}

function ext_from_image_bytes($bytes) {
    // Use getimagesizefromstring when possible
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($bytes);
        if ($info && !empty($info['mime'])) {
            switch (strtolower($info['mime'])) {
                case 'image/jpeg': return 'jpg';
                case 'image/png':  return 'png';
                case 'image/gif':  return 'gif';
                case 'image/webp': return 'webp';
                case 'image/svg+xml': return 'svg';
                default: return null;
            }
        }
    }
    // Fallback: magic sniff
    $sig = substr($bytes, 0, 12);
    if (strncmp($sig, "\xFF\xD8", 2) === 0) return 'jpg';
    if (strncmp($sig, "\x89PNG", 4) === 0)  return 'png';
    if (strncmp($sig, "GIF8", 4) === 0)     return 'gif';
    if (strpos($sig, "WEBP") !== false)      return 'webp';
    // crude SVG check
    if (stripos($bytes, '<svg') !== false && stripos($bytes, '</svg>') !== false) return 'svg';
    return null;
}

function sanitize_filename($s) {
    return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $s);
}

// ---------- Query rows ----------
$tbl  = backtick($config['table']);
$idc  = backtick($config['id_col']);
$urlc = backtick($config['url_col']);
$dest = backtick($config['dest_col']);

$where = "($urlc LIKE 'http%' OR $urlc LIKE '//%')";
if ($config['only_if_empty']) {
    $where .= " AND ($dest IS NULL OR $dest = '')";
}

$sql = "SELECT $idc AS pk, $urlc AS src_url FROM $tbl WHERE $where LIMIT :lim";
$st = $winelist_pdo->prepare($sql);
$st->bindValue(':lim', (int)$config['batch'], PDO::PARAM_INT);
$st->execute();

$rows = $st->fetchAll();
if (!$rows) {
    logv("No rows to process. (table={$config['table']}, url_col={$config['url_col']}, dest_col={$config['dest_col']})", $config);
    exit(0);
}

logv("Found " . count($rows) . " rows to process…", $config);

// ---------- Main loop ----------
$updated = 0; $skipped = 0; $failed = 0;
foreach ($rows as $r) {
    $id  = $r['pk'];
    $url = $r['src_url'];

    if (!$url) { $skipped++; continue; }
    if (strpos($url, '//') === 0) { $url = 'https:' . $url; }

    // Download
    list($bytes, $code, $err) = http_get_bytes($url, $config);
    if ($bytes === null) {
        logv("[$id] FAIL download: $url ($err)", $config);
        $failed++;
        usleep($config['sleep_ms'] * 1000);
        continue;
    }

    // Verify image and extension
    $ext = ext_from_image_bytes($bytes);
    if (!$ext) {
        logv("[$id] FAIL: not a recognizable image ($url)", $config);
        $failed++;
        usleep($config['sleep_ms'] * 1000);
        continue;
    }

    // Build filename: prefer {id}.{ext}
    $filename = sanitize_filename($id) . '.' . $ext;
    $abs_path = $config['dest_dir'] . '/' . $filename;
    $rel_path = $config['base_url'] . '/' . $filename;

    // If a file already exists, keep it (idempotent)
    if (is_file($abs_path) && filesize($abs_path) > 0) {
        // update DB just in case it wasn't set before
        $ok = save_dest_path($winelist_pdo, $config, $id, $rel_path);
        if ($ok) $updated++;
        logv("[$id] SKIP existing file → set {$config['dest_col']}='$rel_path'", $config);
        usleep($config['sleep_ms'] * 1000);
        continue;
    }

    // Write file atomically
    $tmp = $abs_path . '.part.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $bytes) === false) {
        logv("[$id] FAIL: cannot write $tmp", $config);
        @unlink($tmp);
        $failed++;
        usleep($config['sleep_ms'] * 1000);
        continue;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $abs_path)) {
        @unlink($tmp);
        logv("[$id] FAIL: cannot move temp file into place", $config);
        $failed++;
        usleep($config['sleep_ms'] * 1000);
        continue;
    }

    // Update DB
    $ok = save_dest_path($winelist_pdo, $config, $id, $rel_path);
    if ($ok) {
        $updated++;
        logv("[$id] OK → $rel_path", $config);
    } else {
        $failed++;
        logv("[$id] FAIL: DB update", $config);
    }

    usleep($config['sleep_ms'] * 1000);
}

logv("Done. updated=$updated, failed=$failed, skipped=$skipped", $config);


// ---------- Helpers needing PDO ----------
function save_dest_path(PDO $winelist_pdo, array $cfg, $id, $rel) {
    $tbl  = backtick($cfg['table']);
    $idc  = backtick($cfg['id_col']);
    $dest = backtick($cfg['dest_col']);
    $sql = "UPDATE $tbl SET $dest = :p WHERE $idc = :id";
    $st = $winelist_pdo->prepare($sql);
    return $st->execute([':p' => $rel, ':id' => $id]);
}

function backtick($s) {
    // very conservative quoting for identifiers
    return '`' . str_replace('`', '``', $s) . '`';
}
