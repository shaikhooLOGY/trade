<?php
/**
 * api/trades/get.php
 *
 * Trades API - Get single trade
 * GET /api/trades/get.php?id={trade_id}
 *
 * Get a single trade by ID (owner or admin only)
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
if (!rate_limit_api_middleware('trade_get', 20)) {
    exit; // Rate limit response already sent
}

try {
    // Validate trade ID parameter
    if (!isset($_GET['id']) || !is_numeric($_GET['id']) || (int)$_GET['id'] <= 0) {
        json_fail('VALIDATION_ERROR', 'Valid trade ID is required', ['field' => 'id']);
    }
    
    $tradeId = (int)$_GET['id'];
    $userId = (int)$_SESSION['user_id'];
    $isAdmin = !empty($_SESSION['is_admin']);
    
    global $mysqli;
    
    // Get trade from database
    $stmt = $mysqli->prepare("SELECT * FROM trades WHERE id = ?");
    if (!$stmt) {
        json_database_error($mysqli, 'Prepare trade query');
    }
    
    $stmt->bind_param('i', $tradeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $trade = $result->fetch_assoc();
    $stmt->close();
    
    if (!$trade) {
        json_not_found('Trade');
    }
    
    // Check permissions - user can only see their own trades unless admin
    if (!$isAdmin && (int)$trade['trader_id'] !== $userId) {
        json_forbidden('You can only view your own trades');
    }
    
    // Format trade response
    $tradeData = [
        'id' => (int)$trade['id'],
        'symbol' => $trade['symbol'],
        'side' => $trade['side'],
        'quantity' => (float)$trade['quantity'],
        'price' => (float)$trade['price'],
        'opened_at' => $trade['opened_at'],
        'closed_at' => $trade['closed_at'],
        'notes' => $trade['notes'],
        'created_at' => $trade['created_at'],
        'trader_id' => (int)$trade['trader_id'],
        'enrollment_id' => $trade['enrollment_id'] ? (int)$trade['enrollment_id'] : null
    ];
    
    // Add additional fields if they exist
    if (isset($trade['stop_loss'])) {
        $tradeData['stop_loss'] = (float)$trade['stop_loss'];
    }
    if (isset($trade['target_price'])) {
        $tradeData['target_price'] = (float)$trade['target_price'];
    }
    if (isset($trade['outcome'])) {
        $tradeData['outcome'] = $trade['outcome'];
    }
    
    json_ok($tradeData, 'Trade retrieved successfully');
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'trade_get_api_error: ' . $e->getMessage());
    
    json_fail('SERVER_ERROR', 'Failed to retrieve trade');
}