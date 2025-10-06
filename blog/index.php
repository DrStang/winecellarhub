<?php
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../auth.php';
require __DIR__.'/../analytics_track.php'; // <-- add this

$posts = load_all_posts();
?>
<!doctype html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../head.php'; ?>
    <?php require_once __DIR__ . '/../partials/header.php'; ?>
    <meta charset="utf-8">
    <title><?= SITE_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="WineCellarHub articles on value wines, cellars, and smart collecting.">

</head>
<body class="bg-[var(--surface)] text-[var(--text)]max-w-6xl mx-auto p-8">
<header class="mb-8">
    <h1 class="text-3xl font-bold">WineCellarHub Blog</h1>
    <p class="muted text-[var(--text)]">Practical wine advice with a wink.</p>
</header>
<main class="mx-auto max-w-6xl px-4 py-6">
    <?php foreach ($posts as $p): ?>
        <article class="border rounded-xl p-5">
            <a href="<?= htmlspecialchars($p['url']) ?>" class="no-underline">
                <h2 class="text-2xl font-semibold mb-1"><?= htmlspecialchars($p['title']) ?></h2>
            </a>
            <div class="text-sm text-gray-500 mb-3"><?= date('F j, Y', strtotime($p['date'])) ?></div>
            <?php if (!empty($p['cover'])): ?>
                <img src="<?= htmlspecialchars($p['cover']) ?>" alt="" class="rounded mb-3" loading="lazy">
            <?php endif; ?>
            <p class="mb-3"><?= htmlspecialchars($p['excerpt']) ?></p>
            <a class="text-indigo-600" href="<?= htmlspecialchars($p['url']) ?>">Read more →</a>
        </article>
    <?php endforeach; ?>
    <link rel="alternate" type="application/rss+xml" title="RSS" href="<?= BLOG_BASE ?>/feed.xml.php" />

</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>

</body>
</html>
