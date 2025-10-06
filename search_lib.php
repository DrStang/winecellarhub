<?php
// search_lib.php (barcode-removed, fuzzy matching, robust best_catalog_match)
declare(strict_types=1);

function normalize_vintage(?string $v): ?string {
    $v = trim((string)$v);
    if ($v === '' || preg_match('/^(NV|N\.?V\.?)$/i', $v)) return null;
    return preg_match('/^\d{4}$/', $v) ? $v : null;
}

function ntext(string $s): string {
    // lower, strip accents, keep [a-z0-9] spaces
    $s = mb_strtolower($s, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s; // best-effort
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Flexible catalog search using per-field filters (no barcode).
 * Accepts array keys: name, winery, vintage, region, grapes. All optional.
 * Orders by a simple score that rewards exacts/prefix/contains and vintage match.
 */
function search_wines_params(PDO $pdo, array $p, int $limit = 10): array {
    $limit = max(1, min(50, (int)$limit));
    $name   = ntext((string)($p['name']   ?? ''));
    $winery = ntext((string)($p['winery'] ?? ''));
    $region = ntext((string)($p['region'] ?? ''));
    $grapes = ntext((string)($p['grapes'] ?? ''));
    $vint   = normalize_vintage($p['vintage'] ?? null);

    // Build broad WHERE with ORs so we don’t miss candidates
    $where = [];
    if ($name !== '')   $where[] = "(LOWER(name)   LIKE :name_like OR LOWER(winery) LIKE :name_like)";
    if ($winery !== '') $where[] = "LOWER(winery) LIKE :winery_like";
    if ($region !== '') $where[] = "LOWER(region) LIKE :region_like";
    if ($grapes !== '') $where[] = "LOWER(grapes) LIKE :grapes_like";
    $cond = $where ? ('('.implode(') OR (', $where).')') : '1';

    $sql = "
      SELECT
        id, name, winery, region, country, vintage, grapes, type, style, image_url,
        (
          (CASE WHEN LOWER(name)   = :name_exact   THEN 9 ELSE 0 END) +
          (CASE WHEN LOWER(winery) = :winery_exact THEN 7 ELSE 0 END) +
          (CASE WHEN LOWER(name)   LIKE :name_pref THEN 5 ELSE 0 END) +
          (CASE WHEN LOWER(name)   LIKE :name_like THEN 3 ELSE 0 END) +
          (CASE WHEN LOWER(winery) LIKE :name_like THEN 2 ELSE 0 END) +
          (CASE WHEN :vintage_val IS NOT NULL AND vintage = :vintage_val THEN 4 ELSE 0 END)
        ) AS score
      FROM wines
      WHERE $cond
      ORDER BY score DESC, id DESC
      LIMIT :lim
    ";
    $st = $pdo->prepare($sql);

    $rawName   = (string)($p['name']   ?? '');
    $rawWinery = (string)($p['winery'] ?? '');

    $st->bindValue(':name_exact',   mb_strtolower($rawName,   'UTF-8'), PDO::PARAM_STR);
    $st->bindValue(':winery_exact', mb_strtolower($rawWinery, 'UTF-8'), PDO::PARAM_STR);

    $st->bindValue(':name_pref', $name !== '' ? $name.'%' : '', PDO::PARAM_STR);
    $st->bindValue(':name_like', $name !== '' ? '%'.$name.'%' : '%', PDO::PARAM_STR);

    if ($name !== '')   $st->bindValue(':name_like',   '%'.$name.'%',   PDO::PARAM_STR);
    if ($winery !== '') $st->bindValue(':winery_like', '%'.$winery.'%', PDO::PARAM_STR);
    if ($region !== '') $st->bindValue(':region_like', '%'.$region.'%', PDO::PARAM_STR);
    if ($grapes !== '') $st->bindValue(':grapes_like', '%'.$grapes.'%', PDO::PARAM_STR);

    if ($vint !== null) $st->bindValue(':vintage_val', $vint, PDO::PARAM_STR);
    else                $st->bindValue(':vintage_val', null, PDO::PARAM_NULL);

    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Backward-compatible free-text search (keeps API some code expects).
 */
function search_wines(PDO $pdo, string $q, int $limit = 10): array {
    $q = trim($q);
    // detect an inline vintage
    $v = null;
    if (preg_match('/\b(19|20)\d{2}\b/', $q, $m)) $v = $m[0];
    // split first tokens into name/winery-ish
    $parts = preg_split('/\s+/', $q);
    $name = implode(' ', array_slice($parts, 0, 2)); // heuristic
    $win  = implode(' ', array_slice($parts, 2));
    return search_wines_params($pdo, [
        'name'   => $name,
        'winery' => $win,
        'vintage'=> $v,
    ], $limit);
}

/**
 * Best match for AI parse without barcode, tolerant to punctuation/case.
 * $ai may contain: name, winery, vintage, region, grapes.
 */
function best_catalog_match(PDO $pdo, array $ai): ?array {
    $name    = trim((string)($ai['name']    ?? ''));
    $winery  = trim((string)($ai['winery']  ?? ''));
    $vintage = normalize_vintage($ai['vintage'] ?? null);
    $region  = trim((string)($ai['region']  ?? ''));
    $grapes  = trim((string)($ai['grapes']  ?? ''));

    // First, try a targeted multi-field search
    $rows = search_wines_params($pdo, [
        'name'   => $name,
        'winery' => $winery,
        'vintage'=> $vintage,
        'region' => $region,
        'grapes' => $grapes,
    ], 10);

    if (!$rows) {
        // fallback: looser name+winery or just name
        $rows = search_wines_params($pdo, [
            'name'   => $name ?: $winery,
            'winery' => $winery ?: $name,
            'vintage'=> $vintage
        ], 10);
    }
    if (!$rows) return null;

    // Re-rank a bit with normalized string similarity
    $an = ntext($name); $aw = ntext($winery);
    $best = null; $bestScore = -INF;

    foreach ($rows as $r) {
        $rn = ntext((string)($r['name']   ?? ''));
        $rw = ntext((string)($r['winery'] ?? ''));
        $score = 0.0;

        if ($an !== '' && $rn === $an) $score += 5;
        if ($aw !== '' && $rw === $aw) $score += 4;

        if ($an !== '' && str_starts_with($rn, $an)) $score += 2;
        if ($aw !== '' && str_starts_with($rw, $aw)) $score += 2;

        if ($vintage && (string)($r['vintage'] ?? '') === (string)$vintage) $score += 2;

        // light grapes reinforcement
        $ag = ntext($grapes);
        $rg = ntext((string)($r['grapes'] ?? ''));
        if ($ag !== '' && $rg !== '') {
            $as = array_filter(explode(' ', $ag));
            $rs = array_filter(explode(' ', $rg));
            if ($as && $rs) {
                $inter = array_intersect($as, $rs);
                if (count($inter) >= 1) $score += 1.5;
            }
        }

        if ($score > $bestScore) { $bestScore = $score; $best = $r; }
    }
    return $best;
}
