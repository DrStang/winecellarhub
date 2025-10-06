<?php
// analytics.php
declare(strict_types=1);
@ini_set('display_errors','0');

require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// ---- Admin gate
$isAdmin = !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
if (!$isAdmin && !empty($_SESSION['user_id'])) {
    $st = $pdo->prepare("SELECT is_admin FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => (int)$_SESSION['user_id']]);
    $isAdmin = (int)($st->fetchColumn() ?: 0) === 1;
    if ($isAdmin) $_SESSION['is_admin'] = 1;
}
if (!$isAdmin) { http_response_code(403); exit('Forbidden'); }

// ---- Inputs
$days = isset($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 30;
$where = " ts >= DATE_SUB(CURDATE(), INTERVAL :days DAY) AND is_bot = 0 ";
$bindDays = [':days' => $days];

// ---- Helpers
function fetchVal(PDO $pdo, string $sql, array $bind): int {
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return (int)$st->fetchColumn();
}
function namedIn(array $values, string $prefix='p'): array {
    $placeholders = [];
    $bind = [];
    foreach (array_values($values) as $i => $v) {
        $k = ":{$prefix}{$i}";
        $placeholders[] = $k;
        $bind[$k] = $v;
    }
    return [implode(',', $placeholders), $bind];
}
function pct(float $num, float $den): string {
    if ($den <= 0) return '0%';
    return rtrim(rtrim(number_format(($num/$den)*100, 1), '0'), '.') . '%';
}

// ---- KPIs
$sessions = fetchVal($pdo, "SELECT COUNT(DISTINCT sid) FROM analytics_events WHERE $where", $bindDays);
$visitors = fetchVal($pdo, "SELECT COUNT(DISTINCT aid) FROM analytics_events WHERE $where", $bindDays);

$landingPages = ['/', '/index.php', '/home.php'];
list($lpIn, $lpBind) = namedIn($landingPages, 'lp');

$st = $pdo->prepare("SELECT COUNT(*) FROM analytics_events WHERE $where AND page IN ($lpIn)");
$st->execute(array_merge($bindDays, $lpBind));
$landingViews = (int)$st->fetchColumn();

$signups = fetchVal($pdo, "SELECT COUNT(*) FROM analytics_events WHERE $where AND event='signup'", $bindDays);

// ---- Funnel (same session, relative to first landing hit)
// Step 1: Landing
$step1 = $landingViews;

// Step 2: Signup within 24h of a landing hit
$funnelSignupSql = "
SELECT COUNT(DISTINCT pv.sid)
FROM analytics_events pv
JOIN analytics_events su
  ON su.sid = pv.sid
 AND su.event = 'signup'
 AND su.ts BETWEEN pv.ts AND pv.ts + INTERVAL 1 DAY
WHERE pv.is_bot=0
  AND pv.page IN ($lpIn)
  AND pv.ts >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
";
$st = $pdo->prepare($funnelSignupSql);
$st->execute(array_merge($bindDays, $lpBind));
$step2 = (int)$st->fetchColumn();

// Step 3: Login within 24h of a landing hit
$funnelLoginSql = "
SELECT COUNT(DISTINCT pv.sid)
FROM analytics_events pv
JOIN analytics_events lg
  ON lg.sid = pv.sid
 AND lg.event = 'login'
 AND lg.ts BETWEEN pv.ts AND pv.ts + INTERVAL 1 DAY
WHERE pv.is_bot=0
  AND pv.page IN ($lpIn)
  AND pv.ts >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
";
$st = $pdo->prepare($funnelLoginSql);
$st->execute(array_merge($bindDays, $lpBind));
$step3 = (int)$st->fetchColumn();

// Step 4: Add bottle within 7 days of a landing hit
$funnelAddSql = "
SELECT COUNT(DISTINCT pv.sid)
FROM analytics_events pv
JOIN analytics_events ab
  ON ab.sid = pv.sid
 AND ab.event = 'add_bottle'
 AND ab.ts BETWEEN pv.ts AND pv.ts + INTERVAL 7 DAY
WHERE pv.is_bot=0
  AND pv.page IN ($lpIn)
  AND pv.ts >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
";
$st = $pdo->prepare($funnelAddSql);
$st->execute(array_merge($bindDays, $lpBind));
$step4 = (int)$st->fetchColumn();

$convLandingToSignup = $step1 > 0 ? round(($step2 / $step1) * 100, 1) : 0.0;

// ---- Trend (14d)
$trendSql = "
SELECT DATE(ts) d, COUNT(*) c
FROM analytics_events
WHERE $where AND event='pageview'
GROUP BY DATE(ts)
ORDER BY d ASC
LIMIT 14
";
$st = $pdo->prepare($trendSql);
$st->execute($bindDays);
$trend = $st->fetchAll(PDO::FETCH_ASSOC);
$maxC = $trend ? max(array_map(fn($r)=>(int)$r['c'], $trend)) : 0;

// ---- Top pages
$st = $pdo->prepare("
SELECT page, COUNT(*) c
FROM analytics_events
WHERE $where
GROUP BY page
ORDER BY c DESC
LIMIT 10
");
$st->execute($bindDays);
$topPages = $st->fetchAll(PDO::FETCH_ASSOC);

// ---- Top referrers (exclude self)
$host = $_SERVER['HTTP_HOST'] ?? '';
$st = $pdo->prepare("
SELECT referrer, COUNT(*) c
FROM analytics_events
WHERE $where
  AND COALESCE(NULLIF(referrer,''),'') <> ''
  AND referrer NOT LIKE CONCAT('%', :host, '%')
GROUP BY referrer
ORDER BY c DESC
LIMIT 10
");
$st->execute(array_merge($bindDays, [':host'=>$host]));
$topRefs = $st->fetchAll(PDO::FETCH_ASSOC);

// ---- Country breakdown (requires extra.cc)
$ccSql = "
SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(extra,'$.cc')),''), 'UNK') AS cc,
       COUNT(*) c
FROM analytics_events
WHERE $where AND event='pageview'
GROUP BY cc
ORDER BY c DESC
LIMIT 12
";
$st = $pdo->prepare($ccSql);
$st->execute($bindDays);
$countries = $st->fetchAll(PDO::FETCH_ASSOC);

// Helper for flag emoji
function flagEmoji(string $cc): string {
    $cc = strtoupper($cc);
    if (!preg_match('/^[A-Z]{2}$/', $cc)) return '🏳️';
    $a = 127397 + ord($cc[0]);
    $b = 127397 + ord($cc[1]);
    return '&#'.$a.';&#'.$b.';';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Analytics</title>
    <style>
        :root{--surface:#fff;--text:#1b2030;--muted:#6b7280}
        body{margin:0;font-family:ui-sans-serif,system-ui,Segoe UI,Roboto,Helvetica,Arial}
        .wrap{max-width:1100px;margin:0 auto;padding:24px}
        .kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        @media(min-width:900px){.kpis{grid-template-columns:repeat(5,minmax(0,1fr));}}
        .card{background:var(--surface);border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .title{font-size:14px;color:var(--muted)} .val{font-size:24px;font-weight:600;margin-top:4px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .row{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9}
        .row:last-child{border-bottom:0}
        .bar{display:inline-block;height:10px;background:#e5e7eb;border-radius:999px;vertical-align:middle}
        .bar-fill{display:inline-block;height:10px;background:black;border-radius:999px}
        .tag{display:inline-flex;align-items:center;font-size:12px;color:#334155;background:#f1f5f9;border-radius:999px;padding:2px 8px}
        .funnel{display:grid;gap:12px}
        .funnel-step{display:flex;align-items:center;justify-content:space-between}
        .funnel-label{font-size:14px;color:#334155;margin-right:8px;min-width:140px}
    </style>
</head>
<body>
<div class="wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h1 style="font-size:24px;margin:0">Analytics (last <?=htmlspecialchars((string)$days)?> days)</h1>
        <div><a class="tag" href="?days=7">7d</a><a class="tag" href="?days=30" style="margin-left:8px">30d</a><a class="tag" href="?days=90" style="margin-left:8px">90d</a></div>
    </div>

    <div class="kpis">
        <div class="card"><div class="title">Sessions</div><div class="val"><?=number_format($sessions)?></div></div>
        <div class="card"><div class="title">Visitors</div><div class="val"><?=number_format($visitors)?></div></div>
        <div class="card"><div class="title">Landing Views</div><div class="val"><?=number_format($landingViews)?></div></div>
        <div class="card"><div class="title">Signups</div><div class="val"><?=number_format($signups)?></div></div>
        <div class="card"><div class="title">Landing → Signup</div><div class="val"><?= $step1 ? pct($step2,$step1) : '0%' ?></div></div>
    </div>

    <!-- SVG FUNNEL -->
    <div class="card" style="margin-top:16px">
        <div class="title">Funnel (Landing → Signup → Login → Add Bottle)</div>
        <?php
        $base = max(1, $step1);
        $w = 720; $h = 160; $maxBar = 620; $y=18; $bh=26; $gap=18;
        $steps = [
            ['label'=>'Landing','val'=>$step1],
            ['label'=>'Signup','val'=>$step2],
            ['label'=>'Login','val'=>$step3],
            ['label'=>'Add Bottle','val'=>$step4],
        ];
        ?>
        <svg viewBox="0 0 <?=$w?> <?=$h?>" width="100%" height="auto" aria-label="Funnel chart">
            <?php foreach ($steps as $i=>$s):
                $val = max(0, (int)$s['val']);
                $width = (int)max(2, round(($val/$base) * $maxBar));
                $x = 40 + (int)((($maxBar - $width) / 2)); // center bars
                $yy = $y + ($i * ($bh + $gap));
                // Step conversion vs previous
                $prev = $i===0 ? $val : max(1, (int)$steps[$i-1]['val']);
                $stepPct = $prev>0 ? round(($val/$prev)*100,1) : 0.0;
                ?>
                <rect x="<?=$x?>" y="<?=$yy?>" width="<?=$width?>" height="<?=$bh?>" rx="8" ry="8" fill="black" opacity="0.08"></rect>
                <text x="24" y="<?=$yy+$bh-6?>" font-size="13" fill="#334155"><?=$s['label']?></text>
                <text x="<?=$x+$width+8?>" y="<?=$yy+$bh-6?>" font-size="13" fill="#111827">
                    <?=number_format($val)?> (<?=$stepPct?>)
                </text>
            <?php endforeach; ?>
        </svg>
        <div style="color:#334155;font-size:12px;margin-top:6px">
            Signup/Login windows: 24h from first landing in the session; Add Bottle: 7 days.
        </div>
    </div>

    <div class="grid" style="margin-top:16px">
        <div class="card">
            <div class="title">Daily pageviews (last 14d)</div>
            <div class="mono" style="margin-top:8px">
                <?php foreach ($trend as $t):
                    $c=(int)$t['c']; $wBar = $maxC? max(2,(int)round($c/$maxC*220)) : 2; ?>
                    <div class="row">
                        <div><?=htmlspecialchars($t['d'])?></div>
                        <div><span class="bar" style="width:220px"><span class="bar-fill" style="width:<?=$wBar?>px"></span></span>
                            <span style="margin-left:8px"><?=number_format($c)?></span></div>
                    </div>
                <?php endforeach; if (!$trend): ?>
                    <div class="row"><div>—</div><div>no data</div></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="title">Top referrers</div>
            <div class="mono" style="margin-top:8px">
                <?php foreach ($topRefs as $r): ?>
                    <div class="row">
                        <div style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($r['referrer'])?></div>
                        <div><?=number_format((int)$r['c'])?></div>
                    </div>
                <?php endforeach; if (!$topRefs): ?>
                    <div class="row"><div>—</div><div>no data</div></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="title">Top pages</div>
            <div class="mono" style="margin-top:8px">
                <?php foreach ($topPages as $p): ?>
                    <div class="row">
                        <div style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($p['page'] ?? '—')?></div>
                        <div><?=number_format((int)$p['c'])?></div>
                    </div>
                <?php endforeach; if (!$topPages): ?>
                    <div class="row"><div>—</div><div>no data</div></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="title">Countries (top 12)</div>
            <div class="mono" style="margin-top:8px">
                <?php
                $totalPv = array_sum(array_map(fn($r)=>(int)$r['c'],$countries));
                if ($totalPv === 0): ?>
                    <div class="row"><div>—</div><div>no data</div></div>
                <?php else:
                    foreach ($countries as $c):
                        $cc = strtoupper($c['cc'] ?? 'UNK'); $cnt = (int)$c['c']; $p = pct($cnt, $totalPv);
                        ?>
                        <div class="row">
                            <div style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <span><?=flagEmoji($cc)?></span>
                                <span style="margin-left:6px"><?=htmlspecialchars($cc)?></span>
                            </div>
                            <div><?=number_format($cnt)?> (<?=$p?>)</div>
                        </div>
                    <?php endforeach; endif; ?>
            </div>
            <div style="color:#334155;font-size:12px;margin-top:6px">Based on <code>extra.cc</code> in pageviews.</div>
        </div>
    </div>
</div>
</body>
</html>
