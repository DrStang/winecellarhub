<?php
require_once __DIR__ . '/../db.php';

/**
 * Build a compact text for embedding from catalog + AI notes.
 */
function wine_text_for_embedding(array $w, ?string $notes_md): string {
    $parts = [];
    $parts[] = $w['name'] ?? '';
    $parts[] = $w['winery'] ?? '';
    $parts[] = $w['country'] ?? '';
    $parts[] = $w['region'] ?? '';
    $parts[] = $w['type'] ?? '';
    $parts[] = $w['grapes'] ?? '';
    $parts[] = $w['style'] ?? '';
    if (!empty($w['vintage'])) $parts[] = 'vintage '.$w['vintage'];
    if (!empty($w['food_pairings'])) $parts[] = 'pairings: '.$w['food_pairings'];
    if ($notes_md) $parts[] = 'ai_notes: '.preg_replace('/\s+/', ' ', strip_tags($notes_md));
    return trim(implode(' | ', array_filter($parts)));
}

/**
 * Call OpenAI embeddings API.
 */
function embed_text(string $text, string $model='text-embedding-3-small'): array {
    $key = $_ENV['OPENAI_API_KEY'] ?? null;
    if (!$key) return [];
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$key,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode(['input'=>$text, 'model'=>$model]),
    ]);
    $res = curl_exec($ch);
    if (!$res) return [];
    $json = json_decode($res, true);
    $vec = $json['data'][0]['embedding'] ?? null;
    return (is_array($vec) ? $vec : []);
}

// Select wines missing embeddings or stale (>30d)
$rows = $winelist_pdo->query("
  SELECT w.id, w.name, w.winery, w.country, w.region, w.type, w.style, w.grapes, w.vintage, w.food_pairings,
         a.notes_md,
         e.updated_at AS emb_updated
  FROM wines w
  LEFT JOIN wines_ai a ON a.wine_id = w.id
  LEFT JOIN wine_embeddings e ON e.wine_id = w.id
  WHERE e.wine_id IS NULL OR e.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
  ORDER BY w.created_at DESC
  LIMIT 1000
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "Nothing to embed.\n"; exit; }

$ins = $winelist_pdo->prepare("
  INSERT INTO wine_embeddings (wine_id, embedding, model, updated_at)
  VALUES (?,?,?,NOW())
  ON DUPLICATE KEY UPDATE embedding=VALUES(embedding), model=VALUES(model), updated_at=NOW()
");

$count = 0;
foreach ($rows as $w) {
    $text = wine_text_for_embedding($w, $w['notes_md'] ?? null);
    if ($text === '') continue;

    $vec = embed_text($text);
    if (!$vec) continue;

    $ins->execute([$w['id'], json_encode($vec), 'text-embedding-3-small']);
    $count++;
}
echo "Embedded/updated: $count\n";

