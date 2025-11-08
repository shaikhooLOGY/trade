<?php
/**
 * env_diagnostic.php - Complete environment diagnostic for live server
 * Upload this to your live server and access via browser
 * This provides comprehensive system information
 */

echo "<h2>Live Server Environment Diagnostic</h2>";

echo "<h3>1. PHP Information</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Property</th><th>Value</th></tr>";
echo "<tr><td>PHP Version</td><td>" . PHP_VERSION . "</td></tr>";
echo "<tr><td>Server Software</td><td>" . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Document Root</td><td>" . htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Current Directory</td><td>" . __DIR__ . "</td></tr>";
echo "<tr><td>Current Script</td><td>" . htmlspecialchars($_SERVER['SCRIPT_FILENAME'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Request URI</td><td>" . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'Unknown') . "</td></tr>";
echo "</table>";

// Check loaded extensions
echo "<h3>2. PHP Extensions</h3>";
$extensions = ['curl', 'openssl', 'mbstring', 'mysqli', 'json'];
foreach ($extensions as $ext) {
    $status = extension_loaded($ext) ? "✅ Loaded" : "❌ Not loaded";
    echo "<p>{$ext}: {$status}</p>";
}

// File system check
echo "<h3>3. File System Check</h3>";
$required_files = [
    '.env' => 'Environment configuration file',
    'includes/env.php' => 'Environment loader',
    'config.php' => 'Main configuration file',
    'mailer.php' => 'Email sending function',
    'config_local.php' => 'Local development config',
    'composer.json' => 'Composer dependencies',
    'vendor/autoload.php' => 'Composer autoloader'
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>File</th><th>Status</th><th>Size</th><th>Permissions</th></tr>";

foreach ($required_files as $file => $description) {
    $full_path = __DIR__ . '/' . $file;
    if (file_exists($full_path)) {
        $size = filesize($full_path);
        $perms = substr(sprintf('%o', fileperms($full_path)), -4);
        $readable = is_readable($full_path) ? "✅ Readable" : "❌ Not readable";
        echo "<tr><td>{$file}</td><td>✅ Exists</td><td>{$size} bytes</td><td>{$perms} - {$readable}</td></tr>";
    } else {
        echo "<tr><td>{$file}</td><td>❌ Missing</td><td>-</td><td>-</td></tr>";
    }
}
echo "</table>";

// .env file analysis
echo "<h3>4. .env File Analysis</h3>";
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $total_lines = count($lines);
    $comment_lines = 0;
    $valid_pairs = 0;
    $env_vars = [];
    
    foreach ($lines as $line) {
        if (preg_match('/^\s*[#;]/', $line)) {
            $comment_lines++;
        } elseif (strpos($line, '=') !== false) {
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key !== '') {
                $valid_pairs++;
                $env_vars[$key] = $value;
            }
        }
    }
    
    echo "<h4>File Statistics:</h4>";
    echo "<ul>";
    echo "<li>Total lines: {$total_lines}</li>";
    echo "<li>Comment lines: {$comment_lines}</li>";
    echo "<li>Valid key=value pairs: {$valid_pairs}</li>";
    echo "</ul>";
    
    echo "<h4>Critical Environment Variables:</h4>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Variable</th><th>Value</th><th>Status</th></tr>";
    
    $critical_vars = [
        'APP_ENV' => 'Environment mode',
        'DB_HOST' => 'Database host',
        'DB_USER' => 'Database username',
        'DB_NAME' => 'Database name',
        'SMTP_HOST' => 'SMTP server',
        'SMTP_USER' => 'SMTP username',
        'MAIL_FROM' => 'From email address'
    ];
    
    foreach ($critical_vars as $var => $description) {
        $value = $env_vars[$var] ?? 'NOT SET';
        $status = $value === 'NOT SET' ? '❌ Missing' : '✅ Set';
        echo "<tr><td>{$var}</td><td>" . htmlspecialchars($value) . "</td><td>{$status}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ .env file not found</p>";
}

// Include path test
echo "<h3>5. Include Path Test</h3>";
try {
    require_once __DIR__ . '/includes/env.php';
    echo "<p style='color: green;'>✅ includes/env.php loaded successfully</p>";
    echo "<p>APP_ENV defined: " . (defined('APP_ENV') ? 'YES' : 'NO') . "</p>";
    echo "<p>APP_ENV value: " . (defined('APP_ENV') ? APP_ENV : 'N/A') . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ includes/env.php failed to load: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Composer/PHPMailer check
echo "<h3>6. Composer/PHPMailer Check</h3>";
$autoload_file = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_file)) {
    echo "<p style='color: green;'>✅ Composer autoload exists</p>";
    try {
        require_once $autoload_file;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            echo "<p style='color: green;'>✅ PHPMailer loaded from composer</p>";
        } else {
            echo "<p style='color: red;'>❌ PHPMailer class not found</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Failed to load composer autoload: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ Composer autoload not found</p>";
    echo "<ul>";
    echo "<li>Check if vendor/ directory exists</li>";
    echo "<li>Run: composer install</li>";
    echo "<li>Ensure composer.json includes PHPMailer</li>";
    echo "</ul>";
    
    // Check for manual PHPMailer
    $manual_phpmailer = __DIR__ . '/phpmailer/src/PHPMailer.php';
    if (file_exists($manual_phpmailer)) {
        echo "<p style='color: orange;'>⚠️ Manual PHPMailer found at phpmailer/src/</p>";
    } else {
        echo "<p style='color: red;'>❌ No PHPMailer installation found</p>";
    }
}

// Log directory check
echo "<h3>7. Log Directory Check</h3>";
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
    echo "<p style='color: orange;'>⚠️ Created logs directory</p>";
}

if (is_dir($logDir) && is_writable($logDir)) {
    echo "<p style='color: green;'>✅ Logs directory is writable</p>";
    
    // Check log files
    $logFiles = ['mail.log', 'email_deliveries_' . date('Y-m-d') . '.log', 'email_failures_' . date('Y-m-d') . '.log'];
    foreach ($logFiles as $logFile) {
        $logPath = $logDir . '/' . $logFile;
        if (file_exists($logPath)) {
            $size = filesize($logPath);
            echo "<p>📄 {$logFile}: {$size} bytes</p>";
        } else {
            echo "<p>📄 {$logFile}: Not created yet</p>";
        }
    }
} else {
    echo "<p style='color: red;'>❌ Logs directory is not writable</p>";
    echo "<p><strong>Fix:</strong> Run <code>chmod 755 logs/</code> on your server</p>";
}

// Web server configuration check
echo "<h3>8. Web Server Configuration</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
echo "<tr><td>Server Name</td><td>" . htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Server Port</td><td>" . htmlspecialchars($_SERVER['SERVER_PORT'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Document Root</td><td>" . htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>HTTP Host</td><td>" . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Unknown') . "</td></tr>";
echo "<tr><td>Request Method</td><td>" . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'Unknown') . "</td></tr>";
echo "</table>";

// PHP configuration
echo "<h3>9. PHP Configuration</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
echo "<tr><td>max_execution_time</td><td>" . ini_get('max_execution_time') . "</td></tr>";
echo "<tr><td>memory_limit</td><td>" . ini_get('memory_limit') . "</td></tr>";
echo "<tr><td>upload_max_filesize</td><td>" . ini_get('upload_max_filesize') . "</td></tr>";
echo "<tr><td>post_max_size</td><td>" . ini_get('post_max_size') . "</td></tr>";
echo "<tr><td>display_errors</td><td>" . (ini_get('display_errors') ? 'On' : 'Off') . "</td></tr>";
echo "<tr><td>log_errors</td><td>" . (ini_get('log_errors') ? 'On' : 'Off') . "</td></tr>";
echo "<tr><td>error_log</td><td>" . ini_get('error_log') . "</td></tr>";
echo "</table>";

// MySQL connectivity test
echo "<h3>10. MySQL Connectivity Test</h3>";
try {
    require_once __DIR__ . '/includes/env.php';
    if (isset($GLOBALS['DB_HOST'])) {
        $mysqli = @new mysqli($GLOBALS['DB_HOST'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $GLOBALS['DB_NAME']);
        if (!$mysqli->connect_errno) {
            echo "<p style='color: green;'>✅ MySQL connection successful</p>";
            $result = $mysqli->query("SELECT VERSION() as version");
            if ($result) {
                $version = $result->fetch_assoc();
                echo "<p>MySQL Version: " . htmlspecialchars($version['version']) . "</p>";
            }
            $mysqli->close();
        } else {
            echo "<p style='color: red;'>❌ MySQL connection failed: " . htmlspecialchars($mysqli->connect_error) . "</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Database credentials not loaded</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database test failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<h3>11. Summary and Next Steps</h3>";
echo "<p><strong>Key Findings:</strong></p>";
echo "<ul>";
echo "<li>✅ All green checkmarks indicate working components</li>";
echo "<li>❌ Red items need immediate attention</li>";
echo "<li>⚠️ Orange items may need attention depending on your requirements</li>";
echo "</ul>";

echo "<p><strong>Recommended Actions:</strong></p>";
echo "<ol>";
echo "<li>Fix any ❌ (red) issues immediately</li>";
echo "<li>Run <code>config_check.php</code> to verify configuration</li>";
echo "<li>Run <code>db_check.php</code> to test database connectivity</li>";
echo "<li>Run <code>smtp_test_live.php</code> to test email functionality</li>";
echo "<li>Check <code>simple_email_test.php</code> for basic PHP mail() test</li>";
echo "</ol>";
?>