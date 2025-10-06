<?php
// search_wine.php — grapes-aware catalog search (drop-in)
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');


require_once __DIR__ . '/db.php'; // provides $winelist_pdo

function get($key){ return isset($_GET[$key]) ? trim((string)$_GET[$key]) : ''; }

try {
    $limit = (int)(get('limit') ?: 25);
    $limit = max(1, min(100, $limit));

    // 0) Exact by id
    if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
        $st = $winelist_pdo->prepare("SELECT * FROM wines WHERE id = ? LIMIT 1");
        $st->execute([ (int)$_GET['id'] ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ? [$row] : []); exit;
    }

    // 1) Exact by barcode/upc
    if (($b = get('barcode')) !== '') {
        $st = $winelist_pdo->prepare("SELECT * FROM wines WHERE barcode = :b OR upc = :b LIMIT 5");
        $st->execute([':b'=>$b]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)); exit;
    }

    // 2) q= or multi-field
    $q       = get('q');
    $name    = get('name');
    $winery  = get('winery');
    $vintage = get('vintage');
    $region  = get('region');
    $grapes  = get('grapes');

    if ($q === '') {
        $parts = [];
        foreach (['name','winery','vintage','region','grapes'] as $k) {
            $v = get($k);
            if ($v !== '') $parts[] = $v;
        }
        if ($parts) $q = implode(' ', $parts);
    }

    // 3) POST fallback for manual mode {query:"..."}
    if ($q === '' && ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_GET['mode'] ?? '') === 'manual')) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (is_array($body) && !empty($body['query'])) $q = trim((string)$body['query']);
    }

    if ($q === '' && $name === '' && $winery === '' && $vintage === '' && $region === '' && $grapes === '') {
        echo json_encode([]); exit;
    }

    // Build a permissive WHERE to pull a candidate pool (rank below)
    $where = []; $args = [];

    // Use q as a broad catch-all, but still allow structured fields to refine
    if ($q !== '') {
        $where[] = "(name LIKE :q OR winery LIKE :q OR region LIKE :q OR grapes LIKE :q)";
        $args[':q'] = '%'.$q.'%';
    }
    if ($name !== '')   { $where[] = "name   LIKE :name";   $args[':name']   = '%'.$name.'%'; }
    if ($winery !== '') { $where[] = "winery LIKE :winery"; $args[':winery'] = '%'.$winery.'%'; }
    if ($vintage !== '' && ctype_digit($vintage)) {
        $where[] = "vintage = :vintage"; $args[':vintage'] = (int)$vintage;
    }
    if ($region !== '') { $where[] = "region LIKE :region"; $args[':region'] = '%'.$region.'%'; }
    if ($grapes !== '') { $where[] = "grapes LIKE :grapes"; $args[':grapes'] = '%'.$grapes.'%'; }

    $sql = "SELECT id, name, winery, vintage, region, country, type, style, 
            COALESCE(NULLIF(TRIM(grapes), '')) AS grapes, 
            image_url, rating
            FROM wines";
    if ($where) $sql .= " WHERE ".implode(" AND ", $where);
    $sql .= " LIMIT 200"; // pool size for ranking

    $st = $winelist_pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);



    // -------- Ranking (grapes-aware) --------
    $norm = fn($s) => strtolower(preg_replace('/[^a-z0-9]+/',' ', (string)$s));
    $gset = function($s){
        $toks = preg_split('/[^\p{L}\p{N}]+/u', strtolower((string)$s), -1, PREG_SPLIT_NO_EMPTY);
        $toks = array_map(fn($t)=>trim($t), $toks);
        $toks = array_filter($toks, fn($t)=>$t && $t !== 'blend');
        return array_values(array_unique($toks));
    };

    $target = [
        'name'    => $norm($name ?: $q),
        'winery'  => $norm($winery ?: $q),
        'vintage' => preg_match('/^\d{4}$/', $vintage) ? $vintage : '',
        'region'  => $norm($region),
        'grapes'  => $gset($grapes),
    ];

    $scored = [];
    foreach ($rows as $r) {
        $score = 0;
        $rn = $norm($r['name']   ?? '');
        $rw = $norm($r['winery'] ?? '');
        $rr = $norm($r['region'] ?? '');
        $rv = (string)($r['vintage'] ?? '');

        // name/winery/vintage similarity
        if ($target['name']) {
            if ($rn === $target['name']) $score += 40;
            elseif ($rn && (str_contains($rn, $target['name']) || str_contains($target['name'], $rn))) $score += 24;
        }
        if ($target['winery']) {
            if ($rw === $target['winery']) $score += 25;
            elseif ($rw && str_contains($rw, $target['winery'])) $score += 10;
        }
        if ($target['vintage'] && $rv === $target['vintage']) $score += 12;

        // region nudge
        if ($target['region']) {
            if ($rr === $target['region']) $score += 10;
            elseif ($rr && (str_contains($rr, $target['region']) || str_contains($target['region'], $rr))) $score += 6;
        }

        // grapes overlap
       $tg = $target['grapes'];
        if (!empty($tg)) {
            $rg = $gset($r['grapes'] ?? '');
            if ($rg) {
                $overlap = count(array_intersect($tg, $rg));
                $union   = count(array_unique(array_merge($tg, $rg)));
                $jaccard = $union ? ($overlap / $union) : 0.0;
                if     ($jaccard >= 0.67) $score += 18;
                elseif ($jaccard >= 0.34) $score += 10;
                elseif ($jaccard == 0.0)  $score -= 15; // obvious mismatch
            }
        }

        // small bias for having an image (helpful UX)
        if (!empty($r['image_url'])) $score += 2;


// --- normalize grapes for output (text only) ---
        $graw = trim((string)($r['grapes'] ?? ''));
        if ($graw !== '' && preg_match('/^\d+(\.\d+)?$/', $graw)) {
            // if grapes looks numeric (actually a rating), fall back to varietal or blank
            $r['grapes'] = isset($r['varietal']) && is_string($r['varietal']) ? trim($r['varietal']) : '';
        } else {
            // if grapes is a CSV, make it nice
            $parts = preg_split('/[\/,]+/', strtolower($graw), -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_values(array_unique(array_map('trim', $parts)));
            $r['grapes'] = $parts ? implode(', ', $parts) : $graw;
        }



        $scored[] = [$score, $r];
    }

    usort($scored, fn($a,$b) => $b[0] <=> $a[0]);
    $out = array_map(fn($t) => $t[1], array_slice($scored, 0, $limit));
    // If grapes accidentally came through numeric (e.g., a rating), scrub it
    foreach ($out as &$o) {
        if (isset($o['grapes'])) {
            $g = trim((string)$o['grapes']);
            if ($g !== '' && preg_match('/^\d+(\.\d+)?$/', $g)) {
                $o['grapes'] = (isset($o['varietal']) && is_string($o['varietal'])) ? trim($o['varietal']) : '';
            }
        }
    }
    unset($o);
    echo json_encode($out, JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'search failed','detail'=>$e->getMessage()]);
}
