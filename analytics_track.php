<?php
// analytics_track.php
declare(strict_types=1);

/**
 * Drop-in pageview tracker.
 * - Respects DNT and basic bot detection
 * - Excludes admins by default (override via define('ANALYTICS_TRACK_ADMINS', true))
 * - Uses Cloudflare country header if present, else MaxMind (PECL or Composer) if available
 * - Stores public client IP (INET6_ATON) and optional {"cc":"US"} in `extra`
 * - Uses cookies:
 *     aid (2y anonymous id), sid (session id rotated ~30m idle), sid_last
 *
 * Requires: PDO $pdo handle to your "Wine" DB (with `analytics_events` table).
 * Safe: All failures are swallowed so this never breaks the page.
 */

// Allow opt-out per page:
if (defined('ANALYTICS_SKIP') && ANALYTICS_SKIP) return;

// Ensure session exists (ok if already started)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Try to ensure $pdo exists (only if not already provided by the page)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dbPath = __DIR__ . '/db.php';
    if (is_file($dbPath)) {
        require_once $dbPath;
    }
    // If still no PDO, bail quietly
    if (!isset($pdo) || !($pdo instanceof PDO)) return;
}

// Optional Composer autoload (for MaxMind PHP reader if installed via Composer)
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    /** @noinspection PhpIncludeInspection */
    require_once $autoload;
}

// ---------- helpers
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function same_site_lax_cookie(string $name, string $value, int $ttl): void {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    @setcookie($name, $value, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function looks_like_bot(?string $ua): bool {
    if (!$ua) return true;
    $ua = mb_strtolower($ua);
    foreach ([
                 'bot','spider','crawler','curl','wget','python-requests','headless',
                 'slurp','bingpreview','facebookexternalhit','whatsapp','telegrambot',
                 'discordbot','monitoring','uptimerobot','pingdom','validator','seo'
             ] as $kw) {
        if (str_contains($ua, $kw)) return true;
    }
    return false;
}
function get_public_ip(): ?string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
        if (empty($_SERVER[$h])) continue;
        foreach (explode(',', $_SERVER[$h]) as $ip) {
            $ip = trim($ip);
            if (!$ip) continue;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return null;
}
function resolve_cc(?string $publicIp): ?string {
    // 0) Cloudflare header wins (if enabled in CF -> Network -> IP Geolocation)
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && $_SERVER['HTTP_CF_IPCOUNTRY'] !== 'XX') {
        $cc = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY']));
        return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : null;
    }
    if (!$publicIp) return null;

    // Choose whichever DB exists
    $dbCountry = '/usr/share/GeoIP/GeoLite2-Country.mmdb';
    $dbCity    = '/usr/share/GeoIP/GeoLite2-City.mmdb';
    $dbPath    = is_file($dbCountry) ? $dbCountry : (is_file($dbCity) ? $dbCity : null);

    // 1) PECL extension
    if ($dbPath && extension_loaded('maxminddb')) {
        try {
            $mmdb = maxminddb_open($dbPath);
            if ($mmdb) {
                $result = null;
                if (maxminddb_lookup_string($mmdb, $publicIp, $result) && is_array($result)) {
                    $cc = $result['country']['iso_code'] ?? null;
                    maxminddb_close($mmdb);
                    if (is_string($cc) && preg_match('/^[A-Z]{2}$/', $cc)) return $cc;
                } else {
                    maxminddb_close($mmdb);
                }
            }
        } catch (Throwable) { /* ignore */ }
    }

    // 2) Composer library (pure PHP)
    if ($dbPath && class_exists('MaxMind\\Db\\Reader')) {
        try {
            $reader = new MaxMind\Db\Reader($dbPath);
            $rec = $reader->get($publicIp) ?: [];
            $cc  = $rec['country']['iso_code'] ?? null;
            if (is_string($cc) && preg_match('/^[A-Z]{2}$/', $cc)) return $cc;
        } catch (Throwable) { /* ignore */ }
    }

    return null;
}

// ---------- Respect DNT + basic filters
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1') return;

$isAdmin = !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$trackAdmins = defined('ANALYTICS_TRACK_ADMINS') && ANALYTICS_TRACK_ADMINS === true;

$isBot = looks_like_bot($ua);
if ($isAdmin && !$trackAdmins) {
    // Treat admin traffic as bot (excluded)
    $isBot = true;
}

// ---------- Cookies: aid (2y), sid (~30m idle), sid_last helper
$aid = $_COOKIE['aid'] ?? null;
if (!$aid || strlen($aid) < 10) {
    $aid = uuid4();
    same_site_lax_cookie('aid', $aid, 60*60*24*730); // 2 years
}
$sid = $_COOKIE['sid'] ?? null;
$last = isset($_COOKIE['sid_last']) ? (int)$_COOKIE['sid_last'] : 0;
$now  = time();
if (!$sid || ($now - $last) > 30*60) { // rotate if idle >30m
    $sid = uuid4();
}
same_site_lax_cookie('sid', $sid, 60*60*24);
same_site_lax_cookie('sid_last', (string)$now, 60*60*24);

// ---------- Request details
$page     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$referrer = $_SERVER['HTTP_REFERER'] ?? null;
$publicIp = get_public_ip();
$ipToStore = $publicIp ?: ($_SERVER['REMOTE_ADDR'] ?? null);

// ---------- Country -> extra JSON
$cc = resolve_cc($publicIp);
$extraJson = $cc ? json_encode(['cc' => $cc], JSON_UNESCAPED_UNICODE) : null;

// ---------- Insert pageview
try {
    // Note: If you haven’t yet created the table, run:
    // CREATE TABLE IF NOT EXISTS analytics_events (
    //   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    //   ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    //   aid CHAR(36) NULL,
    //   sid CHAR(36) NULL,
    //   user_id INT NULL,
    //   page VARCHAR(255) NULL,
    //   referrer VARCHAR(512) NULL,
    //   ua VARCHAR(255) NULL,
    //   ip VARBINARY(16) NULL,
    //   is_bot TINYINT(1) NOT NULL DEFAULT 0,
    //   event ENUM('pageview','signup','login','add_bottle','request_book','other') NOT NULL DEFAULT 'pageview',
    //   extra JSON NULL,
    //   KEY idx_ts (ts), KEY idx_event (event), KEY idx_page (page),
    //   KEY idx_user (user_id), KEY idx_sid (sid), KEY idx_aid (aid),
    //   KEY idx_ts_event (ts,event), KEY idx_page_ts (page,ts)
    // ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    $stmt = $pdo->prepare("
        INSERT INTO analytics_events
          (ts, aid, sid, user_id, page, referrer, ua, ip, is_bot, event, extra)
        VALUES
          (NOW(), :aid, :sid, :uid, :page, :ref, :ua, INET6_ATON(:ip), :is_bot, 'pageview', :extra)
    ");
    $stmt->execute([
        ':aid'    => $aid,
        ':sid'    => $sid,
        ':uid'    => $_SESSION['user_id'] ?? null,
        ':page'   => $page,
        ':ref'    => $referrer,
        ':ua'     => mb_substr($ua ?? '', 0, 250),
        ':ip'     => $ipToStore,
        ':is_bot' => $isBot ? 1 : 0,
        ':extra'  => $extraJson
    ]);
} catch (Throwable) {
    // swallow — analytics should never break the page
}
