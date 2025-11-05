<?php
// debug_all.php — quick diagnostic (safe to remove after)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>debug_all.php</h2>";

// 1) PHP info (first 250 chars)
echo "<h3>PHP Version</h3>";
echo phpversion() . "<br>";

// 2) show current user + path
echo "<h3>Script paths</h3>";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? '') . "<br>";
echo "__DIR__: " . __DIR__ . "<br>";

// 3) try include config.php and report
echo "<h3>Include config.php</h3>";
$cfg = __DIR__ . '/config.php';
if (file_exists($cfg)) {
    echo "config.php found at $cfg<br>";
    try {
        require_once $cfg;
        echo "Included config.php OK.<br>";
    } catch (Throwable $e) {
        echo "Include threw: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
} else {
    echo "config.php NOT FOUND at $cfg<br>";
}

// 4) check $mysqli
echo "<h3>\$mysqli</h3>";
if (isset($mysqli) && ($mysqli instanceof mysqli)) {
    echo "mysqli exists, server: " . htmlspecialchars($mysqli->host_info) . "<br>";
    $res = $mysqli->query("SELECT DATABASE() AS db");
    echo "Connected DB: " . ($res ? htmlspecialchars($res->fetch_assoc()['db']) : 'query failed: '.$mysqli->error) . "<br>";
} else {
    echo "\$mysqli not set or not mysqli instance.<br>";
}

// 5) show last PHP error if any
echo "<h3>Last PHP error</h3>";
$l = error_get_last();
if ($l) {
    echo "<pre>" . htmlspecialchars(print_r($l, true)) . "</pre>";
} else {
    echo "No last error recorded.<br>";
}

// 6) check .htaccess existence
echo "<h3>.htaccess</h3>";
$ht = __DIR__ . '/.htaccess';
echo file_exists($ht) ? ".htaccess exists at $ht<br>" : ".htaccess not found<br>";

// 7) quick file perms for important files
echo "<h3>File perms</h3>";
function fperm($f){ if(!file_exists($f)) return 'MISSING'; $p = substr(sprintf('%o', fileperms($f)), -4); return $p; }
echo "config.php perms: " . fperm(__DIR__.'/config.php') . "<br>";
echo "login.php perms: " . fperm(__DIR__.'/login.php') . "<br>";
echo "header.php perms: " . fperm(__DIR__.'/header.php') . "<br>";

echo "<hr><small>Remove this file after debugging.</small>";