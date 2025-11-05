<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/includes/env.php')) {
    require_once __DIR__ . '/includes/env.php';
}

// -------- Error reporting by env --------
$appEnv = getenv('APP_ENV') ?: 'local';
if ($appEnv === 'local') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
}

// -------- Timezone --------
date_default_timezone_set('Asia/Kolkata');

// -------- DB credentials (from .env or sensible defaults) --------
$dbHost    = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort    = (int)(getenv('DB_PORT') ?: 3306);
$dbName    = getenv('DB_DATABASE') ?: 'shaikhoology';
$dbUser    = getenv('DB_USERNAME') ?: 'shaikh_local';
$dbPass    = getenv('DB_PASSWORD') ?: 'StrongLocalPass!23';

// -------- MySQLi connect --------
$mysqli = @new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($mysqli->connect_errno) {
    error_log('DB connect failed: ' . $mysqli->connect_error);
    throw new mysqli_sql_exception(
        'DB connect failed (' . $mysqli->connect_errno . '): ' . $mysqli->connect_error
    );
}
$mysqli->set_charset('utf8mb4');

// Optional safety: ensure expected DB selected (strict only on prod)
if (function_exists('db_assert_database')) {
    db_assert_database($mysqli, $dbName, $appEnv === 'prod');
}

// -------- Small helpers (guarded) --------
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
}
if (!function_exists('current_user_id')) {
    function current_user_id(): ?int { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
}
if (!function_exists('require_login')) {
    function require_login(): void {
        if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
    }
}
if (!function_exists('require_active_user')) {
    function require_active_user(): void {
        $status = strtolower((string)($_SESSION['status'] ?? ''));
        $emailVerified = (int)($_SESSION['email_verified'] ?? 0);
        if (!is_logged_in() || $emailVerified !== 1 || !in_array($status, ['active','approved'], true)) {
            header('Location: /pending_approval.php'); exit;
        }
    }
}
if (!function_exists('require_admin')) {
    function require_admin(): void {
        if (empty($_SESSION['is_admin'])) { http_response_code(403); exit('Forbidden'); }
    }
}