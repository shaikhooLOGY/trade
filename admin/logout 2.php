<?php
require_once __DIR__ . '/../includes/bootstrap.php';

session_destroy();
// go back to main login in competition folder
header("Location: ../login.php");
exit;