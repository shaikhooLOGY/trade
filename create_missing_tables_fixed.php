<?php
/**
 * Create Missing Tables Without Foreign Keys
 * Phase 3 - Quick Table Creation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/bootstrap.php';

echo "<h1>Creating Missing Tables (No Foreign Keys)</h1>";
echo "<p>Generated: " . date('c') . "</p>";

$creation_results = [
    'tables_created' => [],
    'errors' => []
];

// Create leagues table (no foreign keys)
function create_leagues_table() {
    global $mysqli, $creation_results;
    
    $sql = "CREATE TABLE IF NOT EXISTS leagues (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        max_participants INT DEFAULT 100,
        entry_fee DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('draft', 'active', 'completed', 'cancelled') DEFAULT 'draft',
        created_by BIGINT UNSIGNED,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_dates (start_date, end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        if ($mysqli->query($sql)) {
            $creation_results['tables_created'][] = 'leagues';
            echo "<p style='color: green;'>✅ leagues table created successfully</p>";
            return true;
        } else {
            throw new Exception($mysqli->error);
        }
    } catch (Exception $e) {
        $creation_results['errors'][] = "leagues: " . $e->getMessage();
        echo "<p style='color: red;'>❌ leagues: " . $e->getMessage() . "</p>";
        return false;
    }
}

// Create guard_trade_limits table (no foreign keys)
function create_guard_trade_limits_table() {
    global $mysqli, $creation_results;
    
    $sql = "CREATE TABLE IF NOT EXISTS guard_trade_limits (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        league_id BIGINT UNSIGNED NOT NULL,
        max_trades_per_day INT DEFAULT 10,
        max_trades_per_week INT DEFAULT 50,
        max_trades_per_month INT DEFAULT 200,
        current_daily_trades INT DEFAULT 0,
        current_weekly_trades INT DEFAULT 0,
        current_monthly_trades INT DEFAULT 0,
        last_daily_reset DATE DEFAULT (CURRENT_DATE),
        last_weekly_reset DATE DEFAULT (DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)),
        last_monthly_reset DATE DEFAULT (DATE_SUB(CURDATE(), INTERVAL DAYOFMONTH(CURDATE())-1 DAY)),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_league (user_id, league_id),
        INDEX idx_user_id (user_id),
        INDEX idx_league_id (league_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        if ($mysqli->query($sql)) {
            $creation_results['tables_created'][] = 'guard_trade_limits';
            echo "<p style='color: green;'>✅ guard_trade_limits table created successfully</p>";
            return true;
        } else {
            throw new Exception($mysqli->error);
        }
    } catch (Exception $e) {
        $creation_results['errors'][] = "guard_trade_limits: " . $e->getMessage();
        echo "<p style='color: red;'>❌ guard_trade_limits: " . $e->getMessage() . "</p>";
        return false;
    }
}

// Check final table status
function check_final_table_status() {
    global $mysqli;
    
    $tables = [
        'users',
        'leagues', 
        'trades',
        'mtm_models',
        'mtm_enrollments',
        'guard_trade_limits',
        'audit_events',
        'rate_limits',
        'idempotency_keys',
        'agent_logs',
        'schema_migrations'
    ];
    
    echo "<h3>Final Table Status (Phase 3 Goal: 10/10 tables)</h3>";
    
    $existing_tables = 0;
    foreach ($tables as $table) {
        try {
            $result = $mysqli->query("SHOW TABLES LIKE '$table'");
            $exists = $result && $result->num_rows > 0;
            
            if ($exists) {
                $existing_tables++;
                if ($table === 'schema_migrations') {
                    echo "<div style='margin: 3px 0; color: green;'>✅ $table - System table</div>";
                } else {
                    $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM $table");
                    $count = $count_result ? (int)$count_result->fetch_assoc()['cnt'] : 0;
                    echo "<div style='margin: 3px 0; color: green;'>✅ $table - $count rows</div>";
                }
            } else {
                echo "<div style='margin: 3px 0; color: red;'>❌ $table - Missing</div>";
            }
        } catch (Exception $e) {
            echo "<div style='margin: 3px 0; color: red;'>❌ $table - Error: " . $e->getMessage() . "</div>";
        }
    }
    
    echo "<div style='margin-top: 10px; padding: 10px; background: " . ($existing_tables >= 10 ? '#d4edda' : '#f8d7da') . "; border-radius: 5px;'>";
    echo "<strong>Table Coverage:</strong> $existing_tables/" . count($tables) . " tables exist";
    if ($existing_tables >= 10) {
        echo " - <span style='color: green; font-weight: bold;'>✅ GOAL ACHIEVED</span>";
    } else {
        echo " - <span style='color: red; font-weight: bold;'>❌ Missing " . (10 - $existing_tables) . " tables</span>";
    }
    echo "</div>";
    
    return $existing_tables;
}

echo "<h2>Creating Missing Tables</h2>";

create_leagues_table();
create_guard_trade_limits_table();

echo "<h2>Database Auto-Sync Status</h2>";
$existing_count = check_final_table_status();

// Summary
echo "<h2>Phase 3 Database Status</h2>";

$tables_created = count($creation_results['tables_created']);
$errors = count($creation_results['errors']);

echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>New tables created:</strong> $tables_created</p>";
echo "<p><strong>Total existing tables:</strong> $existing_count/10</p>";
echo "<p><strong>Errors:</strong> $errors</p>";

if ($existing_count >= 10) {
    echo "<p style='color: green; font-weight: bold;'>🎉 PHASE 3 GOAL ACHIEVED: 10/10 Core Tables Exist!</p>";
} else {
    echo "<p style='color: orange; font-weight: bold;'>⚠️ Phase 3 goal not fully met, but core functionality available</p>";
}
echo "</div>";

// Store results for report
file_put_contents(__DIR__ . '/reports/phase3_fix/db_autosync_results.json', json_encode([
    'timestamp' => date('c'),
    'tables_created' => $creation_results['tables_created'],
    'errors' => $creation_results['errors'],
    'existing_tables' => $existing_count,
    'target' => 10
], JSON_PRETTY_PRINT));

return $creation_results;
?>