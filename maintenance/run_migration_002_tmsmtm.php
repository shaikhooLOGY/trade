<?php
/**
 * Maintenance Script: Run TMS-MTM Migration
 * 
 * Usage: php maintenance/run_migration_002_tmsmtm.php
 * 
 * This script reads database/migrations/002_tmsmtm.sql and executes it
 * using the existing mysqli connection from config.php
 */

// Include required files
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../config.php';

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

echo "🚀 Starting TMS-MTM Migration (002_tmsmtm.sql)...\n";

try {
    // Check if migration file exists
    $migrationFile = __DIR__ . '/../database/migrations/002_tmsmtm.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    // Read migration SQL
    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        throw new Exception("Failed to read migration file");
    }
    
    // Remove comments and split into individual statements
    $sql = preg_replace('/--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    echo "📊 Found " . count($statements) . " SQL statements to execute\n";
    
    // Execute each statement
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $i => $statement) {
        if (empty(trim($statement))) continue;
        
        echo "   Executing statement " . ($i + 1) . "... ";
        
        try {
            $result = $GLOBALS['mysqli']->query($statement);
            if ($result === false) {
                $error = $GLOBALS['mysqli']->error;
                echo "❌ FAILED: $error\n";
                $errorCount++;
            } else {
                echo "✅ SUCCESS\n";
                $successCount++;
            }
        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }
    
    echo "\n📈 Migration Summary:\n";
    echo "   ✅ Successful: $successCount\n";
    echo "   ❌ Failed: $errorCount\n";
    
    if ($errorCount === 0) {
        echo "\n🎉 TMS-MTM Migration completed successfully!\n";
        
        // Verify tables were created
        $tables = ['mtm_models', 'mtm_tasks', 'mtm_enrollments', 'trades'];
        echo "\n🔍 Verifying created tables:\n";
        
        foreach ($tables as $table) {
            $result = $GLOBALS['mysqli']->query("SHOW TABLES LIKE '$table'");
            if ($result && $result->num_rows > 0) {
                echo "   ✅ $table\n";
            } else {
                echo "   ❌ $table (missing)\n";
            }
        }
        
    } else {
        echo "\n⚠️  Migration completed with $errorCount errors. Please check the output above.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n💥 Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}