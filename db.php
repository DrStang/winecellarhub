<?php
// db.php — Shared database connection
#session_start();
require __DIR__.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();



$host = '100.66.175.61:3306';
$db   = 'Wine';
$user = 'wine';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

//function is_logged_in() {
 //   return isset($_SESSION['user_id']);
//}
// db.php
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return !empty($_SESSION['user_id']);
    }
}

#function is_admin() {
#    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
#}

#function require_login() {
#    if (!is_logged_in()) {
#        header('Location: login.php');
#        exit();
#    }
#}

function require_admin() {
    if (!is_admin()) {
        header('Location: index.php');
        exit();
    }
}
// --- Optional second DB connection for winelist ---
$winelist_host = '100.66.175.61:3306';
$winelist_db   = 'winelist';
$winelist_user = 'winelist';
$winelist_pass = '';

try {
    $winelist_pdo = new PDO("mysql:host=$winelist_host;dbname=$winelist_db;charset=utf8mb4", $winelist_user, $winelist_pass);
    $winelist_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Winelist DB connection failed: " . $e->getMessage());
}
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env') as $line) {
        if (preg_match('/^\s*([^#=]+?)\s*=\s*(.*?)\s*$/', $line, $matches)) {
            $_ENV[$matches[1]] = $matches[2];
        }
    }
}
