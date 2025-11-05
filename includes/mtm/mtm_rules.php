<?php
/**
 * MTM Rules Engine
 * 
 * Business rules and task resolution logic for TMS-MTM module
 */

if (!function_exists('resolve_next_task')) {
    /**
     * Resolve the next task for a trader in a specific model and tier
     * 
     * @param int $modelId Model ID
     * @param string $tier Tier (basic, intermediate, advanced)
     * @param mysqli $mysqli Database connection
     * @return int|null Task ID of the next task to unlock, or null if no task found
     */
    function resolve_next_task(int $modelId, string $tier, mysqli $mysqli): ?int {
        try {
            // Get the first active task for this model and tier (lowest sort_order)
            $stmt = $mysqli->prepare("
                SELECT id 
                FROM mtm_tasks 
                WHERE model_id = ? AND tier = ? AND is_active = 1 
                ORDER BY sort_order ASC, id ASC 
                LIMIT 1
            ");
            
            if (!$stmt) {
                app_log([
                    'event' => 'resolve_task_error',
                    'model_id' => $modelId,
                    'tier' => $tier,
                    'error' => 'Failed to prepare task query'
                ]);
                return null;
            }
            
            $stmt->bind_param('is', $modelId, $tier);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                app_log([
                    'event' => 'resolve_task_info',
                    'model_id' => $modelId,
                    'tier' => $tier,
                    'message' => 'No active tasks found for model and tier'
                ]);
                $stmt->close();
                return null;
            }
            
            $task = $result->fetch_assoc();
            $taskId = (int)$task['id'];
            $stmt->close();
            
            app_log([
                'event' => 'resolve_task_success',
                'model_id' => $modelId,
                'tier' => $tier,
                'task_id' => $taskId
            ]);
            
            return $taskId;
            
        } catch (Exception $e) {
            app_log([
                'event' => 'resolve_task_error',
                'model_id' => $modelId,
                'tier' => $tier,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}

if (!function_exists('parse_rule_config')) {
    /**
     * Parse rule configuration from JSON text
     * 
     * @param string $text JSON configuration text
     * @return array Parsed configuration array
     */
    function parse_rule_config(string $text): array {
        $config = json_decode($text, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            app_log([
                'event' => 'parse_rule_config_error',
                'error' => json_last_error_msg(),
                'raw_text' => substr($text, 0, 200) // Log first 200 chars for debugging
            ]);
            
            return [
                'error' => 'Invalid JSON configuration',
                'defaults_applied' => true
            ];
        }
        
        // Ensure required fields exist with defaults
        $defaults = [
            'min_trades' => 1,
            'min_volume' => 1000,
            'min_success_rate' => 0.5,
            'max_drawdown' => 0.1,
            'required_metrics' => []
        ];
        
        return array_merge($defaults, $config);
    }
}

if (!function_exists('evaluate_task_completion')) {
    /**
     * Evaluate if a trader has completed a specific task
     * 
     * @param int $traderId Trader ID
     * @param int $taskId Task ID
     * @param mysqli $mysqli Database connection
     * @return array ['completed' => bool, 'details' => array]
     */
    function evaluate_task_completion(int $traderId, int $taskId, mysqli $mysqli): array {
        try {
            // Get task details and rule configuration
            $stmt = $mysqli->prepare("
                SELECT t.*, m.code as model_code
                FROM mtm_tasks t
                JOIN mtm_models m ON t.model_id = m.id
                WHERE t.id = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare task query");
            }
            
            $stmt->bind_param('i', $taskId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $stmt->close();
                return ['completed' => false, 'details' => ['error' => 'Task not found']];
            }
            
            $task = $result->fetch_assoc();
            $stmt->close();
            
            // Parse rule configuration
            $rules = parse_rule_config($task['rule_config']);
            
            // Get trader's trade statistics
            $stats = get_trader_statistics($traderId, $task['tier'], $mysqli);
            
            // Evaluate each rule
            $evaluation = [
                'task_id' => $taskId,
                'rules' => $rules,
                'statistics' => $stats,
                'checks' => []
            ];
            
            // Check minimum trades
            $tradesCheck = $stats['total_trades'] >= ($rules['min_trades'] ?? 1);
            $evaluation['checks']['min_trades'] = [
                'required' => $rules['min_trades'] ?? 1,
                'actual' => $stats['total_trades'],
                'passed' => $tradesCheck
            ];
            
            // Check minimum volume
            $volumeCheck = $stats['total_volume'] >= ($rules['min_volume'] ?? 1000);
            $evaluation['checks']['min_volume'] = [
                'required' => $rules['min_volume'] ?? 1000,
                'actual' => $stats['total_volume'],
                'passed' => $volumeCheck
            ];
            
            // Check minimum success rate
            $successRate = $stats['total_trades'] > 0 ? ($stats['winning_trades'] / $stats['total_trades']) : 0;
            $successCheck = $successRate >= ($rules['min_success_rate'] ?? 0.5);
            $evaluation['checks']['min_success_rate'] = [
                'required' => $rules['min_success_rate'] ?? 0.5,
                'actual' => $successRate,
                'passed' => $successCheck
            ];
            
            // Overall completion status
            $allChecksPassed = $tradesCheck && $volumeCheck && $successCheck;
            $evaluation['completed'] = $allChecksPassed;
            
            return $evaluation;
            
        } catch (Exception $e) {
            app_log([
                'event' => 'evaluate_task_error',
                'trader_id' => $traderId,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'completed' => false,
                'details' => ['error' => 'Evaluation failed: ' . $e->getMessage()]
            ];
        }
    }
}

if (!function_exists('get_trader_statistics')) {
    /**
     * Get trading statistics for a trader within a specific tier
     * 
     * @param int $traderId Trader ID
     * @param string $tier Tier to filter by
     * @param mysqli $mysqli Database connection
     * @return array Statistics array
     */
    function get_trader_statistics(int $traderId, string $tier, mysqli $mysqli): array {
        try {
            // Get all trades for this trader (could be enhanced to filter by enrollment date)
            $stmt = $mysqli->prepare("
                SELECT 
                    COUNT(*) as total_trades,
                    SUM(CASE WHEN closed_at IS NOT NULL THEN quantity ELSE 0 END) as total_volume,
                    SUM(CASE WHEN closed_at IS NOT NULL THEN 
                        CASE WHEN side = 'buy' THEN quantity * price ELSE -(quantity * price) END 
                        ELSE 0 END) as total_pnl,
                    SUM(CASE WHEN closed_at IS NOT NULL AND
                        ((side = 'buy' AND quantity * price > 0) OR (side = 'sell' AND quantity * price < 0))
                        THEN 1 ELSE 0 END) as winning_trades,
                    SUM(CASE WHEN closed_at IS NOT NULL AND
                        ((side = 'buy' AND quantity * price <= 0) OR (side = 'sell' AND quantity * price >= 0))
                        THEN 1 ELSE 0 END) as losing_trades
                FROM trades 
                WHERE trader_id = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare statistics query");
            }
            
            $stmt->bind_param('i', $traderId);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $stmt->close();
            
            // Ensure we have valid numbers
            return [
                'total_trades' => (int)($stats['total_trades'] ?? 0),
                'total_volume' => (float)($stats['total_volume'] ?? 0),
                'total_pnl' => (float)($stats['total_pnl'] ?? 0),
                'winning_trades' => (int)($stats['winning_trades'] ?? 0),
                'losing_trades' => (int)($stats['losing_trades'] ?? 0)
            ];
            
        } catch (Exception $e) {
            app_log([
                'event' => 'get_statistics_error',
                'trader_id' => $traderId,
                'tier' => $tier,
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_trades' => 0,
                'total_volume' => 0,
                'total_pnl' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0
            ];
        }
    }
}

if (!function_exists('get_available_models')) {
    /**
     * Get all available and active MTM models
     * 
     * @param mysqli $mysqli Database connection
     * @return array Array of model data
     */
    function get_available_models(mysqli $mysqli): array {
        try {
            $stmt = $mysqli->prepare("
                SELECT 
                    id,
                    code,
                    name,
                    tiering,
                    is_active,
                    created_at
                FROM mtm_models 
                WHERE is_active = 1 
                ORDER BY name ASC
            ");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare models query");
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $models = [];
            while ($row = $result->fetch_assoc()) {
                // Parse tiering JSON
                $row['tiering'] = json_decode($row['tiering'], true);
                $models[] = $row;
            }
            
            $stmt->close();
            return $models;
            
        } catch (Exception $e) {
            app_log([
                'event' => 'get_models_error',
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}