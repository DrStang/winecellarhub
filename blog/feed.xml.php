<?php
require_once __DIR__ . '/_lib.php';
header('Content-Type: application/rss+xml; charset=utf-8');

$posts = load_all_posts();
$site  = rtrim(($BASE_URL ?? 'https://winecellarhub.com'), '/');
$blog  = $site . BLOG_BASE . '/';

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<rss version="2.0">
    <channel>
        <title><?= htmlspecialchars(SITE_NAME) ?></title>
        <link><?= htmlspecialchars($blog) ?></link>
        <description>WineCellarHub articles on value wines, cellars, and smart collecting.</description>
        <language>en-us</language>
        <lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
        <?php foreach ($posts as $p):
            $link = $site . $p['url']; ?>
            <item>
                <title><?= htmlspecialchars($p['title']) ?></title>
                <link><?= htmlspecialchars($link) ?></link>
                <guid isPermaLink="true"><?= htmlspecialchars($link) ?></guid>
                <pubDate><?= date(DATE_RSS, strtotime($p['date'])) ?></pubDate>
                <description><![CDATA[<?= $p['excerpt'] ?>]]></description>
            </item>
        <?php endforeach; ?>
    </channel>
</rss>
