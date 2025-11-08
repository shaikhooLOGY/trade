<?php
require_once __DIR__ . '/includes/env.php';

// config.php — Local Dev Config
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Kolkata');

// Load SMTP configuration from .env for email functionality
if (defined('SMTP_HOST')) {
    // SMTP settings are already loaded from .env
} else {
    // Check if SMTP settings exist in environment
    $smtpHost = getenv('SMTP_HOST') ?: (isset($GLOBALS['SMTP_HOST']) ? $GLOBALS['SMTP_HOST'] : null);
    if ($smtpHost) {
        define('SMTP_HOST', $smtpHost);
        define('SMTP_PORT', getenv('SMTP_PORT') ?: (isset($GLOBALS['SMTP_PORT']) ? $GLOBALS['SMTP_PORT'] : 587));
        define('SMTP_SECURE', getenv('SMTP_SECURE') ?: (isset($GLOBALS['SMTP_SECURE']) ? $GLOBALS['SMTP_SECURE'] : 'tls'));
        define('SMTP_USER', getenv('SMTP_USER') ?: (isset($GLOBALS['SMTP_USER']) ? $GLOBALS['SMTP_USER'] : ''));
        define('SMTP_PASS', getenv('SMTP_PASS') ?: (isset($GLOBALS['SMTP_PASS']) ? $GLOBALS['SMTP_PASS'] : ''));
        define('MAIL_FROM', getenv('MAIL_FROM') ?: (isset($GLOBALS['MAIL_FROM']) ? $GLOBALS['MAIL_FROM'] : (defined('SMTP_USER') ? SMTP_USER : 'no-reply@example.com')));
        define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: (isset($GLOBALS['MAIL_FROM_NAME']) ? $GLOBALS['MAIL_FROM_NAME'] : 'Shaikhoology'));
    }
}

// ======================
// Database Configuration
// ======================

/**
 * Centralized database connection function
 * Uses environment configuration with production safeguards
 */
if (!function_exists('db')) {
    function db(): mysqli {
        static $connection = null;
        
        if ($connection instanceof mysqli && $connection->ping()) {
            return $connection;
        }
        
        try {
            $config = db_config();
            
            $connection = @new mysqli(
                $config['host'],
                $config['user'],
                $config['pass'],
                $config['name']
            );
            
            if ($connection->connect_errno) {
                $error = sprintf(
                    "DB connection failed: (%d) %s",
                    $connection->connect_errno,
                    $connection->connect_error
                );
                
                // Log detailed error but don't expose secrets
                $logMessage = sprintf(
                    "MySQL connection failed - Host: %s, User: %s, DB: %s, Error: (%d) %s",
                    $config['host'],
                    $config['user'],
                    $config['name'],
                    $connection->connect_errno,
                    $connection->connect_error
                );
                error_log($logMessage, 3, __DIR__ . '/logs/php_errors.log');
                
                throw new Exception("Database connection failed. Please contact support.");
            }
            
            $connection->set_charset('utf8mb4');
            
            // Log successful connection (monitoring)
            if (APP_ENV === 'prod' || APP_ENV === 'production') {
                error_log("Database connection established to {$config['name']}", 3, __DIR__ . '/logs/database_connect.log');
            }
            
            return $connection;
        } catch (Exception $e) {
            $logMessage = sprintf(
                "Database connection error: %s in %s:%d",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
            error_log($logMessage, 3, __DIR__ . '/logs/php_errors.log');
            throw $e;
        }
    }
}

// Create legacy $mysqli global for existing code
try {
    $mysqli = db();
} catch (Exception $e) {
    // In production, don't expose error details
    if (APP_ENV === 'prod' || APP_ENV === 'production') {
        die("Database connection failed. Please contact support.");
    } else {
        die("❌ " . $e->getMessage());
    }
}

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
