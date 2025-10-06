<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_login();
header('Content-Type: application/json');

// very simple admin gate; adapt to your role system
if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

// CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400); echo json_encode(['error'=>'bad_request']); exit;
}

$action = $_POST['action'] ?? '';
$wine_id = isset($_POST['wine_id']) ? (int)$_POST['wine_id'] : 0;

$php = escapeshellcmd(PHP_BINARY);
$root = realpath(__DIR__ . '/..');

if ($action === 'insights_one' && $wine_id > 0) {
    // run in background so the request returns immediately
    $cmd = "nohup {$php} {$root}/cron/cron_ai_insights.php --wine-id={$wine_id} --force >> /var/log/wine/ai_insights_manual.log 2>&1 & echo $!";
    $pid = trim(shell_exec($cmd) ?? '');
    echo json_encode(['ok'=>true,'pid'=>$pid]); exit;
}

if ($action === 'insights_inventory') {
    // prioritize users' inventory in background
    $cmd = "nohup {$php} {$root}/cron/cron_ai_insights_inventory.php --batch=800 --force >> /var/log/wine/ai_insights_inv_manual.log 2>&1 & echo $!";
    $pid = trim(shell_exec($cmd) ?? '');
    echo json_encode(['ok'=>true,'pid'=>$pid]); exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown_action']);

