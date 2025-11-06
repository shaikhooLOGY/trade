<?php
/**
 * api/trades/update.php
 *
 * Trades API - Update trade with authoritative audit trail
 * POST/PUT /api/trades/update.php
 *
 * Update a trade record (owner or admin only)
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/logger/audit_log.php';

header('Content-Type: application/json');

// Allow POST or PUT requests
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
    json_fail('INVALID_INPUT', 'Only POST or PUT requests are allowed');
}

// Require authentication and active user
require_active_user_json('Authentication required');

// CSRF protection for mutating requests
csrf_api_middleware();

// Rate limiting
if (!rate_limit_api_middleware('trade_update', 10)) {
    exit; // Rate limit response already sent
}

try {
    // Read JSON input
    $input = get_json_input();
    
    // Validate required fields
    if (!isset($input['id']) || !is_numeric($input['id']) || (int)$input['id'] <= 0) {
        json_fail('VALIDATION_ERROR', 'Valid trade ID is required', ['field' => 'id']);
    }
    
    $tradeId = (int)$input['id'];
    $userId = (int)$_SESSION['user_id'];
    $isAdmin = !empty($_SESSION['is_admin']);
    
    global $mysqli;
    
    // Get existing trade to check ownership
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
    
    // Check permissions - user can only update their own trades unless admin
    if (!$isAdmin && (int)$trade['trader_id'] !== $userId) {
        json_forbidden('You can only update your own trades');
    }
    
    // Prepare update data
    $updateFields = [];
    $updateValues = [];
    $updateTypes = '';
    
    // Only allow updating certain fields
    $allowedFields = ['symbol', 'side', 'quantity', 'price', 'opened_at', 'closed_at', 'notes'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field]) && $input[$field] !== '') {
            $updateFields[] = "$field = ?";
            $updateValues[] = $input[$field];
            $updateTypes .= is_numeric($input[$field]) ? 'd' : 's';
        }
    }
    
    // Check if there are fields to update
    if (empty($updateFields)) {
        json_fail('VALIDATION_ERROR', 'No valid fields provided for update', ['allowed_fields' => $allowedFields]);
    }
    
    // Build and execute update query
    $sql = "UPDATE trades SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
    $updateValues[] = $tradeId;
    $updateTypes .= 'i';
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        json_database_error($mysqli, 'Prepare update query');
    }
    
    $stmt->bind_param($updateTypes, ...$updateValues);
    $success = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    if (!$success) {
        json_fail('DATABASE_ERROR', 'Failed to update trade');
    }
    
    if ($affectedRows === 0) {
        json_fail('VALIDATION_ERROR', 'No changes made to trade');
    }
    
    // Log successful trade update using authoritative audit function
    audit_trade_create(
        $userId,
        'update',
        $tradeId,
        sprintf('Trade updated successfully - Fields: %s',
            implode(', ', array_keys(array_intersect_key($input, array_flip($allowedFields))))
        )
    );
    
    json_ok(['id' => $tradeId], 'Trade updated successfully');
    
} catch (Exception $e) {
    // Log error using authoritative audit function
    audit_trade_create(
        $_SESSION['user_id'] ?? null,
        'system_error',
        $tradeId ?? null,
        'Trade update API error: ' . $e->getMessage()
    );
    
    app_log('error', 'trade_update_api_error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to update trade');
}