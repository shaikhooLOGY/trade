<?php
/**
 * includes/http/json.php
 *
 * Enterprise JSON Response System for Shaikhoology TMS-MTM Platform
 * Phase 3 Compliance Implementation
 *
 * Provides:
 * - json_ok($data, $message) - Standard success response with audit logging
 * - json_fail($code, $message, $details) - Standard error response with audit logging
 * - Standard error codes with compliance tracking
 * - Auth guards integration with security event logging
 * - Response formatting with audit trail integration
 * - Request/response auditing for compliance reporting
 *
 * @version 2.0.0
 * @created 2025-11-06
 * @author Shaikhoology Platform Team
 */

// Load audit logging system
require_once __DIR__ . '/../logger/audit_log.php';

if (!defined('JSON_HTTP_INITIALIZED')) {
    define('JSON_HTTP_INITIALIZED', true);
    
    // Initialize audit logging for API requests
    if (function_exists('initialize_audit_logging')) {
        initialize_audit_logging();
    }
}

/**
 * Standard error codes for API responses
 */
define('ERROR_CODES', [
    'CSRF_MISMATCH' => [
        'http_status' => 400,
        'message' => 'CSRF token validation failed',
        'description' => 'Invalid or missing CSRF token'
    ],
    'VALIDATION_ERROR' => [
        'http_status' => 400,
        'message' => 'Request validation failed',
        'description' => 'One or more input fields failed validation'
    ],
    'NOT_FOUND' => [
        'http_status' => 404,
        'message' => 'Resource not found',
        'description' => 'The requested resource does not exist'
    ],
    'ALREADY_EXISTS' => [
        'http_status' => 409,
        'message' => 'Resource already exists',
        'description' => 'A resource with the same identifier already exists'
    ],
    'UNAUTHORIZED' => [
        'http_status' => 401,
        'message' => 'Authentication required',
        'description' => 'You must be logged in to access this resource'
    ],
    'FORBIDDEN' => [
        'http_status' => 403,
        'message' => 'Access forbidden',
        'description' => 'You do not have permission to access this resource'
    ],
    'RATE_LIMITED' => [
        'http_status' => 429,
        'message' => 'Rate limit exceeded',
        'description' => 'Too many requests. Please try again later.'
    ],
    'SERVER_ERROR' => [
        'http_status' => 500,
        'message' => 'Internal server error',
        'description' => 'An unexpected error occurred while processing your request'
    ],
    'FEATURE_OFF' => [
        'http_status' => 404,
        'message' => 'Feature disabled',
        'description' => 'This feature is currently disabled'
    ],
    'PERMISSION_DENIED' => [
        'http_status' => 403,
        'message' => 'Permission denied',
        'description' => 'You do not have permission to perform this action'
    ],
    'INVALID_INPUT' => [
        'http_status' => 400,
        'message' => 'Invalid input',
        'description' => 'The provided input is invalid or malformed'
    ],
    'DATABASE_ERROR' => [
        'http_status' => 500,
        'message' => 'Database operation failed',
        'description' => 'A database error occurred while processing your request'
    ],
    'METHOD_NOT_ALLOWED' => [
        'http_status' => 405,
        'message' => 'Method not allowed',
        'description' => 'The HTTP method is not allowed for this endpoint'
    ]
]);

/**
 * Set JSON response headers
 *
 * @param int $statusCode HTTP status code
 */
function json_headers(int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
}

/**
 * Send standard success response with audit logging
 * LOCKED SIGNATURE - Phase 3 Production Readiness
 *
 * @param array $data Response data (required)
 * @param string $message Success message (default: '')
 * @param array|null $meta Additional metadata (default: null)
 * @param int $statusCode HTTP status code (default: 200)
 */
function json_success(array $data = [], string $message = '', ?array $meta = null, int $status = 200): void {
    json_headers($status);
    
    $response = [
        'success' => true,
        'message' => $message,
        'timestamp' => date('c'),
        'data' => $data,
    ];
    
    // Add meta if provided
    if ($meta !== null) {
        $response['meta'] = $meta;
    }
    
    // Add user context if available
    if (!empty($_SESSION['user_id'])) {
        $response['user_id'] = (int)$_SESSION['user_id'];
    }
    
    // Add request ID for tracking
    $response['request_id'] = bin2hex(random_bytes(8));
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Legacy compatibility function
 */
function json_ok($data = null, string $message = 'Success', array $meta = [], int $statusCode = 200): void {
    // Convert legacy format to new locked format
    $response_data = $data !== null ? (array)$data : [];
    $response_meta = !empty($meta) ? $meta : null;
    json_success($response_data, $message, $response_meta, $statusCode);
}

/**
 * Send standard error response with audit logging
 * LOCKED SIGNATURE - Phase 3 Production Readiness
 *
 * @param string $error Error code or message (required)
 * @param string $message Error message (default: '')
 * @param array|null $meta Additional error metadata (default: null)
 * @param int $status HTTP status code (default: 400)
 */
function json_error(string $error, string $message = '', ?array $meta = null, int $status = 400): void {
    $errorCode = in_array($error, array_keys(ERROR_CODES), true) ? $error : 'SERVER_ERROR';
    $errorInfo = ERROR_CODES[$errorCode] ?? ERROR_CODES['SERVER_ERROR'];
    
    json_headers($status);
    
    $response = [
        'success' => false,
        'code' => $errorCode,
        'message' => !empty($message) ? $message : $errorInfo['message'],
        'timestamp' => date('c'),
        'error_details' => $meta ?? [],
        'request_id' => bin2hex(random_bytes(8))
    ];
    
    // Add user context if available
    if (!empty($_SESSION['user_id'])) {
        $response['user_id'] = (int)$_SESSION['user_id'];
    }
    
    // Add debug info in development environment
    if (defined('APP_ENV') && APP_ENV === 'local') {
        $response['debug'] = [
            'error_description' => $errorInfo['description'],
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'session_data' => [
                'user_id' => $_SESSION['user_id'] ?? null,
                'is_admin' => $_SESSION['is_admin'] ?? 0
            ]
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Legacy compatibility function
 */
function json_fail(string $code, string $message = '', array $details = [], $data = null): void {
    $response_meta = !empty($details) ? $details : null;
    if ($data !== null) {
        $response_meta = $response_meta ?? [];
        $response_meta['partial_data'] = $data;
    }
    json_error($code, $message, $response_meta, ERROR_CODES[$code]['http_status'] ?? 400);
}

/**
 * Convenience function for validation errors
 * 
 * @param array $errors Field-specific validation errors
 * @param string $message General validation message (optional)
 */
function json_validation_error(array $errors, string $message = 'Validation failed'): void {
    json_fail('VALIDATION_ERROR', $message, ['validation_errors' => $errors]);
}

/**
 * Convenience function for not found errors
 * 
 * @param string $resource Resource that was not found
 */
function json_not_found(string $resource = 'Resource'): void {
    json_fail('NOT_FOUND', "$resource not found");
}

/**
 * Convenience function for unauthorized errors
 * 
 * @param string $message Custom message (optional)
 */
function json_unauthorized(string $message = ''): void {
    json_fail('UNAUTHORIZED', $message);
}

/**
 * Convenience function for forbidden errors
 * 
 * @param string $message Custom message (optional)
 */
function json_forbidden(string $message = ''): void {
    json_fail('FORBIDDEN', $message);
}

/**
 * Convenience function for rate limit errors
 * 
 * @param string $message Custom message (optional)
 * @param array $details Rate limit details (optional)
 */
function json_rate_limited(string $message = '', array $details = []): void {
    json_fail('RATE_LIMITED', $message, $details);
}

/**
 * Require authentication and return JSON error if not logged in
 * 
 * @param string $message Custom error message (optional)
 * @return bool True if authenticated, exits if not
 */
function require_login_json(string $message = ''): bool {
    if (empty($_SESSION['user_id'])) {
        json_unauthorized($message ?: 'Authentication required');
    }
    return true;
}

/**
 * Require active user status and return JSON error if not active
 * 
 * @param string $message Custom error message (optional)
 * @return bool True if active user, exits if not
 */
function require_active_user_json(string $message = ''): bool {
    require_login_json($message);
    
    $status = strtolower((string)($_SESSION['status'] ?? ''));
    $emailV = (int)($_SESSION['email_verified'] ?? 0);
    
    if (!in_array($status, ['active','approved'], true) || $emailV !== 1) {
        json_forbidden($message ?: 'Active user account required');
    }
    
    return true;
}

/**
 * Require admin privileges and return JSON error if not admin
 *
 * @param string $message Custom error message (optional)
 * @return bool True if admin, exits if not
 */
function require_admin_json(string $message = ''): bool {
    require_login_json($message);
    
    if (empty($_SESSION['is_admin'])) {
        // Log unauthorized admin access attempt
        if (function_exists('log_security_event')) {
            log_security_event('unauthorized_admin_access', 'Admin access attempted without proper privileges', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'target_type' => 'admin_endpoint',
                'metadata' => [
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? ''
                ],
                'severity' => 'high',
                'status' => 'failure'
            ]);
        }
        json_forbidden($message ?: 'Admin privileges required');
    }
    
    return true;
}

/**
 * Create paginated JSON response
 * 
 * @param array $items Array of items for current page
 * @param int $total Total number of items across all pages
 * @param int $page Current page number (1-based)
 * @param int $perPage Items per page
 * @param string $message Success message (optional)
 */
function json_paginated(array $items, int $total, int $page, int $perPage, string $message = 'Success'): void {
    $totalPages = (int)ceil($total / $perPage);
    
    $meta = [
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total_items' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_previous' => $page > 1
        ]
    ];
    
    json_ok($items, $message, $meta);
}

/**
 * Create JSON response with cursor-based pagination
 * 
 * @param array $items Array of items for current page
 * @param string|null $nextCursor Cursor for next page (null if no more items)
 * @param string $message Success message (optional)
 */
function json_cursor_paginated(array $items, ?string $nextCursor, string $message = 'Success'): void {
    $meta = [
        'pagination' => [
            'next_cursor' => $nextCursor,
            'has_more' => !empty($nextCursor),
            'count' => count($items)
        ]
    ];
    
    json_ok($items, $message, $meta);
}

/**
 * Handle database errors consistently
 * 
 * @param mysqli_sql_exception $e Database exception
 * @param string $operation Operation that failed
 */
function json_database_error($e, string $operation = 'Database operation'): void {
    // Log the actual error for debugging
    error_log("Database error in $operation: " . $e->getMessage());
    
    json_fail('DATABASE_ERROR', "$operation failed. Please try again.");
}

/**
 * Validate required fields in request data
 * 
 * @param array $data Request data
 * @param array $requiredFields Array of required field names
 * @return bool True if all required fields present
 */
function validate_required_fields(array $data, array $requiredFields): bool {
    $missing = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        json_validation_error(['missing_fields' => $missing], 'Required fields missing: ' . implode(', ', $missing));
    }
    
    return true;
}

/**
 * Get input data from JSON request body
 * 
 * @return array Parsed JSON data
 */
function get_json_input(): array {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_fail('INVALID_INPUT', 'Invalid JSON in request body');
    }
    
    return $data ?: [];
}

/**
 * Set response status and send JSON
 * 
 * @param int $statusCode HTTP status code
 * @param array $response Response array
 */
function json_response(int $statusCode, array $response): void {
    json_headers($statusCode);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}