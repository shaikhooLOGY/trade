<?php
/**
 * includes/security/ratelimit.php
 * 
 * Session-based rate limiting system for PHP trading platform
 * 
 * Provides:
 * - limit_per_route($key, $max_per_minute) - Rate limit by route
 * - Environment-based configuration for different request types
 * - Session + IP based tracking for distributed systems
 * - Different limits for GET, MUTATING, and Admin operations
 * 
 * Configuration (via environment variables):
 * - RATE_LIMIT_GET: Rate limit for GET requests (default: 120/min)
 * - RATE_LIMIT_MUT: Rate limit for mutating requests (default: 30/min)
 * - RATE_LIMIT_ADMIN_MUT: Rate limit for admin mutating requests (default: 10/min)
 */

// Session should already be started via bootstrap.php

/**
 * Rate limiting configuration
 */
define('RATE_LIMIT_GET', getenv('RATE_LIMIT_GET') ?: '120');
define('RATE_LIMIT_MUT', getenv('RATE_LIMIT_MUT') ?: '30');
define('RATE_LIMIT_ADMIN_MUT', getenv('RATE_LIMIT_ADMIN_MUT') ?: '10');

/**
 * Initialize rate limiting storage in session
 */
function rate_limit_init(): void {
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
}

/**
 * Get client identifier for rate limiting (session + IP)
 * 
 * @return string Client identifier
 */
function rate_limit_client_id(): string {
    $sessionId = session_id();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Hash combination to avoid storing sensitive data
    return hash('sha256', $sessionId . ':' . $ipAddress);
}

/**
 * Clean expired rate limit entries
 * 
 * @param array $limits Rate limit storage
 * @param int $now Current timestamp
 * @param int $window Time window in seconds (default: 60)
 * @return array Cleaned rate limit storage
 */
function rate_limit_clean(array $limits, int $now, int $window = 60): array {
    foreach ($limits as $key => $data) {
        // Remove entries older than the window
        if ($now - $data['first_request'] > $window) {
            unset($limits[$key]);
        }
    }
    return $limits;
}

/**
 * Get default rate limit based on request type and user role
 * 
 * @return int Rate limit per minute
 */
function rate_limit_default(): int {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    
    // Check if user is admin
    $isAdmin = !empty($_SESSION['is_admin']);
    
    if ($method === 'GET') {
        return (int)RATE_LIMIT_GET;
    }
    
    // For mutating requests
    if ($isAdmin) {
        return (int)RATE_LIMIT_ADMIN_MUT;
    }
    
    return (int)RATE_LIMIT_MUT;
}

/**
 * Rate limit enforcement function
 * 
 * @param string $key Rate limit key (route identifier)
 * @param int|null $maxPerMinute Maximum requests per minute (null for default)
 * @return bool True if allowed, false if rate limited
 */
function limit_per_route(string $key, ?int $maxPerMinute = null): bool {
    // Initialize rate limiting storage
    rate_limit_init();
    
    // Use default limit if not specified
    if ($maxPerMinute === null) {
        $maxPerMinute = rate_limit_default();
    }
    
    // Get client identifier
    $clientId = rate_limit_client_id();
    $fullKey = $clientId . ':' . $key;
    
    // Clean expired entries
    $now = time();
    $_SESSION['rate_limits'] = rate_limit_clean($_SESSION['rate_limits'], $now);
    
    // Check if key exists and get current count
    if (!isset($_SESSION['rate_limits'][$fullKey])) {
        $_SESSION['rate_limits'][$fullKey] = [
            'count' => 0,
            'first_request' => $now,
            'requests' => []
        ];
    }
    
    $limitData = &$_SESSION['rate_limits'][$fullKey];
    
    // Clean old requests within the window
    $windowStart = $now - 60; // 60 second window
    $limitData['requests'] = array_filter(
        $limitData['requests'],
        function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        }
    );
    
    // Update count
    $limitData['count'] = count($limitData['requests']);
    
    // Check if rate limit exceeded
    if ($limitData['count'] >= $maxPerMinute) {
        // Log rate limit violation
        rate_limit_log_violation($key, $limitData['count'], $maxPerMinute);
        
        return false; // Rate limit exceeded
    }
    
    // Add current request
    $limitData['requests'][] = $now;
    $limitData['count']++;
    
    return true; // Request allowed
}

/**
 * Get current rate limit status for a key
 * 
 * @param string $key Rate limit key
 * @return array Rate limit status information
 */
function rate_limit_status(string $key): array {
    rate_limit_init();
    
    $clientId = rate_limit_client_id();
    $fullKey = $clientId . ':' . $key;
    $maxPerMinute = rate_limit_default();
    
    $now = time();
    $_SESSION['rate_limits'] = rate_limit_clean($_SESSION['rate_limits'], $now);
    
    if (!isset($_SESSION['rate_limits'][$fullKey])) {
        return [
            'allowed' => true,
            'count' => 0,
            'remaining' => $maxPerMinute,
            'reset_time' => $now + 60,
            'max_per_minute' => $maxPerMinute
        ];
    }
    
    $limitData = $_SESSION['rate_limits'][$fullKey];
    $currentCount = count($limitData['requests']);
    
    return [
        'allowed' => $currentCount < $maxPerMinute,
        'count' => $currentCount,
        'remaining' => max(0, $maxPerMinute - $currentCount),
        'reset_time' => $now + 60,
        'max_per_minute' => $maxPerMinute
    ];
}

/**
 * Log rate limit violations for monitoring
 * 
 * @param string $key Rate limit key that was exceeded
 * @param int $currentCount Current request count
 * @param int $maxPerMinute Maximum allowed per minute
 */
function rate_limit_log_violation(string $key, int $currentCount, int $maxPerMinute): void {
    $logData = [
        'event_type' => 'rate_limit_exceeded',
        'key' => $key,
        'current_count' => $currentCount,
        'max_per_minute' => $maxPerMinute,
        'timestamp' => date('c'),
        'user_id' => $_SESSION['user_id'] ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? 0,
        'client_id' => rate_limit_client_id(),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
    ];
    
    // Use existing app_log function if available, otherwise file_put_contents
    if (function_exists('app_log')) {
        app_log('security', json_encode($logData));
    } else {
        $logFile = __DIR__ . '/../logs/rate_limit_violations.log';
        $logLine = json_encode($logData) . PHP_EOL;
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Send rate limit exceeded response
 * 
 * @param string $key Rate limit key
 * @param array|null $status Status information (optional)
 */
function rate_limit_response(string $key, ?array $status = null): void {
    if ($status === null) {
        $status = rate_limit_status($key);
    }
    
    http_response_code(429); // Too Many Requests
    header('Content-Type: application/json');
    header('Retry-After: 60'); // Suggest retry after 60 seconds
    
    $response = [
        'success' => false,
        'code' => 'RATE_LIMITED',
        'message' => 'Rate limit exceeded. Try again later.',
        'retry_after' => 60,
        'rate_limit' => [
            'limit' => $status['max_per_minute'],
            'remaining' => 0,
            'reset' => $status['reset_time']
        ],
        'timestamp' => date('c')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
}

/**
 * Middleware function for API endpoints
 * 
 * @param string $route Route identifier
 * @param int|null $maxPerMinute Custom rate limit (optional)
 * @return bool True if allowed, exits with error if rate limited
 */
function rate_limit_api_middleware(string $route, ?int $maxPerMinute = null): bool {
    if (!limit_per_route($route, $maxPerMinute)) {
        rate_limit_response($route);
        exit;
    }
    
    return true;
}

/**
 * Clear rate limits for a specific key (useful for testing)
 * 
 * @param string $key Rate limit key to clear
 */
function rate_limit_clear(string $key): void {
    rate_limit_init();
    
    $clientId = rate_limit_client_id();
    $fullKey = $clientId . ':' . $key;
    
    unset($_SESSION['rate_limits'][$fullKey]);
}

/**
 * Clear all rate limits for current session (useful for testing)
 */
function rate_limit_clear_all(): void {
    $_SESSION['rate_limits'] = [];
}

/**
 * Get recommended route keys for common endpoints
 */
define('RATE_LIMIT_ROUTES', [
    'login' => 'login_attempt',
    'register' => 'registration',
    'trade_create' => 'trade_create',
    'trade_list' => 'trade_list',
    'mtm_enroll' => 'mtm_enroll',
    'api_general' => 'api_general',
    'admin_action' => 'admin_action',
    'profile_update' => 'profile_update'
]);