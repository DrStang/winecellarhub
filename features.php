<?php
// /features.php — public, indexable marketing page
// Adjust $SITE_NAME and $BASE_URL if needed.
$SITE_NAME = 'WineCellarHub';
$BASE_URL  = (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$selfUrl   = $BASE_URL . '/features.php';

$title = "Features & Benefits • $SITE_NAME";
$desc  = "Organize your cellar, get AI insights, track value, and share curated picks. $SITE_NAME is the modern wine inventory and recommendation platform for collectors and enthusiasts.";
$ogImg = $BASE_URL . '/features_og.php?title=' . rawurlencode('Modern wine inventory & AI insights')
    . '&subtitle=' . rawurlencode('Track, pair, and drink at the optimal time');?>
<meta property="og:image" content="<?= htmlspecialchars($ogImg) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($ogImg) ?>">


<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($desc) ?>">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="<?= htmlspecialchars($selfUrl) ?>"/>

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($SITE_NAME) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($selfUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImg) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($desc) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImg) ?>">

    <!-- JSON-LD: WebSite + SoftwareApplication + FAQ + Breadcrumbs -->
    <script type="application/ld+json">
        {
          "@context":"https://schema.org",
          "@graph":[
            {
              "@type":"WebSite",
              "name":"<?= addslashes($SITE_NAME) ?>",
        "url":"<?= addslashes($BASE_URL) ?>",
        "potentialAction":{
          "@type":"SearchAction",
          "target":"<?= addslashes($BASE_URL) ?>/search.php?q={query}",
          "query-input":"required name=query"
        }
      },
      {
        "@type":"SoftwareApplication",
        "name":"<?= addslashes($SITE_NAME) ?>",
        "applicationCategory":"BusinessApplication",
        "operatingSystem":"Web",
        "description":"<?= addslashes($desc) ?>",
        "url":"<?= addslashes($BASE_URL) ?>",
        "image":"<?= addslashes($ogImg) ?>",
        "offers": {"@type":"Offer","price":"0","priceCurrency":"USD"}
      },
      {
        "@type":"BreadcrumbList",
        "itemListElement":[
          {"@type":"ListItem","position":1,"name":"Home","item":"<?= addslashes($BASE_URL) ?>"},
          {"@type":"ListItem","position":2,"name":"Features","item":"<?= addslashes($selfUrl) ?>"}
        ]
      },
      {
        "@type":"FAQPage",
        "mainEntity":[
          {
            "@type":"Question",
            "name":"Is my inventory private?",
            "acceptedAnswer":{"@type":"Answer","text":"Yes. Your logged-in cellar is private by default. You may optionally create share links for specific bottles or curated lists that are public and indexable."}
          },
          {
            "@type":"Question",
            "name":"Can I import from spreadsheets or other apps?",
            "acceptedAnswer":{"@type":"Answer","text":"Yes. Use our CSV import to bring in existing collections. We’ll normalize vintages, varietals, and wineries."}
          },
          {
            "@type":"Question",
            "name":"What are AI insights?",
            "acceptedAnswer":{"@type":"Answer","text":"AI insights provide tasting notes, food pairings, and cellaring guidance based on your bottles, regions, and styles."}
          }
        ]
      }
    ]
  }
    </script>

    <style>
        :root{
            --bg:#0b0b0e; --card:#13131a; --text:#f6f6f7; --muted:#a1a1aa; --accent:#8a1538;
            --border:#22222b; --chip:#1b1b23; --ok:#10b981; --warn:#f59e0b; --link:#e5e7eb;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
        a{color:var(--link);text-decoration:none}
        a:hover{text-decoration:underline}
        .wrap{max-width:1080px;margin:0 auto;padding:28px}
        .hero{display:grid;grid-template-columns:1.2fr 1fr;gap:28px;align-items:center}
        .hero h1{font-size:40px;line-height:1.1;margin:0 0 10px}
        .hero p{font-size:18px;color:var(--muted);margin:0 0 18px}
        .cta{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px}
        .btn{appearance:none;border:0;border-radius:12px;padding:13px 16px;font-weight:700;cursor:pointer}
        .btn-primary{background:#e5e7eb;color:#0b0b0e}
        .btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
        .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px}
        .grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:28px}
        .feat h3{margin:0 0 6px;font-size:18px}
        .feat p{margin:0;color:var(--muted);font-size:14px;line-height:1.6}
        .section{margin-top:42px}
        .chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
        .chip{background:var(--chip);border:1px solid var(--border);color:var(--muted);padding:6px 10px;border-radius:999px;font-size:12px}
        .bullets{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:12px}
        .bullets li{list-style:none;background:var(--chip);border:1px solid var(--border);padding:10px;border-radius:10px}
        .faq dt{font-weight:700;margin:10px 0 6px}
        .faq dd{margin:0 0 10px;color:var(--muted)}
        .note{font-size:12px;color:var(--muted);margin-top:20px}
        .heroimg{max-width:100%;height:auto;max-height:400px;aspect-ratio:16/9;background:#0e0e14;border:1px dashed #2a2a35;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#6b7280;overflow:hidden;}
        .heroimg img{max-width:100%;max-height:100%;height:auto;width:auto;object-fit:contain;}
        @media (max-width:500px){.heroimg {max-height:240px;}}{ .hero{grid-template-columns:1fr} .grid3{grid-template-columns:1fr} .bullets{grid-template-columns:1fr} }
    </style>
</head>
<body>
<div class="wrap">
    <!-- HERO -->
    <section class="hero">
        <div>
            <h1>Modern wine inventory & AI insights</h1>
            <p>Track every bottle, surface smart pairings, and know exactly when to drink. Built for collectors and enthusiasts who want sipping, not spreadsheets.</p>
            <div class="chips" aria-label="Quick facts">
                <span class="chip">Private by default</span>
                <span class="chip">Share curated picks publicly</span>
                <span class="chip">Mobile-friendly</span>
            </div>
            <div class="cta">
                <a class="btn btn-primary" href="/register.php">Create your cellar</a>
                <a class="btn btn-outline" href="/login.php">Log in</a>
            </div>
            <!--<div class="note">Indexable page • Linked from robots.txt and included in sitemap.xml</div>-->
        </div>
        <div class="heroimg"><img src="/assets/screens/home.png"></div>
    </section>

    <!-- WHO IT'S FOR -->
    <section class="section card">
        <h2 style="margin:0 0 6px">Who it's for</h2>
        <p class="muted">Whether you’re cellaring First Growths or tracking weeknight favorites, <?= htmlspecialchars($SITE_NAME) ?> streamlines the work and amplifies the fun.</p>
        <ul class="bullets">
            <li>Collectors tracking provenance, drink windows, and value</li>
            <li>Casual drinkers organizing favorites and pairings</li>
            <li>Hosts planning menus by varietal, region, or theme</li>
            <li>Friends sharing recommendations via public links</li>
        </ul>
    </section>

    <!-- FEATURES GRID -->
    <section class="section">
        <h2 style="margin:0 0 10px">Key features</h2>
        <div class="grid3">
            <div class="card feat">
                <h3>Inventory that just works</h3>
                <p>Add by scan or search, and view beautiful bottle cards with labels, regions, and tasting notes.</p>
            </div>
            <div class="card feat">
                <h3>AI insights</h3>
                <p>Tasting notes, food pairings, and cellaring guidance synthesized from your bottles and styles.</p>
            </div>
            <div class="card feat">
                <h3>Drink window & alerts</h3>
                <p>Approaching peak? Get gentle nudges so your best bottles don’t get forgotten.</p>
            </div>
            <div class="card feat">
                <h3>Portfolio view</h3>
                <p>High-level KPIs like total value, regions, varietal diversity, and trend charts.</p>
            </div>
            <div class="card feat">
                <h3>Shareable picks</h3>
                <p>Create public pages for a single bottle or a curated list.</p>
            </div>
            <div class="card feat">
                <h3>Import & export</h3>
                <p>Bring your data via CSV and export anytime. You own your cellar.</p>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="section card" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <div>
            <h2 style="margin:0 0 6px">Ready to get started?</h2>
            <p class="muted" style="margin:0">Sign up free, import your bottles, and unlock AI insights in minutes.</p>
        </div>
        <div class="cta">
            <a class="btn btn-primary" href="/register.php">Create your cellar</a>
            <a class="btn btn-outline" href="/share_wine.php?t=_oScAiVweP-bbH3N0094kLvw-6eLRsd0" aria-label="View a demo shared bottle">See a demo bottle</a>
        </div>
    </section>

    <!-- FAQ -->
    <section class="section card">
        <h2 style="margin:0 0 6px">FAQs</h2>
        <dl class="faq">
            <dt>Is the site private?</dt>
            <dd>Yes. All logged-in content is private by default. You can optionally create public share links for selected bottles or lists.</dd>
            <dt>Can my friends see my whole cellar?</dt>
            <dd>Only if you share it. By default, no. </dd>
        </dl>

    </section>
</div>
</body>
</html>
