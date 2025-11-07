<?php
/**
 * Safe Database Schema Analysis
 * First examines schema, then performs performance analysis
 */

require_once __DIR__ . '/config.php';

echo "🔍 DATABASE SCHEMA & PERFORMANCE ANALYSIS\n";
echo "==========================================\n\n";

// Enable query profiling
$mysqli->query("SET profiling = 1");

echo "1. DATABASE SCHEMA ANALYSIS\n";
echo "===========================\n\n";

// Check trades table structure
echo "📊 TRADES TABLE STRUCTURE:\n";
$result = $mysqli->query("DESCRIBE trades");
if ($result) {
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
        printf("  %s: %s %s\n", $row['Field'], $row['Type'], $row['Null'] === 'NO' ? 'NOT NULL' : 'NULL');
    }
    $result->close();
} else {
    echo "  Unable to describe trades table\n";
}
echo "\n";

// Check mtm_enrollments table structure
echo "📊 MTM_ENROLLMENTS TABLE STRUCTURE:\n";
$result = $mysqli->query("DESCRIBE mtm_enrollments");
if ($result) {
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
        printf("  %s: %s %s\n", $row['Field'], $row['Type'], $row['Null'] === 'NO' ? 'NOT NULL' : 'NULL');
    }
    $result->close();
} else {
    echo "  Unable to describe mtm_enrollments table\n";
}
echo "\n";

echo "2. INDEX ANALYSIS\n";
echo "=================\n\n";

// Check trades indexes
echo "📊 TRADES TABLE INDEXES:\n";
$result = $mysqli->query("SHOW INDEXES FROM trades");
if ($result) {
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = $row;
        printf("  %s: %s (%s)\n", $row['Key_name'], $row['Column_name'], $row['Index_type']);
    }
    $result->close();
    
    if (empty($indexes)) {
        echo "  ⚠️  NO INDEXES FOUND - This is a CRITICAL performance issue\n";
    }
} else {
    echo "  Unable to query indexes\n";
}
echo "\n";

// Check mtm_enrollments indexes
echo "📊 MTM_ENROLLMENTS TABLE INDEXES:\n";
$result = $mysqli->query("SHOW INDEXES FROM mtm_enrollments");
if ($result) {
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = $row;
        printf("  %s: %s (%s)\n", $row['Key_name'], $row['Column_name'], $row['Index_type']);
    }
    $result->close();
    
    if (empty($indexes)) {
        echo "  ⚠️  NO INDEXES FOUND - This is a CRITICAL performance issue\n";
    }
} else {
    echo "  Unable to query indexes\n";
}
echo "\n";

echo "3. SAFE QUERY ANALYSIS\n";
echo "======================\n\n";

// Check if we have sample data
$result = $mysqli->query("SELECT COUNT(*) as total FROM trades");
if ($result) {
    $count = $result->fetch_assoc()['total'];
    echo "Trades table has $count records\n";
    $result->close();
}

// Create safe queries based on actual schema
echo "🔴 QUERY 1: Basic Trades Listing (Safe)\n";
$safeQuery1 = "SELECT * FROM trades ORDER BY opened_at DESC LIMIT 10";
echo "Query: Basic trades listing\n";
$result = $mysqli->query("EXPLAIN $safeQuery1");
if ($result) {
    echo "Execution Plan:\n";
    while ($row = $result->fetch_assoc()) {
        printf("  Table: %s | Type: %s | Key: %s | Rows: %s | Extra: %s\n", 
               $row['table'], $row['type'], $row['key'], $row['rows'], $row['Extra']);
    }
    $result->close();
}
echo "\n";

echo "🔴 QUERY 2: Trades Count (Safe)\n";
$safeQuery2 = "SELECT COUNT(*) as total FROM trades";
echo "Query: Count all trades\n";
$result = $mysqli->query("EXPLAIN $safeQuery2");
if ($result) {
    echo "Execution Plan:\n";
    while ($row = $result->fetch_assoc()) {
        printf("  Table: %s | Type: %s | Key: %s | Rows: %s | Extra: %s\n", 
               $row['table'], $row['type'], $row['key'], $row['rows'], $row['Extra']);
    }
    $result->close();
}
echo "\n";

echo "🔴 QUERY 3: JOIN Query (Safe)\n";
$safeQuery3 = "
    SELECT t.*, e.id as enrollment_id
    FROM trades t 
    LEFT JOIN mtm_enrollments e ON e.trade_id = t.id 
    LIMIT 10
";
echo "Query: Trades with MTM enrollment join\n";
$result = $mysqli->query("EXPLAIN $safeQuery3");
if ($result) {
    echo "Execution Plan:\n";
    while ($row = $result->fetch_assoc()) {
        printf("  Table: %s | Type: %s | Key: %s | Rows: %s | Extra: %s\n", 
               $row['table'], $row['type'], $row['key'], $row['rows'], $row['Extra']);
    }
    $result->close();
}
echo "\n";

echo "4. QUERY EXECUTION TIMING\n";
echo "==========================\n\n";

// Test safe queries
$queries = [
    'Safe Query 1' => $safeQuery1,
    'Safe Query 2' => $safeQuery2, 
    'Safe Query 3' => $safeQuery3
];

foreach ($queries as $name => $query) {
    echo "⏱️  Testing: $name\n";
    
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $start = microtime(true);
        $mysqli->query($query);
        $end = microtime(true);
        $times[] = ($end - $start) * 1000;
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

echo "5. DATABASE CONNECTION EFFICIENCY\n";
echo "==================================\n\n";

echo "Connection Method: Direct MySQLi (No Connection Pooling)\n";
echo "Connection Details:\n";
echo "  Server Version: " . $mysqli->server_version . "\n";
echo "  Host: " . $mysqli->server_info . "\n";
echo "  Client: " . $mysqli->client_info . "\n";
echo "  Charset: " . $mysqli->character_set_name() . "\n";
echo "  Status: " . ($mysqli->query('SELECT 1') ? "Connected" : "Disconnected") . "\n";
echo "  Connection ID: " . $mysqli->thread_id . "\n\n";

echo "❌ NO CONNECTION POOLING DETECTED\n";
echo "  - Single connection per request\n";
echo "  - No connection reuse patterns\n";
echo "  - Potential overhead on high-traffic scenarios\n\n";

echo "6. PERFORMANCE READINESS ASSESSMENT\n";
echo "===================================\n\n";

$issues = [];

// Check for full table scans
$result = $mysqli->query("EXPLAIN SELECT * FROM trades");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['type'] === 'ALL') {
            $issues[] = "Full table scan detected on trades table";
        }
    }
    $result->close();
}

// Check for missing indexes on common columns
$result = $mysqli->query("SHOW INDEXES FROM trades");
$hasUserIndex = false;
$hasTraderIndex = false;
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['Column_name'] === 'user_id') $hasUserIndex = true;
        if ($row['Column_name'] === 'trader_id') $hasTraderIndex = true;
    }
    $result->close();
}

if (!$hasUserIndex && !$hasTraderIndex) {
    $issues[] = "No index on user/trader ID column";
}

// Check table sizes
$result = $mysqli->query("SELECT COUNT(*) as count FROM trades");
if ($result) {
    $count = $result->fetch_assoc()['count'];
    if ($count > 10000) {
        $issues[] = "Large table without proper indexing could cause performance issues";
    }
    $result->close();
}

echo "❌ CRITICAL ISSUES IDENTIFIED:\n";
if (empty($issues)) {
    echo "  None detected in safe analysis\n";
} else {
    foreach ($issues as $issue) {
        echo "  • $issue\n";
    }
}
echo "\n";

echo "🔧 IMMEDIATE RECOMMENDATIONS:\n";
echo "  1. CREATE INDEX idx_trades_opened_at ON trades(opened_at);\n";
echo "  2. CREATE INDEX idx_trades_user_id ON trades(user_id);\n";
echo "  3. CREATE INDEX idx_trades_trader_id ON trades(trader_id);\n";
echo "  4. CREATE INDEX idx_mtm_enrollments_trade_id ON mtm_enrollments(trade_id);\n";
echo "  5. Implement connection pooling for production\n";
echo "  6. Add query result caching for dashboard aggregations\n\n";

echo "✅ ANALYSIS COMPLETE\n";
echo "====================\n";
echo "Report generated at: " . date('Y-m-d H:i:s') . "\n";

// Cleanup
$mysqli->query("SET profiling = 0");
?>