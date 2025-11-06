<?php
/**
 * Security Hardening Layer
 * Enhanced security measures for production readiness
 */

if (!defined('SECURITY_HARDENING_LOADED')) {
    define('SECURITY_HARDENING_LOADED', true);
}

/**
 * Enhanced CSRF validation with rotation tracking
 */
if (!function_exists('validate_csrf_enhanced')) {
    function validate_csrf_enhanced(): bool {
        $token = $_POST['csrf'] ?? $_GET['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $isValid = validate_csrf($token);
        
        // Log invalid CSRF attempts
        if (!$isValid && !empty($token)) {
            if (function_exists('audit_admin_action')) {
                audit_admin_action(
                    $_SESSION['user_id'] ?? null,
                    'csrf_validation_failed',
                    'security',
                    null,
                    'Invalid CSRF token provided'
                );
            }
        }
        
        return $isValid;
    }
}

/**
 * Enhanced rate limiting with burst detection
 */
if (!function_exists('enhanced_rate_limit')) {
    function enhanced_rate_limit(string $bucket, int $limitPerMinute): array {
        global $mysqli;
        
        // Get the basic rate limit result
        $result = rate_limit($bucket, $limitPerMinute);
        
        // Check for burst patterns (multiple 429s in short time)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userId = $_SESSION['user_id'] ?? null;
        
        // Create burst detection key
        $burstKey = "burst_detect:" . ($userId ? "uid:$userId" : "ip:$ip");
        
        // Check recent 429s (last 5 minutes)
        $stmt = $mysqli->prepare("
            SELECT COUNT(*) as violation_count 
            FROM rate_limits 
            WHERE actor_key IN (?, ?) 
            AND window_start >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            AND count > ?
        ");
        
        if ($stmt) {
            $userKey = $userId ? "uid:$userId" : null;
            $ipKey = "ip:$ip";
            
            if ($userKey) {
                $stmt->bind_param('ssii', $userKey, $ipKey, $limitPerMinute, $limitPerMinute);
            } else {
                $stmt->bind_param('ssii', $ipKey, $ipKey, $limitPerMinute, $limitPerMinute);
            }
            
            $stmt->execute();
            $violationResult = $stmt->get_result();
            $violationData = $violationResult->fetch_assoc();
            $violationCount = (int)($violationData['violation_count'] ?? 0);
            $stmt->close();
            
            // If more than 3 violations in 5 minutes, apply additional restrictions
            if ($violationCount > 3) {
                $result['burst_suppression'] = true;
                $result['suppression_time'] = time() + 300; // 5 minutes
                
                // Log burst detection
                if (function_exists('audit_admin_action')) {
                    audit_admin_action(
                        $userId,
                        'rate_limit_burst_detected',
                        'security',
                        null,
                        "Burst pattern detected: $violationCount violations in 5 minutes"
                    );
                }
            }
        }
        
        return $result;
    }
}

/**
 * Security headers enhancement
 */
if (!function_exists('set_security_headers')) {
    function set_security_headers(): void {
        if (headers_sent()) {
            return;
        }
        
        // Remove server signature
        header_remove('X-Powered-By');
        header_remove('Server');
        
        // Security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Enhanced CSP for production
        $appEnv = getenv('APP_ENV') ?: 'local';
        if ($appEnv === 'prod' || $appEnv === 'production') {
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; font-src 'self'; object-src 'none'; media-src 'self'; frame-src 'none';");
        } else {
            // More permissive for development
            header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https: data:; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:;");
        }
        
        // HSTS header for HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // Additional security headers
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }
}

/**
 * Enhanced session security
 */
if (!function_exists('enhance_session_security')) {
    function enhance_session_security(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        
        // Session fixation protection
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
        
        // Track session fingerprint
        $fingerprint = hash('sha256', $_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);
        if (!isset($_SESSION['fingerprint'])) {
            $_SESSION['fingerprint'] = $fingerprint;
        } elseif ($_SESSION['fingerprint'] !== $fingerprint) {
            // Potential session hijacking attempt
            session_destroy();
            if (function_exists('audit_admin_action')) {
                audit_admin_action(
                    $_SESSION['user_id'] ?? null,
                    'session_fingerprint_mismatch',
                    'security',
                    null,
                    'Session fingerprint mismatch detected'
                );
            }
            json_error('Security violation detected', 403);
        }
        
        // Set secure session cookie parameters
        $appEnv = getenv('APP_ENV') ?: 'local';
        $isProduction = ($appEnv === 'prod' || $appEnv === 'production');
        
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || $isProduction;
        
        // Update cookie parameters if needed
        $currentParams = session_get_cookie_params();
        if ($currentParams['secure'] !== $secure || $currentParams['httponly'] !== true || $currentParams['samesite'] !== 'Lax') {
            session_set_cookie_params([
                'lifetime' => $currentParams['lifetime'],
                'path' => $currentParams['path'],
                'domain' => $currentParams['domain'],
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

/**
 * Input sanitization and validation
 */
if (!function_exists('sanitize_input_enhanced')) {
    function sanitize_input_enhanced($input, string $type = 'string') {
        switch ($type) {
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : 0;
            case 'float':
                return filter_var($input, FILTER_VALIDATE_FLOAT) !== false ? (float)$input : 0.0;
            case 'email':
                return filter_var($input, FILTER_VALIDATE_EMAIL) !== false ? filter_var($input, FILTER_SANITIZE_EMAIL) : '';
            case 'url':
                return filter_var($input, FILTER_VALIDATE_URL) !== false ? filter_var($input, FILTER_SANITIZE_URL) : '';
            case 'string':
            default:
                return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

/**
 * SQL injection prevention helper
 */
if (!function_exists('prepare_safe_query')) {
    function prepare_safe_query(mysqli $mysqli, string $query, array $params = []): mysqli_stmt {
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception('Failed to prepare query: ' . $mysqli->error);
        }
        
        if (!empty($params)) {
            $types = str_repeat('s', count($params)); // Default to strings
            $stmt->bind_param($types, ...$params);
        }
        
        return $stmt;
    }
}

// Initialize security hardening
set_security_headers();
enhance_session_security();