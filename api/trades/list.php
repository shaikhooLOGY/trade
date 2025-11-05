<?php
/**
 * api/trades/list.php
 *
 * Trades API - List user trades
 * GET /api/trades/list.php
 *
 * Get trade history for the authenticated trader with optional filtering
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('INVALID_INPUT', 'Only GET requests are allowed');
}

// Require authentication and active user
require_active_user_json('Authentication required');

// Rate limiting
if (!rate_limit_api_middleware('trade_list', 10)) {
    exit; // Rate limit response already sent
}

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
    $items = array_map(function($trade) {
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
    }, $trades);
    
    $meta = [
        'pagination' => [
            'limit' => $pagination['limit'],
            'offset' => $pagination['offset'],
            'count' => count($trades)
        ],
        'filters' => $filters
    ];
    
    json_ok($items, 'Trades retrieved successfully', $meta);
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'trade_list_api_error: ' . $e->getMessage());
    
    json_fail('SERVER_ERROR', 'Failed to retrieve trades');
}