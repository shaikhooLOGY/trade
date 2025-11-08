<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "1. start\n";
require_once __DIR__.'/includes/bootstrap.php';
echo "2. bootstrap ok\n";

// minimal CSRF sanity
if (!function_exists('csrf_token')) { echo "csrf_token missing\n"; exit; }
$t = csrf_token();
echo "3. csrf ok\n";

// minimal DB sanity (optional)
global $mysqli;
if (!$mysqli || !$mysqli->ping()) { echo "4. mysqli not ready\n"; exit; }
echo "4. mysqli ok\n";

echo "DONE\n";