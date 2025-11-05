<?php
/**
 * api/dashboard/metrics.php
 *
 * Dashboard API - Get metrics for user's dashboard
 * GET /api/dashboard/metrics.php
 */

require_once __DIR__ . '/../../_bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('METHOD_NOT_ALLOWED', 'Only GET method is allowed');
}

try {
    // Require authentication
    $user = require_login_json();
    $userId = (int)$user['id'];
    $isAdmin = isset($user['role']) && $user['role'] === 'admin';
    
    // Check CSRF for API endpoints
    csrf_api_middleware();
    
    global $mysqli;
    
    // Initialize metrics array
    $metrics = [
        'total_trades' => 0,
        'win_rate' => 0,
        'total_pnl' => 0,
        'active_models' => 0,
        'pending_approvals' => 0
    ];
    
    // 1. Get trade metrics using the trades service
    require_once __DIR__ . '/../../includes/trades/service.php';
    $tradeMetrics = calculate_trade_metrics_service($userId);
    
    $metrics['total_trades'] = $tradeMetrics['total_trades'];
    $metrics['win_rate'] = $tradeMetrics['win_rate'];
    $metrics['total_pnl'] = $tradeMetrics['total_pnl'];
    
    // 2. Get active MTM models count
    try {
        $stmt = $mysqli->prepare("
            SELECT COUNT(*) as active_models 
            FROM mtm_models 
            WHERE status = 'active' 
            AND (end_date IS NULL OR end_date >= CURDATE())
        ");
        
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $metrics['active_models'] = (int)$result->fetch_assoc()['active_models'];
            $stmt->close();
        }
    } catch (Exception $e) {
        app_log('error', 'Dashboard metrics - active models query failed: ' . $e->getMessage());
    }
    
    // 3. Get pending approvals count
    try {
        if ($isAdmin) {
            // Admin sees all pending approvals
            $stmt = $mysqli->prepare("
                SELECT COUNT(*) as pending_approvals 
                FROM mtm_enrollments 
                WHERE status = 'pending'
            ");
        } else {
            // Regular user sees their own pending enrollments
            $stmt = $mysqli->prepare("
                SELECT COUNT(*) as pending_approvals 
                FROM mtm_enrollments 
                WHERE user_id = ? 
                AND status = 'pending'
            ");
            $stmt->bind_param('i', $userId);
        }
        
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $metrics['pending_approvals'] = (int)$result->fetch_assoc()['pending_approvals'];
            $stmt->close();
        }
    } catch (Exception $e) {
        app_log('error', 'Dashboard metrics - pending approvals query failed: ' . $e->getMessage());
    }
    
    // 4. Get additional user-specific metrics
    try {
        // Get user's participation stats
        $stmt = $mysqli->prepare("
            SELECT 
                COUNT(CASE WHEN e.status = 'approved' THEN 1 END) as approved_enrollments,
                COUNT(CASE WHEN e.status = 'pending' THEN 1 END) as pending_enrollments,
                COUNT(CASE WHEN e.status = 'rejected' THEN 1 END) as rejected_enrollments
            FROM mtm_enrollments e
            WHERE e.user_id = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $userStats = $result->fetch_assoc();
            $stmt->close();
            
            // Add to metrics if not already present
            if ($metrics['pending_approvals'] === 0) {
                $metrics['pending_approvals'] = (int)($userStats['pending_enrollments'] ?? 0);
            }
        }
    } catch (Exception $e) {
        app_log('error', 'Dashboard metrics - user stats query failed: ' . $e->getMessage());
    }
    
    // 5. Get performance data for charts (last 30 days)
    try {
        $performanceData = [];
        
        // Check if trader_id column exists
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
        
        $deletedCondition = $hasDeletedAt ? "AND t.deleted_at IS NULL" : '';
        
        $stmt = $mysqli->prepare("
            SELECT 
                DATE(t.opened_at) as trade_date,
                COUNT(*) as daily_trades,
                SUM(CASE 
                    WHEN t.side = 'buy' THEN t.quantity * (COALESCE(t.close_price, t.price) - t.price)
                    ELSE t.quantity * (t.price - COALESCE(t.close_price, t.price))
                END) as daily_pnl,
                COUNT(CASE WHEN t.outcome = 'win' THEN 1 END) as daily_wins,
                COUNT(*) as total_trades
            FROM trades t
            WHERE t.{$traderColumn} = ? 
            AND t.opened_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            $deletedCondition
            GROUP BY DATE(t.opened_at)
            ORDER BY t.opened_at DESC
            LIMIT 30
        ");
        
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $dailyWinRate = $row['total_trades'] > 0 ? 
                    ($row['daily_wins'] / $row['total_trades']) * 100 : 0;
                
                $performanceData[] = [
                    'date' => $row['trade_date'],
                    'trades' => (int)$row['daily_trades'],
                    'pnl' => round((float)$row['daily_pnl'], 2),
                    'win_rate' => round($dailyWinRate, 2)
                ];
            }
            $stmt->close();
        }
        
        $metrics['performance_trend'] = array_reverse($performanceData); // chronological order
        
    } catch (Exception $e) {
        app_log('error', 'Dashboard metrics - performance trend query failed: ' . $e->getMessage());
        $metrics['performance_trend'] = [];
    }
    
    // 6. Get recent activity (last 5 trades)
    try {
        $recentTrades = [];
        
        // Use the same column checks as above
        $traderColumn = 'trader_id';
        $userCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'user_id'");
        if ($userCheck->num_rows > 0) {
            $traderColumn = 'user_id';
        }
        $userCheck->close();
        
        $deletedCheck = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'deleted_at'");
        $hasDeletedAt = $deletedCheck->num_rows > 0;
        $deletedCheck->close();
        
        $deletedCondition = $hasDeletedAt ? "AND t.deleted_at IS NULL" : '';
        
        $stmt = $mysqli->prepare("
            SELECT t.id, t.symbol, t.side, t.quantity, t.price, t.outcome, t.opened_at
            FROM trades t
            WHERE t.{$traderColumn} = ? 
            $deletedCondition
            ORDER BY t.opened_at DESC 
            LIMIT 5
        ");
        
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $recentTrades[] = [
                    'id' => (int)$row['id'],
                    'symbol' => $row['symbol'],
                    'side' => $row['side'],
                    'quantity' => (float)$row['quantity'],
                    'price' => (float)$row['price'],
                    'outcome' => $row['outcome'],
                    'opened_at' => $row['opened_at']
                ];
            }
            $stmt->close();
        }
        
        $metrics['recent_trades'] = $recentTrades;
        
    } catch (Exception $e) {
        app_log('error', 'Dashboard metrics - recent trades query failed: ' . $e->getMessage());
        $metrics['recent_trades'] = [];
    }
    
    // Log dashboard access
    app_log('info', sprintf(
        'Dashboard metrics accessed - User: %d, Total Trades: %d, Win Rate: %.2f%%, P&L: %.2f',
        $userId,
        $metrics['total_trades'],
        $metrics['win_rate'],
        $metrics['total_pnl']
    ));
    
    // Return success response
    json_ok($metrics);
    
} catch (Exception $e) {
    app_log('error', 'Dashboard metrics error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to retrieve dashboard metrics');
}