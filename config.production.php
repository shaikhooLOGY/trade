<?php
/**
 * config.production.php — Production Environment Configuration
 * Uses environment variables for secure deployment
 * 
 * For live server deployment, copy to config.php
 */

require_once __DIR__ . '/includes/env.php';

// Production Environment Configuration
ini_set('display_errors', 0); // DISABLED for production
error_reporting(0); // DISABLED for production

if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Kolkata');

// ======================
// Database Configuration (Production)
// ======================

// Use environment variables from .env.production or server env vars
$DB_HOST = $GLOBALS['DB_HOST'] ?? $_ENV['DB_HOST'] ?? 'localhost';
$DB_USER = $GLOBALS['DB_USER'] ?? $_ENV['DB_USER'] ?? 'u613260542_tcmtm';
$DB_PASS = $GLOBALS['DB_PASS'] ?? $_ENV['DB_PASS'] ?? 'TC@Shaikhoology25';
$DB_NAME = $GLOBALS['DB_NAME'] ?? $_ENV['DB_NAME'] ?? 'u613260542_tcmtm';

// Establish database connection
$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($mysqli->connect_errno) {
    // Log error to file instead of displaying
    $error = "❌ DB connection failed: (" . $mysqli->connect_errno . ") " . htmlspecialchars($mysqli->connect_error);
    error_log($error, 3, __DIR__ . '/logs/database_errors.log');
    die("Database connection failed. Please contact support.");
}

$mysqli->set_charset('utf8mb4');

// ======================
// SMTP Configuration (Production)
// ======================
$SMTP_HOST = $GLOBALS['SMTP_HOST'] ?? $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
$SMTP_PORT = $GLOBALS['SMTP_PORT'] ?? $_ENV['SMTP_PORT'] ?? 587;
$SMTP_SECURE = $GLOBALS['SMTP_SECURE'] ?? $_ENV['SMTP_SECURE'] ?? 'tls';
$SMTP_USER = $GLOBALS['SMTP_USER'] ?? $_ENV['SMTP_USER'] ?? 'help@shaikhoology.com';
$SMTP_PASS = $GLOBALS['SMTP_PASS'] ?? $_ENV['SMTP_PASS'] ?? 'TC@Shaikhoology25';
$MAIL_FROM = $GLOBALS['MAIL_FROM'] ?? $_ENV['MAIL_FROM'] ?? 'help@shaikhoology.com';
$MAIL_FROM_NAME = $GLOBALS['MAIL_FROM_NAME'] ?? $_ENV['MAIL_FROM_NAME'] ?? 'Shaikhoology — Trading Psychology';

// Define SMTP constants
if (!defined('SMTP_HOST')) define('SMTP_HOST', $SMTP_HOST);
if (!defined('SMTP_PORT')) define('SMTP_PORT', $SMTP_PORT);
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', $SMTP_SECURE);
if (!defined('SMTP_USER')) define('SMTP_USER', $SMTP_USER);
if (!defined('SMTP_PASS')) define('SMTP_PASS', $SMTP_PASS);
if (!defined('MAIL_FROM')) define('MAIL_FROM', $MAIL_FROM);
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', $MAIL_FROM_NAME);

// ======================
// Base / Site URL (Production)
// ======================
if (!defined('SITE_URL')) define('SITE_URL', 'https://tradersclub.shaikhoology.com');

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
            'username' => $_SESSION['username'] ?? 'user',
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

// ======================
// Production Security Settings
// ======================

// Enable error logging but disable display
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Set security headers (can be added via .htaccess or headers)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
}

// Session security for production
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Only if using HTTPS
ini_set('session.use_strict_mode', 1);

// Log successful connection (for monitoring)
error_log("Database connection established successfully to {$DB_NAME}", 3, __DIR__ . '/logs/database_connect.log');