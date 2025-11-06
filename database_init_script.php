<?php
/**
 * Database Initialization and Migration Script
 * Phase 3 - Complete Database Setup
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/bootstrap.php';

echo "<h1>Database Initialization and Migration</h1>";
echo "<p>Generated: " . date('c') . "</p>";

$sync_results = [
    'schema_table_created' => false,
    'migrations_executed' => [],
    'table_status' => [],
    'errors' => []
];

// Create schema_migrations table if it doesn't exist
function create_schema_migrations_table() {
    global $mysqli, $sync_results;
    
    echo "<h3>Creating schema_migrations table...</h3>";
    
    $sql = "CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(50) NOT NULL UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        if ($mysqli->query($sql)) {
            echo "<p style='color: green;'>✅ schema_migrations table created successfully</p>";
            $sync_results['schema_table_created'] = true;
            return true;
        } else {
            $error = "Failed to create schema_migrations table: " . $mysqli->error;
            echo "<p style='color: red;'>❌ $error</p>";
            $sync_results['errors'][] = $error;
            return false;
        }
    } catch (Exception $e) {
        $error = "Error creating schema_migrations table: " . $e->getMessage();
        echo "<p style='color: red;'>❌ $error</p>";
        $sync_results['errors'][] = $error;
        return false;
    }
}

// Check if migration was applied
function is_migration_applied($version) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM schema_migrations WHERE version = ?");
    $stmt->bind_param('s', $version);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = (int)$result->fetch_assoc()['COUNT(*)'];
    $stmt->close();
    return $count > 0;
}

// Execute migration file
function execute_migration($filename) {
    global $mysqli, $sync_results;
    
    $version = str_replace('.sql', '', $filename);
    
    if (is_migration_applied($version)) {
        echo "<p>✅ Migration $version already applied, skipping</p>";
        return true;
    }
    
    echo "<h3>Executing: $filename</h3>";
    
    if (!file_exists(__DIR__ . "/database/migrations/$filename")) {
        $error = "Migration file not found: $filename";
        echo "<p style='color: red;'>❌ $error</p>";
        $sync_results['errors'][] = $error;
        return false;
    }
    
    $sql = file_get_contents(__DIR__ . "/database/migrations/$filename");
    if (empty($sql)) {
        $error = "Migration file is empty: $filename";
        echo "<p style='color: red;'>❌ $error</p>";
        $sync_results['errors'][] = $error;
        return false;
    }
    
    $mysqli->begin_transaction();
    $success = false;
    
    try {
        // Split SQL into individual statements and execute
        $statements = preg_split('/;\s*$/m', $sql);
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !preg_match('/^(--|\/\*)/', $statement)) {
                if (strpos(strtoupper($statement), 'schema_migrations') !== false && 
                    strpos(strtoupper($statement), 'INSERT') !== false) {
                    continue; // Skip schema_migrations insert as we'll do it ourselves
                }
                
                if (!empty($statement)) {
                    if (!$mysqli->query($statement)) {
                        throw new Exception("SQL Error in $filename: " . $mysqli->error . "\nQuery: " . substr($statement, 0, 200));
                    }
                }
            }
        }
        
        // Record migration as applied
        $stmt = $mysqli->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (?, NOW())");
        $stmt->bind_param('s', $version);
        $stmt->execute();
        $stmt->close();
        
        $mysqli->commit();
        echo "<p style='color: green;'>✅ Migration $filename executed successfully</p>";
        $sync_results['migrations_executed'][] = $filename;
        $success = true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $error = "Migration $filename failed: " . $e->getMessage();
        echo "<p style='color: red;'>❌ $error</p>";
        $sync_results['errors'][] = $error;
    }
    
    return $success;
}

// Check table status
function check_table_status() {
    global $mysqli, $sync_results;
    
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
    
    echo "<h3>Table Status Check</h3>";
    
    foreach ($tables as $table) {
        $status = ['exists' => false, 'count' => 0, 'indexes' => 0, 'error' => null];
        
        try {
            // Check if table exists
            $result = $mysqli->query("SHOW TABLES LIKE '$table'");
            if ($result && $result->num_rows > 0) {
                $status['exists'] = true;
                
                // Get row count (skip for system tables)
                if ($table !== 'schema_migrations') {
                    $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM $table");
                    if ($count_result) {
                        $status['count'] = (int)$count_result->fetch_assoc()['cnt'];
                    }
                }
                
                // Get index count
                $index_result = $mysqli->query("SHOW INDEX FROM $table");
                if ($index_result) {
                    $status['indexes'] = $index_result->num_rows;
                }
            }
        } catch (Exception $e) {
            $status['error'] = $e->getMessage();
        }
        
        $sync_results['table_status'][$table] = $status;
        
        $exists_icon = $status['exists'] ? '✅' : '❌';
        $count_text = $status['exists'] ? ($table === 'schema_migrations' ? 'N/A' : $status['count']) : 'N/A';
        $indexes_text = $status['exists'] ? $status['indexes'] : 'N/A';
        $error_text = $status['error'] ? " (Error: {$status['error']})" : '';
        
        echo "<div style='margin: 5px 0; padding: 5px; border: 1px solid #ccc;'>";
        echo "$exists_icon <strong>$table</strong> - Rows: $count_text, Indexes: $indexes_text$error_text";
        echo "</div>";
    }
}

echo "<h2>Step 1: Create schema_migrations table</h2>";
create_schema_migrations_table();

echo "<h2>Step 2: Execute Critical Migrations</h2>";

// Critical migrations in order
$critical_migrations = [
    '002_tmsmtm.sql',        // Base TMS-MTM tables
    '003_prod_readiness.sql', // Production readiness features
    '003_prod_readiness_guarded.sql', // Guarded features
    '004_fix_guarded_indexes.sql', // Index fixes
    '005_audit_trail.sql',   // Audit trail
    '007_audit_fix.sql',     // Audit fix
    '008_rate_limit_store.sql' // Rate limiting store
];

foreach ($critical_migrations as $migration) {
    execute_migration($migration);
}

echo "<h2>Step 3: Check Final Table Status</h2>";
check_table_status();

// Summary
echo "<h2>Database Initialization Summary</h2>";

$schema_created = $sync_results['schema_table_created'];
$migrations_executed = count($sync_results['migrations_executed']);
$errors = count($sync_results['errors']);

echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>Schema migrations table created:</strong> " . ($schema_created ? '✅ Yes' : '❌ No') . "</p>";
echo "<p><strong>Migrations executed:</strong> $migrations_executed</p>";
echo "<p><strong>Errors:</strong> $errors</p>";

if ($errors === 0) {
    echo "<p style='color: green; font-weight: bold;'>🎉 Database Initialization Completed Successfully!</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>❌ Database Initialization Completed with Errors</p>";
}
echo "</div>";

return $sync_results;
?>