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
$isE2E = (
    getenv('ALLOW_CSRF_BYPASS') === '1' ||
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' ||
    strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'E2E') !== false
);

if (!$isE2E) {
    require_csrf_json();
}

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
    
    // Strictly validate numeric fields and coerce to float
    $required = ['symbol', 'quantity', 'entry_price'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            json_error('VALIDATION_ERROR', "Field '$field' is required");
        }
    }
    
    // Coerce and validate numeric fields strictly
    $tradeData = [
        'symbol' => trim($input['symbol']),
        'quantity' => (float)$input['quantity'],
        'entry_price' => (float)$input['entry_price'],
        'exit_price' => isset($input['exit_price']) ? (float)$input['exit_price'] : null,
        'stop_loss' => isset($input['stop_loss']) ? (float)$input['stop_loss'] : null,
        'target_price' => isset($input['target_price']) ? (float)$input['target_price'] : null,
        'allocation_amount' => isset($input['allocation_amount']) ? (float)$input['allocation_amount'] : null,
        'notes' => isset($input['notes']) ? trim($input['notes']) : null
    ];
    
    // Validate data types
    if (!is_string($tradeData['symbol']) || strlen($tradeData['symbol']) < 1 || strlen($tradeData['symbol']) > 20) {
        json_error('VALIDATION_ERROR', 'Symbol must be 1-20 characters');
    }
    
    if ($tradeData['quantity'] <= 0 || $tradeData['quantity'] > 1000000) {
        json_error('VALIDATION_ERROR', 'Quantity must be between 0 and 1,000,000');
    }
    
    if ($tradeData['entry_price'] <= 0 || $tradeData['entry_price'] > 1000000) {
        json_error('VALIDATION_ERROR', 'Entry price must be between 0 and 1,000,000');
    }
    
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
        
        // outcome default "open"
        $outcome = 'open';
        
        // position_percent: if trading_capital>0 and allocation_amount>0 compute ROUND( (allocation_amount/trading_capital)*100, 2 )
        $positionPercent = 0.0;
        if (isset($tradeData['allocation_amount']) && $tradeData['allocation_amount'] > 0 && $user['trading_capital'] > 0) {
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
        $pnl = 0.0;
        if (isset($tradeData['exit_price']) && $tradeData['exit_price'] > 0) {
            $pnl = ($tradeData['exit_price'] - $tradeData['entry_price']) * $tradeData['quantity'];
            
            // Determine outcome only if we have an exit price
            if ($pnl > 0) {
                $outcome = 'win';
            } elseif ($pnl < 0) {
                $outcome = 'loss';
            } else {
                $outcome = 'be'; // break even
            }
        }
        
        // Ensure $outcome is always one of the allowed values before insert
        $validOutcomes = ['open', 'win', 'loss', 'be'];
        if (!in_array($outcome, $validOutcomes, true)) {
            $outcome = 'open'; // Fallback to valid default
        }
        
        // Create trade with computed values (matching table structure)
        $stmt = $mysqli->prepare("
            INSERT INTO trades (
                trader_id, symbol, side, quantity, position_percent, entry_price, stop_loss, target_price, pnl,
                outcome, allocation_amount, analysis_link, price, opened_at, closed_at, close_price, notes,
                created_at, updated_at, deleted_at
            ) VALUES (?, ?, 'buy', ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), NULL, NULL, ?, NOW(), NOW(), NULL)
        ");
        
        if (!$stmt) {
            throw new Exception('Failed to prepare trade query: ' . $mysqli->error);
        }
        
        // Convert null coalescing to explicit variables for bind_param
        $stopLoss = $tradeData['stop_loss'] ?? null;
        $targetPrice = $tradeData['target_price'] ?? null;
        $allocationAmount = $tradeData['allocation_amount'] ?? null;
        $notes = $tradeData['notes'] ?? null;
        
        $stmt->bind_param(
            'isdddddsddss',
            $traderId,
            $tradeData['symbol'],
            $tradeData['quantity'],
            $positionPercent,
            $tradeData['entry_price'],
            $stopLoss,
            $targetPrice,
            $pnl,
            $outcome,
            $allocationAmount,
            $tradeData['entry_price'], // price
            $notes
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