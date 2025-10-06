<?php
$log_file = '/mnt/wine/logs/trigger_log.json';
$data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) : [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Scraper Monitor</title>
    <meta http-equiv="refresh" content="15">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-900 p-8">
    <h1 class="text-3xl font-bold mb-6">📡 Bright Data Scraper Monitor</h1>
    <table class="min-w-full bg-white shadow rounded">
        <thead>
            <tr class="bg-gray-200 text-left">
                <th class="p-3">Keyword</th>
                <th class="p-3">Source</th>
                <th class="p-3">Dataset ID</th>
                <th class="p-3">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($data as $entry): ?>
            <?php
                $status = $entry['status'] ?? 'pending';
                $color = $status === 'done' ? 'text-green-600' : ($status === 'error' ? 'text-red-600' : 'text-yellow-600');
            ?>
            <tr class="border-b">
                <td class="p-3"><?= htmlspecialchars($entry['keyword']) ?></td>
                <td class="p-3"><?= htmlspecialchars($entry['source']) ?></td>
                <td class="p-3 text-xs text-gray-500"><?= htmlspecialchars($entry['dataset_id'] ?? '—') ?></td>
                <td class="p-3 font-semibold <?= $color ?>"><?= htmlspecialchars($status) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="text-sm text-gray-500 mt-6">⏱ Auto-refreshing every 15 seconds</p>
</body>
</html>
