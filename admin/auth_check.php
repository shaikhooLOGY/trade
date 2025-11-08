<?php
require __DIR__ . '/../config.php';
if (!is_logged_in() || empty($_SESSION['is_admin'])) {
    header('Location: ../login.php');
    exit;
}
?>