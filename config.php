<?php
// config.php — Production Database Configuration
// Fixed version for Hostinger deployment

// Environment configuration
if (!defined('APP_ENV')) {
    $appEnv = 'prod'; // Set production environment by default
    define('APP_ENV', $appEnv);
}

// Database configuration with fallback
function get_db_config(): array {
    // Try to load from environment first
    $host = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? 'localhost';
    $user = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? 'u613260542_tcmtm';
    $pass = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? 'TC@Shaikhoology25';
    $name = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? 'u613260542_tcmtm';
    
    return [
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'name' => $name
    ];
}

// Legacy db() function for backward compatibility
if (!function_exists('db')) {
    function db(): mysqli {
        static $connection = null;
        
        if ($connection instanceof mysqli && $connection->ping()) {
            return $connection;
        }
        
        try {
            $config = get_db_config();
            
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
                
                // Log error but don't expose sensitive info
                error_log("MySQL connection failed - User: {$config['user']}, DB: {$config['name']}, Error: ({$connection->connect_errno})", 3, __DIR__ . '/logs/php_errors.log');
                
                if (APP_ENV === 'prod' || APP_ENV === 'production') {
                    die("Database connection failed. Please contact support.");
                } else {
                    die("❌ Database connection failed: " . $error);
                }
            }
            
            $connection->set_charset('utf8mb4');
            
            // Log successful connection in production
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

// Create database connection and $mysqli global
try {
    $config = get_db_config();
    
    $mysqli = @new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $config['name']
    );
    
    if ($mysqli->connect_errno) {
        $error = sprintf(
            "DB connection failed: (%d) %s",
            $mysqli->connect_errno,
            $mysqli->connect_error
        );
        
        // Log error but don't expose sensitive info
        error_log("MySQL connection failed - User: {$config['user']}, DB: {$config['name']}, Error: ({$mysqli->connect_errno})", 3, __DIR__ . '/logs/php_errors.log');
        
        if (APP_ENV === 'prod' || APP_ENV === 'production') {
            die("Database connection failed. Please contact support.");
        } else {
            die("❌ Database connection failed: " . $error);
        }
    }
    
    $mysqli->set_charset('utf8mb4');
    
    // Log successful connection in production
    if (APP_ENV === 'prod' || APP_ENV === 'production') {
        error_log("Database connection established to {$config['name']}", 3, __DIR__ . '/logs/database_connect.log');
    }
    
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

// Error reporting settings
if (APP_ENV === 'prod' || APP_ENV === 'production') {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = (defined('APP_ENV') && APP_ENV === 'prod') ? 60*60*2 : 60*60*12; // 2h prod, 12h local
    @ini_set('session.gc_maxlifetime', $lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Site URL configuration
if (!defined('SITE_URL')) {
    if (APP_ENV === 'prod' || APP_ENV === 'production') {
        define('SITE_URL', 'https://tradersclub.shaikhoology.com');
    } else {
        define('SITE_URL', 'http://localhost:8000');
    }
}

// Helper functions
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

if (!function_exists('h')) {
    function h(string $s): string { 
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); 
    }
}
