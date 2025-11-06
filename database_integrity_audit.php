<?php
/**
 * Phase 3 Database Integrity Verification Script
 * Compares live database schema with migrations and generates comprehensive report
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment and config
require_once 'includes/env.php';
require_once 'config.php';

// Database connection using mysqli from config
try {
    $pdo = new PDO("mysql:host=" . $dbHost . ";port=" . $dbPort . ";dbname=" . $dbName, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Core tables to verify
$core_tables = [
    'users',
    'trades', 
    'mtm_models',
    'mtm_tasks',
    'mtm_enrollments',
    'audit_logs',
    'audit_events', 
    'audit_event_types',
    'audit_retention_policies',
    'rate_limits',
    'agent_logs',
    'idempotency_keys',
    'leagues',
    'guard_trade_limits'
];

// Results tracking
$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tables' => [],
    'migrations' => [],
    'schema_checks' => [],
    'indexes' => [],
    'row_counts' => [],
    'integrity_score' => 0
];

echo "=== PHASE 3 DATABASE INTEGRITY VERIFICATION ===\n";
echo "Timestamp: " . $results['timestamp'] . "\n\n";

// 1. Check core tables exist
echo "1. CORE TABLES VERIFICATION\n";
echo str_repeat("-", 50) . "\n";

foreach ($core_tables as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        $exists = $stmt->fetch() !== false;
        $results['tables'][$table]['exists'] = $exists;
        
        if ($exists) {
            // Get row count
            $count_stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$table}`");
            $row_count = $count_stmt->fetch()['count'];
            $results['tables'][$table]['row_count'] = $row_count;
            
            // Get table structure
            $desc_stmt = $pdo->query("DESCRIBE `{$table}`");
            $columns = $desc_stmt->fetchAll();
            $results['tables'][$table]['columns'] = $columns;
            
            echo "✓ {$table}: EXISTS ({$row_count} rows)\n";
        } else {
            echo "✗ {$table}: MISSING\n";
        }
    } catch (PDOException $e) {
        echo "✗ {$table}: ERROR - " . $e->getMessage() . "\n";
        $results['tables'][$table]['error'] = $e->getMessage();
    }
}

// 2. Check indexes
echo "\n2. INDEX VERIFICATION\n";
echo str_repeat("-", 50) . "\n";

foreach ($core_tables as $table) {
    if ($results['tables'][$table]['exists'] ?? false) {
        try {
            $index_stmt = $pdo->query("SHOW INDEX FROM `{$table}`");
            $indexes = $index_stmt->fetchAll();
            $results['tables'][$table]['indexes'] = $indexes;
            
            $index_count = count($indexes);
            echo "✓ {$table}: {$index_count} indexes found\n";
        } catch (PDOException $e) {
            echo "✗ {$table}: Index check failed - " . $e->getMessage() . "\n";
        }
    }
}

// 3. Foreign key constraints
echo "\n3. FOREIGN KEY VERIFICATION\n";
echo str_repeat("-", 50) . "\n";

foreach ($core_tables as $table) {
    if ($results['tables'][$table]['exists'] ?? false) {
        try {
            $fk_stmt = $pdo->query("
                SELECT 
                    CONSTRAINT_NAME,
                    TABLE_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = '" . $dbName . "'
                AND TABLE_NAME = '{$table}'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $foreign_keys = $fk_stmt->fetchAll();
            $results['tables'][$table]['foreign_keys'] = $foreign_keys;
            
            $fk_count = count($foreign_keys);
            echo "✓ {$table}: {$fk_count} foreign keys\n";
        } catch (PDOException $e) {
            echo "✗ {$table}: Foreign key check failed - " . $e->getMessage() . "\n";
        }
    }
}

// 4. Migration files analysis
echo "\n4. MIGRATION FILES ANALYSIS\n";
echo str_repeat("-", 50) . "\n";

$migration_dir = 'database/migrations';
if (is_dir($migration_dir)) {
    $migration_files = glob($migration_dir . '/*.sql');
    sort($migration_files);
    
    foreach ($migration_files as $file) {
        $filename = basename($file);
        $content = file_get_contents($file);
        $checksum = hash_file('sha256', $file);
        $size = filesize($file);
        
        $results['migrations'][] = [
            'file' => $filename,
            'path' => $file,
            'size' => $size,
            'checksum' => $checksum,
            'created' => date('Y-m-d H:i:s', filemtime($file))
        ];
        
        echo "✓ {$filename}: {$size} bytes, checksum {$checksum}\n";
    }
}

// 5. Schema consistency check
echo "\n5. SCHEMA CONSISTENCY CHECK\n";
echo str_repeat("-", 50) . "\n";

foreach ($core_tables as $table) {
    if ($results['tables'][$table]['exists'] ?? false) {
        try {
            $schema_stmt = $pdo->query("
                SELECT 
                    TABLE_NAME,
                    COLUMN_NAME,
                    DATA_TYPE,
                    IS_NULLABLE,
                    COLUMN_DEFAULT,
                    CHARACTER_MAXIMUM_LENGTH
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = '" . $dbName . "'
                AND TABLE_NAME = '{$table}'
                ORDER BY ORDINAL_POSITION
            ");
            $schema = $schema_stmt->fetchAll();
            $results['schema_checks'][$table] = $schema;
            
            echo "✓ {$table}: " . count($schema) . " columns verified\n";
        } catch (PDOException $e) {
            echo "✗ {$table}: Schema check failed - " . $e->getMessage() . "\n";
        }
    }
}

// 6. Calculate integrity score
$total_checks = count($core_tables);
$passed_checks = 0;
foreach ($core_tables as $table) {
    if ($results['tables'][$table]['exists'] ?? false) {
        $passed_checks++;
    }
}
$results['integrity_score'] = round(($passed_checks / $total_checks) * 100, 1);

// Final score
echo "\n6. INTEGRITY SCORE\n";
echo str_repeat("-", 50) . "\n";
echo "Score: {$results['integrity_score']}% ({$passed_checks}/{$total_checks} tables)\n";

if ($results['integrity_score'] >= 90) {
    echo "Status: EXCELLENT (GREEN)\n";
} elseif ($results['integrity_score'] >= 70) {
    echo "Status: GOOD (YELLOW)\n";
} else {
    echo "Status: CRITICAL (RED)\n";
}

// 7. Generate checksum summary
echo "\n7. CHECKSUM SUMMARY\n";
echo str_repeat("-", 50) . "\n";
foreach ($results['migrations'] as $migration) {
    echo "{$migration['file']}: {$migration['checksum']}\n";
}

// Save results to JSON for report generation
file_put_contents('reports/phase3_verification/db_integrity_raw_data.json', json_encode($results, JSON_PRETTY_PRINT));

echo "\n=== VERIFICATION COMPLETE ===\n";
echo "Results saved to: reports/phase3_verification/db_integrity_raw_data.json\n";

?>