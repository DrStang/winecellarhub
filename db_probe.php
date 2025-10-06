<?php
$t = microtime(true); require 'db.php';
echo "require: ".round(microtime(true)-$t,3)."s\n";
$t = microtime(true); echo "app: ".$pdo->query("SELECT 1")->fetchColumn()." (".round(microtime(true)-$t,3)."s)\n";
$t = microtime(true); echo "catalog: ".$winelist_pdo->query("SELECT 1")->fetchColumn()." (".round(microtime(true)-$t,3)."s)\n";
