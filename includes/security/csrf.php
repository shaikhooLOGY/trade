<?php
/**
 * includes/security/csrf.php
 *
 * Unified CSRF protection system for PHP trading platform
 * Now uses unified helpers from csrf_unify.php
 *
 * Provides:
 * - csrf_token() - Generate/retrieve CSRF token (wrapper for get_csrf_token())
 * - csrf_check($token) - Validate CSRF token with logging (wrapper for validate_csrf())
 * - Backward compatibility with legacy function signatures
 * - API and form integration support
 *
 * Security Features:
 * - Uses unified CSRF token management
 * - Timing-safe comparison using hash_equals()
 * - Comprehensive logging of CSRF failures
 * - Support for both form and API requests
 */

// Include unified CSRF helpers
require_once __DIR__ . '/csrf_unify.php';

/**
 * Generate or retrieve current CSRF token (legacy wrapper)
 *
 * @return string CSRF token for current session
 */
function csrf_token(): string {
    return get_csrf_token();
}

/**
 * Validate CSRF token with comprehensive security checks (legacy wrapper)
 *
 * @param string|null $token Token to validate (from request)
 * @return bool True if valid, false otherwise
 */
function csrf_check(?string $token = null): bool {
    // Get token from request if not provided
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ??
                 $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    }
    
    // Use unified validation
    return validate_csrf($token);
}

/**
 * Log CSRF validation failures for security monitoring
 * 
 * @param string $reason Failure reason code
 * @param string $message Detailed failure message
 */
function csrf_log_failure(string $reason, string $message): void {
    $logData = [
        'event_type' => 'csrf_validation_failed',
        'reason' => $reason,
        'message' => $message,
        'timestamp' => date('c'),
        'user_id' => $_SESSION['user_id'] ?? null,
        'session_id' => session_id(),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
    ];
    
    // Use existing app_log function if available, otherwise file_put_contents
    if (function_exists('app_log')) {
        app_log('security', json_encode($logData));
    } else {
        $logFile = __DIR__ . '/../logs/csrf_failures.log';
        $logLine = json_encode($logData) . PHP_EOL;
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Generate CSRF token for API responses
 * 
 * @return array API response format with token
 */
function csrf_api_response(): array {
    return [
        'success' => true,
        'csrf_token' => csrf_token(),
        'timestamp' => date('c')
    ];
}

// Auto-generate token on first load if not exists
// Token generation is now handled by the unified system in csrf_unify.php