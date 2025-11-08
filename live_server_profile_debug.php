<?php
// Live Server Profile Debug Script
// This script will help identify what's causing the profile page to fail on the live server

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>🔧 Live Server Profile Debug</h1>";
echo "<hr>";

echo "<h2>1. Server Environment</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Server:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</p>";
echo "<p><strong>Document Root:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</p>";
echo "<p><strong>Current Directory:</strong> " . __DIR__ . "</p>";
echo "<p><strong>Current Time:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<hr>";

echo "<h2>2. File Existence Check</h2>";
$required_files = [
    'config.php' => '/Config file',
    'guard.php' => '/Guard file', 
    'includes/env.php' => '/Environment file',
    'includes/bootstrap.php' => '/Bootstrap file',
    'includes/config.php' => '/Include config',
    'includes/functions.php' => '/Functions file',
    'profile_fields.php' => '/Profile fields',
    'header.php' => '/Header template',
    'footer.php' => '/Footer template'
];

foreach ($required_files as $file => $desc) {
    $exists = file_exists($file);
    echo "<p><strong>$desc:</strong> " . ($exists ? "✅ EXISTS" : "❌ MISSING") . " - <code>$file</code></p>";
}
echo "<hr>";

echo "<h2>3. Environment Variables</h2>";
// Check if .env exists and load it
$env_file = __DIR__ . '/includes/.env';
if (file_exists($env_file)) {
    echo "<p><strong>.env file:</strong> ✅ EXISTS</p>";
    
    // Load .env content (basic parsing)
    if (is_readable($env_file)) {
        $env_content = file_get_contents($env_file);
        echo "<p><strong>.env file readable:</strong> ✅ YES</p>";
        
        // Extract APP_ENV
        if (preg_match('/APP_ENV\s*=\s*(.+)/', $env_content, $matches)) {
            $app_env = trim($matches[1]);
            echo "<p><strong>APP_ENV:</strong> $app_env</p>";
            
            if (!defined('APP_ENV')) {
                define('APP_ENV', $app_env);
            }
        }
    } else {
        echo "<p><strong>.env file readable:</strong> ❌ NO</p>";
    }
} else {
    echo "<p><strong>.env file:</strong> ❌ MISSING</p>";
}
echo "<hr>";

echo "<h2>4. Test File Includes</h2>";
echo "<h3>Testing config.php include...</h3>";
try {
    require_once __DIR__ . '/config.php';
    echo "<p>✅ Config loaded successfully</p>";
    
    // Check database connection
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        echo "<p>✅ Database connection: OK</p>";
        echo "<p><strong>DB Host:</strong> " . $mysqli->server_info . "</p>";
        echo "<p><strong>DB Error:</strong> " . ($mysqli->connect_error ?: "None") . "</p>";
        
        // Test query
        $result = $mysqli->query("SELECT 1 as test");
        if ($result) {
            echo "<p>✅ Database query test: PASSED</p>";
            $result->close();
        } else {
            echo "<p>❌ Database query test: FAILED</p>";
        }
    } else {
        echo "<p>❌ Database connection: FAILED</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Config loading error: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack trace:</strong><br><pre>" . $e->getTraceAsString() . "</pre></p>";
} catch (Error $e) {
    echo "<p>❌ PHP Error: " . $e->getMessage() . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
}

echo "<hr>";

echo "<h3>Testing guard.php include...</h3>";
try {
    // Note: guard.php may redirect, so we handle it gracefully
    $_SERVER['SCRIPT_NAME'] = 'live_server_profile_debug.php'; // Mock script name
    require_once __DIR__ . '/guard.php';
    echo "<p>✅ Guard loaded successfully</p>";
} catch (Exception $e) {
    echo "<p>❌ Guard loading error: " . $e->getMessage() . "</p>";
} catch (Error $e) {
    echo "<p>❌ Guard PHP Error: " . $e->getMessage() . "</p>";
} catch (Throwable $e) {
    echo "<p>❌ Guard Throwable: " . $e->getMessage() . "</p>";
}

echo "<hr>";

echo "<h3>Testing functions.php include...</h3>";
try {
    require_once __DIR__ . '/includes/functions.php';
    echo "<p>✅ Functions loaded successfully</p>";
} catch (Exception $e) {
    echo "<p>❌ Functions loading error: " . $e->getMessage() . "</p>";
} catch (Error $e) {
    echo "<p>❌ Functions PHP Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";

echo "<h3>Testing profile_fields.php include...</h3>";
try {
    $profile_fields = require __DIR__ . '/profile_fields.php';
    echo "<p>✅ Profile fields loaded: " . count($profile_fields) . " fields</p>";
    echo "<p><strong>Fields:</strong> " . implode(', ', array_keys($profile_fields)) . "</p>";
} catch (Exception $e) {
    echo "<p>❌ Profile fields loading error: " . $e->getMessage() . "</p>";
} catch (Error $e) {
    echo "<p>❌ Profile fields PHP Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";

echo "<h2>5. Session Check</h2>";
session_start();
echo "<p><strong>Session Status:</strong> " . session_status() . "</p>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Session Data:</strong></p>";
echo "<pre>";
var_dump($_SESSION);
echo "</pre>";
echo "<hr>";

echo "<h2>6. File Permissions Check</h2>";
$check_dirs = ['includes', 'logs', 'uploads'];
foreach ($check_dirs as $dir) {
    if (is_dir($dir)) {
        $readable = is_readable($dir);
        $writable = is_writable($dir);
        echo "<p><strong>$dir:</strong> Readable: " . ($readable ? "✅" : "❌") . ", Writable: " . ($writable ? "✅" : "❌") . "</p>";
    } else {
        echo "<p><strong>$dir:</strong> ❌ Directory doesn't exist</p>";
    }
}
echo "<hr>";

echo "<h2>7. Live Server Profile Test</h2>";
echo "<p>If all tests above pass, try accessing:</p>";
echo "<ul>";
echo "<li><a href='profile.php' target='_blank'>Profile Page</a></li>";
echo "<li><a href='header.php' target='_blank'>Header Template</a></li>";
echo "<li><a href='footer.php' target='_blank'>Footer Template</a></li>";
echo "</ul>";

echo "<p><strong>Debug completed at:</strong> " . date('Y-m-d H:i:s') . "</p>";
?>