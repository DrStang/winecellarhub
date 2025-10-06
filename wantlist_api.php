<?php
require 'db.php';
require 'auth.php';
$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'add_manual') {
    $stmt = $pdo->prepare("INSERT INTO wantlist (user_id, name, winery, region, type, vintage, notes) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
        $user_id,
        trim($_POST['name'] ?? ''),
        trim($_POST['winery'] ?? ''),
        trim($_POST['region'] ?? ''),
        trim($_POST['type'] ?? ''),
        trim($_POST['vintage'] ?? ''),
        trim($_POST['notes'] ?? ''),
    ]);
    header("Location: wantlist.php");
    exit;
}

if ($action === 'remove') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM wantlist WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
    header("Location: wantlist.php");
    exit;
}

if ($action === 'move_to_inventory') {
    $id = (int)($_POST['id'] ?? 0);
    // Pull the wantlist entry
    $w = $pdo->prepare("SELECT * FROM wantlist WHERE id = ? AND user_id = ?");
    $w->execute([$id, $user_id]);
    $it = $w->fetch(PDO::FETCH_ASSOC);
    if ($it) {
        // Insert minimal bottle record (extend to include location, etc. as needed)
        // Detect existing columns to avoid errors
        $cols = $pdo->query("SHOW COLUMNS FROM bottles")->fetchAll(PDO::FETCH_ASSOC);
        $fields = array_map(fn($c)=> strtolower($c['Field']), $cols);
        $hasType = in_array('type', $fields, true);
        $hasVintage = in_array('vintage', $fields, true);
        $hasWineId = in_array('wine_id', $fields, true);

        $columns = ['user_id'];
        $values = [$user_id];
        if ($hasWineId) { $columns[] = 'wine_id'; $values[] = (int)($it['wine_id'] ?? 0); }
        if ($hasType)   { $columns[] = 'type';    $values[] = trim((string)($it['type'] ?? '')); }
        if ($hasVintage){ $columns[] = 'vintage'; $values[] = trim((string)($it['vintage'] ?? '')); }

        $colsSql = implode(',', $columns);
        $place = implode(',', array_fill(0, count($columns), '?'));
        $ins = $pdo->prepare("INSERT INTO bottles ($colsSql) VALUES ($place)");
        $ins->execute($values);

        // Remove from wantlist after move
        $del = $pdo->prepare("DELETE FROM wantlist WHERE id = ? AND user_id = ?");
        $del->execute([$id, $user_id]);
    }
    header("Location: wantlist.php");
    exit;
}

if ($action === 'search') {
    header('Content-Type: text/html; charset=utf-8');
    $q = trim($_POST['q'] ?? '');
    if ($q === '') { echo '<div class="p-2 text-gray-600">Enter a search term.</div>'; exit; }

    if (!isset($winelist_pdo) || !($winelist_pdo instanceof PDO)) {
        echo '<div class="p-2 text-gray-600">Central catalog unavailable.</div>';
        exit;
    }

    $rows = [];
    $sql = "SELECT id, name, winery, region, type, vintage FROM wines
            WHERE MATCH(name, winery, grapes, region) AGAINST(:q IN NATURAL LANGUAGE MODE)
               OR name LIKE :q2 OR winery LIKE :q2 OR region LIKE :q2
            ORDER BY vintage DESC
            LIMIT 25";
    try {
        $stmt = $winelist_pdo->prepare($sql);
        $stmt->execute(['q'=>$q, 'q2'=>'%'.$q.'%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // fallback: LIKE only
        $sql2 = "SELECT id, name, winery, region, type, vintage FROM wines
                 WHERE name LIKE :q2 OR winery LIKE :q2 OR region LIKE :q2
                 ORDER BY vintage DESC LIMIT 25";
        $stmt = $winelist_pdo->prepare($sql2);
        $stmt->execute(['q2'=>'%'.$q.'%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$rows) {
        echo '<div class="p-2 text-gray-600">No results.</div>';
        exit;
    }

    foreach ($rows as $r) {
        $safeName = htmlspecialchars($r['name']);
        $safeWinery = htmlspecialchars($r['winery'] ?? '');
        $safeRegion = htmlspecialchars($r['region'] ?? '');
        $safeType = htmlspecialchars($r['type'] ?? '');
        $safeVintage = htmlspecialchars($r['vintage'] ?? '');
        echo "<div class='p-3 flex items-center justify-between'>
                <div class='min-w-0'>
                  <div class='font-medium truncate'>{$safeName}</div>
                  <div class='text-sm text-gray-600 truncate'>{$safeWinery}".(!empty($r['winery'])?' · ':'')."{$safeRegion}".(!empty($r['region'])?' · ':'')."{$safeType}".(!empty($r['type'])?' · ':'')."{$safeVintage}</div>
                </div>
                <form method='post' action='wantlist_api.php' class='ml-3'>
                  <input type='hidden' name='action' value='add_from_catalog' />
                  <input type='hidden' name='wine_id' value='".(int)$r['id']."' />
                  <input type='hidden' name='name' value=\"{$safeName}\" />
                  <input type='hidden' name='winery' value=\"{$safeWinery}\" />
                  <input type='hidden' name='region' value=\"{$safeRegion}\" />
                  <input type='hidden' name='type' value=\"{$safeType}\" />
                  <input type='hidden' name='vintage' value=\"{$safeVintage}\" />
                  <button class='px-3 py-1 rounded-lg border hover:bg-gray-50'>Add</button>
                </form>
              </div>";
    }
    exit;
}

if ($action === 'add_from_catalog') {
    $stmt = $pdo->prepare("INSERT INTO wantlist (user_id, wine_id, name, winery, region, type, vintage) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
        $user_id,
        (int)($_POST['wine_id'] ?? 0),
        trim($_POST['name'] ?? ''),
        trim($_POST['winery'] ?? ''),
        trim($_POST['region'] ?? ''),
        trim($_POST['type'] ?? ''),
        trim($_POST['vintage'] ?? ''),
    ]);
    header("Location: wantlist.php");
    exit;
}

// Default
http_response_code(400);
echo "Unsupported action.";
