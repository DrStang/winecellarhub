<?php
require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';
$st = $wine_pdo->prepare("SELECT * FROM digital_products WHERE slug='tasting-journal' AND is_active=1");
$st->execute();
$product = $st->fetch(PDO::FETCH_ASSOC);
if (!$product) { http_response_code(404); exit('Product unavailable'); }
$price = number_format($product['price_cents']/100, 2);
?>
<!doctype html>
<html>
<head><title><?=htmlspecialchars($product['name'])?></title></head>
<body class="prose max-w-2xl mx-auto p-6">
<h1><?=htmlspecialchars($product['name'])?></h1>
<p>Track, rate, and remember every bottle you enjoy. (Yes, even that “mystery red”.)</p>
<ul>
    <li>Printable pages (aroma, taste, finish, food pairing)</li>
    <li>Glossary with sarcasm baked in</li>
    <li>Instant download</li>
</ul>
<form method="POST" action="/wine/api/create_checkout.php">
    <input type="hidden" name="item_type" value="product">
    <input type="hidden" name="item_slug" value="tasting-journal">
    <button class="btn">Buy Now – $<?=$price?></button>
</form>
</body>
</html>
