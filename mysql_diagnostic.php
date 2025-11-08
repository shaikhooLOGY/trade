<?php
// mysql_diagnostic.php - MySQL Connection Diagnostic Tool
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 MySQL Connection Diagnostic</h2>";

// Test the current configuration from config.php
require_once __DIR__ . '/config.php';

echo "<h3>Current Configuration Test:</h3>";
echo "<p><strong>Host:</strong> " . htmlspecialchars($DB_HOST) . "</p>";
echo "<p><strong>User:</strong> " . htmlspecialchars($DB_USER) . "</p>";
echo "<p><strong>Database:</strong> " . htmlspecialchars($DB_NAME) . "</p>";

if (isset($mysqli)) {
    echo "<h3>Connection Test Results:</h3>";
    
    if ($mysqli->connect_errno) {
        echo "<p style='color: red;'>❌ Connection Failed: " . htmlspecialchars($mysqli->connect_error) . "</p>";
    } else {
        echo "<p style='color: green;'>✅ Connection Successful!</p>";
        
        // Test database selection
        $result = $mysqli->query("SELECT DATABASE() as current_db, USER() as mysql_user");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "<p><strong>Current Database:</strong> " . htmlspecialchars($row['current_db']) . "</p>";
            echo "<p><strong>Current User:</strong> " . htmlspecialchars($row['current_user']) . "</p>";
            echo "<p><strong>Server Time:</strong> " . htmlspecialchars($row['current_time']) . "</p>";
        }
        
        // Test table access
        $tables_result = $mysqli->query("SHOW TABLES");
        if ($tables_result) {
            echo "<h4>Available Tables:</h4><ul>";
            while ($table = $tables_result->fetch_array()) {
                echo "<li>" . htmlspecialchars($table[0]) . "</li>";
            }
            echo "</ul>";
        }
        
        // Test user count if users table exists
        $user_count = $mysqli->query("SELECT COUNT(*) as count FROM users");
        if ($user_count) {
            $count = $user_count->fetch_assoc()['count'];
            echo "<p><strong>Total Users:</strong> " . (int)$count . "</p>";
        }
        
        $mysqli->close();
    }
} else {
    echo "<p style='color: red;'>❌ \$mysqli object not initialized</p>";
}

echo "<h3>System Information:</h3>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>MySQL Extension:</strong> " . (extension_loaded('mysqli') ? 'Loaded' : 'Not Loaded') . "</p>";
echo "<p><strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</p>";
?>