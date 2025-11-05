<?php
// Config file - DB connection and environment setup
// Session management and auth functions are now in bootstrap.php
// This file should be included via bootstrap.php

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

// -------- Database helpers --------
if (!function_exists('current_user_id')) {
    function current_user_id(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}