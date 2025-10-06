<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user_id = $_SESSION['user_id'];

/** Simple food->types map (customize anytime) */
$PAIRINGS = [
  'steak / burgers' => ['red'],
  'roast chicken' => ['white','rose','red'],
  'seafood (light)' => ['white','sparkling','rose'],
  'spicy asian' => ['white','rose','sparkling'],
  'pasta (red sauce)' => ['red'],
  'pasta (cream)' => ['white','sparkling'],
  'cheese board' => ['red','white','sparkling','rose','fortified'],
  'desserts' => ['dessert','fortified'],
  'bbq / smoked' => ['red','rose'],
  'tapas / cured meats' => ['red','rose','fortified'],
];

$choice = strtolower(trim($_GET['food'] ?? ''));
$types = $PAIRINGS[$choice] ?? [];

$results = [];
try {
  if ($types) {
    $ids = [];
    if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
      // Get wine_ids by type(s)
      $in = implode(',', array_fill(0, count($types), '?'));
      $s = $winelist_pdo->prepare("SELECT id FROM wines WHERE LOWER(type) IN ($in) LIMIT 2000");
      $s->execute(array_map('strtolower', $types));
      $ids = array_map(fn($r)=> (int)$r['id'], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($ids) {
      // Fetch user's bottles with those ids, and then pull details to display
      $place = implode(',', array_fill(0, count($ids), '?'));
      $b = $pdo->prepare("SELECT id AS bottle_id, wine_id FROM bottles WHERE user_id = ? AND wine_id IN ($place) LIMIT 200");
      $b->execute(array_merge([$user_id], $ids));
      $bottles = $b->fetchAll(PDO::FETCH_ASSOC);

      if ($bottles && isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        $wineIds = array_values(array_unique(array_map(fn($x)=> (int)$x['wine_id'], $bottles)));
        if ($wineIds) {
          $place2 = implode(',', array_fill(0, count($wineIds), '?'));
          $w = $winelist_pdo->prepare("
            SELECT id, name, winery, region, type, vintage
            FROM wines WHERE id IN ($place2)
          ");
          $w->execute($wineIds);
          $map = [];
          foreach ($w->fetchAll(PDO::FETCH_ASSOC) as $row) { $map[(int)$row['id']] = $row; }
          foreach ($bottles as $btl) {
            $info = $map[(int)$btl['wine_id']] ?? [];
            $results[] = array_merge(['bottle_id'=>$btl['bottle_id']], $info);
          }
        }
      }
    } else {
      // Fallback: use bottles.type when present
      $cols = $pdo->query('SHOW COLUMNS FROM bottles')->fetchAll(PDO::FETCH_ASSOC);
      $hasType = false;
      foreach ($cols as $c) if (strtolower($c['Field'])==='type') { $hasType=true; break; }
      if ($hasType) {
        $in = implode(',', array_fill(0,count($types),'?'));
        $stmt = $pdo->prepare("
          SELECT id AS bottle_id, wine_id, type, vintage, location
          FROM bottles
          WHERE user_id = ? AND LOWER(type) IN ($in)
          ORDER BY vintage DESC
          LIMIT 200
        ");
        $params = array_merge([$user_id], array_map('strtolower', $types));
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }
    }
  }
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/head.php'; ?>

    <meta charset="UTF-8" />
  <title>Pairing</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
<?php require __DIR__ . '/partials/header.php'; ?>
<div class="max-w-5xl mx-auto p-6">
    <h1 class="text-3xl font-bold mb-4">🍽️ Pairing</h1>
    <form method="get" class="bg-white rounded-2xl shadow p-4 flex flex-wrap gap-3 items-center">
      <label for="food" class="text-sm">What are you eating?</label>
      <select id="food" name="food" class="border rounded-lg p-2">
        <option value="">Select a dish...</option>
        <?php foreach ($PAIRINGS as $food => $_): ?>
          <option value="<?= htmlspecialchars($food) ?>" <?= $choice===$food?'selected':'' ?>><?= htmlspecialchars(ucfirst($food)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="px-4 py-2 bg-indigo-600 text-white rounded-xl">Find Pairings</button>
      <a class="px-4 py-2 rounded-xl border" href="home.php">Back</a>
    </form>

    <?php if ($choice && !$results): ?>
      <p class="mt-6 text-gray-600">No matches in your inventory for <strong><?= htmlspecialchars($choice) ?></strong>.</p>
    <?php endif; ?>

    <?php if ($results): ?>
      <div class="mt-6 bg-white rounded-2xl shadow divide-y">
        <?php foreach ($results as $w): ?>
          <div class="p-4 flex items-center justify-between">
            <div>
              <div class="font-medium"><?= htmlspecialchars($w['name'] ?? ('Bottle #'.$w['bottle_id'])) ?></div>
              <div class="text-sm text-gray-600">
                <?= htmlspecialchars(($w['winery'] ?? '')) ?> <?= !empty($w['winery']) ? '· ' : '' ?>
                <?= htmlspecialchars(($w['region'] ?? '')) ?> <?= !empty($w['region']) ? '· ' : '' ?>
                <?= htmlspecialchars(strtolower($w['type'] ?? '')) ?> <?= !empty($w['type']) ? '· ' : '' ?>
                <?= htmlspecialchars(($w['vintage'] ?? '')) ?>
              </div>
            </div>
            <a class="text-indigo-600 hover:underline" href="edit_bottle.php?id=<?= (int)$w['bottle_id'] ?>">Open →</a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
<?php require __DIR__ . '/partials/footer.php'; ?>

</html>
