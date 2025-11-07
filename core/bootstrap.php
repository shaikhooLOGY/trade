<?php
/**
 * core/bootstrap.php
 * Unified Core Bootstrap for Shaikhoology TMS-MTM Platform
 * Phase-3 Production Readiness Implementation
 * 
 * This is the single source of truth for all application bootstrapping.
 * It consolidates all environmental setup, security, and core functionality.
 */

// Prevent direct access
if (!defined('UNIFIED_BOOTSTRAP_LOADED')) {
    define('UNIFIED_BOOTSTRAP_LOADED', true);
}

// Load sequence - order matters
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/http/json.php';
require_once __DIR__ . '/../includes/security/csrf_unify.php';
require_once __DIR__ . '/../includes/security/ratelimit.php';
require_once __DIR__ . '/../includes/logger/audit_log.php';
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/mtm/mtm_service.php';
require_once __DIR__ . '/../includes/mtm/mtm_validation.php';

// Initialize database connection
global $mysqli;
if (!isset($mysqli) || !$mysqli instanceof mysqli) {
    // Create database connection using environment variables
    $db_host = $GLOBALS['DB_HOST'] ?? 'localhost';
    $db_user = $GLOBALS['DB_USER'] ?? '';
    $db_pass = $GLOBALS['DB_PASS'] ?? '';
    $db_name = $GLOBALS['DB_NAME'] ?? '';
    
    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($mysqli->connect_error) {
        error_log('Database connection failed: ' . $mysqli->connect_error);
        die('Database connection failed. Please try again later.');
    }
    
    $GLOBALS['mysqli'] = $mysqli;
}

// Session management - start exactly once
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Environment-based session configuration
    $appEnv = getenv('APP_ENV') ?: 'local';
    $isProduction = ($appEnv === 'prod' || $appEnv === 'production');
    
    // Cookie security flags based on environment
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || $isProduction;
    $httponly = true; // Always true for security
    $samesite = 'Lax';
    
    // Configure session settings
    @ini_set('session.cookie_secure', $secure ? 1 : 0);
    @ini_set('session.cookie_httponly', 1);
    @ini_set('session.use_strict_mode', 1);
    @ini_set('session.use_only_cookies', 1);
    @ini_set('session.cookie_samesite', $samesite);
    
    // Shorter lifetime for prod; longer for local
    $lifetime = $isProduction ? 60*60*2 : 60*60*12; // 2h prod, 12h local
    @ini_set('session.gc_maxlifetime', $lifetime);
    
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    
    session_start();
    
    // Regenerate session ID periodically for security (every 2 hours)
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 7200) { // 2 hours
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// CSRF token rotation (every 2 hours)
if (!isset($_SESSION['csrf_rotation_time'])) {
    $_SESSION['csrf_rotation_time'] = time();
} elseif (time() - $_SESSION['csrf_rotation_time'] > 7200) {
    // Rotate CSRF token
    unset($_SESSION['csrf']);
    $_SESSION['csrf_rotation_time'] = time();
}

// Security headers (safe on all pages)
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0'); // modern browsers use CSP
    
    // Content Security Policy
    header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https: data:; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:;");
}

// Register shutdown function for safe exit logging
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (function_exists('app_log')) {
            app_log('error', 'Fatal error: ' . json_encode($error));
        } else {
            error_log('Fatal error: ' . json_encode($error));
        }
    }
});

/**
 * EXPOSED HELPER FUNCTIONS
 * 
 * These functions are available throughout the application
 * and provide unified interface for JSON responses and security checks.
 */

/**
 * Send JSON success response
 */
if (!function_exists('json_success')) {
    function json_success($data = [], string $message = 'Success', array $meta = []): void {
        header('Content-Type: application/json');
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'error' => null,
            'meta' => $meta,
            'timestamp' => gmdate('c')
        ];
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Send JSON error response
 */
if (!function_exists('json_error')) {
    function json_error(string $message = 'An error occurred', int $code = 400, $data = null, array $meta = []): void {
        http_response_code($code);
        header('Content-Type: application/json');
        $response = [
            'success' => false,
            'data' => $data,
            'message' => '',
            'error' => $message,
            'meta' => $meta,
            'timestamp' => gmdate('c')
        ];
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Require login for API endpoints (JSON response)
 */
if (!function_exists('require_login_json')) {
    function require_login_json(): void {
        if (empty($_SESSION['user_id'])) {
            json_error('Authentication required', 401);
        }
    }
}

/**
 * Require admin privileges for API endpoints (JSON response)
 */
if (!function_exists('require_admin_json')) {
    function require_admin_json(): void {
        if (empty($_SESSION['user_id'])) {
            json_error('Authentication required', 401);
        }
        if (empty($_SESSION['is_admin'])) {
            json_error('Admin privileges required', 403);
        }
    }
}

/**
 * Enhanced CSRF verification for API endpoints
 */
if (!function_exists('validate_csrf_api')) {
    function validate_csrf_api(): void {
        $token = $_POST['csrf'] ?? $_GET['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!validate_csrf($token)) {
            json_error('Invalid CSRF token', 403);
        }
    }
}

/**
 * Idempotency key validation
 */
if (!function_exists('validate_idempotency_key')) {
    function validate_idempotency_key(): ?string {
        $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? null;
        if ($key && strlen($key) >= 10) {
            return $key;
        }
        return null;
    }
}

/**
 * Rate limiting helper for API endpoints
 */
if (!function_exists('api_rate_limit')) {
    function api_rate_limit(string $bucket, int $limit): void {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $isAdmin = !empty($_SESSION['is_admin']);
        
        // Apply method-based rate limits
        if ($method === 'GET') {
            $limit = min($limit, RATE_LIMIT_GET);
        } elseif ($isAdmin) {
            $limit = min($limit, RATE_LIMIT_ADMIN_MUT);
        } else {
            $limit = min($limit, RATE_LIMIT_MUT);
        }
        
        require_rate_limit($bucket, $limit);
    }
}

/**
 * Environment information
 */
if (!function_exists('get_env_info')) {
    function get_env_info(): array {
        return [
            'app_env' => APP_ENV,
            'php_version' => PHP_VERSION,
            'db_connection' => !empty($GLOBALS['mysqli']) ? 'connected' : 'disconnected',
            'session_id' => session_id(),
            'timestamp' => gmdate('c')
        ];
    }
}

// Load enhanced security hardening
require_once __DIR__ . '/../includes/security/enhanced_security.php';

// Auto-initialize audit logging
if (function_exists('initialize_audit_logging')) {
    initialize_audit_logging();
}

// Set global database connection for backward compatibility
$global_db = $GLOBALS['mysqli'] ?? null;

// Log bootstrap completion in development (only if function exists)
if (APP_ENV === 'local') {
    if (function_exists('app_log')) {
        app_log('debug', 'Unified bootstrap loaded successfully');
    } else {
        // Fallback logging for when app_log is not yet available
        if (defined('STDIN') || !headers_sent()) {
            echo "DEBUG: Unified bootstrap loaded successfully at " . date('c') . "\n";
        }
    }
}