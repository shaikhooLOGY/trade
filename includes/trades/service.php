<?php
/**
 * includes/trades/service.php
 * 
 * Trade Service Layer - Business logic for trade operations
 */

// Include required files
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/repo.php';

/**
 * Create a new trade
 * 
 * @param int $traderId User ID of the trader
 * @param array $tradeData Validated trade data
 * @return array Result with success status and trade ID or error
 */
function create_trade_service(int $traderId, array $tradeData): array {
    global $mysqli;
    
    try {
        // Use the repository to create the trade
        $result = create_trade_repo($traderId, $tradeData);
        
        if ($result['success']) {
            // Log the successful trade creation
            app_log('info', sprintf(
                'Trade created: ID=%d, User=%d, Symbol=%s, Qty=%d, Price=%.2f',
                $result['trade_id'],
                $traderId,
                $tradeData['symbol'],
                $tradeData['quantity'],
                $tradeData['price']
            ));
        }
        
        return $result;
        
    } catch (Exception $e) {
        app_log('error', 'Trade creation service error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'TRADE_CREATE_FAILED'];
    }
}

/**
 * Get trades for a user with filtering and pagination
 * 
 * @param int $traderId User ID of the trader
 * @param array $filters Optional filters (symbol, from, to date)
 * @param int $limit Number of trades to return
 * @param int $offset Number of trades to skip
 * @return array Array of trades
 */
function get_trades_service(int $traderId, array $filters = [], int $limit = 50, int $offset = 0): array {
    try {
        return get_user_trades_repo($traderId, $filters, $limit, $offset);
        
    } catch (Exception $e) {
        app_log('error', 'Get trades service error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get a single trade by ID with ownership verification
 * 
 * @param int $tradeId Trade ID
 * @param int $userId User ID requesting the trade
 * @param bool $isAdmin Whether the user is an admin
 * @return array|null Trade data or null if not found/no permission
 */
function get_trade_service(int $tradeId, int $userId, bool $isAdmin = false): ?array {
    try {
        global $mysqli;
        
        $stmt = $mysqli->prepare("
            SELECT * FROM trades 
            WHERE id = ? 
            AND (trader_id = ? OR ? = 1)
            AND (deleted_at IS NULL)
        ");
        
        if (!$stmt) {
            throw new Exception('Failed to prepare trade query');
        }
        
        $stmt->bind_param('iii', $tradeId, $userId, $isAdmin ? 1 : 0);
        $stmt->execute();
        $result = $stmt->get_result();
        $trade = $result->fetch_assoc();
        $stmt->close();
        
        return $trade ?: null;
        
    } catch (Exception $e) {
        app_log('error', 'Get trade service error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Update a trade
 * 
 * @param int $tradeId Trade ID
 * @param int $userId User ID of the owner
 * @param bool $isAdmin Whether the user is an admin
 * @param array $updateData Data to update
 * @return array Result with success status
 */
function update_trade_service(int $tradeId, int $userId, bool $isAdmin, array $updateData): array {
    try {
        global $mysqli;
        
        // Verify ownership first
        $trade = get_trade_service($tradeId, $userId, $isAdmin);
        if (!$trade) {
            return ['success' => false, 'error' => 'TRADE_NOT_FOUND'];
        }
        
        // Check if user can edit this trade
        if (!$isAdmin && (int)$trade['trader_id'] !== $userId) {
            return ['success' => false, 'error' => 'PERMISSION_DENIED'];
        }
        
        // Prepare update fields
        $updateFields = [];
        $updateValues = [];
        $updateTypes = '';
        
        $allowedFields = ['symbol', 'side', 'quantity', 'price', 'opened_at', 'closed_at', 'notes'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $updateData)) {
                $updateFields[] = "$field = ?";
                $updateValues[] = $updateData[$field];
                $updateTypes .= is_numeric($updateData[$field]) ? 'd' : 's';
            }
        }
        
        if (empty($updateFields)) {
            return ['success' => false, 'error' => 'NO_FIELDS_TO_UPDATE'];
        }
        
        // Add updated_at timestamp
        $updateFields[] = 'updated_at = NOW()';
        
        // Build and execute update query
        $sql = "UPDATE trades SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateValues[] = $tradeId;
        $updateTypes .= 'i';
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare update query');
        }
        
        $stmt->bind_param($updateTypes, ...$updateValues);
        $success = $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if (!$success) {
            return ['success' => false, 'error' => 'UPDATE_FAILED'];
        }
        
        if ($affectedRows === 0) {
            return ['success' => false, 'error' => 'NO_CHANGES_MADE'];
        }
        
        // Log the update
        app_log('info', sprintf(
            'Trade updated: ID=%d, User=%d, Fields=%s',
            $tradeId,
            $userId,
            implode(', ', array_keys($updateData))
        ));
        
        return ['success' => true, 'trade_id' => $tradeId];
        
    } catch (Exception $e) {
        app_log('error', 'Update trade service error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVICE_ERROR'];
    }
}

/**
 * Delete a trade
 * 
 * @param int $tradeId Trade ID
 * @param int $userId User ID of the owner
 * @param bool $isAdmin Whether the user is an admin
 * @return array Result with success status
 */
function delete_trade_service(int $tradeId, int $userId, bool $isAdmin): array {
    try {
        global $mysqli;
        
        // Verify ownership first
        $trade = get_trade_service($tradeId, $userId, $isAdmin);
        if (!$trade) {
            return ['success' => false, 'error' => 'TRADE_NOT_FOUND'];
        }
        
        // Check if user can delete this trade
        if (!$isAdmin && (int)$trade['trader_id'] !== $userId) {
            return ['success' => false, 'error' => 'PERMISSION_DENIED'];
        }
        
        // Check if trade is part of MTM enrollment (prevent deletion)
        if (!empty($trade['enrollment_id'])) {
            return ['success' => false, 'error' => 'MTM_ENROLLMENT_LINKED'];
        }
        
        // Perform soft or hard delete based on table structure
        $checkColumn = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'deleted_at'");
        $hasDeletedAt = $checkColumn->num_rows > 0;
        $checkColumn->close();
        
        if ($hasDeletedAt) {
            // Soft delete
            $stmt = $mysqli->prepare("UPDATE trades SET deleted_at = NOW() WHERE id = ?");
        } else {
            // Hard delete
            $stmt = $mysqli->prepare("DELETE FROM trades WHERE id = ?");
        }
        
        if (!$stmt) {
            throw new Exception('Failed to prepare delete query');
        }
        
        $stmt->bind_param('i', $tradeId);
        $success = $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if (!$success || $affectedRows === 0) {
            return ['success' => false, 'error' => 'DELETE_FAILED'];
        }
        
        // Log the deletion
        app_log('info', sprintf(
            'Trade %s: ID=%d, User=%d, Symbol=%s',
            $hasDeletedAt ? 'soft deleted' : 'deleted',
            $tradeId,
            $userId,
            $trade['symbol']
        ));
        
        return ['success' => true, 'trade_id' => $tradeId];
        
    } catch (Exception $e) {
        app_log('error', 'Delete trade service error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVICE_ERROR'];
    }
}

/**
 * Validate trade input data
 * 
 * @param array $input Raw input data
 * @return array Validation result with 'valid' boolean and 'errors' or 'sanitized' data
 */
function validate_trade_input_service(array $input): array {
    $errors = [];
    $sanitized = [];
    
    // Required fields
    $required = ['symbol', 'side', 'quantity', 'price', 'opened_at'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            $errors[$field] = "Field '$field' is required";
        }
    }
    
    // Validate symbol
    if (isset($input['symbol'])) {
        $symbol = trim($input['symbol']);
        if (strlen($symbol) > 32) {
            $errors['symbol'] = 'Symbol must be 32 characters or less';
        } elseif (!preg_match('/^[A-Z0-9._-]+$/', $symbol)) {
            $errors['symbol'] = 'Symbol must contain only uppercase letters, numbers, dots, underscores, and hyphens';
        } else {
            $sanitized['symbol'] = strtoupper($symbol);
        }
    }
    
    // Validate side
    if (isset($input['side'])) {
        $side = strtolower(trim($input['side']));
        if (!in_array($side, ['buy', 'sell'], true)) {
            $errors['side'] = 'Side must be either "buy" or "sell"';
        } else {
            $sanitized['side'] = $side;
        }
    }
    
    // Validate quantity
    if (isset($input['quantity'])) {
        $quantity = (float)$input['quantity'];
        if ($quantity <= 0) {
            $errors['quantity'] = 'Quantity must be greater than 0';
        } else {
            $sanitized['quantity'] = $quantity;
        }
    }
    
    // Validate price
    if (isset($input['price'])) {
        $price = (float)$input['price'];
        if ($price <= 0) {
            $errors['price'] = 'Price must be greater than 0';
        } else {
            $sanitized['price'] = $price;
        }
    }
    
    // Validate opened_at
    if (isset($input['opened_at'])) {
        $openedAt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $input['opened_at']);
        if (!$openedAt) {
            $openedAt = DateTime::createFromFormat('Y-m-d H:i:s', $input['opened_at']);
        }
        if (!$openedAt) {
            $errors['opened_at'] = 'Invalid date format. Use ISO 8601 format';
        } else {
            $sanitized['opened_at'] = $openedAt->format('Y-m-d H:i:s');
        }
    }
    
    // Validate optional closed_at
    if (isset($input['closed_at']) && $input['closed_at'] !== '') {
        $closedAt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $input['closed_at']);
        if (!$closedAt) {
            $closedAt = DateTime::createFromFormat('Y-m-d H:i:s', $input['closed_at']);
        }
        if (!$closedAt) {
            $errors['closed_at'] = 'Invalid closed_at date format';
        } else {
            $sanitized['closed_at'] = $closedAt->format('Y-m-d H:i:s');
        }
    }
    
    // Sanitize notes
    if (isset($input['notes'])) {
        $notes = trim($input['notes']);
        if (strlen($notes) > 1000) {
            $errors['notes'] = 'Notes must be 1000 characters or less';
        } else {
            $sanitized['notes'] = htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'sanitized' => $sanitized
    ];
}

/**
 * Calculate trade metrics for dashboard
 * 
 * @param int $traderId User ID
 * @param string|null $fromDate Optional date range start
 * @param string|null $toDate Optional date range end
 * @return array Trade metrics
 */
function calculate_trade_metrics_service(int $traderId, ?string $fromDate = null, ?string $toDate = null): array {
    try {
        global $mysqli;
        
        // Base query conditions
        $whereConditions = ["trader_id = ?", "deleted_at IS NULL"];
        $params = [$traderId];
        $paramTypes = 'i';
        
        // Add date filters if provided
        if ($fromDate) {
            $whereConditions[] = "opened_at >= ?";
            $params[] = $fromDate . ' 00:00:00';
            $paramTypes .= 's';
        }
        
        if ($toDate) {
            $whereConditions[] = "opened_at <= ?";
            $params[] = $toDate . ' 23:59:59';
            $paramTypes .= 's';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Get total trades
        $stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM trades WHERE $whereClause");
        if (!$stmt) {
            throw new Exception('Failed to prepare total trades query');
        }
        
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalTrades = (int)$result->fetch_assoc()['total'];
        $stmt->close();
        
        // Get winning trades
        $winningWhere = $whereClause . " AND outcome = 'win'";
        $stmt = $mysqli->prepare("SELECT COUNT(*) as wins FROM trades WHERE $winningWhere");
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $winningTrades = (int)$result->fetch_assoc()['wins'];
        $stmt->close();
        
        // Calculate win rate
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        
        // Get total P&L (simplified calculation)
        $pnlWhere = $whereClause . " AND quantity IS NOT NULL AND price IS NOT NULL";
        $stmt = $mysqli->prepare("
            SELECT 
                SUM(CASE 
                    WHEN side = 'buy' THEN quantity * (COALESCE(close_price, price) - price)
                    ELSE quantity * (price - COALESCE(close_price, price))
                END) as total_pnl
            FROM trades WHERE $pnlWhere
        ");
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalPnl = (float)($result->fetch_assoc()['total_pnl'] ?? 0);
        $stmt->close();
        
        return [
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'win_rate' => round($winRate, 2),
            'total_pnl' => round($totalPnl, 2)
        ];
        
    } catch (Exception $e) {
        app_log('error', 'Calculate trade metrics error: ' . $e->getMessage());
        return [
            'total_trades' => 0,
            'winning_trades' => 0,
            'win_rate' => 0,
            'total_pnl' => 0
        ];
    }
}