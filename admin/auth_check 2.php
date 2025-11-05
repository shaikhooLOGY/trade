<?php
require __DIR__ . '/../config.php';
if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php'); exit;
}
?>