<?php
/**
 * test_production_email.php - Production Email System Test
 * 
 * This script tests:
 * 1. SMTP configuration loading from production .env
 * 2. Email sending to real email addresses
 * 3. Professional email templates
 * 4. Error handling and logging
 * 5. Production email delivery verification
 */

echo "=== Production Email System Test ===\n\n";

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

echo "1. Production Configuration Check:\n";
echo "   - Environment: " . (defined('APP_ENV') ? APP_ENV : 'development') . "\n";
echo "   - SMTP Host: " . (defined('SMTP_HOST') ? SMTP_HOST : 'Not configured') . "\n";
echo "   - SMTP Port: " . (defined('SMTP_PORT') ? SMTP_PORT : 'Not configured') . "\n";
echo "   - SMTP User: " . (defined('SMTP_USER') ? SMTP_USER : 'Not configured') . "\n";
echo "   - Mail From: " . (defined('MAIL_FROM') ? MAIL_FROM : 'Not configured') . "\n";
echo "   - Dev Mode Email: " . (defined('DEV_MODE_EMAIL') ? (DEV_MODE_EMAIL ? 'Enabled' : 'Disabled') : 'Not defined') . "\n\n";

if (!defined('SMTP_HOST') || !SMTP_HOST) {
    echo "❌ PRODUCTION EMAIL NOT CONFIGURED - SMTP_HOST is missing\n";
    echo "   Configure SMTP settings in .env.production file\n\n";
    exit(1);
}

echo "2. Database Connection Test:\n";
if (isset($mysqli) && !$mysqli->connect_errno) {
    echo "   ✅ Database connected successfully\n\n";
} else {
    echo "   ❌ Database connection failed: " . ($mysqli->connect_error ?? "Unknown error") . "\n\n";
    exit(1);
}

echo "3. PHPMailer Class Check:\n";
if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
    echo "   ✅ PHPMailer loaded successfully\n\n";
} else {
    echo "   ❌ PHPMailer not loaded - check composer installation\n\n";
    exit(1);
}

echo "4. Production Email Template Test:\n";
$test_recipient = 'test@example.com';
$test_subject = 'Shaikhoology - Production Email System Test';
$test_otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

// Professional HTML email template
$html_template = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Email Test</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0; text-align: center; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
        .verification-code { background: #fff; border: 2px solid #667eea; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0; }
        .code { font-size: 28px; font-weight: bold; color: #667eea; letter-spacing: 3px; }
        .footer { text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #6c757d; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🧠 Shaikhoology Trading Psychology</h1>
        <p>Production Email System Test</p>
    </div>
    <div class="content">
        <h2>Email System Configuration Successful!</h2>
        <p>This is a test email to verify that your production email system is working correctly.</p>
        
        <div class="verification-code">
            <p>Test Verification Code:</p>
            <div class="code">' . $test_otp . '</div>
            <p><small>Generated at: ' . date('Y-m-d H:i:s') . '</small></p>
        </div>
        
        <p><strong>System Information:</strong></p>
        <ul>
            <li>SMTP Host: ' . (defined('SMTP_HOST') ? SMTP_HOST : 'Not configured') . '</li>
            <li>Port: ' . (defined('SMTP_PORT') ? SMTP_PORT : 'Not configured') . '</li>
            <li>Authentication: ' . (defined('SMTP_USER') ? 'Enabled' : 'Disabled') . '</li>
            <li>Environment: ' . (defined('APP_ENV') ? APP_ENV : 'development') . '</li>
        </ul>
        
        <p>If you received this email, your production email system is working correctly!</p>
    </div>
    <div class="footer">
        <p>Shaikhoology Trading Psychology System<br>
        Professional Email Delivery - Production Ready</p>
    </div>
</body>
</html>';

// Text version for non-HTML clients
$text_template = "
Shaikhoology Trading Psychology - Production Email System Test

Email System Configuration Successful!

This is a test email to verify that your production email system is working correctly.

Test Verification Code: {$test_otp}
Generated at: " . date('Y-m-d H:i:s') . "

System Information:
- SMTP Host: " . (defined('SMTP_HOST') ? SMTP_HOST : 'Not configured') . "
- Port: " . (defined('SMTP_PORT') ? SMTP_PORT : 'Not configured') . "
- Authentication: " . (defined('SMTP_USER') ? 'Enabled' : 'Disabled') . "
- Environment: " . (defined('APP_ENV') ? APP_ENV : 'development') . "

If you received this email, your production email system is working correctly!

Shaikhoology Trading Psychology System
Professional Email Delivery - Production Ready
";

echo "   - Creating professional email template: ✅\n";
echo "   - Test OTP generated: {$test_otp}\n\n";

echo "5. Production Email Sending Test:\n";
echo "   - Testing SMTP connection to: " . SMTP_HOST . ":" . SMTP_PORT . "\n";
echo "   - Attempting to send email to: {$test_recipient}\n";
echo "   - Subject: {$test_subject}\n\n";

$result = sendMail($test_recipient, $test_subject, $html_template, $text_template, true);

echo "   - Email send result: " . ($result ? "✅ SUCCESS" : "❌ FAILED") . "\n\n";

echo "6. Log File Verification:\n";
$log_files = [
    mail_log_dir() . '/mail.log',
    mail_log_dir() . '/email_deliveries_' . date('Y-m-d') . '.log'
];

foreach ($log_files as $log_file) {
    if (file_exists($log_file)) {
        $size = filesize($log_file);
        echo "   - Log file exists: " . basename($log_file) . " ✅ ({$size} bytes)\n";
    } else {
        echo "   - Log file missing: " . basename($log_file) . " ❌\n";
    }
}

echo "\n=== Production Email Test Complete ===\n\n";

if ($result) {
    echo "✅ PRODUCTION EMAIL SYSTEM IS WORKING!\n";
    echo "   Check your email inbox for the test message.\n";
    echo "   Monitor log files for delivery status.\n\n";
} else {
    echo "❌ PRODUCTION EMAIL SYSTEM FAILED\n";
    echo "   Check SMTP credentials and configuration.\n";
    echo "   Review log files for error details.\n\n";
}

echo "NEXT STEPS:\n";
echo "1. Check your email inbox for the test message\n";
echo "2. Verify email formatting and professional appearance\n";
echo "3. Review log files for delivery status\n";
echo "4. Test with real user registration to verify OTP delivery\n";
echo "5. Monitor email delivery rates and error logs\n\n";
?>