<?php
// /api/create_share.php
declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php'; // should populate $_SESSION['user_id']

header('Content-Type: application/json; charset=utf-8');

function base64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function generate_share_token(int $bytes = 24): string {
    return base64url_encode(random_bytes($bytes));
}

try {
    // Ensure exceptions so we can catch errors cleanly

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'auth']);
        exit;
    }

    // Inputs
    $wineId      = (int)($_POST['wine_id'] ?? 0);
    $title       = trim((string)($_POST['title'] ?? ''));
    $excerpt     = trim((string)($_POST['excerpt'] ?? ''));
    $isIndexable = isset($_POST['is_indexable']) ? (int)((bool)$_POST['is_indexable']) : 1;
    $expiresAt   = trim((string)($_POST['expires_at'] ?? ''));

    if ($wineId <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'wine_id required']);
        exit;
    }

    // Verify wine exists (optional but recommended)
    $chk = $winelist_pdo->prepare("SELECT id FROM winelist.wines WHERE id=:id LIMIT 1");
    $chk->execute([':id'=>$wineId]);
    if (!$chk->fetch()) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid wine_id']);
        exit;
    }

    // --- Simple, robust rate limit (fixed 1h window), transactional & lock-safe ---
    $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $limit       = 5;        // max creates per hour per user+ip
    $windowSecs  = 3600;     // 1 hour
    $now         = time();
    $windowStart = $now - ($now % $windowSecs);
    $windowStartStr = date('Y-m-d H:i:s', $windowStart);
    $keyName     = 'share:uid='.$userId.'|ip='.$ip;

    // Start transaction ONCE; make sure we only roll back if it's really open.
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    // Lock current rate row
    $rs = $pdo->prepare("SELECT id, count FROM rate_limiter WHERE key_name=:k AND window_start=:ws FOR UPDATE");
    $rs->execute([':k'=>$keyName, ':ws'=>$windowStartStr]);
    $row = $rs->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if ((int)$row['count'] >= $limit) {
            // We didn't change anything; commit to release lock and return 429 (no rollback)
            if ($pdo->inTransaction()) $pdo->commit();
            http_response_code(429);
            echo json_encode([
                'ok'=>false,
                'error'=>'rate_limited',
                'retry_after'=> ($windowStart + $windowSecs - $now)
            ]);
            exit;
        }
        $upd = $pdo->prepare("UPDATE rate_limiter SET count = count + 1 WHERE id = :id");
        $upd->execute([':id'=>$row['id']]);
    } else {
        $ins = $pdo->prepare("INSERT INTO rate_limiter (key_name, window_start, count) VALUES (:k, :ws, 1)");
        $ins->execute([':k'=>$keyName, ':ws'=>$windowStartStr]);
    }

    // Create the share
    $token = generate_share_token();
    $ins2 = $winelist_pdo->prepare("
    INSERT INTO public_shares
      (token, wine_id, user_id, title, excerpt, is_indexable, expires_at)
    VALUES
      (:t, :w, :u, NULLIF(:ti,''), NULLIF(:ex,''), :ix, NULLIF(:exp,''))
  ");
    $ins2->execute([
        ':t'=>$token,
        ':w'=>$wineId,
        ':u'=>$userId,
        ':ti'=>$title,
        ':ex'=>$excerpt,
        ':ix'=>$isIndexable,
        ':exp'=>$expiresAt
    ]);

    // Set dynamic OG image to our composer
    $baseUrl = (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $ogUrl   = $baseUrl . '/features_og.php?t=' . rawurlencode($token);

    $upOg = $winelist_pdo->prepare("UPDATE public_shares SET og_image_url = :og WHERE token = :t");
    $upOg->execute([':og'=>$ogUrl, ':t'=>$token]);

    // Commit the whole unit of work
    if ($pdo->inTransaction()) $pdo->commit();

    echo json_encode([
        'ok'        => true,
        'share_url' => $baseUrl . '/share_wine.php?t=' . rawurlencode($token),
        'og_image'  => $ogUrl
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    // Roll back ONLY if a transaction is open
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()]);
}
