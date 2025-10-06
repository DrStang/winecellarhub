<?php
require_once __DIR__ . '/blog/_lib.php';
header('Content-Type: application/xml; charset=utf-8');

$posts = load_all_posts();
$site  = rtrim(($BASE_URL ?? 'https://winecellarhub.com'), '/');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!--<url>
        <loc><?= htmlspecialchars($site . BLOG_BASE . '/') ?></loc>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url> -->
    <url><loc><?= htmlspecialchars($BASE_URL) ?>/features.php</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>

    <!-- <?php foreach ($posts as $p): ?>
        <url>
            <loc><?= htmlspecialchars($site . $p['url']) ?></loc>
            <lastmod><?= date('Y-m-d', strtotime($p['date'])) ?></lastmod>
            <changefreq>monthly</changefreq>
            <priority>0.6</priority>
        </url> -->
    <?php endforeach; ?>
</urlset>

