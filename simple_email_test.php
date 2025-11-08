<?php
/**
 * simple_email_test.php - Basic PHP mail() test for live server
 * Upload this to your live server and access via browser
 * This tests if PHP mail() function is available and working
 */

echo "<h2>Simple Email Test - PHP mail() Function</h2>";

echo "<h3>1. Server Information</h3>";
echo "<p><strong>Server:</strong> " . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</p>";
echo "<p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>";
echo "<p><strong>Current Time:</strong> " . date('Y-m-d H:i:s') . "</p>";

echo "<h3>2. PHP mail() Function Check</h3>";
if (function_exists('mail')) {
    echo "<p style='color: green;'>✅ PHP mail() function is available</p>";
} else {
    echo "<p style='color: red;'>❌ PHP mail() function is not available</p>";
    echo "<p><strong>Fix:</strong> Your hosting provider may not have email functionality enabled</p>";
    echo "<p><strong>Alternative:</strong> Use SMTP with PHPMailer instead</p>";
    exit;
}

echo "<h3>3. Mail Configuration Check</h3>";
$mail_settings = [
    'SMTP' => ini_get('SMTP'),
    'smtp_port' => ini_get('smtp_port'),
    'sendmail_from' => ini_get('sendmail_from'),
    'sendmail_path' => ini_get('sendmail_path')
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
foreach ($mail_settings as $setting => $value) {
    echo "<tr><td>{$setting}</td><td>" . htmlspecialchars($value ?: 'Not set') . "</td></tr>";
}
echo "</table>";

echo "<h3>4. Test Email Configuration</h3>";
// Try to load environment variables for mail settings
try {
    require_once __DIR__ . '/includes/env.php';
    
    $test_email = 'test@example.com';
    $test_subject = 'Simple Email Test - ' . date('Y-m-d H:i:s');
    
    // If we have MAIL_FROM defined, use it
    if (defined('MAIL_FROM')) {
        $from_email = MAIL_FROM;
        $from_name = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Test Server';
    } else {
        $from_email = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $from_name = 'Email Test';
    }
    
    echo "<p><strong>From:</strong> " . htmlspecialchars($from_name) . " <" . htmlspecialchars($from_email) . "></p>";
    echo "<p><strong>To:</strong> " . htmlspecialchars($test_email) . "</p>";
    echo "<p><strong>Subject:</strong> " . htmlspecialchars($test_subject) . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Could not load environment settings: " . htmlspecialchars($e->getMessage()) . "</p>";
    // Use default settings
    $test_email = 'test@example.com';
    $test_subject = 'Simple Email Test - ' . date('Y-m-d H:i:s');
    $from_email = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $from_name = 'Email Test';
    
    echo "<p><strong>Using default settings:</strong></p>";
    echo "<p><strong>From:</strong> " . htmlspecialchars($from_name) . " <" . htmlspecialchars($from_email) . "></p>";
    echo "<p><strong>To:</strong> " . htmlspecialchars($test_email) . "</p>";
}

echo "<h3>5. Email Headers</h3>";
$headers = [];
$headers[] = "MIME-Version: 1.0";
$headers[] = "Content-type: text/html; charset=UTF-8";
$headers[] = "From: {$from_name} <{$from_email}>";
$headers[] = "Reply-To: {$from_email}";
$headers[] = "X-Mailer: PHP/" . PHP_VERSION;

$headers_string = implode("\r\n", $headers);
echo "<pre style='background: #f5f5f5; padding: 10px;'>" . htmlspecialchars($headers_string) . "</pre>";

echo "<h3>6. Email Content</h3>";
$html_content = '
<!DOCTYPE html>
<html>
<head>
    <title>Email Test</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background: #007cba; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; }
        .footer { background: #f5f5f5; padding: 10px; text-align: center; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📧 Email Test Successful!</h1>
    </div>
    <div class="content">
        <h2>Your email system is working</h2>
        <p>This email was sent from your live server at <strong>' . date('Y-m-d H:i:s') . '</strong></p>
        
        <h3>System Information:</h3>
        <ul>
            <li><strong>Server:</strong> ' . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . '</li>
            <li><strong>PHP Version:</strong> ' . PHP_VERSION . '</li>
            <li><strong>From:</strong> ' . htmlspecialchars($from_name) . ' <' . htmlspecialchars($from_email) . '></li>
            <li><strong>Test Time:</strong> ' . date('Y-m-d H:i:s T') . '</li>
        </ul>
        
        <p><strong>Next Steps:</strong></p>
        <ol>
            <li>Check your email inbox (including spam folder)</li>
            <li>If you receive this email, PHP mail() is working</li>
            <li>If you don\'t receive it, the issue may be with your hosting provider\'s email service</li>
            <li>Consider using SMTP with PHPMailer for better reliability</li>
        </ol>
    </div>
    <div class="footer">
        <p>Email test completed successfully! 🟢</p>
        <p>This email was generated by the simple_email_test.php diagnostic script</p>
    </div>
</body>
</html>';

echo "<p>Email will be sent with HTML content containing system information</p>";

echo "<h3>7. Attempting to Send Email</h3>";
echo "<p>Calling PHP mail() function...</p>";

$email_sent = @mail($test_email, $test_subject, $html_content, $headers_string);

if ($email_sent) {
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>✅ EMAIL SENT SUCCESSFULLY!</h3>";
    echo "<p>Your PHP mail() function is working correctly.</p>";
    echo "<p><strong>Please check your email inbox and spam folder for the test message.</strong></p>";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>❌ EMAIL SENDING FAILED</h3>";
    echo "<p>Your PHP mail() function is available but the email was not sent successfully.</p>";
    echo "<p><strong>Possible Causes:</strong></p>";
    echo "<ul>";
    echo "<li>Your hosting provider has disabled email sending</li>";
    echo "<li>The email was rejected by the receiving server</li>";
    echo "<li>Server configuration issues</li>";
    echo "<li>Rate limiting by your hosting provider</li>";
    echo "</ul>";
    echo "<p><strong>Recommendations:</strong></p>";
    echo "<ul>";
    echo "<li>Contact your hosting provider about email sending</li>";
    echo "<li>Use SMTP with PHPMailer for better reliability</li>";
    echo "<li>Check your hosting provider's email sending limits</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<h3>8. Log File Check</h3>";
$log_files = [
    __DIR__ . '/logs/mail.log' => 'Mail system log',
    '/var/log/apache2/error.log' => 'Apache error log',
    '/var/log/nginx/error.log' => 'Nginx error log',
    ini_get('error_log') => 'PHP error log'
];

foreach ($log_files as $log_path => $description) {
    if ($log_path && file_exists($log_path) && is_readable($log_path)) {
        echo "<p style='color: green;'>✅ {$description} is accessible</p>";
        
        // Show last few lines if it's not too large
        $log_content = @file_get_contents($log_path);
        if ($log_content !== false && strlen($log_content) < 10000) {
            $lines = explode("\n", $log_content);
            $recent_lines = array_slice($lines, -5);
            echo "<details><summary>Show recent log entries</summary>";
            echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 200px; overflow: auto;'>";
            foreach ($recent_lines as $line) {
                if (trim($line)) {
                    echo htmlspecialchars($line) . "\n";
                }
            }
            echo "</pre></details>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ {$description} not accessible</p>";
    }
}

echo "<h3>9. Alternative Test Methods</h3>";
echo "<p>If this test fails, try these alternatives:</p>";
echo "<ol>";
echo "<li><strong>SMTP Test:</strong> Use <code>smtp_test_live.php</code> to test SMTP email sending</li>";
echo "<li><strong>Contact Hosting:</strong> Many providers require enabling email or have specific requirements</li>";
echo "<li><strong>Third-party Services:</strong> Consider using services like SendGrid, Mailgun, or AWS SES</li>";
echo "<li><strong>Email Queue:</strong> Some hosts require using their email queue system instead of direct mail()</li>";
echo "</ol>";

echo "<h3>10. Summary</h3>";
if ($email_sent) {
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px;'>";
    echo "<h4>🎉 SUCCESS: Your email system is working!</h4>";
    echo "<p>PHP mail() function is available and successfully sent an email.</p>";
    echo "<p><strong>Next steps:</strong> Test the full registration process to see if OTP emails work.</p>";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px;'>";
    echo "<h4>⚠️ WARNING: Email sending needs attention</h4>";
    echo "<p>PHP mail() function exists but emails are not being sent successfully.</p>";
    echo "<p><strong>Immediate actions:</strong></p>";
    echo "<ul>";
    echo "<li>Check with your hosting provider about email sending policies</li>";
    echo "<li>Try SMTP email sending with <code>smtp_test_live.php</code></li>";
    echo "<li>Consider using a third-party email service</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<hr>";
echo "<p><strong>Test completed at:</strong> " . date('Y-m-d H:i:s T') . "</p>";
?>