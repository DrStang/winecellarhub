<?php
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../auth.php';

// Get slug safely (from ?slug= or pretty URL)
$slug = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
if ($slug === '' && isset($_SERVER['REQUEST_URI'])) {
    $slug = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
}
$slug = preg_replace('~[^a-z0-9\-]+~i', '-', $slug);   // normalize
$slug = trim($slug, '-');

if ($slug === '') {
    http_response_code(404);
    echo "Post not found";
    exit;
}

// Load posts and find a match
$posts = load_all_posts();
$match = null;
foreach ($posts as $p) {
    if (isset($p['slug']) && $p['slug'] === $slug) { $match = $p; break; }
}
if (!$match) {
    http_response_code(404);
    echo "Post not found";
    exit;
}

// Meta
$title = $match['title'] ?? 'Post';
$desc  = $match['description'] ?: ($match['excerpt'] ?? '');
$cover = $match['cover'] ?? '';
$site  = rtrim(($BASE_URL ?? 'https://winecellarhub.com'), '/');
$url   = $site . ($match['url'] ?? (BLOG_BASE . '/' . $slug));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title) ?> · <?= SITE_NAME ?></title>
    <?php require_once __DIR__ . '/../head.php'; ?>
    <?php require_once __DIR__ . '/../partials/header.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <?php if ($cover): ?><meta property="og:image" content="<?= htmlspecialchars($cover) ?>"><?php endif; ?>
    <meta property="og:url" content="<?= htmlspecialchars($url) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <style>
        .prose img{max-width:100%;border-radius:12px;margin:12px 0;}
        .prose h1,.prose h2,.prose h3{margin-top:1.2em}
        .prose p{line-height:1.7;margin:0.6em 0}
        .prose ul{padding-left:1.2em;margin:0.6em 0}
        .muted{color:#6b7280}
    </style>
</head>
<body class="max-w-6xl mx-auto p-6">
<a href="<?= BLOG_BASE ?>/" class="muted">← Back to Blog</a>
<article class="prose">
    <header class="mb-3">
        <h1 class="text-3xl font-bold"><?= htmlspecialchars($title) ?></h1>
        <div class="muted"><?= date('F j, Y', strtotime($match['date'])) ?></div>
        <?php if ($cover): ?><img src="<?= htmlspecialchars($cover) ?>" alt=""><?php endif; ?>
    </header>

    <!-- Top CTA -->
    <aside class="my-6 p-4 rounded-xl border">
        <h3 class="font-semibold mb-1">Free goodies for wine nerds 🍷</h3>
        <p class="mb-2">Grab the <a class="underline" href="/store/journal-free">Wine Tasting Journal (PDF)</a> or the <a class="underline" href="/store/checklist-free">Wine Cellar Setup Checklist (PDF)</a> and try the <a class="underline" href="/wine/services/pairing-free">Wine Pairing Concierge</a></p>
    </aside>

    <?= $match['html'] ?>

    <!-- Bottom CTA -->
    <aside class="my-8 p-4 rounded-xl border">
        <h3 class="font-semibold mb-1">Want more?</h3>
        <ul class="list-disc ml-5">
            <li><a class="underline" href="/store/journal-free">Download the Wine Tasting Journal </a></li>
            <li><a class="underline" href="/store/checklist-free">Download the Wine Cellar Setup Checklist</a></li>
            <li><a class="underline" href="/services/pairing-free">Get personalized pairings </a></li>
        </ul>
    </aside>
</article>
</body>
<?php require __DIR__ . '/partials/footer.php'; ?>

</html>
