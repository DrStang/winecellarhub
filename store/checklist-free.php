<?php
require_once __DIR__ . '/../db.php'; // only if you need header/footer; otherwise not required
require __DIR__.'/../analytics_track.php'; // <-- add this

$pdf = '/var/www/html/wine/assets/pdfs/Wine_Cellar_Setup_Checklist.pdf'; // put the file here
if (!is_file($pdf)) { http_response_code(404); exit('File not found'); }
?>
<!doctype html>
<html lang="en ">
<head><title>Wine Cellar Setup Checklist (Free)</title>
    <meta name="description" content="Keep organized while setting up your cellar. Free download.">
    <?php require_once __DIR__ . '/../head.php'; ?>
    <?php require_once __DIR__ . '/../partials/header.php'; ?>
</head>
<body class="max-w-6xl mx-auto p-6 prose">
<header class="mb-8">
<h1 class="text-3xl font-bold">Wine Cellar Setup Checklist</h1>
<p>Keep organized while you start building your cellar</p>
</header>
<main class="mx-auto max-w-6xl px-4 py-6">
<ul class="list-disc p1-6">
    <li>Printable checklist</li>
    <li>Keeps you organized when you start building your mini-empire of wine</li>
    <li>PDF instant download</li>
</ul>

<a class="inline-block px-4 py-2 rounded bg-indigo-600 text-white no-underline"
   href="/store/checklist-free-download.php">Download PDF</a>
</main>
<!--<p class="text-sm mt-4">Limited time: this will become a paid download soon. Get it while it’s free.</p>-->
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
