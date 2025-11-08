<?php
// dbtest.php - simple DB connection health check
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php'; // आपकी config.php वहीँ होनी चाहिए

echo "<h3>DB test</h3>";

if (!isset($mysqli)) {
    echo "<b>\$mysqli not set — config.php may not have created connection.</b><br>";
    exit;
}

if ($mysqli->connect_errno) {
    echo "<b>Connect error:</b> " . htmlspecialchars($mysqli->connect_error);
    exit;
}

$row = $mysqli->query("SELECT DATABASE()")->fetch_row();
echo "Connected OK. Current DB: " . htmlspecialchars($row[0]) . "<br>";

$res = $mysqli->query("SELECT COUNT(*) FROM users");
if ($res) {
    $c = $res->fetch_row()[0];
    echo "Users table rows: " . (int)$c . "<br>";
} else {
    echo "Could not query users table: " . htmlspecialchars($mysqli->error) . "<br>";
}
?>