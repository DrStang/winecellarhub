<?php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../db.php';                  // must provide $wine_pdo (Wine) and $winelist_pdo (Winelist)
require_once __DIR__ . '/../mailer/send_email.php';

// --- compatibility shim if your db.php exposes only $pdo for the Wine DB
if (!isset($wine_pdo) && isset($pdo)) { $wine_pdo = $pdo; }

const MAX_PICKS = 5;

// Optional alternative: uncomment this line instead of inlining LIMITs below
// $wine_pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

// Load recipients (Wine DB)
$users = $wine_pdo->query("
  SELECT u.id, u.email, COALESCE(ep.weekly_digest,1) AS enabled,
         COALESCE(u.username, 'there') AS name
  FROM users u
  LEFT JOIN email_prefs ep ON ep.user_id = u.id
  WHERE u.email IS NOT NULL AND u.email <> ''
")->fetchAll(PDO::FETCH_ASSOC);

// Email template
$tpl = file_get_contents(__DIR__ . '/../mailer/templates/weekly_digest.html');

function html_list(array $items): string {
    return implode('', array_map(fn($t) => "<li>{$t}</li>", $items));
}

function hydrate_wines_by_ids(PDO $winelist, array $ids): array {
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $winelist->prepare("
      SELECT id AS wine_id, name, vintage, region, winery, country, image_url
      FROM wines
      WHERE id IN ($in)
    ");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['wine_id']] = $row;
    }
    return $out;
}

foreach ($users as $u) {
    if (!(int)$u['enabled']) continue;

    $uid   = (int)$u['id'];
    $email = $u['email'];
    $name  = $u['name'];

    // 1) Personalized recos (Wine DB -> ids), then hydrate from Winelist DB
    $recSql = "
        SELECT ur.wine_id, ur.score
        FROM user_recos ur
        WHERE ur.user_id = ?
        ORDER BY ur.score DESC
        LIMIT " . (int)MAX_PICKS;   // <-- inline validated integer
    $recSt = $wine_pdo->prepare($recSql);
    $recSt->execute([$uid]);
    $recRows = $recSt->fetchAll(PDO::FETCH_ASSOC);

    $recoList = [];
    if ($recRows) {
        $ids   = array_map(fn($r)=>(int)$r['wine_id'], $recRows);
        $byId  = hydrate_wines_by_ids($winelist_pdo, $ids);

        foreach ($recRows as $r) {
            $wid = (int)$r['wine_id'];
            if (!empty($byId[$wid])) {
                $w = $byId[$wid];
                $title = trim(($w['name'] ?? '') . (empty($w['vintage']) ? '' : ' ' . $w['vintage']));
                $region = $w['region'] ?? '';
                $recoList[] = htmlspecialchars($title . (strlen($region)? " ({$region})" : ''));
            }
        }
    }

    // 2) Fallback: recently added to *this user’s* cellar (Wine DB), then hydrate by wine_id via Winelist DB
    if (count($recoList) < MAX_PICKS) {
        $need = MAX_PICKS - count($recoList);

        $fbSql = "
            SELECT id, wine_id, name, vintage, region
            FROM bottles
            WHERE user_id = ?
            ORDER BY COALESCE(added_at, created_at) DESC
            LIMIT " . (int)$need;   // <-- inline validated integer
        $fbSt = $wine_pdo->prepare($fbSql);
        $fbSt->execute([$uid]);
        $bottles = $fbSt->fetchAll(PDO::FETCH_ASSOC);

        // Hydrate from Winelist if wine_id present
        $ids = array_values(array_filter(array_map(fn($b)=> (int)($b['wine_id'] ?? 0), $bottles)));
        $byId = $ids ? hydrate_wines_by_ids($winelist_pdo, $ids) : [];

        foreach ($bottles as $b) {
            $row = null;
            if (!empty($b['wine_id']) && !empty($byId[(int)$b['wine_id']])) {
                $w = $byId[(int)$b['wine_id']];
                $title  = trim(($w['name'] ?? '') . (empty($w['vintage']) ? '' : ' ' . $w['vintage']));
                $region = $w['region'] ?? '';
                $row = $title . (strlen($region)? " ({$region})" : '');
            } else {
                // Fallback to bottle’s own fields if no catalog row
                $title  = trim(($b['name'] ?? '') . (empty($b['vintage']) ? '' : ' ' . $b['vintage']));
                $region = $b['region'] ?? '';
                if ($title !== '') {
                    $row = $title . (strlen($region)? " ({$region})" : '');
                }
            }
            if ($row) $recoList[] = htmlspecialchars($row);
            if (count($recoList) >= MAX_PICKS) break;
        }
    }

    // 3) “Drink soon” counter (Wine DB)
    $near = $wine_pdo->prepare("
        SELECT COUNT(*)
        FROM bottles
        WHERE user_id = ?
          AND drink_from IS NOT NULL
          AND DATEDIFF(drink_from, CURDATE()) BETWEEN 0 AND 60
    ");
    $near->execute([$uid]);
    $near_count = (int)($near->fetchColumn() ?: 0);

    // 4) Render + send
    $html = str_replace(
        ['{{NAME}}','{{RECOS}}','{{NEAR_COUNT}}'],
        [htmlspecialchars($name), html_list($recoList), $near_count],
        $tpl
    );

    $ok = send_email($email, "🍷 Your Weekly Wine Picks & Cellar Alerts", $html, true);

    // 5) Log (Wine DB)
    $log = $wine_pdo->prepare("
        INSERT INTO email_log(user_id, template, subject, sent_at, status, err)
        VALUES(?,?,?,?,?,?)
    ");
    $log->execute([
        $uid,
        'weekly_digest',
        'Weekly Picks',
        date('Y-m-d H:i:s'),
        $ok ? 'sent' : 'failed',
        $ok ? null : 'send failed'
    ]);
}
