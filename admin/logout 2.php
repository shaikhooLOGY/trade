<?php
session_start();
session_destroy();
// go back to main login in competition folder
header("Location: ../login.php");
exit;