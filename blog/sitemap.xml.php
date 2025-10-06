<?php
// sitemap.php -> outputs XML
header('Content-Type: application/xml; charset=utf-8');
$base = (($_SERVER['HTTPS']??'off')==='on'?'https://':'http://').$_SERVER['HTTP_HOST'];


$urls = [];
$urls[] = ['loc'=>"$base/features.php", 'changefreq'=>'weekly', 'priority'=>'0.9'];

$stmt = $winelist_pdo->query("SELECT token, GREATEST(created_at, IFNULL(expires_at, created_at)) AS lastmod
                     FROM public_shares
                     WHERE status='active' AND (expires_at IS NULL OR expires_at>NOW()) AND is_indexable=1
                     ORDER BY id DESC LIMIT 5000");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $urls[] = ['loc'=>"$base/share_wine.php?t=".$r['token'], 'changefreq'=>'weekly', 'priority'=>'0.6', 'lastmod'=>substr($r['lastmod'],0,10)];
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <?php foreach ($urls as $u): ?>
        <url>
            <loc><?= htmlspecialchars($u['loc'], ENT_XML1) ?></loc>
            <?php if (!empty($u['lastmod'])): ?><lastmod><?= htmlspecialchars($u['lastmod'], ENT_XML1) ?></lastmod><?php endif; ?>
            <?php if (!empty($u['changefreq'])): ?><changefreq><?= $u['changefreq'] ?></changefreq><?php endif; ?>
            <?php if (!empty($u['priority'])): ?><priority><?= $u['priority'] ?></priority><?php endif; ?>
        </url>
    <?php endforeach; ?>
</urlset>
