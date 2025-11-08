<?php
/**
 * db_check.php - Database connection verification for live server
 * Upload this to your live server and access via browser
 */

echo "<h2>Live Server Database Connection Check</h2>";

echo "<h3>1. Load Environment Configuration</h3>";
try {
    require_once __DIR__ . '/includes/env.php';
    echo "<p style='color: green;'>✅ Environment loaded</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Environment loading failed: " . $e->getMessage() . "</p>";
    echo "<p><strong>Fix:</strong> Check includes/env.php file exists and .env file is readable</p>";
    exit;
}

echo "<h3>2. Database Credentials from .env</h3>";
if (isset($GLOBALS['DB_HOST'])) {
    echo "<p style='color: green;'>✅ Database credentials loaded from .env</p>";
    echo "<ul>";
    echo "<li>Host: " . htmlspecialchars($GLOBALS['DB_HOST']) . "</li>";
    echo "<li>User: " . htmlspecialchars($GLOBALS['DB_USER']) . "</li>";
    echo "<li>Password: " . (empty($GLOBALS['DB_PASS']) ? "Empty" : "Set (length: " . strlen($GLOBALS['DB_PASS']) . ")") . "</li>";
    echo "<li>Database: " . htmlspecialchars($GLOBALS['DB_NAME']) . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color: red;'>❌ Database credentials not loaded from .env file</p>";
    echo "<p><strong>Fix:</strong> Check your .env file has DB_HOST, DB_USER, DB_PASS, DB_NAME defined</p>";
    exit;
}

echo "<h3>3. Database Connection Test</h3>";
try {
    $mysqli = @new mysqli($GLOBALS['DB_HOST'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $GLOBALS['DB_NAME']);
    
    if ($mysqli->connect_errno) {
        echo "<p style='color: red;'>❌ Database connection failed</p>";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($mysqli->connect_error) . "</p>";
        echo "<p><strong>Error Number:</strong> " . $mysqli->connect_errno . "</p>";
        
        // Common solutions
        echo "<h4>Common Solutions:</h4>";
        echo "<ul>";
        echo "<li>Verify database credentials in your .env file</li>";
        echo "<li>Check if database exists on your hosting server</li>";
        echo "<li>Ensure database user has proper permissions</li>";
        echo "<li>Check if database host is correct (localhost, 127.0.0.1, or specific IP)</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: green;'>✅ Database connected successfully</p>";
        
        // Get database info
        $result = $mysqli->query("SELECT VERSION() as version, DATABASE() as dbname, USER() as user");
        if ($result) {
            $info = $result->fetch_assoc();
            echo "<h4>Database Information:</h4>";
            echo "<ul>";
            echo "<li>MySQL Version: " . htmlspecialchars($info['version']) . "</li>";
            echo "<li>Current Database: " . htmlspecialchars($info['dbname']) . "</li>";
            echo "<li>Current User: " . htmlspecialchars($info['user']) . "</li>";
            echo "</ul>";
        }
        
        // Check if required tables exist
        echo "<h4>Table Structure Check</h4>";
        $tables = ['users', 'email_verifications', 'password_resets'];
        foreach ($tables as $table) {
            $result = $mysqli->query("SHOW TABLES LIKE '{$table}'");
            if ($result && $result->num_rows > 0) {
                echo "<p style='color: green;'>✅ Table '{$table}' exists</p>";
                
                // Show table structure
                $struct = $mysqli->query("DESCRIBE {$table}");
                if ($struct) {
                    echo "<details><summary>Show '{$table}' structure</summary>";
                    echo "<pre>";
                    while ($row = $struct->fetch_assoc()) {
                        echo htmlspecialchars($row['Field'] . " - " . $row['Type'] . "\n");
                    }
                    echo "</pre></details>";
                }
            } else {
                echo "<p style='color: orange;'>⚠️ Table '{$table}' not found</p>";
            }
        }
        
        $mysqli->close();
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database connection exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><strong>Testing Registration System Tables:</strong></p>";

// Test if we can insert and read from users table (if it exists)
echo "<h3>4. Users Table Test</h3>";
try {
    $mysqli = @new mysqli($GLOBALS['DB_HOST'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $GLOBALS['DB_NAME']);
    if (!$mysqli->connect_errno) {
        // Check if users table exists
        $result = $mysqli->query("SHOW TABLES LIKE 'users'");
        if ($result && $result->num_rows > 0) {
            // Check if email_verifications table exists
            $result2 = $mysqli->query("SHOW TABLES LIKE 'email_verifications'");
            if ($result2 && $result2->num_rows > 0) {
                echo "<p style='color: green;'>✅ Both 'users' and 'email_verifications' tables exist</p>";
                echo "<p>The database structure appears correct for email verification system.</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ 'users' table exists but 'email_verifications' table is missing</p>";
                echo "<p>Email verification may not work properly. Create the email_verifications table.</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ 'users' table not found</p>";
            echo "<p>Registration system may not work properly. Check your database schema.</p>";
        }
        $mysqli->close();
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Table test failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>5. Next Steps</h3>";
echo "<ol>";
echo "<li>Fix any database connection issues</li>";
echo "<li>Ensure all required tables exist (users, email_verifications, password_resets)</li>";
echo "<li>Run <code>config_check.php</code> to verify environment loading</li>";
echo "<li>Run <code>smtp_test_live.php</code> to test email functionality</li>";
echo "<li>Test user registration after database issues are resolved</li>";
echo "</ol>";
?>