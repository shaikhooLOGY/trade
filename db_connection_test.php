<?php
/**
 * Database Connection Diagnostic Script
 * Tests local and production database connectivity
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Connection Diagnostic</h1>";

echo "<h2>1. Current Environment</h2>";
require_once __DIR__ . '/includes/env.php';

echo "<h3>Environment Variables Loaded:</h3>";
echo "<ul>";
echo "<li>APP_ENV: " . (defined('APP_ENV') ? APP_ENV : 'NOT SET') . "</li>";
echo "<li>DB_HOST: " . ($GLOBALS['DB_HOST'] ?? 'NOT SET') . "</li>";
echo "<li>DB_USER: " . ($GLOBALS['DB_USER'] ?? 'NOT SET') . "</li>";
echo "<li>DB_PASS: " . (empty($GLOBALS['DB_PASS']) ? "Empty" : "Set (length: " . strlen($GLOBALS['DB_PASS']) . ")") . "</li>";
echo "<li>DB_NAME: " . ($GLOBALS['DB_NAME'] ?? 'NOT SET') . "</li>";
echo "</ul>";

echo "<h2>2. Test Production Database Connection</h2>";
try {
    $prod_mysqli = @new mysqli($GLOBALS['DB_HOST'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $GLOBALS['DB_NAME']);
    if ($prod_mysqli->connect_errno) {
        echo "<p style='color: red;'>❌ Production DB Connection Failed: " . $prod_mysqli->connect_error . "</p>";
    } else {
        echo "<p style='color: green;'>✅ Production DB Connection Successful</p>";
        $prod_mysqli->close();
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Production DB Error: " . $e->getMessage() . "</p>";
}

echo "<h2>3. Test Local Database Connection</h2>";
// Test local database connection
$local_host = '127.0.0.1';
$local_user = 'root';
$local_pass = '';
$local_name = 'traders_local';

echo "<h3>Testing Local DB:</h3>";
echo "<ul>";
echo "<li>Host: $local_host</li>";
echo "<li>User: $local_user</li>";
echo "<li>Password: (empty)</li>";
echo "<li>Database: $local_name</li>";
echo "</ul>";

try {
    $local_mysqli = @new mysqli($local_host, $local_user, $local_pass, $local_name);
    if ($local_mysqli->connect_errno) {
        echo "<p style='color: red;'>❌ Local DB Connection Failed: " . $local_mysqli->connect_error . "</p>";
        
        // Try with different database names
        $test_databases = ['traders_club', 'tradersclub', 'test', 'mysql'];
        echo "<h4>Testing alternative local database names:</h4>";
        foreach ($test_databases as $db_name) {
            try {
                $test_mysqli = @new mysqli($local_host, $local_user, $local_pass, $db_name);
                if (!$test_mysqli->connect_errno) {
                    echo "<p style='color: green;'>✅ Local DB Connection successful with database: $db_name</p>";
                    $test_mysqli->close();
                    break;
                }
            } catch (Exception $e) {
                // Continue to next database
            }
        }
    } else {
        echo "<p style='color: green;'>✅ Local DB Connection Successful with $local_name</p>";
        $local_mysqli->close();
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Local DB Error: " . $e->getMessage() . "</p>";
}

echo "<h2>4. Test Current Application DB Connection</h2>";
try {
    // Test using the current application configuration
    require_once __DIR__ . '/config.php';
    
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        if ($mysqli->ping()) {
            echo "<p style='color: green;'>✅ Application DB Connection Successful</p>";
        } else {
            echo "<p style='color: red;'>❌ Application DB Connection Failed (mysqli object exists but ping failed)</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Application DB Connection Failed (mysqli object not created)</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Application DB Error: " . $e->getMessage() . "</p>";
}

echo "<h2>5. Recommendations</h2>";
echo "<div style='background: #f0f8ff; padding: 15px; border: 1px solid #0066cc; border-radius: 5px;'>";
echo "<h3>Solution Options:</h3>";
echo "<ol>";
echo "<li><strong>Use Local Database:</strong> Set up local MySQL with 'traders_local' database</li>";
echo "<li><strong>Update .env for Local:</strong> Modify .env to use local database credentials</li>";
echo "<li><strong>Create Local Override:</strong> Create local configuration that overrides .env for development</li>";
echo "</ol>";
echo "</div>";

?>