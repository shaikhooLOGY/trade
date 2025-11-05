<?php
// Logout handler - uses centralized session management
require_once __DIR__ . '/includes/bootstrap.php';
session_unset();
session_destroy();
// Remove session cookie
setcookie(session_name(), '', time() - 3600, '/');
$baseUrl = getenv('BASE_URL') ?: '/';
header('Location: ' . rtrim($baseUrl, '/') . '/login.php');
exit;