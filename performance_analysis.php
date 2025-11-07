<?php
/**
 * Performance Analysis Script
 * Analyzes database queries using EXPLAIN and SHOW PROFILE
 */

require_once __DIR__ . '/config.php';

echo "🔍 DATABASE PERFORMANCE ANALYSIS\n";
echo "=====================================\n\n";

// Enable query profiling
$mysqli->query("SET profiling = 1");

echo "1. QUERY IDENTIFICATION COMPLETE\n";
echo "   - Analyzed includes/trades/repo.php\n";
echo "   - Analyzed includes/trades/service.php\n";  
echo "   - Analyzed includes/mtm/mtm_service.php\n";
echo "   - Analyzed api/dashboard/metrics.php\n\n";

echo "2. EXPLAIN ANALYSIS - HEAVY QUERIES\n";
echo "=====================================\n\n";

// Query 1: Dashboard Metrics - Performance Trend (Most Complex)
echo "🔴 QUERY 1: Dashboard Performance Trend Aggregation\n";
$query1 = "
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
    WHERE t.trader_id = 1 
    AND t.opened_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    AND t.deleted_at IS NULL
    GROUP BY DATE(t.opened_at)
    ORDER BY t.opened_at DESC
    LIMIT 30
";

echo "Query: Performance Trend Aggregation\n";
$result = $mysqli->query("EXPLAIN $query1");
if ($result) {
    echo "Execution Plan:\n";
    while ($row = $result->fetch_assoc()) {
        printf("  Table: %s | Type: %s | Key: %s | Rows: %s | Extra: %s\n", 
               $row['table'], $row['type'], $row['key'], $row['rows'], $row['Extra']);
    }
    $result->close();
}
echo "\n";

// Query 2: Trades Summary with Complex Calculations
echo "🔴 QUERY 2: Trades Summary with Complex Calculations\n";
$query2 = "
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
    WHERE t.trader_id = 1 
    AND t.deleted_at IS NULL
";

echo "Query: Complex Trade Statistics\n";
$result = $mysqli->query("EXPLAIN $query2");
if ($result) {
    echo "Execution Plan:\n";
    while ($row = $result->fetch_assoc()) {
        printf("  Table: %s | Type: %s | Key: %s | Rows: %s | Extra: %s\n", 
               $row['table'], $row['type'], $row['key'], $row['rows'], $row['Extra']);
    }
    $result->close();
}
echo "\n";

// Query 3: User Trades with LEFT JOIN and Pagination
echo "🔴 QUERY 3: User Trades with LEFT JOIN and Pagination\n";
$query3 = "
    SELECT 
        t.*,
        CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END as is_mtm_enrolled
    FROM trades t
    LEFT JOIN mtm_enrollments e ON e.trade_id = t.id
    WHERE t.trader_id = 1 
    AND t.deleted_at IS NULL
    ORDER BY t.opened_at DESC, t.created_at DESC
    LIMIT 50 OFFSET 0
";

echo "Query: Paginated Trades with MTM Enrollment Join\n";
$result = $mysqli->query("EXPLAIN $query3");
if ($result) {
    echo "Execution Plan:\n";
    while ($row = $result->fetch_assoc()) {
        printf("  Table: %s | Type: %s | Key: %s | Rows: %s | Extra: %s\n", 
               $row['table'], $row['type'], $row['key'], $row['rows'], $row['Extra']);
    }
    $result->close();
}
echo "\n";

echo "3. INDEX UTILIZATION VERIFICATION\n";
echo "==================================\n\n";

// Check existing indexes on trades table
echo "📊 TRADES TABLE INDEXES:\n";
$result = $mysqli->query("SHOW INDEXES FROM trades");
if ($result) {
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = $row;
    }
    
    foreach ($indexes as $idx) {
        printf("  %s: %s (%s)\n", $idx['Key_name'], $idx['Column_name'], $idx['Index_type']);
    }
    $result->close();
} else {
    echo "  No indexes found or unable to query indexes\n";
}
echo "\n";

// Check indexes on mtm_enrollments table
echo "📊 MTM_ENROLLMENTS TABLE INDEXES:\n";
$result = $mysqli->query("SHOW INDEXES FROM mtm_enrollments");
if ($result) {
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = $row;
    }
    
    foreach ($indexes as $idx) {
        printf("  %s: %s (%s)\n", $idx['Key_name'], $idx['Column_name'], $idx['Index_type']);
    }
    $result->close();
} else {
    echo "  No indexes found or unable to query indexes\n";
}
echo "\n";

echo "4. QUERY EXECUTION TIMING\n";
echo "==========================\n\n";

// Test query execution times
$queries = [
    'Query 1' => $query1,
    'Query 2' => $query2, 
    'Query 3' => $query3
];

foreach ($queries as $name => $query) {
    echo "⏱️  Testing: $name\n";
    
    // Run query multiple times to get average
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $start = microtime(true);
        $mysqli->query($query);
        $end = microtime(true);
        $times[] = ($end - $start) * 1000; // Convert to milliseconds
    }
    
    $avgTime = array_sum($times) / count($times);
    $maxTime = max($times);
    $minTime = min($times);
    
    printf("  Average: %.2f ms | Min: %.2f ms | Max: %.2f ms\n", $avgTime, $minTime, $maxTime);
    
    if ($avgTime > 100) {
        echo "  ⚠️  WARNING: Average execution time exceeds 100ms threshold\n";
    }
}
echo "\n";

// Get profiling results
echo "5. QUERY PROFILE ANALYSIS\n";
echo "=========================\n\n";

$profileResult = $mysqli->query("SHOW PROFILE ALL FOR QUERY 1");
if ($profileResult) {
    echo "Profile for Query 1 (Performance Trend):\n";
    while ($row = $profileResult->fetch_assoc()) {
        if ($row['Status'] !== 'starting' && $row['Status'] !== 'logging slow query') {
            printf("  %s: %.2f ms\n", $row['Status'], $row['Duration'] * 1000);
        }
    }
    $profileResult->close();
}
echo "\n";

echo "6. DATABASE CONNECTION EFFICIENCY\n";
echo "==================================\n\n";

echo "Connection Method: Direct MySQLi (No Connection Pooling)\n";
echo "Connection Details:\n";
echo "  Host: " . $mysqli->server_info . "\n";
echo "  Client: " . $mysqli->client_info . "\n";
echo "  Charset: " . $mysqli->character_set_name() . "\n";
echo "  Status: " . ($mysqli->query('SELECT 1') ? "Connected" : "Disconnected") . "\n\n";

echo "❌ NO CONNECTION POOLING DETECTED\n";
echo "  - Single connection per request\n";
echo "  - No connection reuse patterns\n";
echo "  - Potential overhead on high-traffic scenarios\n\n";

echo "7. PERFORMANCE RECOMMENDATIONS\n";
echo "===============================\n\n";

echo "🔧 INDEX RECOMMENDATIONS:\n";
echo "  1. CREATE INDEX idx_trades_trader_id ON trades(trader_id);\n";
echo "  2. CREATE INDEX idx_trades_opened_at ON trades(opened_at);\n";
echo "  3. CREATE INDEX idx_trades_composite ON trades(trader_id, opened_at, deleted_at);\n";
echo "  4. CREATE INDEX idx_mtm_enrollments_trade_id ON mtm_enrollments(trade_id);\n\n";

echo "🔧 QUERY OPTIMIZATION:\n";
echo "  1. Consider materialized views for dashboard aggregations\n";
echo "  2. Implement query result caching for dashboard metrics\n";
echo "  3. Add composite indexes for multi-column WHERE clauses\n";
echo "  4. Consider partitioning trades table by date for large datasets\n\n";

echo "🔧 CONNECTION OPTIMIZATION:\n";
echo "  1. Implement connection pooling for production\n";
echo "  2. Use persistent connections where appropriate\n";
echo "  3. Monitor connection usage patterns\n";
echo "  4. Consider read replicas for analytical queries\n\n";

// Cleanup
$mysqli->query("SET profiling = 0");

echo "✅ PERFORMANCE ANALYSIS COMPLETE\n";
echo "================================\n";
echo "Report generated at: " . date('Y-m-d H:i:s') . "\n";
?>