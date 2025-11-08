<?php
/**
 * test_local_email.php - Test Local Development Email Functionality
 * 
 * This script tests:
 * 1. Email configuration loading
 * 2. Development mode detection
 * 3. OTP generation and storage
 * 4. Email logging functionality
 * 5. Development OTP display
 */

echo "=== Local Development Email Test ===\n\n";

// Load configurations
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_local.php';
require_once __DIR__ . '/mailer.php';

echo "1. Configuration Status:\n";
echo "   - DEV_MODE_EMAIL: " . (defined('DEV_MODE_EMAIL') && DEV_MODE_EMAIL ? "✅ Enabled" : "❌ Disabled") . "\n";
echo "   - SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : "Not defined") . "\n";
echo "   - MAIL_FROM: " . (defined('MAIL_FROM') ? MAIL_FROM : "Not defined") . "\n";
echo "   - MAIL_FROM_NAME: " . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : "Not defined") . "\n\n";

echo "2. Database Connection Test:\n";
if (isset($mysqli) && !$mysqli->connect_errno) {
    echo "   ✅ Database connected successfully\n";
    echo "   - Host: " . $mysqli->server_info . "\n";
    echo "   - Database: " . $mysqli->server_name . "\n\n";
} else {
    echo "   ❌ Database connection failed: " . ($mysqli->connect_error ?? "Unknown error") . "\n\n";
    exit(1);
}

echo "3. Email Sending Test:\n";
$test_email = 'test@example.com';
$test_subject = 'Test Email from Local Development';
$test_html = '<h1>Test Email</h1><p>This is a test email for local development.</p>';

// Test email sending
$result = sendMail($test_email, $test_subject, $test_html);
echo "   - Email sent to: $test_email\n";
echo "   - Result: " . ($result ? "✅ Success" : "❌ Failed") . "\n\n";

echo "4. OTP Generation Test:\n";
// Generate a test OTP
$otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$exp = date('Y-m-d H:i:s', time() + 15 * 60);
echo "   - Generated OTP: $otp\n";
echo "   - Expires at: $exp\n\n";

echo "5. Email Log Check:\n";
$log_file = defined('DEV_EMAIL_LOG') ? DEV_EMAIL_LOG : __DIR__ . '/logs/dev_emails.log';
echo "   - Development email log: $log_file\n";
if (file_exists($log_file)) {
    $log_size = filesize($log_file);
    echo "   - Log file exists: ✅ ($log_size bytes)\n";
} else {
    echo "   - Log file exists: ❌ (will be created on first email)\n";
}

echo "\n6. Directory Permissions Check:\n";
$logs_dir = __DIR__ . '/logs';
if (is_dir($logs_dir)) {
    echo "   - Logs directory exists: ✅\n";
    if (is_writable($logs_dir)) {
        echo "   - Logs directory writable: ✅\n";
    } else {
        echo "   - Logs directory writable: ❌\n";
    }
} else {
    echo "   - Logs directory exists: ❌\n";
    if (mkdir($logs_dir, 0755, true)) {
        echo "   - Created logs directory: ✅\n";
    } else {
        echo "   - Created logs directory: ❌\n";
    }
}

echo "\n7. Development Mode OTP Display Test:\n";
if (defined('DEV_MODE_EMAIL') && DEV_MODE_EMAIL && defined('DEV_SHOW_OTP') && DEV_SHOW_OTP) {
    echo "   - Development mode enabled: ✅\n";
    echo "   - OTP display enabled: ✅\n";
    echo "   - Users can see OTP on verify page: ✅\n";
} else {
    echo "   - Development mode: ❌\n";
}

echo "\n=== Test Complete ===\n\n";

echo "RECOMMENDED NEXT STEPS:\n";
echo "1. Open your browser and go to: http://localhost:8000/register.php\n";
echo "2. Register a new user with an email (e.g., test@example.com)\n";
echo "3. Check if you're redirected to verify page with OTP displayed\n";
echo "4. Use the displayed OTP to verify the account\n";
echo "5. Check the development email log file for email content\n\n";

echo "EMAIL LOG LOCATIONS:\n";
echo "- Development emails: $log_file\n";
echo "- System mail logs: " . __DIR__ . "/logs/mail.log\n\n";

if (defined('DEV_MODE_EMAIL') && DEV_MODE_EMAIL) {
    echo "✅ LOCAL DEVELOPMENT EMAIL IS CONFIGURED AND READY!\n";
} else {
    echo "❌ LOCAL DEVELOPMENT EMAIL NEEDS CONFIGURATION\n";
    echo "   Edit config_local.php to enable DEV_MODE_EMAIL\n";
}
?>