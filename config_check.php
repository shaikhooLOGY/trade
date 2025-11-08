<?php
/**
 * config_check.php - Configuration verification for live server
 * Upload this to your live server and access via browser
 */

echo "<h2>Live Server Configuration Check</h2>";

// Check if .env file exists and is readable
$envFile = __DIR__ . '/.env';
echo "<h3>1. .env File Check</h3>";

if (file_exists($envFile)) {
    echo "<p style='color: green;'>✅ .env file exists</p>";
    if (is_readable($envFile)) {
        echo "<p style='color: green;'>✅ .env file is readable</p>";
        echo "<p>File size: " . filesize($envFile) . " bytes</p>";
    } else {
        echo "<p style='color: red;'>❌ .env file exists but is not readable</p>";
        echo "<p><strong>Fix:</strong> Set file permissions to 644: <code>chmod 644 .env</code></p>";
    }
} else {
    echo "<p style='color: red;'>❌ .env file does not exist</p>";
    echo "<p><strong>Fix:</strong> Upload .env file to the same directory as includes/env.php</p>";
}

echo "<hr>";

// Check environment loading
echo "<h3>2. Environment Loading</h3>";
try {
    require_once __DIR__ . '/includes/env.php';
    echo "<p style='color: green;'>✅ includes/env.php loaded successfully</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ includes/env.php failed to load: " . $e->getMessage() . "</p>";
    echo "<p><strong>Fix:</strong> Check if includes/env.php file exists and is readable</p>";
}

echo "<h3>3. Environment Variables</h3>";
echo "<ul>";
echo "<li>APP_ENV: " . (defined('APP_ENV') ? APP_ENV : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "<li>DB_HOST: " . (isset($GLOBALS['DB_HOST']) ? $GLOBALS['DB_HOST'] : '<span style="color: red;">NOT LOADED</span>') . "</li>";
echo "<li>DB_USER: " . (isset($GLOBALS['DB_USER']) ? $GLOBALS['DB_USER'] : '<span style="color: red;">NOT LOADED</span>') . "</li>";
echo "<li>DB_NAME: " . (isset($GLOBALS['DB_NAME']) ? $GLOBALS['DB_NAME'] : '<span style="color: red;">NOT LOADED</span>') . "</li>";
echo "<li>SMTP_HOST: " . (isset($GLOBALS['SMTP_HOST']) ? $GLOBALS['SMTP_HOST'] : '<span style="color: red;">NOT LOADED</span>') . "</li>";
echo "<li>SMTP_USER: " . (isset($GLOBALS['SMTP_USER']) ? $GLOBALS['SMTP_USER'] : '<span style="color: red;">NOT LOADED</span>') . "</li>";
echo "</ul>";

echo "<h3>4. Configuration Constants</h3>";
if (defined('SMTP_HOST')) {
    echo "<p style='color: green;'>✅ SMTP constants are defined</p>";
    echo "<ul>";
    echo "<li>Host: " . SMTP_HOST . "</li>";
    echo "<li>Port: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT DEFINED') . "</li>";
    echo "<li>User: " . (defined('SMTP_USER') ? SMTP_USER : 'NOT DEFINED') . "</li>";
    echo "<li>From: " . (defined('MAIL_FROM') ? MAIL_FROM : 'NOT DEFINED') . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color: red;'>❌ SMTP constants are not defined</p>";
    echo "<p>This means .env file is not loading properly. Check file permissions and content.</p>";
}

echo "<hr>";

// Check config.php loading
echo "<h3>5. Config.php Loading</h3>";
try {
    require_once __DIR__ . '/config.php';
    echo "<p style='color: green;'>✅ config.php loaded successfully</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ config.php failed to load: " . $e->getMessage() . "</p>";
}

echo "<h3>6. File Permissions</h3>";
$files_to_check = ['.env', 'includes/env.php', 'config.php', 'mailer.php', 'logs'];
foreach ($files_to_check as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        echo "<p>{$file}: {$perms} " . (is_readable($path) ? "✅ Readable" : "❌ Not readable") . "</p>";
    } else {
        echo "<p>{$file}: <span style='color: red;'>❌ Missing</span></p>";
    }
}

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Fix any red (❌) issues above</li>";
echo "<li>Run <code>db_check.php</code> to test database connection</li>";
echo "<li>Run <code>smtp_test_live.php</code> to test email sending</li>";
echo "<li>Check <code>env_diagnostic.php</code> for detailed environment info</li>";
echo "</ol>";
?>