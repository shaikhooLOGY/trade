<?php
require_once __DIR__ . '/includes/env.php';

// config.php — Local Dev Config
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Kolkata');

// ======================
// Database (Local)
// ======================
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'traders_local';

$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    die("❌ DB connection failed: (" . $mysqli->connect_errno . ") " . htmlspecialchars($mysqli->connect_error));
}
$mysqli->set_charset('utf8mb4');

// ======================
// Base / Site URL
// ======================
if (!defined('SITE_URL')) define('SITE_URL', 'http://localhost:8000');

// ======================
// Common helpers
// ======================
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return !empty($_SESSION['user_id']);
    }
}
if (!function_exists('current_user')) {
    function current_user(): ?array {
        if (empty($_SESSION['user_id'])) return null;
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'local_user',
            'is_admin' => $_SESSION['is_admin'] ?? false,
        ];
    }
}
if (!function_exists('site_url')) {
    function site_url(string $path = ''): string {
        $base = rtrim(SITE_URL, '/');
        $path = ltrim($path, '/');
        return $path ? "{$base}/{$path}" : $base;
    }
}
