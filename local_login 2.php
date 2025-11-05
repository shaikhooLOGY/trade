<?php
require __DIR__.'/config.php';
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['is_admin'] = 1;
header('Location: /local_whoami.php');
exit;
