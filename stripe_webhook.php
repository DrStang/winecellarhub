<?php
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../mailer.php'; // PHPMailer setup you already use
\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET'));

$payload = @file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = getenv('STRIPE_WEBHOOK_SECRET');
try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig, $endpoint_secret);
} catch(\Exception $e) {
    http_response_code(400); exit('Invalid');
}

if ($event->type === 'checkout.session.completed') {
    $sess = $event->data->object;
    $purchase_id = $sess->metadata->purchase_id ?? null;

    // Mark paid + generate download token for products
    $st = $wine_pdo->prepare("SELECT * FROM purchases WHERE id=?");
    $st->execute([$purchase_id]);
    if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
        $token = bin2hex(random_bytes(16));
        $upd = $wine_pdo->prepare("UPDATE purchases SET status='paid', stripe_payment_intent=?, download_token=? WHERE id=?");
        $upd->execute([$sess->payment_intent, ($p['item_type']==='product') ? $token : null, $p['id']]);
        if ($p['item_type']==='service') {
            $tier = ($p['item_slug']==='pairing-premium') ? 'premium' : 'starter';
            $dish = $sess->metadata->dish ?? '';
            $budget_cents = (int)($sess->metadata->budget ?? 0);
            $regions = $sess->metadata->regions ?? null;
            $diet = $sess->metadata->dietary ?? null;
            $guests = (int)($sess->metadata->guests ?? 2);

            $ins2 = $wine_pdo->prepare("INSERT INTO pairing_requests
    (purchase_id,user_id,email,dish,budget_cents,preferred_regions,dietary_notes,guest_count,tier,status)
    VALUES (?,?,?,?,?,?,?,?,?,'queued')");
            $ins2->execute([$p['id'],$p['user_id'],$p['email'],$dish,$budget_cents,$regions,$diet,$guests,$tier]);

            // Acknowledge with “we’re on it” email
            sendMail($p['email'],
                "We’re pairing your menu 🍷",
                "Thanks! Your menu is in the queue. You’ll get curated wines with tasting notes soon.");
        }

        if ($p['item_type']==='product') {
            // Email download link
            $downloadUrl = getenv('BASE_URL')."/downloads/{$p['item_slug']}?token=".$token;
            sendMail($p['email'],
                "Your download: ".$p['item_slug'],
                "Thanks! Your download link: <a href=\"$downloadUrl\">$downloadUrl</a><br>(It’s yours forever; don’t share with party crashers.)");
        }
    }
}
http_response_code(200);
