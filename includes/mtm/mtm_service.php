<?php
/**
 * MTM Service Layer
 * 
 * Core business logic for TMS-MTM module
 */

if (!function_exists('mtm_enroll')) {
    /**
     * Enroll a trader in an MTM model
     * 
     * @param int $traderId Trader ID from session
     * @param int $modelId Model ID to enroll in
     * @param string $tier Tier to enroll in
     * @return array ['success' => bool, 'enrollment_id' => int|null, 'unlocked_task_id' => int|null, 'error' => string|null]
     */
    function mtm_enroll(int $traderId, int $modelId, string $tier): array {
        try {
            // Get database connection
            $mysqli = $GLOBALS['mysqli'];
            
            // Start transaction
            $mysqli->begin_transaction();
            
            // Log enrollment attempt
            app_log([
                'event' => 'mtm_enroll_attempt',
                'trader_id' => $traderId,
                'model_id' => $modelId,
                'tier' => $tier,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Check for existing enrollment (UNIQUE constraint will catch this too)
            $checkStmt = $mysqli->prepare("SELECT id FROM mtm_enrollments WHERE trader_id = ? AND model_id = ?");
            if (!$checkStmt) {
                throw new Exception("Failed to prepare enrollment check");
            }
            
            $checkStmt->bind_param('ii', $traderId, $modelId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                $checkStmt->close();
                $mysqli->rollback();
                
                app_log([
                    'event' => 'mtm_enroll_conflict',
                    'trader_id' => $traderId,
                    'model_id' => $modelId,
                    'reason' => 'duplicate_enrollment'
                ]);
                
                return [
                    'success' => false,
                    'enrollment_id' => null,
                    'unlocked_task_id' => null,
                    'error' => 'ALREADY_ENROLLED'
                ];
            }
            
            $checkStmt->close();
            
            // Insert new enrollment
            $enrollStmt = $mysqli->prepare("
                INSERT INTO mtm_enrollments (trader_id, model_id, tier, status) 
                VALUES (?, ?, ?, 'active')
            ");
            
            if (!$enrollStmt) {
                throw new Exception("Failed to prepare enrollment statement");
            }
            
            $enrollStmt->bind_param('iis', $traderId, $modelId, $tier);
            
            if (!$enrollStmt->execute()) {
                $enrollStmt->close();
                $mysqli->rollback();
                
                // Check if it's a duplicate key error
                if ($mysqli->errno === 1062) {
                    app_log([
                        'event' => 'mtm_enroll_conflict',
                        'trader_id' => $traderId,
                        'model_id' => $modelId,
                        'reason' => 'unique_constraint'
                    ]);
                    
                    return [
                        'success' => false,
                        'enrollment_id' => null,
                        'unlocked_task_id' => null,
                        'error' => 'ALREADY_ENROLLED'
                    ];
                }
                
                throw new Exception("Failed to execute enrollment: " . $mysqli->error);
            }
            
            $enrollmentId = $mysqli->insert_id;
            $enrollStmt->close();
            
            // Unlock first task for this model and tier
            $unlockedTaskId = resolve_next_task($modelId, $tier, $mysqli);
            
            // Commit transaction
            $mysqli->commit();
            
            // Log successful enrollment
            app_log([
                'event' => 'mtm_enroll_success',
                'trader_id' => $traderId,
                'model_id' => $modelId,
                'enrollment_id' => $enrollmentId,
                'unlocked_task_id' => $unlockedTaskId,
                'tier' => $tier
            ]);
            
            return [
                'success' => true,
                'enrollment_id' => $enrollmentId,
                'unlocked_task_id' => $unlockedTaskId,
                'error' => null
            ];
            
        } catch (Exception $e) {
            if (isset($mysqli)) {
                $mysqli->rollback();
            }
            
            app_log([
                'event' => 'mtm_enroll_error',
                'trader_id' => $traderId,
                'model_id' => $modelId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'enrollment_id' => null,
                'unlocked_task_id' => null,
                'error' => 'SERVER_ERROR'
            ];
        }
    }
}

if (!function_exists('get_user_enrollments')) {
    /**
     * Get all enrollments for a trader with model information
     * 
     * @param int $traderId Trader ID
     * @return array Array of enrollment data
     */
    function get_user_enrollments(int $traderId): array {
        try {
            $mysqli = $GLOBALS['mysqli'];
            
            $stmt = $mysqli->prepare("
                SELECT 
                    e.id,
                    e.model_id,
                    e.tier,
                    e.status,
                    e.started_at,
                    m.code as model_code,
                    m.name as model_name
                FROM mtm_enrollments e
                JOIN mtm_models m ON e.model_id = m.id
                WHERE e.trader_id = ?
                ORDER BY e.created_at DESC
            ");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare enrollment query");
            }
            
            $stmt->bind_param('i', $traderId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $enrollments = [];
            while ($row = $result->fetch_assoc()) {
                $enrollments[] = $row;
            }
            
            $stmt->close();
            return $enrollments;
            
        } catch (Exception $e) {
            app_log([
                'event' => 'get_enrollments_error',
                'trader_id' => $traderId,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}

if (!function_exists('create_trade')) {
    /**
     * Create a new trade record
     * 
     * @param int $traderId Trader ID from session
     * @param array $tradeData Trade data
     * @return array ['success' => bool, 'trade_id' => int|null, 'error' => string|null]
     */
    function create_trade(int $traderId, array $tradeData): array {
        try {
            $mysqli = $GLOBALS['mysqli'];
            
            // Validate required fields
            $required = ['symbol', 'side', 'quantity', 'price', 'opened_at'];
            foreach ($required as $field) {
                if (!isset($tradeData[$field])) {
                    return [
                        'success' => false,
                        'trade_id' => null,
                        'error' => 'MISSING_FIELD_' . strtoupper($field)
                    ];
                }
            }
            
            // Log trade creation attempt
            app_log([
                'event' => 'trade_create',
                'trader_id' => $traderId,
                'symbol' => $tradeData['symbol'],
                'side' => $tradeData['side'],
                'quantity' => $tradeData['quantity'],
                'price' => $tradeData['price'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Insert trade
            $stmt = $mysqli->prepare("
                INSERT INTO trades (trader_id, symbol, side, quantity, price, opened_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare trade statement");
            }
            
            $notes = $tradeData['notes'] ?? null;
            
            $stmt->bind_param(
                'issdds',
                $traderId,
                $tradeData['symbol'],
                $tradeData['side'],
                $tradeData['quantity'],
                $tradeData['price'],
                $tradeData['opened_at'],
                $notes
            );
            
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception("Failed to execute trade insertion: " . $mysqli->error);
            }
            
            $tradeId = $mysqli->insert_id;
            $stmt->close();
            
            app_log([
                'event' => 'trade_created',
                'trader_id' => $traderId,
                'trade_id' => $tradeId,
                'symbol' => $tradeData['symbol']
            ]);
            
            return [
                'success' => true,
                'trade_id' => $tradeId,
                'error' => null
            ];
            
        } catch (Exception $e) {
            app_log([
                'event' => 'trade_create_error',
                'trader_id' => $traderId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'trade_id' => null,
                'error' => 'SERVER_ERROR'
            ];
        }
    }
}

if (!function_exists('get_user_trades')) {
    /**
     * Get trades for a user with optional filtering
     * 
     * @param int $traderId Trader ID
     * @param array $filters Optional filters ['symbol', 'from', 'to']
     * @param int $limit Maximum number of trades to return
     * @param int $offset Offset for pagination
     * @return array Array of trade data
     */
    function get_user_trades(int $traderId, array $filters = [], int $limit = 50, int $offset = 0): array {
        try {
            $mysqli = $GLOBALS['mysqli'];
            
            $where = ['trader_id = ?'];
            $params = [$traderId];
            $types = ['i'];
            
            if (!empty($filters['symbol'])) {
                $where[] = 'symbol LIKE ?';
                $params[] = '%' . $filters['symbol'] . '%';
                $types[] = 's';
            }
            
            if (!empty($filters['from'])) {
                $where[] = 'DATE(opened_at) >= ?';
                $params[] = $filters['from'];
                $types[] = 's';
            }
            
            if (!empty($filters['to'])) {
                $where[] = 'DATE(opened_at) <= ?';
                $params[] = $filters['to'];
                $types[] = 's';
            }
            
            $sql = "SELECT * FROM trades WHERE " . implode(' AND ', $where) . " ORDER BY opened_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $types[] = 'i';
            $types[] = 'i';
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception("Failed to prepare trades query");
            }
            
            $stmt->bind_param(implode('', $types), ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }
            
            $stmt->close();
            return $trades;
            
        } catch (Exception $e) {
            app_log([
                'event' => 'get_trades_error',
                'trader_id' => $traderId,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}