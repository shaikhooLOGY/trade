<?php
/**
 * Database Migration Runner
 * Runs all migrations in order and generates sync report
 */

// Fallback app_log function for migration script
if (!function_exists('app_log')) {
    function app_log(string $level, string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        echo "LOG: $logEntry";
    }
}

require_once __DIR__ . '/../core/bootstrap.php';

function run_database_migration(string $migrationFile): array {
    global $mysqli;
    
    $result = [
        'file' => basename($migrationFile),
        'status' => 'pending',
        'error' => null,
        'executed_at' => date('c')
    ];
    
    try {
        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: $migrationFile");
        }
        
        $sql = file_get_contents($migrationFile);
        if ($sql === false) {
            throw new Exception("Could not read migration file: $migrationFile");
        }
        
        // Split SQL into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        $mysqli->begin_transaction();
        
        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue; // Skip empty lines and comments
            }
            
            if (!$mysqli->query($statement)) {
                throw new Exception("SQL Error: " . $mysqli->error . " in statement: " . substr($statement, 0, 100));
            }
        }
        
        $mysqli->commit();
        $result['status'] = 'success';
        
    } catch (Exception $e) {
        // Check if mysqli connection is still valid
        if ($mysqli && $mysqli->stat() !== false) {
            $mysqli->rollback();
        }
        $result['status'] = 'error';
        $result['error'] = $e->getMessage();
        
        app_log('error', "Migration failed: {$migrationFile} - " . $e->getMessage());
    }
    
    return $result;
}

function verify_database_schema(): array {
    global $mysqli;
    
    $expectedTables = [
        'users', 'mtm_models', 'mtm_tasks', 'mtm_enrollments', 'trades',
        'rate_limits', 'idempotency_keys', 'audit_events', 'audit_event_types',
        'audit_retention_policies', 'agent_logs', 'leagues', 'schema_migrations'
    ];
    
    $verification = [
        'total_expected' => count($expectedTables),
        'tables_found' => [],
        'tables_missing' => [],
        'status' => 'unknown'
    ];
    
    try {
        $result = $mysqli->query("SHOW TABLES");
        $existingTables = [];
        
        while ($row = $result->fetch_array()) {
            $existingTables[] = $row[0];
        }
        
        foreach ($expectedTables as $table) {
            if (in_array($table, $existingTables, true)) {
                $verification['tables_found'][] = $table;
            } else {
                $verification['tables_missing'][] = $table;
            }
        }
        
        $verification['status'] = empty($verification['tables_missing']) ? 'complete' : 'incomplete';
        
    } catch (Exception $e) {
        $verification['error'] = $e->getMessage();
        $verification['status'] = 'error';
    }
    
    return $verification;
}

function generate_sync_report(array $migrationResults, array $verification): string {
    $report = [];
    $report[] = "# Database Unified Sync Report";
    $report[] = "Generated: " . date('c');
    $report[] = "";
    
    $report[] = "## Migration Results";
    $report[] = "";
    $successful = 0;
    $failed = 0;
    
    foreach ($migrationResults as $result) {
        $status = $result['status'] === 'success' ? '✅' : '❌';
        $report[] = "- $status **{$result['file']}**: {$result['status']}";
        if ($result['status'] === 'error') {
            $report[] = "  - Error: " . $result['error'];
        }
        $report[] = "  - Executed: {$result['executed_at']}";
        $report[] = "";
        
        if ($result['status'] === 'success') {
            $successful++;
        } else {
            $failed++;
        }
    }
    
    $report[] = "**Summary**: $successful successful, $failed failed";
    $report[] = "";
    
    $report[] = "## Schema Verification";
    $report[] = "";
    $report[] = "**Status**: " . ($verification['status'] === 'complete' ? '✅ Complete' : '❌ Incomplete');
    $report[] = "**Expected Tables**: {$verification['total_expected']}";
    $report[] = "**Found**: " . count($verification['tables_found']);
    $report[] = "**Missing**: " . count($verification['tables_missing']);
    $report[] = "";
    
    if (!empty($verification['tables_found'])) {
        $report[] = "### Tables Found";
        foreach ($verification['tables_found'] as $table) {
            $report[] = "- ✅ $table";
        }
        $report[] = "";
    }
    
    if (!empty($verification['tables_missing'])) {
        $report[] = "### Tables Missing";
        foreach ($verification['tables_missing'] as $table) {
            $report[] = "- ❌ $table";
        }
        $report[] = "";
    }
    
    if (isset($verification['error'])) {
        $report[] = "### Verification Error";
        $report[] = $verification['error'];
        $report[] = "";
    }
    
    $report[] = "## Next Steps";
    $report[] = "";
    if ($verification['status'] === 'complete') {
        $report[] = "✅ Database schema is fully synchronized";
        $report[] = "✅ All required tables are present";
        $report[] = "✅ System ready for Phase C (API Standardization)";
    } else {
        $report[] = "❌ Database schema synchronization incomplete";
        $report[] = "⚠️ Missing tables must be created before proceeding";
        $report[] = "🔧 Run missing migrations or check database permissions";
    }
    
    return implode("\n", $report);
}

// Main execution
try {
    echo "🚀 Starting Database Migration Process...\n";
    
    // Find all migration files
    $migrationDir = __DIR__ . '/../database/migrations';
    $migrationFiles = glob($migrationDir . '/*.sql');
    sort($migrationFiles);
    
    $results = [];
    
    // Run each migration
    foreach ($migrationFiles as $file) {
        echo "Running migration: " . basename($file) . "\n";
        $result = run_database_migration($file);
        $results[] = $result;
        
        if ($result['status'] === 'success') {
            echo "  ✅ Success\n";
        } else {
            echo "  ❌ Failed: {$result['error']}\n";
        }
    }
    
    // Verify schema
    echo "Verifying database schema...\n";
    $verification = verify_database_schema();
    
    // Generate report
    $report = generate_sync_report($results, $verification);
    
    // Save report
    $reportFile = __DIR__ . '/../reports/db_unified_sync.md';
    file_put_contents($reportFile, $report);
    
    echo "\n📊 Migration Results:\n";
    echo "  - Successful: " . count(array_filter($results, fn($r) => $r['status'] === 'success')) . "\n";
    echo "  - Failed: " . count(array_filter($results, fn($r) => $r['status'] === 'error')) . "\n";
    echo "  - Schema Status: " . $verification['status'] . "\n";
    echo "  - Report saved to: $reportFile\n";
    
    // Exit with appropriate code
    exit($verification['status'] === 'complete' ? 0 : 1);
    
} catch (Exception $e) {
    echo "❌ Migration process failed: " . $e->getMessage() . "\n";
    app_log('error', 'Database migration process failed: ' . $e->getMessage());
    exit(1);
}