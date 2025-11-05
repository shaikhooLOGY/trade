<?php
/**
 * API Endpoint: Get User MTM Enrollments
 * GET /api/mtm/enrollments.php
 * 
 * Returns all enrollments for the authenticated trader
 */

// Include required files
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/guard.php';

// Include MTM modules
require_once __DIR__ . '/../../includes/mtm/mtm_service.php';
require_once __DIR__ . '/../../includes/mtm/mtm_rules.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Require authentication and active user
require_login();
require_active_user();

// Basic rate limiting (10 requests per minute per endpoint)
if (!isset($_SESSION['api_rate_limit'])) {
    $_SESSION['api_rate_limit'] = [];
}

$endpoint = 'mtm_enrollments';
$now = time();
$window = 60; // 1 minute window

// Clean old entries
if (isset($_SESSION['api_rate_limit'][$endpoint])) {
    $_SESSION['api_rate_limit'][$endpoint] = array_filter(
        $_SESSION['api_rate_limit'][$endpoint],
        function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        }
    );
}

// Check rate limit
if (isset($_SESSION['api_rate_limit'][$endpoint]) && 
    count($_SESSION['api_rate_limit'][$endpoint]) >= 10) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Rate limit exceeded. Try again later.',
        'retry_after' => 60
    ]);
    exit;
}

// Record this request
$_SESSION['api_rate_limit'][$endpoint][] = $now;

try {
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    
    // Get user enrollments
    $enrollments = get_user_enrollments($traderId);
    
    // Format response
    $response = [
        'success' => true,
        'items' => array_map(function($enrollment) {
            return [
                'id' => (int)$enrollment['id'],
                'model_id' => (int)$enrollment['model_id'],
                'model_code' => $enrollment['model_code'],
                'model_name' => $enrollment['model_name'],
                'tier' => $enrollment['tier'],
                'status' => $enrollment['status'],
                'started_at' => $enrollment['started_at']
            ];
        }, $enrollments)
    ];
    
    // Success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log error
    app_log([
        'event' => 'mtm_enrollments_api_error',
        'trader_id' => $_SESSION['user_id'] ?? null,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code' => 'SERVER_ERROR',
        'message' => 'Internal server error'
    ]);
}