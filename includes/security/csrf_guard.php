<?php
/**
 * includes/security/csrf_guard.php
 *
 * Centralized CSRF Protection for Phase-3 Litmus Auto-Fix Pack
 * Returns 403 (not 401) on token failure for API JSON routes
 * 
 * Provides:
 * - require_csrf_json() - Centralized CSRF validation for API JSON endpoints
 * - Centralized token validation with unified error handling
 * - E2E test bypass support
 */

// Load unified CSRF functionality
require_once __DIR__ . '/csrf_unify.php';

/**
 * Centralized CSRF validation for API JSON endpoints
 * 
 * Validates CSRF token via unified system and returns 403 JSON error on failure.
 * Only validates for state-changing operations (POST, PUT, DELETE, PATCH).
 * Provides E2E test bypass support.
 * 
 * @return bool True if CSRF validation passes, exits with 403 error if fails
 */
function require_csrf_json(): bool {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // Only validate CSRF for state-changing operations
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return true;
    }
    
    // E2E test detection and bypass - when (APP_ENV in {local, dev}) AND (ALLOW_CSRF_BYPASS == '1')
    $appEnv = getenv('APP_ENV') ?? '';
    $allowBypass = getenv('ALLOW_CSRF_BYPASS') === '1';
    $isE2E = in_array($appEnv, ['local', 'dev'], true) && $allowBypass;
    
    if ($isE2E) {
        // Log bypass via audit logger: event=CSRF_BYPASS_E2E, user or anon
        $userId = $_SESSION['user_id'] ?? 'anon';
        if (function_exists('app_log')) {
            app_log('info', sprintf(
                'CSRF_BYPASS_E2E - User=%s, URI=%s, User-Agent=%s',
                $userId,
                $_SERVER['REQUEST_URI'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ));
        }
        return true;
    }
    
    // Extract CSRF token from headers or body
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    
    // Validate CSRF token using unified system
    if (!validate_csrf($token)) {
        // Return 403 JSON error (not 401) for CSRF violations
        json_error("CSRF_VIOLATION", "Invalid or missing CSRF token", null, 403);
    }
    
    return true;
}
