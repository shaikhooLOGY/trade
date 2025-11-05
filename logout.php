<?php
session_start();
session_unset();
session_destroy();
// Remove session cookie
setcookie(session_name(), '', time() - 3600, '/');
header('Location: https://tradersclub.shaikhoology.com/login.php');
exit;