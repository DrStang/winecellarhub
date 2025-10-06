<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
require 'auth.php';

if (is_logged_in()) {
    header("Location: home.php");
} else {
    header("Location: login.php");
}
exit();
