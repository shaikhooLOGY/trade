<?php
require __DIR__.'/config.php';
$r = $mysqli->query("SELECT COUNT(*) c FROM mtm_models");
$row = $r->fetch_assoc();
echo "<h3>DB OK. mtm_models count: ".(int)$row['c']."</h3>";
echo '<p><a href="/local_login.php">Local login as admin</a></p>';
