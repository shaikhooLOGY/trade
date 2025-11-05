<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/guard.php';

header('Content-Type: application/json');
require_login();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo json_encode(['csrf_token' => $_SESSION['csrf_token'] ?? '']);