<?php
// composer require stripe/stripe-php
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../auth.php';
\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET'));

$item_type = $_POST['item_type'] ?? '';
$item_slug = $_POST['item_slug'] ?? '';
$email = $current_user['email'] ?? ($_POST['email'] ?? null);
if (!$email) { http_response_code(400); exit('Email required'); }

if ($item_type==='product') {
    $st = $wine_pdo->prepare("SELECT name, price_cents FROM digital_products WHERE slug=? AND is_active=1");
    $st->execute([$item_slug]);
    $prod = $st->fetch(PDO::FETCH_ASSOC);
    if (!$prod) { http_response_code(404); exit('Product not found'); }

    // Create pending purchase
    $ins = $wine_pdo->prepare("INSERT INTO purchases (user_id,email,item_type,item_slug,price_cents,status) VALUES (?,?,?,?,?,'pending')");
    $ins->execute([$current_user['id'] ?? null, $email, 'product', $item_slug, $prod['price_cents']]);
    $purchase_id = $wine_pdo->lastInsertId();
// gather service intake fields for metadata (if service)
    $meta = ['item_type'=>$item_type,'item_slug'=>$item_slug];
    if ($item_type==='service') {
        $meta['dish'] = substr($_POST['dish'] ?? '', 0, 2000);
        $meta['budget'] = (int)(($_POST['budget'] ?? 0) * 100);
        $meta['regions'] = substr($_POST['regions'] ?? '', 0, 255);
        $meta['dietary'] = substr($_POST['dietary'] ?? '', 0, 255);
        $meta['guests'] = (int)($_POST['guests'] ?? 2);
    }


    $session = \Stripe\Checkout\Session::create([
        'mode' => 'payment',
        'customer_email' => $email,
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => $prod['name']],
                'unit_amount' => (int)$prod['price_cents'],
            ],
            'quantity' => 1,
        ]],
        'success_url' => getenv('BASE_URL')."/store/success?pid={$purchase_id}",
        'cancel_url'  => getenv('BASE_URL')."/store/journal?canceled=1",
       // 'metadata'    => ['purchase_id' => $purchase_id, 'item_slug' => $item_slug, 'item_type' => 'product']
    'metadata' => array_merge(['purchase_id'=>$purchase_id], $meta)

    ]);
    header('Location: '.$session->url);
    exit;
}
http_response_code(400); echo 'Unsupported item';
