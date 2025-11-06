<?php
/**
 * Create Missing Tables Directly
 * Phase 3 - Final Table Creation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/bootstrap.php';

echo "<h1>Creating Missing Tables</h1>";
echo "<p>Generated: " . date('c') . "</p>";

$creation_results = [
    'tables_created' => [],
    'errors' => []
];

// Create leagues table
function create_leagues_table() {
    global $mysqli, $creation_results;
    
    $sql = "CREATE TABLE IF NOT EXISTS leagues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        max_participants INT DEFAULT 100,
        entry_fee DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('draft', 'active', 'completed', 'cancelled') DEFAULT 'draft',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
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

// Create guard_trade_limits table
function create_guard_trade_limits_table() {
    global $mysqli, $creation_results;
    
    $sql = "CREATE TABLE IF NOT EXISTS guard_trade_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        league_id INT NOT NULL,
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
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
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

// Create idempotency_keys table
function create_idempotency_keys_table() {
    global $mysqli, $creation_results;
    
    $sql = "CREATE TABLE IF NOT EXISTS idempotency_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_hash VARCHAR(64) NOT NULL,
        request_hash VARCHAR(64) NOT NULL,
        response_data LONGTEXT,
        status_code INT DEFAULT 200,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_key_hash (key_hash),
        INDEX idx_key_hash (key_hash),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        if ($mysqli->query($sql)) {
            $creation_results['tables_created'][] = 'idempotency_keys';
            echo "<p style='color: green;'>✅ idempotency_keys table created successfully</p>";
            return true;
        } else {
            throw new Exception($mysqli->error);
        }
    } catch (Exception $e) {
        $creation_results['errors'][] = "idempotency_keys: " . $e->getMessage();
        echo "<p style='color: red;'>❌ idempotency_keys: " . $e->getMessage() . "</p>";
        return false;
    }
}

// Create agent_logs table
function create_agent_logs_table() {
    global $mysqli, $creation_results;
    
    $sql = "CREATE TABLE IF NOT EXISTS agent_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id VARCHAR(100) NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        message TEXT,
        context JSON,
        severity ENUM('debug', 'info', 'warning', 'error', 'critical') DEFAULT 'info',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_agent_id (agent_id),
        INDEX idx_event_type (event_type),
        INDEX idx_severity (severity),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        if ($mysqli->query($sql)) {
            $creation_results['tables_created'][] = 'agent_logs';
            echo "<p style='color: green;'>✅ agent_logs table created successfully</p>";
            return true;
        } else {
            throw new Exception($mysqli->error);
        }
    } catch (Exception $e) {
        $creation_results['errors'][] = "agent_logs: " . $e->getMessage();
        echo "<p style='color: red;'>❌ agent_logs: " . $e->getMessage() . "</p>";
        return false;
    }
}

// Check final table status
function check_final_table_status() {
    global $mysqli, $creation_results;
    
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
    
    echo "<h3>Final Table Status</h3>";
    
    foreach ($tables as $table) {
        try {
            $result = $mysqli->query("SHOW TABLES LIKE '$table'");
            $exists = $result && $result->num_rows > 0;
            
            if ($exists) {
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
}

echo "<h2>Creating Missing Tables</h2>";

create_leagues_table();
create_guard_trade_limits_table();
create_idempotency_keys_table();
create_agent_logs_table();

echo "<h2>Final Table Status</h2>";
check_final_table_status();

// Summary
echo "<h2>Table Creation Summary</h2>";

$tables_created = count($creation_results['tables_created']);
$errors = count($creation_results['errors']);

echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>Tables created successfully:</strong> $tables_created</p>";
echo "<p><strong>Errors:</strong> $errors</p>";

if ($errors === 0) {
    echo "<p style='color: green; font-weight: bold;'>🎉 All missing tables created successfully!</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>❌ Some tables failed to create</p>";
}
echo "</div>";

return $creation_results;
?>