<?php
/**
 * includes/trades/repo.php
 * 
 * Trade Repository Layer - Database operations for trades
 */

// Include required files
require_once __DIR__ . '/../config.php';

/**
 * Create a new trade in the database
 * 
 * @param int $traderId User ID of the trader
 * @param array $tradeData Validated trade data
 * @return array Result with success status and trade ID or error
 */
function create_trade_repo(int $traderId, array $tradeData): array {
    global $mysqli;
    
    try {
        // Check if the 'trader_id' column exists in trades table
        $columnCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'trader_id'");
        $hasTraderId = $columnCheck->num_rows > 0;
        $columnCheck->close();
        
        if ($hasTraderId) {
            // Use the trader_id column
            $stmt = $mysqli->prepare("
                INSERT INTO trades (trader_id, symbol, side, quantity, price, opened_at, notes, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
        } else {
            // Check if there's a 'user_id' column instead
            $columnCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'user_id'");
            $hasUserId = $columnCheck->num_rows > 0;
            $columnCheck->close();
            
            if ($hasUserId) {
                $stmt = $mysqli->prepare("
                    INSERT INTO trades (user_id, symbol, side, quantity, price, opened_at, notes, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
            } else {
                // No user/trader column found, use without it (might be admin entry)
                $stmt = $mysqli->prepare("
                    INSERT INTO trades (symbol, side, quantity, price, opened_at, notes, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
            }
        }
        
        if (!$stmt) {
            throw new Exception('Failed to prepare insert query: ' . $mysqli->error);
        }
        
        $symbol = $tradeData['symbol'];
        $side = $tradeData['side'];
        $quantity = (float)$tradeData['quantity'];
        $price = (float)$tradeData['price'];
        $openedAt = $tradeData['opened_at'];
        $notes = $tradeData['notes'] ?? null;
        
        if ($hasTraderId) {
            $stmt->bind_param('issdss', $traderId, $symbol, $side, $quantity, $price, $openedAt, $notes);
        } elseif ($hasUserId) {
            $stmt->bind_param('issdss', $traderId, $symbol, $side, $quantity, $price, $openedAt, $notes);
        } else {
            $stmt->bind_param('sdsss', $symbol, $side, $quantity, $price, $openedAt, $notes);
        }
        
        $success = $stmt->execute();
        $tradeId = $stmt->insert_id;
        $stmt->close();
        
        if (!$success) {
            throw new Exception('Failed to execute insert query');
        }
        
        if ($tradeId === 0) {
            throw new Exception('Insert query succeeded but no trade ID returned');
        }
        
        return ['success' => true, 'trade_id' => $tradeId];
        
    } catch (Exception $e) {
        error_log('Trade creation repository error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'DATABASE_ERROR'];
    }
}

/**
 * Get trades for a user with filtering and pagination
 * 
 * @param int $traderId User ID of the trader
 * @param array $filters Optional filters (symbol, from, to date)
 * @param int $limit Number of trades to return
 * @param int $offset Number of trades to skip
 * @return array Array of trades with pagination info
 */
function get_user_trades_repo(int $traderId, array $filters = [], int $limit = 50, int $offset = 0): array {
    global $mysqli;
    
    try {
        // Check column names in trades table
        $traderColumn = 'trader_id';
        $userCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'user_id'");
        if ($userCheck->num_rows > 0) {
            $traderColumn = 'user_id';
        }
        $userCheck->close();
        
        // Build base query
        $whereConditions = [];
        $params = [];
        $paramTypes = '';
        
        // Add user filter
        if ($traderId > 0) {
            $whereConditions[] = "t.{$traderColumn} = ?";
            $params[] = $traderId;
            $paramTypes .= 'i';
        }
        
        // Add symbol filter
        if (!empty($filters['symbol'])) {
            $whereConditions[] = "t.symbol = ?";
            $params[] = strtoupper(trim($filters['symbol']));
            $paramTypes .= 's';
        }
        
        // Add date filters
        if (!empty($filters['from'])) {
            $whereConditions[] = "t.opened_at >= ?";
            $params[] = $filters['from'] . ' 00:00:00';
            $paramTypes .= 's';
        }
        
        if (!empty($filters['to'])) {
            $whereConditions[] = "t.opened_at <= ?";
            $params[] = $filters['to'] . ' 23:59:59';
            $paramTypes .= 's';
        }
        
        // Add deleted check if column exists
        $deletedCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'deleted_at'");
        $hasDeletedAt = $deletedCheck->num_rows > 0;
        $deletedCheck->close();
        
        if ($hasDeletedAt) {
            $whereConditions[] = "t.deleted_at IS NULL";
        }
        
        $whereClause = empty($whereConditions) ? '1=1' : implode(' AND ', $whereConditions);
        
        // Count total trades for pagination
        $countQuery = "SELECT COUNT(*) as total FROM trades t WHERE $whereClause";
        $countStmt = $mysqli->prepare($countQuery);
        if (!$countStmt) {
            throw new Exception('Failed to prepare count query');
        }
        
        if (!empty($params)) {
            $countStmt->bind_param($paramTypes, ...$params);
        }
        
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalTrades = (int)$countResult->fetch_assoc()['total'];
        $countStmt->close();
        
        // Get trades with pagination
        $query = "
            SELECT 
                t.*,
                CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END as is_mtm_enrolled
            FROM trades t
            LEFT JOIN mtm_enrollments e ON e.trade_id = t.id
            WHERE $whereClause
            ORDER BY t.opened_at DESC, t.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception('Failed to prepare trades query');
        }
        
        $params[] = $limit;
        $params[] = $offset;
        $paramTypes .= 'ii';
        
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $trades = [];
        while ($row = $result->fetch_assoc()) {
            $trades[] = $row;
        }
        $stmt->close();
        
        // Calculate pagination info
        $totalPages = ceil($totalTrades / $limit);
        $currentPage = floor($offset / $limit) + 1;
        
        return [
            'trades' => $trades,
            'pagination' => [
                'total' => $totalTrades,
                'page' => $currentPage,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'has_next' => $currentPage < $totalPages,
                'has_prev' => $currentPage > 1
            ]
        ];
        
    } catch (Exception $e) {
        error_log('Get user trades repository error: ' . $e->getMessage());
        return ['trades' => [], 'pagination' => ['total' => 0, 'page' => 1, 'limit' => $limit, 'total_pages' => 0]];
    }
}

/**
 * Get a single trade by ID
 * 
 * @param int $tradeId Trade ID
 * @param int $userId User ID for ownership check (0 if no check)
 * @param bool $isAdmin Whether user is admin
 * @return array|null Trade data or null if not found
 */
function get_trade_by_id_repo(int $tradeId, int $userId = 0, bool $isAdmin = false): ?array {
    global $mysqli;
    
    try {
        // Check column names
        $traderColumn = 'trader_id';
        $userCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'user_id'");
        if ($userCheck->num_rows > 0) {
            $traderColumn = 'user_id';
        }
        $userCheck->close();
        
        // Check if deleted_at column exists
        $deletedCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'deleted_at'");
        $hasDeletedAt = $deletedCheck->num_rows > 0;
        $deletedCheck->close();
        
        // Build query
        $whereConditions = ["t.id = ?"];
        $params = [$tradeId];
        $paramTypes = 'i';
        
        if ($userId > 0 && !$isAdmin) {
            $whereConditions[] = "t.{$traderColumn} = ?";
            $params[] = $userId;
            $paramTypes .= 'i';
        }
        
        if ($hasDeletedAt) {
            $whereConditions[] = "t.deleted_at IS NULL";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $query = "
            SELECT 
                t.*,
                CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END as is_mtm_enrolled
            FROM trades t
            LEFT JOIN mtm_enrollments e ON e.trade_id = t.id
            WHERE $whereClause
            LIMIT 1
        ";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception('Failed to prepare trade query');
        }
        
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $trade = $result->fetch_assoc();
        $stmt->close();
        
        return $trade ?: null;
        
    } catch (Exception $e) {
        error_log('Get trade by ID repository error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check if a trade exists (for validation)
 * 
 * @param int $tradeId Trade ID
 * @return bool True if trade exists, false otherwise
 */
function trade_exists_repo(int $tradeId): bool {
    global $mysqli;
    
    try {
        $stmt = $mysqli->prepare("SELECT 1 FROM trades WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Failed to prepare existence check query');
        }
        
        $stmt->bind_param('i', $tradeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        
        return $exists;
        
    } catch (Exception $e) {
        error_log('Trade existence check error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get trades summary for a user (for leaderboard/dashboard)
 * 
 * @param int $traderId User ID
 * @param string|null $period Period filter (weekly, monthly, all)
 * @return array Trade summary statistics
 */
function get_trades_summary_repo(int $traderId, ?string $period = 'all'): array {
    global $mysqli;
    
    try {
        // Check column names
        $traderColumn = 'trader_id';
        $userCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'user_id'");
        if ($userCheck->num_rows > 0) {
            $traderColumn = 'user_id';
        }
        $userCheck->close();
        
        // Build date filter based on period
        $dateCondition = '';
        $dateParams = [];
        $dateTypes = '';
        
        if ($period === 'weekly') {
            $dateCondition = "AND t.opened_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
        } elseif ($period === 'monthly') {
            $dateCondition = "AND t.opened_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        }
        
        // Check if deleted_at column exists
        $deletedCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'deleted_at'");
        $hasDeletedAt = $deletedCheck->num_rows > 0;
        $deletedCheck->close();
        
        $deletedCondition = $hasDeletedAt ? 'AND t.deleted_at IS NULL' : '';
        
        $query = "
            SELECT 
                COUNT(t.id) as total_trades,
                COUNT(CASE WHEN t.outcome = 'win' THEN 1 END) as winning_trades,
                COUNT(CASE WHEN t.outcome = 'loss' THEN 1 END) as losing_trades,
                SUM(CASE 
                    WHEN t.side = 'buy' THEN t.quantity * (COALESCE(t.close_price, t.price) - t.price)
                    ELSE t.quantity * (t.price - COALESCE(t.close_price, t.price))
                END) as total_pnl,
                AVG(CASE 
                    WHEN t.side = 'buy' THEN (COALESCE(t.close_price, t.price) - t.price) / t.price * 100
                    ELSE (t.price - COALESCE(t.close_price, t.price)) / t.price * 100
                END) as avg_return_pct
            FROM trades t 
            WHERE t.{$traderColumn} = ? 
            $dateCondition 
            $deletedCondition
        ";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception('Failed to prepare summary query');
        }
        
        $stmt->bind_param('i', $traderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $summary = $result->fetch_assoc();
        $stmt->close();
        
        // Calculate win rate
        $totalTrades = (int)($summary['total_trades'] ?? 0);
        $winningTrades = (int)($summary['winning_trades'] ?? 0);
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        
        return [
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => (int)($summary['losing_trades'] ?? 0),
            'win_rate' => round($winRate, 2),
            'total_pnl' => round((float)($summary['total_pnl'] ?? 0), 2),
            'avg_return_pct' => round((float)($summary['avg_return_pct'] ?? 0), 2)
        ];
        
    } catch (Exception $e) {
        error_log('Get trades summary repository error: ' . $e->getMessage());
        return [
            'total_trades' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
            'win_rate' => 0,
            'total_pnl' => 0,
            'avg_return_pct' => 0
        ];
    }
}