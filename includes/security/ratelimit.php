<?php
/**
 * includes/security/ratelimit.php
 *
 * Database-backed rate limiting system for PHP trading platform
 *
 * Provides:
 * - rl_actor_key() - Generate consistent actor key for rate limiting
 * - rate_limit($bucket, $limitPerMin, $now = null) - Main rate limiting function
 * - require_rate_limit($bucket, $limitPerMin) - Central helper for easy integration
 * - Database-backed concurrent-safe implementation
 * - Works for both authenticated and anonymous users
 * - Proper HTTP 429 responses with Retry-After and X-RateLimit-* headers
 */

// Ensure database connection is available
global $mysqli;
if (!isset($mysqli) || !$mysqli instanceof mysqli) {
    error_log('Rate limiting requires database connection ($mysqli)');
    return false;
}

/**
 * Generate actor key for rate limiting
 * 
 * @return string Actor key identifier
 */
function rl_actor_key(): string {
    // If logged in, use user ID
    if (!empty($_SESSION['user_id'])) {
        return "uid:" . $_SESSION['user_id'];
    }
    
    // For anonymous users, use IP address
    $ipAddress = null;
    
    // Check for X-Forwarded-For header first (for load balancers/proxies)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // X-Forwarded-For can contain multiple IPs, take the first one
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ipAddress = trim($ips[0]);
    }
    
    // Fall back to REMOTE_ADDR if X-Forwarded-For not available
    if (empty($ipAddress)) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    // Normalize IPv6 loopback to IPv4
    if ($ipAddress === '::1' || $ipAddress === '::ffff:127.0.0.1') {
        $ipAddress = '127.0.0.1';
    }
    
    return "ip:" . $ipAddress;
}

/**
 * Rate limit enforcement function (database-backed)
 * 
 * @param string $bucket Rate limit bucket/key identifier
 * @param int $limitPerMinute Maximum requests per minute
 * @param string|null $now Custom timestamp (defaults to current UTC)
 * @return array Result array with ['allowed' => bool, 'remaining' => int, 'reset' => int]
 */
function rate_limit(string $bucket, int $limitPerMinute, ?string $now = null): array {
    global $mysqli;
    
    // Use current UTC time if not specified
    if ($now === null) {
        $now = gmdate('Y-m-d H:i:s');
    }
    
    // Calculate window start (floor to minute)
    $windowStart = date('Y-m-d H:i:00', strtotime($now));
    
    // Get actor key
    $actorKey = rl_actor_key();
    
    // Use INSERT ... ON DUPLICATE KEY UPDATE for concurrent safety
    $sql = "INSERT INTO rate_limits (bucket, actor_key, window_start, count) 
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE count = count + 1, updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log('Rate limit prepare failed: ' . $mysqli->error);
        return ['allowed' => true, 'remaining' => $limitPerMinute, 'reset' => strtotime($windowStart) + 60];
    }
    
    $stmt->bind_param('sss', $bucket, $actorKey, $windowStart);
    $result = $stmt->execute();
    
    if (!$result) {
        error_log('Rate limit execution failed: ' . $stmt->error);
        $stmt->close();
        return ['allowed' => true, 'remaining' => $limitPerMinute, 'reset' => strtotime($windowStart) + 60];
    }
    
    $stmt->close();
    
    // Get current count after update
    $sql = "SELECT count FROM rate_limits WHERE bucket = ? AND actor_key = ? AND window_start = ?";
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        error_log('Rate limit count query prepare failed: ' . $mysqli->error);
        return ['allowed' => true, 'remaining' => $limitPerMinute, 'reset' => strtotime($windowStart) + 60];
    }
    
    $stmt->bind_param('sss', $bucket, $actorKey, $windowStart);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $currentCount = 0;
    if ($row = $result->fetch_assoc()) {
        $currentCount = (int)$row['count'];
    }
    
    $stmt->close();
    
    // Calculate reset time (window start + 60 seconds)
    $resetTimestamp = strtotime($windowStart) + 60;
    $remaining = max(0, $limitPerMinute - $currentCount);
    
    // Check if rate limit exceeded
    $allowed = $currentCount <= $limitPerMinute;
    
    return [
        'allowed' => $allowed,
        'count' => $currentCount,
        'remaining' => $remaining,
        'reset' => $resetTimestamp,
        'limit' => $limitPerMinute
    ];
}

/**
 * Central helper function for easy integration
 * Automatically handles HTTP 429 responses with proper headers
 * 
 * @param string $bucket Rate limit bucket/key identifier
 * @param int $limitPerMinute Maximum requests per minute
 * @return bool True if allowed, exits with 429 error if rate limited
 */
function require_rate_limit(string $bucket, int $limitPerMinute): bool {
    $result = rate_limit($bucket, $limitPerMinute);
    
    // Set rate limit headers
    header('X-RateLimit-Limit: ' . $result['limit']);
    header('X-RateLimit-Remaining: ' . $result['remaining']);
    header('X-RateLimit-Reset: ' . $result['reset']);
    
    if (!$result['allowed']) {
        // Rate limit exceeded - send 429 response
        http_response_code(429); // Too Many Requests
        
        // Calculate seconds until reset
        $retryAfter = max(0, $result['reset'] - time());
        
        header('Retry-After: ' . $retryAfter);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded',
            'rate_limit' => [
                'limit' => $result['limit'],
                'remaining' => 0,
                'reset' => $result['reset'],
                'retry_after' => $retryAfter
            ],
            'timestamp' => gmdate('c')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    return true;
}

/**
 * Get current rate limit status for a bucket
 * 
 * @param string $bucket Rate limit bucket/key identifier
 * @param int $limitPerMinute Maximum requests per minute
 * @return array Rate limit status information
 */
function rate_limit_status(string $bucket, int $limitPerMinute): array {
    return rate_limit($bucket, $limitPerMinute);
}

/**
 * Legacy compatibility functions (maintained for backward compatibility)
 * These wrap the new database-backed implementation
 */

/**
 * Legacy rate limit function (session-based compatibility)
 * Now redirects to database-backed implementation
 */
function limit_per_route(string $key, ?int $maxPerMinute = null): bool {
    if ($maxPerMinute === null) {
        $maxPerMinute = rate_limit_default();
    }
    
    return require_rate_limit($key, $maxPerMinute);
}

/**
 * Legacy API middleware function
 * Now redirects to database-backed implementation
 */
function rate_limit_api_middleware(string $route, ?int $maxPerMinute = null): bool {
    if ($maxPerMinute === null) {
        $maxPerMinute = rate_limit_default();
    }
    
    return require_rate_limit($route, $maxPerMinute);
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
        return 120; // RATE_LIMIT_GET
    }
    
    // For mutating requests
    if ($isAdmin) {
        return 10; // RATE_LIMIT_ADMIN_MUT
    }
    
    return 30; // RATE_LIMIT_MUT
}

/**
 * Rate limit configuration constants
 */
define('RATE_LIMIT_GET', 120);
define('RATE_LIMIT_MUT', 30);
define('RATE_LIMIT_ADMIN_MUT', 10);

/**
 * Recommended route keys for common endpoints
 */
define('RATE_LIMIT_ROUTES', [
    'auth:login' => 'auth:login',
    'auth:register' => 'auth:register', 
    'auth:resend' => 'auth:resend',
    'api:trades:create' => 'api:trades:create',
    'api:mtm:enroll' => 'api:mtm:enroll',
    'api:admin:approve' => 'api:admin:approve',
]);

/**
 * Log rate limit violations for monitoring
 * 
 * @param string $bucket Rate limit bucket that was exceeded
 * @param array $result Rate limit result information
 */
function rate_limit_log_violation(string $bucket, array $result): void {
    $logData = [
        'event_type' => 'rate_limit_exceeded',
        'bucket' => $bucket,
        'count' => $result['count'],
        'limit' => $result['limit'],
        'timestamp' => gmdate('c'),
        'user_id' => $_SESSION['user_id'] ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? 0,
        'actor_key' => rl_actor_key(),
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
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Clear rate limits for a specific bucket and actor (useful for testing)
 * 
 * @param string $bucket Rate limit bucket to clear
 * @param string|null $actorKey Specific actor key (optional)
 */
function rate_limit_clear(string $bucket, ?string $actorKey = null): void {
    global $mysqli;
    
    if ($actorKey === null) {
        $actorKey = rl_actor_key();
    }
    
    $sql = "DELETE FROM rate_limits WHERE bucket = ? AND actor_key = ?";
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param('ss', $bucket, $actorKey);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Clear all rate limits for current actor (useful for testing)
 */
function rate_limit_clear_all(): void {
    global $mysqli;
    
    $actorKey = rl_actor_key();
    $sql = "DELETE FROM rate_limits WHERE actor_key = ?";
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param('s', $actorKey);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Legacy compatibility function for rate_limit($bucket, $limit, $key)
 */
function rate_limit_legacy(string $bucket, int $limitPerMinute, ?string $key = null): bool {
    $result = rate_limit($bucket, $limitPerMinute);
    return $result['allowed'];
}