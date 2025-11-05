<?php
/**
 * API Endpoint: List Trades
 * GET /api/trades/list.php
 * 
 * Get trade history for the authenticated trader with optional filtering
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

$endpoint = 'trade_list';
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
    
    // Parse query parameters for filtering
    $filters = [];
    
    if (isset($_GET['symbol']) && trim($_GET['symbol']) !== '') {
        $filters['symbol'] = trim($_GET['symbol']);
    }
    
    if (isset($_GET['from']) && trim($_GET['from']) !== '') {
        $dateFrom = DateTime::createFromFormat('Y-m-d', $_GET['from']);
        if ($dateFrom !== false) {
            $filters['from'] = $dateFrom->format('Y-m-d');
        }
    }
    
    if (isset($_GET['to']) && trim($_GET['to']) !== '') {
        $dateTo = DateTime::createFromFormat('Y-m-d', $_GET['to']);
        if ($dateTo !== false) {
            $filters['to'] = $dateTo->format('Y-m-d');
        }
    }
    
    // Validate pagination parameters
    $pagination = validate_pagination_params($_GET);
    
    // Get user trades
    $trades = get_user_trades($traderId, $filters, $pagination['limit'], $pagination['offset']);
    
    // Format response
    $response = [
        'success' => true,
        'items' => array_map(function($trade) {
            return [
                'id' => (int)$trade['id'],
                'symbol' => $trade['symbol'],
                'side' => $trade['side'],
                'quantity' => (float)$trade['quantity'],
                'price' => (float)$trade['price'],
                'opened_at' => $trade['opened_at'],
                'closed_at' => $trade['closed_at'],
                'notes' => $trade['notes'],
                'created_at' => $trade['created_at']
            ];
        }, $trades),
        'pagination' => [
            'limit' => $pagination['limit'],
            'offset' => $pagination['offset'],
            'count' => count($trades)
        ],
        'filters' => $filters
    ];
    
    // Success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log error
    app_log([
        'event' => 'trade_list_api_error',
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