<?php
// add_bottle.php — solid save path: single form, working analyze/preview, no style split, ai_blob backfill.
declare(strict_types=1);
session_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php'; // must set $pdo (user DB) and $winelist_pdo (catalog DB)
require_once __DIR__ . '/analytics_track.php';


// ---- CSRF ----
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];
function ensure_post_csrf(): void {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        http_response_code(400); echo "Bad CSRF token."; exit;
    }
}

// ---- Helpers ----
function str_or_null($v){ $v=trim((string)$v); return $v===''?null:$v; }
function int_or_null($v){ $v=trim((string)$v); if($v==='')return null; return is_numeric($v)?(int)$v:null; }
function dec_or_null($v){ $v=trim((string)$v); if($v==='')return null; return is_numeric($v)?(string)$v:null; }
function normalize_vintage($v){
    $v=trim((string)$v);
    if($v===''||preg_match('/^(NV|N\.?V\.?)$/i',$v)) return null;
    if(preg_match('/^\d{4}$/',$v)) return $v;
    return null;
}
function slug($s){ $s=strtolower(trim($s)); $s=preg_replace('/[^a-z0-9]+/','-',$s); $s=trim($s,'-'); return $s?:'wine'; }
function table_columns(PDO $pdo, string $table): array { $cols=[]; foreach($pdo->query("DESCRIBE `$table`") as $r){ $cols[]=$r['Field']; } return $cols; }
function insert_row(PDO $pdo, string $table, array $row): int {
    $cols = table_columns($pdo,$table);
    $row  = array_intersect_key($row, array_flip($cols));
    if (!$row) throw new RuntimeException("No valid columns to insert into `$table`.");
    $fields=array_keys($row); $place=array_map(fn($c)=>":$c",$fields);
    $st=$pdo->prepare("INSERT INTO `$table` (".implode(',',$fields).") VALUES (".implode(',',$place).")");
    foreach($row as $k=>$v){ $st->bindValue(":$k",$v); } $st->execute(); return (int)$pdo->lastInsertId();
}
function catalog_colmap(PDO $pdo): array {
    static $cache=null; if($cache!==null) return $cache;
    $cols=table_columns($pdo,'wines'); $has=array_flip($cols);
    $pick=function(array $cands)use($has){ foreach($cands as $c){ if(isset($has[$c])) return $c; } return null; };
    return $cache=[
        'id'=>'id',
        'name'=>$pick(['name','label','title'])??'name',
        'winery'=>$pick(['winery','producer','brand'])??'winery',
        'vintage'=>$pick(['vintage','year'])??'vintage',
        'country'=>$pick(['country'])??'country',
        'region'=>$pick(['region','appellation','ava','subregion','sub_region'])??'region',
        'grapes'=>$pick(['grapes','varietal','varietals'])??'grapes',
        'type'=>$pick(['type','wine_type','color'])??'type',
        'style'=>$pick(['style'])??'style',
        'barcode'=>$pick(['barcode','ean','gtin'])??'barcode',
        'upc'=>$pick(['upc','barcode'])??'upc',
        'image_url'=>$pick(['image_url','image','photo','cover_url'])??'image_url',
        'price'=>$pick(['price','avg_price'])??'price',
    ];
}
function build_catalog_row(PDO $pdo, array $src): array {
    $map=catalog_colmap($pdo); $row=[];
    $put=function(string $logical,$value)use(&$row,$map){
        if($value===null||$value==='') return;
        $col=$map[$logical]??null; if($col && (!array_key_exists($col,$row) || $row[$col]==='' || $row[$col]===null)){ $row[$col]=$value; }
    };
    $put('name',str_or_null($src['name']??'')); $put('winery',str_or_null($src['winery']??'')); $v=normalize_vintage($src['vintage']??''); if($v!==null)$put('vintage',$v);
    $put('country',str_or_null($src['country']??'')); $put('region',str_or_null($src['region']??'')); $put('grapes',str_or_null($src['grapes']??'')); $put('type',str_or_null($src['type']??'')); $put('style',str_or_null($src['style']??''));
    $code=str_or_null($src['barcode']??$src['upc']??''); if($code!==null){ $put('barcode',$code); $put('upc',$code); } else { $put('upc',str_or_null($src['upc']??'')); }
    $put('image_url',str_or_null($src['catalog_image_url']??$src['image_url']??'')); $put('price',dec_or_null($src['price']??null));
    return $row;
}
function update_missing_catalog_fields(PDO $pdo, int $id, array $row): void {
    if(!$row) return; $st=$pdo->prepare("SELECT * FROM `wines` WHERE `id`=?"); $st->execute([$id]); $cur=$st->fetch(PDO::FETCH_ASSOC)?:[];
    foreach($row as $col=>$val){ if($val===null||$val==='')continue;
        $curVal=$cur[$col]??null; $empty=$curVal===null||$curVal===''||$curVal===0||$curVal==='0';
        if($empty){ $u=$pdo->prepare("UPDATE `wines` SET `$col`=:v WHERE `id`=:id AND (`$col` IS NULL OR `$col`='' OR `$col`=0)"); $u->execute([':v'=>$val,':id'=>$id]); }
    }
}
function upsert_catalog(PDO $catalog, array $d): array {
    $map=catalog_colmap($catalog); $imageCol=$map['image_url']; $nameCol=$map['name']; $winCol=$map['winery']; $vintCol=$map['vintage'];
    $readById=function(int $id)use($catalog,$imageCol){ $st=$catalog->prepare("SELECT *, (`$imageCol` IS NOT NULL AND `$imageCol`<>'') has_img FROM `wines` WHERE id=? LIMIT 1"); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC); return $row?['row'=>$row,'had_image'=>(bool)$row['has_img']]:null; };
    // by explicit id
    if(!empty($d['catalog_wine_id']) && ctype_digit((string)$d['catalog_wine_id'])){ $res=$readById((int)$d['catalog_wine_id']); if($res){ update_missing_catalog_fields($catalog,(int)$d['catalog_wine_id'],build_catalog_row($catalog,$d)); return ['wine_id'=>(int)$d['catalog_wine_id'],'had_image'=>$res['had_image']]; } }
    // exact-ish name+winery(+vintage)
    $name=str_or_null($d['name']??''); $winery=str_or_null($d['winery']??''); $v=normalize_vintage($d['vintage']??'');
    if($name && $winery){
        if($v && $vintCol){ $st=$catalog->prepare("SELECT *, (`$imageCol` IS NOT NULL AND `$imageCol`<>'') has_img FROM wines WHERE `$nameCol`=? AND `$winCol`=? AND `$vintCol`=? LIMIT 1"); $st->execute([$name,$winery,$v]); }
        else { $st=$catalog->prepare("SELECT *, (`$imageCol` IS NOT NULL AND `$imageCol`<>'') has_img FROM wines WHERE `$nameCol`=? AND `$winCol`=? ORDER BY (vintage IS NOT NULL) DESC, id DESC LIMIT 1"); $st->execute([$name,$winery]); }
        if($row=$st->fetch(PDO::FETCH_ASSOC)){ update_missing_catalog_fields($catalog,(int)$row['id'],build_catalog_row($catalog,$d)); return ['wine_id'=>(int)$row['id'],'had_image'=>(bool)$row['has_img']]; }
    }
    // insert new
    $insertRow = build_catalog_row($catalog,$d);
    if(!$insertRow) throw new RuntimeException("Catalog insert has no valid columns.");
    $id=insert_row($catalog,'wines',$insertRow); return ['wine_id'=>$id,'had_image'=>!empty($insertRow[$imageCol])];
}
function handle_image_upload(array $file, ?string $nameForSlug=null): ?string {
    if(empty($file)||!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) return null;
    $tmp=$file['tmp_name']; if(!is_uploaded_file($tmp)) return null;
    $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo,$tmp); finfo_close($finfo);
    $ext=match($mime){'image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp', default=>'.jpg'};
    $base=($nameForSlug?slug($nameForSlug):'wine').'-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)); $rel="/covers/{$base}{$ext}";
    $abs=rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__,'/').$rel; @mkdir(dirname($abs),0775,true);
    if(!move_uploaded_file($tmp,$abs)) throw new RuntimeException("Failed to save uploaded image."); return $rel;
}
function handle_image_b64_upload(string $dataUrl, ?string $nameForSlug=null): ?string {
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl, $m)) return null;
    $ext = strtolower($m[1] === 'jpg' ? 'jpeg' : $m[1]);
    $b64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bin = base64_decode($b64, true);
    if ($bin === false) return null;

    $slug = preg_replace('/[^a-z0-9]+/i', '-', trim((string)$nameForSlug)) ?: 'label';
    $slug = trim($slug, '-');

    $dir = __DIR__ . '/covers/' . date('Y/m');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = $slug . '-' . substr(sha1(random_bytes(8)), 0, 8) . '.' . $ext;
    $path = $dir . '/' . $name;
    if (file_put_contents($path, $bin) === false) return null;

    // Return web path relative to docroot
    return 'covers/' . date('Y/m') . '/' . $name;
}

// simple debug line to /tmp
function debug_log_submit(array $post, array $builtRow, array $bottleRow): void {
    try{ $line=json_encode(['ts'=>date('c'),'POST'=>array_diff_key($post,['csrf'=>1]),'catalog_insert_row'=>$builtRow,'bottle_insert_row'=>$bottleRow],JSON_UNESCAPED_SLASHES);
        @file_put_contents('/tmp/add_bottle_debug.log',$line.PHP_EOL,FILE_APPEND);}catch(\Throwable $e){}
}

// ---- POST handler ----
$error=null; $retryPayload=null; $photo_path=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add'){
    try{
        ensure_post_csrf();
        $userId=$_SESSION['user_id']??null; if(!$userId) throw new RuntimeException("Not authenticated.");

        // Raw form
        $form = $_POST;

        // Merge in the selected candidate snapshot so fields never get lost between modal → inputs → submit
        if (!empty($form['ai_blob'])) {
            try{
                $snap=json_decode($form['ai_blob'],true,512,JSON_THROW_ON_ERROR);
                $c=$snap['chosen']??[];
                $bf=function($key) use (&$form,$c){ if(!isset($form[$key])||trim((string)$form[$key])===''){ if(isset($c[$key])&&$c[$key]!==''&&$c[$key]!==null){ $form[$key]=$c[$key]; } } };
                foreach(['name','winery','vintage','country','region','grapes','type','style','image_url','barcode'] as $k){ $bf($k); }
            }catch(\Throwable $e){ /* ignore malformed ai_blob */ }
        }

        // Only require name+winery when not linking an existing catalog row
        $hasCatalogId = !empty(trim((string)($form['catalog_wine_id'] ?? '')));
        $hasAISnap    = !empty(trim((string)($form['ai_blob'] ?? '')));

        if (!$hasCatalogId && !$hasAISnap) {
            $hasName   = trim((string)($form['name']   ?? ''))   !== '';
            $hasWinery = trim((string)($form['winery'] ?? ''))   !== '';
            if (!($hasName && $hasWinery)) {
                throw new RuntimeException("Please provide Name and Winery, or pick a catalog result.");
            }
        }


        // 1) Upsert / match catalog FIRST
        $up = upsert_catalog($winelist_pdo, array_merge($form, [
            'catalog_image_url' => $form['catalog_image_url'] ?? null
        ]));
        $wine_id = $up['wine_id']; $catalogHadImage = $up['had_image'];

        // Current catalog image (if any)
        $st=$winelist_pdo->prepare("SELECT image_url FROM wines WHERE id=? LIMIT 1"); $st->execute([$wine_id]);
        $catalogImageUrl = (string)($st->fetchColumn() ?: '');

        // 2) Uploaded handheld photo handling
// 2) Uploaded handheld photo handling (file OR base64 from scan)
        $photo_path = null;
        $nameForSlug = trim(($form['name'] ?? '') . ' ' . ($form['winery'] ?? ''));

// Prefer base64 (from scan.php) if present
        $photo_b64 = trim((string)($form['photo_b64'] ?? ''));
        if ($photo_b64 && str_starts_with($photo_b64, 'data:image')) {
            $photo_path = handle_image_b64_upload($photo_b64, $nameForSlug);
            $userUploaded = (bool)$photo_path;
        } else {
            // Fallback to classic file upload
            $userUploaded = (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE);
            if ($userUploaded) {
                $photo_path = handle_image_upload($_FILES['photo'], $nameForSlug);
            }
        }

// If catalog had no image, promote the user photo to the catalog image_url
        if (!$catalogHadImage && $photo_path) {
            $st = $winelist_pdo->prepare("UPDATE wines SET image_url=:u WHERE id=:id AND (image_url IS NULL OR image_url='')");
            $st->execute([':u' => $photo_path, ':id' => $wine_id]);
            $catalogImageUrl = $catalogImageUrl ?: $photo_path;
        }

        // 3) Insert into user's inventory
        $b=[
            'wine_id'=>$wine_id,'user_id'=>$userId,'owner_id'=>$userId,
            'photo_path'=>$photo_path,
            'image_url' => $photo_path ?: (str_or_null($form['image_url'] ?? '') ?: ($catalogImageUrl ?: null)),
            'my_price'=>dec_or_null($form['my_price'] ?? null),
            'my_rating'=>dec_or_null($form['my_rating'] ?? null),
            'quantity'=>int_or_null($form['quantity'] ?? '1') ?? 1,
            'location'=>str_or_null($form['location'] ?? ''),
            'notes'=>str_or_null($form['notes'] ?? ''),
            'purchase_date'=>str_or_null($form['purchase_date'] ?? ''),
            'status'=>'active','created_at'=>date('Y-m-d H:i:s'),
        ];

        // DEBUG snapshot
        $__builtCatalogRow = build_catalog_row($winelist_pdo, $form);
        if (!empty($_GET['debug'])) { debug_log_submit($_POST, $__builtCatalogRow, $b); }

        $bottle_id = insert_row($pdo,'bottles',$b);
        header("Location: /inventory.php?added=".$bottle_id); exit;

    }catch(\Throwable $e){
        $error=$e->getMessage(); $retryPayload=$_POST; if(!empty($photo_path)) $retryPayload['photo_already_saved']=$photo_path;
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <title>Add Bottle</title>
    <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.1/dist/tailwind.min.css">
    <style>
        :root{ --primary:#4f46e5; --on-primary:#fff; --surface:#fff; --text:#0f172a; --muted:#6b7280; --border:#e5e7eb; --bg:#f8fafc; }
        body{ background:var(--bg); color:var(--text); }
        .card{border-radius:1rem;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05);background:var(--surface);color:var(--text)}
        .soft-border{border:1px solid var(--border)}
        .input{ background:var(--surface); color:var(--text); border:1px solid var(--border); }
        .btn{border-radius:.5rem;padding:.5rem .75rem}
        .btn-primary{background:var(--primary);color:var(--on-primary)}
        .btn-ghost{background:transparent;border:1px solid var(--border)}
        thead{background:color-mix(in srgb, var(--surface) 70%, #000 0%)}
        .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:50}
        .modal{background:var(--surface);color:var(--text);border-radius:0.75rem;max-width:48rem;width:100%;margin:1rem;box-shadow:0 10px 25px rgba(0,0,0,.2)}
    </style>
</head>
<body class="min-h-screen">
<?php if (file_exists(__DIR__.'/partials/header.php')) require __DIR__ . '/partials/header.php'; ?>

<main class="max-w-6xl mx-auto px-4 py-6">
    <header class="mb-4"><h1 class="text-2xl font-extrabold tracking-tight">Add Bottle</h1>
        <p>Enter details manually, upload a label for AI recognition or search our catalog at the bottle of the page</p>
    </header>

    <?php if ($error): ?>
        <div class="p-3 mb-4 rounded soft-border" style="background:#fef2f2;color:#991b1b;border-color:#fecaca">
            <div class="font-medium mb-1">Something went wrong</div>
            <div class="text-sm"><?=htmlspecialchars($error)?></div>
            <?php if ($retryPayload): ?>
                <form method="post" class="mt-3">
                    <?php foreach ($retryPayload as $k=>$v): if (is_array($v)) continue; ?>
                        <input type="hidden" name="<?=htmlspecialchars($k)?>" value="<?=htmlspecialchars($v)?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="csrf" value="<?=htmlspecialchars($CSRF)?>">
                    <input type="hidden" name="action" value="add">
                    <button class="btn btn-primary">Retry</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- SINGLE outer form -->
    <form id="addForm" method="post" enctype="multipart/form-data" class="p-4 md:p-6 card">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($CSRF)?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" id="catalog_wine_id" name="catalog_wine_id" value="">
        <input type="hidden" id="catalog_image_url" name="catalog_image_url" value="">
        <input type="hidden" id="ai_blob" name="ai_blob" value=""><!-- snapshot of chosen AI/Catalog candidate -->

        <div class="grid md:grid-cols-3 gap-6">
            <div class="md:col-span-2">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div><label class="block text-sm" style="color:var(--muted)">Wine name</label>
                        <input name="name" id="name" class="mt-1 w-full rounded-md p-2 input" required></div>
                    <div><label class="block text-sm" style="color:var(--muted)">Winery</label>
                        <input name="winery" id="winery" class="mt-1 w-full rounded-md p-2 input" required></div>
                    <div><label class="block text-sm" style="color:var(--muted)">Vintage</label>
                        <input name="vintage" id="vintage" class="mt-1 w-full rounded-md p-2 input" placeholder="e.g., 2018 or NV"></div>
                    <div><label class="block text-sm" style="color:var(--muted)">Country</label>
                        <input name="country" id="country" class="mt-1 w-full rounded-md p-2 input"></div>
                    <div><label class="block text-sm" style="color:var(--muted)">Region</label>
                        <input name="region" id="region" class="mt-1 w-full rounded-md p-2 input"></div>
                    <div><label class="block text-sm" style="color:var(--muted)">Grapes</label>
                        <input name="grapes" id="grapes" class="mt-1 w-full rounded-md p-2 input"></div>
                    <div><label class="block text-sm" style="color:var(--muted)">Type</label>
                        <select name="type" id="type" class="mt-1 w-full rounded-md p-2 input">
                            <option value="">— Select type —</option><option>Red</option><option>White</option><option>Rosé</option>
                            <option>Sparkling</option><option>Dessert</option><option>Fortified</option>
                        </select></div>
                    <div><label class="block text-sm" style="color:var(--muted)">Style (optional)</label>
                        <input name="style" id="style" class="mt-1 w-full rounded-md p-2 input" placeholder="e.g., Napa Valley Cabernet Sauvignon"></div>
                </div>

                <div class="mt-6">
                    <h3 class="font-medium mb-2">Your inventory details</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div><label class="block text-sm" style="color:var(--muted)">My price</label>
                            <input name="my_price" id="my_price" class="mt-1 w-full rounded-md p-2 input" inputmode="decimal"></div>
                        <div><label class="block text-sm" style="color:var(--muted)">My rating</label>
                            <input name="my_rating" id="my_rating" class="mt-1 w-full rounded-md p-2 input" inputmode="decimal" placeholder="0–5"></div>
                        <div><label class="block text-sm" style="color:var(--muted)">Quantity</label>
                            <input name="quantity" id="quantity" class="mt-1 w-full rounded-md p-2 input" inputmode="numeric" value="1"></div>
                        <div><label class="block text-sm" style="color:var(--muted)">Location</label>
                            <input name="location" id="location" class="mt-1 w-full rounded-md p-2 input" placeholder="Rack A3">
                            <p class="text-xs text-gray-500 mt-1">Your storage spot. We like lettered racks and numbered columns like <strong>A2, B3</strong>, but label however you like.</p>
                        </div>
                        <div><label class="block text-sm" style="color:var(--muted)">Purchase date</label>
                            <input type="date" name="purchase_date" id="purchase_date" class="mt-1 w-full rounded-md p-2 input"></div>
                        <div class="md:col-span-2"><label class="block text-sm" style="color:var(--muted)">Notes</label>
                            <textarea name="notes" id="notes" class="mt-1 w-full rounded-md p-2 input" rows="3"></textarea></div>
                        <div><label class="block text-sm" style="color:var(--muted)">Barcode / UPC (optional)</label>
                            <input name="barcode" id="barcode" class="mt-1 w-full rounded-md p-2 input"></div>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm" style="color:var(--muted)">Upload bottle image for AI analysis</label>
                <input type="file" name="photo" id="photo" accept="image/*" class="mt-1 w-full rounded-md p-2 input">
                <div id="scanPreviewWrap" class="mt-3">
                    <div class="text-sm" style="color:var(--muted)">Image preview</div>
                    <img id="preview" alt="Preview" class="w-48 h-64 object-cover rounded soft-border" style="background:#f1f5f9">
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="button" id="analyzeBtn" class="btn btn-primary hover:opacity-90">Analyze Label</button>
                    <input type="file" id="labelFile" accept="image/*" capture="environment" class="hidden">
                </div>
            </div>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button class="btn btn-primary hover:opacity-90" type="submit">Save</button>
            <a href="/inventory.php" class="btn btn-ghost">Cancel</a>
        </div>

        <!-- Catalog search UI (no nested form!) -->
        <section id="catalog-search" class="mt-8 mx-auto max-w-4xl card soft-border">
            <h2 class="text-lg font-semibold mb-3">🔎 Search Catalog</h2>
            <div id="catalogSearchForm" class="grid gap-3">
                <div class="grid md:grid-cols-[1fr_auto_auto] gap-2 items-end">
                    <div>
                        <label for="q_name" class="text-sm" style="color:var(--muted)">Search</label>
                        <input id="q_name" type="text" placeholder="e.g., Chateau Montelena 2018" class="mt-1 w-full rounded-md p-2 input"/>
                    </div>
                    <button id="runCatalogSearch" type="button" class="px-3 py-2 border rounded-md bg-gray-50 hover:bg-gray-100">Search</button>
                    <button id="clearCatalogResults" type="button" class="px-3 py-2 border rounded-md bg-gray-50 hover:bg-gray-100">Clear</button>
                </div>
                <details class="mt-1">
                    <summary class="cursor-pointer text-sm" style="color:var(--muted)">Advanced filters</summary>
                    <div class="grid md:grid-cols-4 gap-3 mt-3">
                        <div><label for="q_winery" class="text-sm" style="color:var(--muted)">Winery</label>
                            <input id="q_winery" type="text" class="mt-1 w-full rounded-md p-2 input"/></div>
                        <div><label for="q_vintage" class="text-sm" style="color:var(--muted)">Vintage</label>
                            <input id="q_vintage" type="number" min="1900" max="2100" class="mt-1 w-full rounded-md p-2 input"/></div>
                        <div><label for="q_region" class="text-sm" style="color:var(--muted)">Region</label>
                            <input id="q_region" type="text" class="mt-1 w-full rounded-md p-2 input"/></div>
                        <div><label for="q_grapes" class="text-sm" style="color:var(--muted)">Grapes / Varietal</label>
                            <input id="q_grapes" type="text" class="mt-1 w-full rounded-md p-2 input"/></div>
                    </div>
                </details>
                <small id="catalogSearchStatus" class="text-sm" style="color:var(--muted)"></small>
            </div>

            <div id="catalogResultsWrap" class="mt-4 hidden">
                <div class="flex items-center justify-between"><strong>Results</strong></div>
                <div id="catalogResultsNotice" class="mt-1 text-sm" style="color:var(--muted)"></div>
                <div class="overflow-auto mt-2 rounded-lg soft-border">
                    <table id="catalogResults" class="w-full text-sm">
                        <thead>
                        <tr><th class="text-left p-2">Label</th><th class="text-left p-2">Wine Name</th><th class="text-left p-2">Winery</th><th class="text-left p-2">Vintage</th><th class="text-left p-2">Region</th><th class="text-left p-2">Type</th><th class="text-left p-2">Grapes</th><th class="text-left p-2">Action</th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <div id="catalogConfirm" class="hidden mt-4 rounded-xl p-3 soft-border" style="border-style:dashed">
                <div id="catalogConfirmBody" class="flex gap-3 items-start"></div>
                <div class="mt-3 flex gap-2">
                    <button id="catalogBackBtn" type="button" class="btn btn-ghost">Back</button>
                    <button id="catalogConfirmBtn" type="button" class="btn btn-ghost">Confirm &amp; Pre-fill</button>
                </div>
            </div>
        </section>
        <script src="catalog_search.js" defer></script>
        <script>
            document.getElementById('addForm')?.addEventListener('submit', () => {
                // Make sure search fields never contribute to POST
                ['q_name','q_winery','q_vintage','q_region','q_grapes'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.removeAttribute('name');
                });
            });
        </script>

    </form>
</main>

<!-- Analyze Modal -->
<div id="modalBackdrop" class="modal-backdrop">
    <div class="modal">
        <div class="p-4 soft-border" style="border-top:none;border-left:none;border-right:none">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Label results</h3>
                <button id="modalClose" class="hover:opacity-70" style="color:var(--muted)">&times;</button>
            </div>
        </div>
        <div id="modalBody" class="p-4">
            <div id="modalLoading" class="text-sm" style="color:var(--muted)">Analyzing label…</div>
            <div id="modalError" class="hidden text-sm" style="color:#b91c1c"></div>
            <div id="modalList" class="hidden">
                <p class="text-sm" style="color:var(--muted)">Select the correct wine, then Confirm.</p>
                <div id="candidates" class="space-y-2 mt-2"></div>
            </div>
        </div>
        <div class="p-4 soft-border" style="border-bottom:none;border-left:none;border-right:none">
            <div class="flex items-center justify-end gap-2">
                <button id="modalConfirm" class="btn btn-primary" disabled style="display:none">Confirm</button>
                <button id="modalCancel" class="btn btn-ghost">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const $ = (i)=>document.getElementById(i);
        const csrf = document.querySelector('input[name="csrf"]')?.value || '';

        // refs
        const photoInput=$('photo'), previewEl=$('preview');
        const analyzeBtn=$('analyzeBtn'), labelFile=$('labelFile');
        const modalBackdrop=$('modalBackdrop'), modalClose=$('modalClose'), modalCancel=$('modalCancel');
        const modalConfirm=$('modalConfirm'), modalLoading=$('modalLoading'), modalError=$('modalError'), modalList=$('modalList'), candidatesEl=$('candidates');
        const catIdInput=$('catalog_wine_id'), catImgInput=$('catalog_image_url'), aiBlob=$('ai_blob');

        // helpers
        const openModal = ()=>{ modalBackdrop.style.display='flex'; };
        const closeModal= ()=>{ modalBackdrop.style.display='none'; };
        const resetModal= ()=>{ modalLoading.classList.remove('hidden'); modalError.classList.add('hidden'); modalList.classList.add('hidden'); modalError.textContent=''; modalConfirm.disabled=true; modalConfirm.style.display='none'; candidatesEl.innerHTML=''; };
        const setVal = (id,val)=>{ const el=$(id); if(!el) return; el.value = (val ?? ''); el.dispatchEvent(new Event('change',{bubbles:true})); };
        const escapeHtml = (s)=>String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;' }[m]));
        const field = (label, value)=> (value===undefined||value===null||value==='') ? '' : `<div><span style="color:var(--muted)">${escapeHtml(label)}:</span> ${escapeHtml(value)}</div>`;

        const fillFromCatalog=(r)=>{ setVal('name',r.name); setVal('winery',r.winery); setVal('vintage',r.vintage); setVal('country',r.country); setVal('region',r.region); setVal('grapes',r.grapes); setVal('type',r.type); setVal('style',r.style);
            if(r.image_url && !photoInput.files?.length){ previewEl.src=r.image_url; if(catImgInput) catImgInput.value=r.image_url; }
            if(catIdInput) catIdInput.value=r.id||''; };

        const fillFromAI=(c)=>{ setVal('name',c.name); setVal('winery',c.winery); setVal('vintage',c.vintage); if(c.country) setVal('country',c.country); if(c.region) setVal('region',c.region); if(c.type) setVal('type',c.type); if(c.grapes) setVal('grapes',c.grapes); if(c.barcode) setVal('barcode',c.barcode);
            if(c.image_url && !photoInput.files?.length){ previewEl.src=c.image_url; if(catImgInput) catImgInput.value=c.image_url; }
            if(catIdInput) catIdInput.value=''; };

        // image preview
        photoInput?.addEventListener('change', () => {
            const f=photoInput.files?.[0]; if(!f) return;
            const url=URL.createObjectURL(f); previewEl.src=url; previewEl.onload=()=>URL.revokeObjectURL(url);
        });

        // modal buttons
        modalClose?.addEventListener('click', closeModal);
        modalCancel?.addEventListener('click', closeModal);

        // analyze
        analyzeBtn?.addEventListener('click', () => {
            const reuse = photoInput.files?.[0]; if (reuse) runAnalyze(reuse); else labelFile.click();
        });
        labelFile?.addEventListener('change', ()=>{ const f=labelFile.files?.[0]; if(f) runAnalyze(f); });

        async function runAnalyze(file){
            if(catIdInput) catIdInput.value='';
            resetModal(); openModal();
            try{
                const fd=new FormData(); fd.append('photo',file,'label.jpg'); fd.append('csrf',csrf); fd.append('json','1');
                const resp=await fetch('/label_upload.php',{ method:'POST', body:fd, credentials:'include', headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF':csrf } });
                if(!resp.ok) throw new Error(`Analyzer HTTP ${resp.status}`);
                const data=await resp.json();
                const cand=Array.isArray(data.candidates)?data.candidates:[];
                if(!cand.length){ modalLoading.classList.add('hidden'); modalError.classList.remove('hidden'); modalError.textContent='No label candidates found. You can edit fields manually.'; return; }

                modalLoading.classList.add('hidden'); modalList.classList.remove('hidden'); candidatesEl.innerHTML='';

                cand.forEach((c)=>{
                    const cat = (c.matched_catalog && c.catalog_row) ? c.catalog_row : null;
                    const card=document.createElement('div'); card.className='p-3 rounded soft-border';
                    card.innerHTML=`
          <div class="grid md:grid-cols-2 gap-3">
            <div><div class="text-sm font-semibold mb-1">AI parse</div>
              <div class="text-sm space-y-0.5">
                ${field('Name',c.name||'')}${field('Winery',c.winery||'')}${field('Vintage',c.vintage||'')}
                ${field('Type',c.type||'')}${field('Region',c.region||'')}${field('Country',c.country||'')}
                ${field('Grapes',c.grapes||'')}${field('Barcode',c.barcode||'')}
              </div></div>
            <div><div class="text-sm font-semibold mb-1">Catalog match</div>
              ${ cat ? `
                <div class="flex gap-3">
                  <img src="${cat.image_url||''}" onerror="this.style.display='none'" class="w-12 h-16 object-cover rounded soft-border" style="background:#f1f5f9">
                  <div class="text-sm space-y-0.5">
                    ${field('Name',cat.name||'')}${field('Winery',cat.winery||'')}${field('Vintage',cat.vintage||'')}
                    ${field('Type',cat.type||'')}${field('Region',cat.region||'')}${field('Country',cat.country||'')}
                    ${field('Grapes',cat.grapes||'')}${field('ID',cat.id||'')}
                  </div>
                </div>` : `<div class="text-sm" style="color:var(--muted)">No catalog match. You can use the AI fields.</div>`}
            </div>
          </div>
          <div class="mt-3 flex flex-wrap gap-2">
            ${cat ? `<button class="btn btn-primary use-catalog">Yes, use catalog</button>` : ''}
            <button class="btn btn-ghost use-ai">No, use AI fields</button>
          </div>
        `;
                    card.querySelector('.use-ai')?.addEventListener('click', ()=>{
                        fillFromAI(c);
                        if(aiBlob) aiBlob.value = JSON.stringify({ source:'ai', chosen:c });
                        closeModal(); window.scrollTo({top:0,behavior:'smooth'});
                    });
                    if(cat){
                        card.querySelector('.use-catalog')?.addEventListener('click', async ()=>{
                            // optional re-query omitted — use provided cat row
                            fillFromCatalog(cat);
                            if(aiBlob) aiBlob.value = JSON.stringify({ source:'catalog', chosen:cat });
                            closeModal(); window.scrollTo({top:0,behavior:'smooth'});
                        });
                    }
                    candidatesEl.appendChild(card);
                });

            }catch(e){
                console.error(e);
                modalLoading.classList.add('hidden'); modalError.classList.remove('hidden'); modalError.textContent='Analyze failed. Try another photo or fill fields manually.';
            }
        }
    })();
</script>
<script>
(function() {
    try {
        const raw = sessionStorage.getItem('prefill_wine');
        if (!raw) return;
        const w = JSON.parse(raw);
// Map catalog fields into the appropriate inputs.
        const map = {
            name: 'name', winery: 'winery', vintage: 'vintage', grapes: 'grapes',
            region: 'region', country: 'country', type: 'type', style: 'style',
            rating: 'rating', price: 'price', upc: 'upc', image: 'image_url'
// NOTE: We intentionally do NOT auto-fill my_rating/my_price here.
        };
        Object.keys(map).forEach(k => {
            const el = document.querySelector('[name="' + map[k] + '"]');
            if (el && w[k] != null) el.value = w[k];
        });
// If we carried a DataURL over from scan.php, preview it
        if (w.image && typeof w.image === 'string' && w.image.startsWith('data:image')) {
            const img = document.getElementById('preview');      // ← this is #somePreviewImg
            const wrap = document.getElementById('scanPreviewWrap');
            if (img) img.src = w.image;
            if (wrap) wrap.classList.remove('hidden');
        }
        sessionStorage.removeItem('prefill_wine');
    } catch (e) {
        console.warn('No prefill', e);
    }
})();
</script>
</body>
<?php require __DIR__ . '/partials/footer.php'; ?>

</html>
