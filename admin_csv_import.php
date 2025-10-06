<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Optional admin gate:
if (empty($_SESSION['is_admin'])) { http_response_code(403); exit('Admins only'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>CSV/JSON Import — Winelist</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;}
        .wrap{max-width:900px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
        label{display:block;margin:.5rem 0 .25rem;font-weight:600}
        input[type=file],input[type=text],select{width:100%;padding:.6rem .7rem;border:1px solid #cfd3d7;border-radius:10px}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .btn{appearance:none;border:0;border-radius:12px;padding:.7rem 1rem;cursor:pointer}
        .primary{background:#4f46e5;color:#fff}
        .secondary{background:#f3f4f6}
        code{background:#f6f8fa;padding:.1rem .35rem;border-radius:6px}
    </style>
</head>
<body style="background:#f6f7fb">
<div class="wrap">
    <h1>Import into <em>winelist</em></h1>
    <p>Upload a <strong>CSV</strong> or <strong>JSON</strong> file containing wine rows.</p>

    <form action="import_csv.php" method="post" enctype="multipart/form-data">
        <label for="file">File (CSV or JSON)</label>
        <input id="file" name="file" type="file" required />

        <div class="row" style="margin-top:12px">
            <div>
                <label for="delimiter">CSV delimiter (optional)</label>
                <input id="delimiter" name="delimiter" type="text" placeholder="Default: , (comma)"/>
            </div>
            <div>
                <label for="dry_run">Dry run?</label>
                <select id="dry_run" name="dry_run">
                    <option value="0">No — write to DB</option>
                    <option value="1">Yes — parse only</option>
                </select>
            </div>
        </div>

        <p style="margin-top:10px;font-size:.9rem;color:#555">
            Recognized columns (case-insensitive):
            <code>name, winery, region, country, grapes, type, vintage, rating, price, image_url, style, food_pairings</code><br/>
            Extras are ignored; missing ones are treated as <code>NULL</code>.
        </p>

        <div style="margin-top:14px;display:flex;gap:10px">
            <button class="btn primary" type="submit">Import</button>
            <a class="btn secondary" href="home.php">Back</a>
        </div>
    </form>
</div>
</body>
</html>
