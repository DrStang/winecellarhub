<?php
function analytics_log_event(PDO $pdo, string $event, array $extra = []): void {
    $aid = $_COOKIE['aid'] ?? null;
    $sid = $_COOKIE['sid'] ?? null;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $page= parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $ref = $_SERVER['HTTP_REFERER'] ?? null;

    // drop null/empty extras
    $filtered = array_filter($extra, fn($v) => !(is_null($v) || $v === ''), ARRAY_FILTER_USE_BOTH);
    $extraJson = $filtered ? json_encode($filtered, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $pdo->prepare("
        INSERT INTO analytics_events (ts, aid, sid, user_id, page, referrer, ua, ip, is_bot, event, extra)
        VALUES (NOW(), :aid, :sid, :uid, :page, :ref, :ua, INET6_ATON(:ip), 0, :event, :extra)
    ");
    $stmt->execute([
        ':aid'   => $aid,
        ':sid'   => $sid,
        ':uid'   => $_SESSION['user_id'] ?? null,
        ':page'  => $page,
        ':ref'   => $ref,
        ':ua'    => mb_substr($ua ?? '', 0, 250),
        ':ip'    => $ip,
        ':event' => $event,
        ':extra' => $extraJson
    ]);
}
