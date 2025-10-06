<?php

require_once __DIR__ . '/../db.php';

$pdo->exec("DELETE FROM user_remember_tokens WHERE expires_at < NOW()");

?>