<?php
/**
 * API Endpoint: Create Trade
 * POST /api/trades/create.php
 * 
 * Create a new trade record for the authenticated trader
 */

// Include required files
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/guard.php';

// Include MTM modules
require_once __DIR__ . '/../../includes/mtm/mtm_validation.php';
require_once __DIR__ . '/../../includes/mtm/mtm_service.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$endpoint = 'trade_create';
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
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'code' => 'INVALID_JSON',
            'message' => 'Invalid JSON in request body'
        ]);
        exit;
    }
    
    // Validate input
    $validation = validate_trade_input($input);
    
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'code' => 'VALIDATION_ERROR',
            'errors' => $validation['errors']
        ]);
        exit;
    }
    
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    $tradeData = $validation['sanitized'];
    
    // Create trade
    $result = create_trade($traderId, $tradeData);
    
    if ($result['success']) {
        // Success response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'id' => $result['trade_id']
        ]);
    } else {
        // Handle specific error cases
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'code' => $result['error'],
            'message' => 'Failed to create trade'
        ]);
    }
    
} catch (Exception $e) {
    // Log error
    app_log([
        'event' => 'trade_create_api_error',
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