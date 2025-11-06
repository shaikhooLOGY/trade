<?php
/**
 * api/trades/create.php
 *
 * Trades API - Create new trade with authoritative audit trail
 * POST /api/trades/create.php
 *
 * Create a new trade record for the authenticated trader
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/security/csrf_guard.php';
require_once __DIR__ . '/../../includes/trades/service.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('INVALID_INPUT', 'Only POST requests are allowed');
}

// Require authentication and active user
require_active_user_json('Authentication required');

// CSRF protection for mutating requests - E2E test bypass
require_csrf_json();

// Rate limiting: 30 per minute
require_rate_limit('api:trades:create', 30);

try {
    // Read JSON input
    $input = get_json_input();
    
    // Validate input
    $validation = validate_trade_input_service($input);
    
    if (!$validation['valid']) {
        json_validation_error($validation['errors'], 'Trade validation failed');
    }
    
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    $tradeData = $validation['sanitized'];
    
    // Create trade
    $result = create_trade_service($traderId, $tradeData);
    
    if ($result['success']) {
        // Log successful trade creation using authoritative audit function
        audit_trade_create(
            $traderId,
            'create',
            $result['trade_id'],
            sprintf('Trade created successfully - Symbol: %s, Quantity: %s, Price: %s',
                $tradeData['symbol'],
                $tradeData['quantity'],
                $tradeData['price']
            )
        );
        
        json_ok(['id' => $result['trade_id']], 'Trade created successfully');
    } else {
        // Log failed trade creation attempt using authoritative audit function
        audit_trade_create(
            $traderId,
            'create_failed',
            null,
            sprintf('Failed to create trade - Symbol: %s, Error: %s',
                $tradeData['symbol'] ?? 'Unknown',
                $result['error'] ?? 'Unknown error'
            )
        );
        
        // Handle specific error cases
        json_fail($result['error'] ?? 'SERVER_ERROR', 'Failed to create trade');
    }
    
} catch (Exception $e) {
    // Log error using authoritative audit function
    audit_trade_create(
        $_SESSION['user_id'] ?? null,
        'system_error',
        null,
        'Trade create API error: ' . $e->getMessage()
    );
    
    app_log('error', 'trade_create_api_error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to create trade');
}