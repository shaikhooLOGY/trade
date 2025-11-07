<?php
/**
 * api/trades/create.php
 *
 * Trades API - Create new trade with validation, PnL computation, and funds deduction
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
    json_error('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

// CSRF protection for mutating requests - E2E test bypass
require_csrf_json();

// Require authentication and active user
require_active_user_json('Authentication required');

// Rate limiting: 30 per minute
require_rate_limit('api:trades:create', 30);

try {
    // Read JSON input
    $input = get_json_input();
    
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    
    global $mysqli;
    
    // Handle idempotency
    $idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
    $idempotentResponse = null;
    
    if ($idempotencyKey) {
        $keyHash = hash('sha256', $idempotencyKey . json_encode($input, 0));
        
        // Check if this idempotency key was already used
        $idempotencyStmt = $mysqli->prepare("
            SELECT response_data FROM idempotency_keys
            WHERE key_hash = ? AND endpoint_path = ?
        ");
        $path = '/api/trades/create.php';
        $idempotencyStmt->bind_param('ss', $keyHash, $path);
        $idempotencyStmt->execute();
        $idempotencyResult = $idempotencyStmt->get_result();
        $existingIdempotency = $idempotencyResult->fetch_assoc();
        $idempotencyStmt->close();
        
        if ($existingIdempotency) {
            // Return the previous response
            $previousResponse = json_decode($existingIdempotency['response_data'], true);
            $previousResponse['meta']['idempotent'] = true;
            $previousResponse['meta']['idempotency_key'] = $idempotencyKey;
            
            header('Content-Type: application/json');
            echo json_encode($previousResponse, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }
    }
    
    // Validate and sanitize input
    $validation = validate_enhanced_trade_input($input);
    if (!$validation['valid']) {
        json_validation_error($validation['errors'], 'Trade validation failed');
    }
    $tradeData = $validation['data'];
    
    // Start transaction for trade creation and funds deduction
    $mysqli->begin_transaction();
    
    try {
        // Get user trading capital and funds available
        $userStmt = $mysqli->prepare("
            SELECT trading_capital, funds_available
            FROM users
            WHERE id = ? FOR UPDATE
        ");
        $userStmt->bind_param('i', $traderId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $user = $userResult->fetch_assoc();
        $userStmt->close();
        
        if (!$user) {
            $mysqli->rollback();
            json_error('NOT_FOUND', 'User not found');
        }
        
        // Calculate position percentage
        $positionPercent = null;
        if (isset($tradeData['allocation_amount']) && isset($user['trading_capital']) && $user['trading_capital'] > 0) {
            $positionPercent = round(($tradeData['allocation_amount'] / $user['trading_capital']) * 100, 2);
        }
        
        // Validate funds availability if allocation_amount provided
        if (isset($tradeData['allocation_amount']) && $tradeData['allocation_amount'] > 0) {
            if ($user['funds_available'] < $tradeData['allocation_amount']) {
                $mysqli->rollback();
                json_error('VALIDATION_ERROR', 'Insufficient funds available for allocation');
            }
        }
        
        // Calculate PnL if exit_price is provided
        $pnl = 0;
        $outcome = 'open'; // default outcome
        if (isset($tradeData['exit_price']) && isset($tradeData['entry_price']) && isset($tradeData['quantity'])) {
            $pnl = ($tradeData['exit_price'] - $tradeData['entry_price']) * $tradeData['quantity'];
            
            // Determine outcome
            if ($pnl > 0) {
                $outcome = 'win';
            } elseif ($pnl < 0) {
                $outcome = 'loss';
            } else {
                $outcome = 'be'; // break even
            }
        }
        
        // Create trade with computed values
        $stmt = $mysqli->prepare("
            INSERT INTO trades (
                user_id, symbol, quantity, entry_price, exit_price,
                stop_loss, target_price, allocation_amount, position_percent,
                pnl, outcome, notes, opened_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        
        if (!$stmt) {
            throw new Exception('Failed to prepare trade query: ' . $mysqli->error);
        }
        
        $stmt->bind_param(
            'isdddddddss',
            $traderId,
            $tradeData['symbol'],
            $tradeData['quantity'],
            $tradeData['entry_price'],
            $tradeData['exit_price'] ?? null,
            $tradeData['stop_loss'] ?? null,
            $tradeData['target_price'] ?? null,
            $tradeData['allocation_amount'] ?? null,
            $positionPercent,
            $pnl,
            $outcome,
            $tradeData['notes'] ?? null
        );
        
        $success = $stmt->execute();
        if (!$success) {
            $stmt->close();
            throw new Exception('Failed to create trade: ' . $stmt->error);
        }
        
        $tradeId = $mysqli->insert_id;
        $stmt->close();
        
        // Update user's funds available if allocation_amount provided
        if (isset($tradeData['allocation_amount']) && $tradeData['allocation_amount'] > 0) {
            $newFundsAvailable = $user['funds_available'] - $tradeData['allocation_amount'];
            
            if ($newFundsAvailable < 0) {
                $mysqli->rollback();
                json_error('VALIDATION_ERROR', 'Insufficient funds - would result in negative balance');
            }
            
            $updateFundsStmt = $mysqli->prepare("
                UPDATE users
                SET funds_available = ?
                WHERE id = ?
            ");
            $updateFundsStmt->bind_param('di', $newFundsAvailable, $traderId);
            $updateFundsStmt->execute();
            $updateFundsStmt->close();
        }
        
        // Store idempotency key if provided
        if ($idempotencyKey) {
            $responseData = json_encode([
                'success' => true,
                'data' => ['trade_id' => $tradeId, 'outcome' => $outcome, 'position_percent' => $positionPercent],
                'message' => 'Trade created successfully',
                'timestamp' => date('c'),
                'meta' => ['idempotent' => false, 'idempotency_key' => $idempotencyKey]
            ]);
            
            $idempotencyInsert = $mysqli->prepare("
                INSERT INTO idempotency_keys (key_hash, endpoint_path, request_method, user_id, response_data, created_at_ts)
                VALUES (?, ?, 'POST', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE response_data = VALUES(response_data)
            ");
            $idempotencyInsert->bind_param('ssiss', $keyHash, $path, $traderId, $responseData);
            $idempotencyInsert->execute();
            $idempotencyInsert->close();
        }
        
        // Log successful trade creation
        app_log('info', sprintf(
            'Trade created: ID=%d, User=%d, Symbol=%s, Qty=%s, Entry=%s, Exit=%s, PnL=%s, Outcome=%s',
            $tradeId,
            $traderId,
            $tradeData['symbol'],
            $tradeData['quantity'],
            $tradeData['entry_price'],
            $tradeData['exit_price'] ?? 'null',
            $pnl,
            $outcome
        ));
        
        // Commit transaction
        $mysqli->commit();
        
        // Return success response in unified JSON envelope format
        json_success([
            'trade_id' => $tradeId,
            'outcome' => $outcome,
            'position_percent' => $positionPercent
        ], 'Trade created successfully', [
            'idempotent' => false,
            'idempotency_key' => $idempotencyKey
        ]);
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'trade_create_api_error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Failed to create trade');
}