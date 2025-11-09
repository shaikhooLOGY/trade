<?php
/**
 * execute_migration.php
 * Execute database migration to create user_profiles table
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

echo "Starting database migration...\n";

try {
    // Read the migration SQL file
    $sqlFile = __DIR__ . '/create_user_profiles_table.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)), function($stmt) {
        return !empty($stmt) && !preg_match('/^--/', $stmt);
    });
    
    echo "Found " . count($statements) . " SQL statements to execute.\n";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $result = $mysqli->query($statement);
            if ($result === true) {
                $successCount++;
                echo "✓ Executed successfully\n";
            } else {
                $errorCount++;
                echo "✗ Query failed: " . $mysqli->error . "\n";
            }
        } catch (Exception $e) {
            $errorCount++;
            echo "✗ Exception: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nMigration Summary:\n";
    echo "Successful: $successCount\n";
    echo "Failed: $errorCount\n";
    
    if ($errorCount === 0) {
        echo "✓ Migration completed successfully!\n";
        
        // Verify that the table was created
        $result = $mysqli->query("SHOW TABLES LIKE 'user_profiles'");
        if ($result->num_rows > 0) {
            echo "✓ user_profiles table confirmed to exist\n";
        } else {
            echo "✗ user_profiles table was not created\n";
        }
        
        // Check if users table was updated
        $result = $mysqli->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($result->num_rows > 0) {
            echo "✓ users.status column confirmed to exist\n";
        } else {
            echo "✗ users.status column was not added\n";
        }
        
    } else {
        echo "✗ Migration completed with errors\n";
    }
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Migration script completed.\n";
?>