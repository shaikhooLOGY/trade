<?php
/**
 * Database Auto-Sync Script
 * Phase 3 - Migration Execution and Verification
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/bootstrap.php';

echo "<h1>Database Auto-Sync Report</h1>";
echo "<p>Generated: " . date('c') . "</p>";

$sync_results = [
    'migrations_checked' => [],
    'missing_migrations' => [],
    'executed_migrations' => [],
    'table_status' => [],
    'errors' => []
];

// Get current schema version
function get_schema_version() {
    global $mysqli;
    $result = $mysqli->query("SELECT version FROM schema_migrations ORDER BY applied_at DESC LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        return $row['version'];
    }
    return null;
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
    
    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)), function($stmt) {
        return !empty($stmt) && !preg_match('/^(--|\/\*)/', $stmt);
    });
    
    $mysqli->begin_transaction();
    $success = true;
    
    try {
        foreach ($statements as $statement) {
            if (preg_match('/INSERT INTO schema_migrations/i', $statement)) {
                continue; // Skip schema_migrations insert as we'll do it ourselves
            }
            
            if (!empty(trim($statement))) {
                if (!$mysqli->query($statement)) {
                    throw new Exception("SQL Error in $filename: " . $mysqli->error . "\nQuery: " . substr($statement, 0, 200));
                }
            }
        }
        
        // Record migration as applied
        $version = str_replace('.sql', '', $filename);
        $stmt = $mysqli->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (?, NOW())");
        $stmt->bind_param('s', $version);
        $stmt->execute();
        $stmt->close();
        
        $mysqli->commit();
        echo "<p style='color: green;'>✅ Migration $filename executed successfully</p>";
        $sync_results['executed_migrations'][] = $filename;
        return true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $error = "Migration $filename failed: " . $e->getMessage();
        echo "<p style='color: red;'>❌ $error</p>";
        $sync_results['errors'][] = $error;
        return false;
    }
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
        'agent_logs'
    ];
    
    echo "<h3>Table Status Check</h3>";
    
    foreach ($tables as $table) {
        $status = ['exists' => false, 'count' => 0, 'indexes' => 0, 'error' => null];
        
        try {
            // Check if table exists
            $result = $mysqli->query("SHOW TABLES LIKE '$table'");
            if ($result && $result->num_rows > 0) {
                $status['exists'] = true;
                
                // Get row count
                $count_result = $mysqli->query("SELECT COUNT(*) as cnt FROM $table");
                if ($count_result) {
                    $status['count'] = (int)$count_result->fetch_assoc()['cnt'];
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
        $count_text = $status['exists'] ? $status['count'] : 'N/A';
        $indexes_text = $status['exists'] ? $status['indexes'] : 'N/A';
        $error_text = $status['error'] ? " (Error: {$status['error']})" : '';
        
        echo "<div style='margin: 5px 0; padding: 5px; border: 1px solid #ccc;'>";
        echo "$exists_icon <strong>$table</strong> - Rows: $count_text, Indexes: $indexes_text$error_text";
        echo "</div>";
    }
}

// Get all migration files
$migration_files = [
    '002_tmsmtm.sql',
    '003_prod_readiness.sql', 
    '003_prod_readiness_guarded.sql',
    '004_fix_guarded_indexes.sql',
    '005_audit_trail.sql',
    '005_create_audit_events.sql',
    '005_create_audit_events_fixed.sql',
    '007_audit_fix.sql',
    '008_rate_limit_store.sql'
];

echo "<h2>Migration Status Check</h2>";

$current_version = get_schema_version();
echo "<p><strong>Current Schema Version:</strong> " . ($current_version ?: 'None') . "</p>";

echo "<h3>Required Migrations</h3>";
foreach ($migration_files as $file) {
    $version = str_replace('.sql', '', $file);
    $applied = is_migration_applied($version);
    $status = $applied ? '✅ Applied' : '❌ Missing';
    
    $sync_results['migrations_checked'][] = [
        'file' => $file,
        'version' => $version,
        'applied' => $applied
    ];
    
    echo "<div style='margin: 5px 0;'>";
    echo "$status - $file";
    if (!$applied) {
        echo " <button onclick='executeMigration(\"$file\")' style='margin-left: 10px;'>Execute</button>";
        $sync_results['missing_migrations'][] = $file;
    }
    echo "</div>";
}

// Execute missing critical migrations
echo "<h2>Executing Missing Critical Migrations</h2>";

$critical_migrations = [
    '007_audit_fix.sql',
    '008_rate_limit_store.sql', 
    '005_audit_trail.sql'
];

foreach ($critical_migrations as $migration) {
    $version = str_replace('.sql', '', $migration);
    if (!is_migration_applied($version)) {
        execute_migration($migration);
    }
}

// Check table status
check_table_status();

// Summary
echo "<h2>Database Sync Summary</h2>";

$total_migrations = count($sync_results['migrations_checked']);
$missing_count = count($sync_results['missing_migrations']);
$executed_count = count($sync_results['executed_migrations']);
$error_count = count($sync_results['errors']);

echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>Total Migrations Checked:</strong> $total_migrations</p>";
echo "<p><strong>Missing Migrations:</strong> $missing_count</p>";
echo "<p><strong>Executed Migrations:</strong> $executed_count</p>";
echo "<p><strong>Errors:</strong> $error_count</p>";

if ($error_count === 0) {
    echo "<p style='color: green; font-weight: bold;'>🎉 Database Sync Completed Successfully!</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>❌ Database Sync Completed with Errors</p>";
}
echo "</div>";

return $sync_results;
?>

<script>
function executeMigration(filename) {
    // In a real implementation, this would make an AJAX call
    // For now, just show a message
    alert('Migration execution would be triggered for: ' + filename);
}
</script>