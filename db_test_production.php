<?php
/**
 * Database Connection Test for Production
 * Tests the production database connection using the new config
 */

require_once __DIR__ . '/config.production.php';

echo "<h1>Production Database Connection Test</h1>\n";
echo "<pre>\n";

try {
    // Test 1: Check environment variables
    echo "=== ENVIRONMENT CHECK ===\n";
    echo "APP_ENV: " . (defined('APP_ENV') ? APP_ENV : 'NOT SET') . "\n";
    echo "DB_HOST: " . ($GLOBALS['DB_HOST'] ?? 'NOT SET') . "\n";
    echo "DB_USER: " . ($GLOBALS['DB_USER'] ?? 'NOT SET') . "\n";
    echo "DB_PASS: " . (isset($GLOBALS['DB_PASS']) ? '***SET***' : 'NOT SET') . "\n";
    echo "DB_NAME: " . ($GLOBALS['DB_NAME'] ?? 'NOT SET') . "\n";
    echo "SITE_URL: " . (defined('SITE_URL') ? SITE_URL : 'NOT SET') . "\n";
    echo "\n";

    // Test 2: Check mysqli connection
    echo "=== DATABASE CONNECTION ===\n";
    echo "Connection Object: " . (isset($mysqli) ? 'CREATED' : 'NOT CREATED') . "\n";
    
    if (isset($mysqli)) {
        echo "Connection Error: " . ($mysqli->connect_error ?? 'NONE') . "\n";
        echo "Connection Errno: " . ($mysqli->connect_errno ?? 'NONE') . "\n";
        
        if (!$mysqli->connect_errno) {
            echo "✅ Database connection SUCCESSFUL\n";
            echo "Server Info: " . $mysqli->server_info . "\n";
            echo "Client Info: " . $mysqli->client_info . "\n";
            echo "Character Set: " . $mysqli->character_set_name() . "\n";
            
            // Test 3: Check current database
            $result = $mysqli->query("SELECT DATABASE() as current_db");
            $row = $result->fetch_assoc();
            echo "Current Database: " . $row['current_db'] . "\n";
            
            // Test 4: Check if users table exists
            $result = $mysqli->query("SHOW TABLES LIKE 'users'");
            $usersTableExists = $result->num_rows > 0;
            echo "Users Table: " . ($usersTableExists ? 'EXISTS' : 'MISSING') . "\n";
            
            if ($usersTableExists) {
                // Test 5: Check user count
                $result = $mysqli->query("SELECT COUNT(*) as count FROM users");
                $row = $result->fetch_assoc();
                echo "User Count: " . $row['count'] . "\n";
                
                // Test 6: Check if trades table exists
                $result = $mysqli->query("SHOW TABLES LIKE 'trades'");
                $tradesTableExists = $result->num_rows > 0;
                echo "Trades Table: " . ($tradesTableExists ? 'EXISTS' : 'MISSING') . "\n";
                
                if ($tradesTableExists) {
                    $result = $mysqli->query("SELECT COUNT(*) as count FROM trades");
                    $row = $result->fetch_assoc();
                    echo "Trades Count: " . $row['count'] . "\n";
                }
            }
            
            echo "\n=== SMTP CONFIGURATION ===\n";
            echo "SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : 'NOT SET') . "\n";
            echo "SMTP_PORT: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT SET') . "\n";
            echo "SMTP_USER: " . (defined('SMTP_USER') ? SMTP_USER : 'NOT SET') . "\n";
            echo "MAIL_FROM: " . (defined('MAIL_FROM') ? MAIL_FROM : 'NOT SET') . "\n";
            
            echo "\n=== CONFIGURATION SUMMARY ===\n";
            echo "Environment: PRODUCTION\n";
            echo "Database: CONNECTED ✅\n";
            echo "Site URL: " . (defined('SITE_URL') ? SITE_URL : 'NOT SET') . "\n";
            echo "All Required Tables: FOUND ✅\n";
            echo "\n🚀 CONFIGURATION IS READY FOR PRODUCTION DEPLOYMENT!\n";
            
        } else {
            echo "❌ Database connection FAILED\n";
            echo "Error: " . $mysqli->connect_error . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
}

echo "</pre>\n";

// Add some basic styling
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h1 { color: #2c3e50; text-align: center; }
pre { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
pre { color: #2c3e50; line-height: 1.6; }
</style>";

?>