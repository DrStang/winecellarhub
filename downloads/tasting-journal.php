<?php
require_once __DIR__.'/../db.php';
$token = $_GET['token'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(403); exit; }
$st = $wine_pdo->prepare("SELECT p.*, d.file_path
  FROM purchases p
  JOIN digital_products d ON d.slug=p.item_slug
  WHERE p.download_token=? AND p.status='paid' AND p.item_type='product'");
$st->execute([$token]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || !is_file($row['file_path'])) { http_response_code(404); exit; }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.basename($row['file_path']).'"');
readfile($row['file_path']);
