<?php
/**
 * api/trades/create.php
 *
 * Trades API - Create new trade
 * POST /api/trades/create.php
 *
 * Create a new trade record for the authenticated trader
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('INVALID_INPUT', 'Only POST requests are allowed');
}

// Require authentication and active user
require_active_user_json('Authentication required');

// CSRF protection for mutating requests
csrf_api_middleware();

// Rate limiting
if (!rate_limit_api_middleware('trade_create', 10)) {
    exit; // Rate limit response already sent
}

try {
    // Read JSON input
    $input = get_json_input();
    
    // Validate input
    $validation = validate_trade_input($input);
    
    if (!$validation['valid']) {
        json_validation_error($validation['errors'], 'Trade validation failed');
    }
    
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    $tradeData = $validation['sanitized'];
    
    // Create trade
    $result = create_trade($traderId, $tradeData);
    
    if ($result['success']) {
        // Audit log successful trade creation
        app_log('audit', json_encode([
            'event_type' => 'trade_create_success',
            'trade_id' => $result['trade_id'],
            'trader_id' => $traderId,
            'symbol' => $tradeData['symbol'],
            'quantity' => $tradeData['quantity'],
            'price' => $tradeData['price']
        ]));
        
        json_ok(['id' => $result['trade_id']], 'Trade created successfully');
    } else {
        // Handle specific error cases
        json_fail($result['error'] ?? 'SERVER_ERROR', 'Failed to create trade');
    }
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'trade_create_api_error: ' . $e->getMessage());
    
    json_fail('SERVER_ERROR', 'Failed to create trade');
}