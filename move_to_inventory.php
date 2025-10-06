<?php
require 'db.php';
require 'auth.php';
require_login();

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$wine_id = isset($_POST['wine_id']) ? (int)$_POST['wine_id'] : 0;
$type    = isset($_POST['type']) ? trim($_POST['type']) : '';
$vintage = isset($_POST['vintage']) ? trim($_POST['vintage']) : '';

if ($wine_id <= 0) {
    http_response_code(400);
    exit('Missing wine_id');
}

// Detect optional columns on bottles
$cols = $pdo->query("SHOW COLUMNS FROM bottles")->fetchAll(PDO::FETCH_ASSOC);
$hasType = $hasVintage = false;
foreach ($cols as $c) {
    $f = strtolower($c['Field']);
    if ($f === 'type') $hasType = true;
    if ($f === 'vintage') $hasVintage = true;
}

$sql = "INSERT INTO bottles (user_id, wine_id";
$vals = "VALUES (:uid, :wid";
$params = [':uid'=>$user_id, ':wid'=>$wine_id];

if ($hasType)   { $sql .= ", type";    $vals .= ", :type";    $params[':type'] = $type; }
if ($hasVintage){ $sql .= ", vintage"; $vals .= ", :vintage"; $params[':vintage'] = $vintage; }

$sql .= ") ".$vals.")";

$st = $pdo->prepare($sql);
$st->execute($params);

// Redirect back to home
header("Location: home.php");
