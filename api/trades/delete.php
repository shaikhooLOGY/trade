<?php
/**
 * api/trades/delete.php
 *
 * Trades API - Delete trade
 * DELETE /api/trades/delete.php
 *
 * Delete a trade record (owner or admin only)
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

// Allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    json_fail('INVALID_INPUT', 'Only DELETE requests are allowed');
}

// Require authentication and active user
require_active_user_json('Authentication required');

// CSRF protection for mutating requests
csrf_api_middleware();

// Rate limiting
if (!rate_limit_api_middleware('trade_delete', 5)) {
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
    
    // Get existing trade to check ownership and soft delete eligibility
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
    
    // Check permissions - user can only delete their own trades unless admin
    if (!$isAdmin && (int)$trade['trader_id'] !== $userId) {
        json_forbidden('You can only delete your own trades');
    }
    
    // Check if trade is in an MTM enrollment (prevent deletion if so)
    if (!empty($trade['enrollment_id'])) {
        json_fail('FORBIDDEN', 'Cannot delete trade that is part of an MTM enrollment');
    }
    
    // Perform soft delete if deleted_at column exists, otherwise hard delete
    $checkColumn = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'deleted_at'");
    $hasDeletedAt = $checkColumn->num_rows > 0;
    $checkColumn->close();
    
    if ($hasDeletedAt) {
        // Soft delete
        $stmt = $mysqli->prepare("UPDATE trades SET deleted_at = NOW() WHERE id = ?");
        if (!$stmt) {
            json_database_error($mysqli, 'Prepare soft delete query');
        }
        $stmt->bind_param('i', $tradeId);
    } else {
        // Hard delete
        $stmt = $mysqli->prepare("DELETE FROM trades WHERE id = ?");
        if (!$stmt) {
            json_database_error($mysqli, 'Prepare hard delete query');
        }
        $stmt->bind_param('i', $tradeId);
    }
    
    $success = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    if (!$success || $affectedRows === 0) {
        json_fail('DATABASE_ERROR', 'Failed to delete trade');
    }
    
    // Audit log successful trade deletion
    app_log('audit', json_encode([
        'event_type' => $hasDeletedAt ? 'trade_delete_soft' : 'trade_delete_hard',
        'trade_id' => $tradeId,
        'trader_id' => $userId,
        'symbol' => $trade['symbol'],
        'is_admin' => $isAdmin
    ]));
    
    json_ok(['id' => $tradeId, 'deleted' => true], 'Trade deleted successfully');
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'trade_delete_api_error: ' . $e->getMessage());
    
    json_fail('SERVER_ERROR', 'Failed to delete trade');
}