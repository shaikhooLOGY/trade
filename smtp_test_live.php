<?php
/**
 * smtp_test_live.php - Live Server SMTP Test
 * Upload this to your live server and access via browser
 * This will test your email sending functionality
 */

echo "<h2>Live Server SMTP Test</h2>";

echo "<h3>1. Configuration Check</h3>";
try {
    require_once __DIR__ . '/includes/env.php';
    require_once __DIR__ . '/config.php';
    echo "<p style='color: green;'>✅ Configuration files loaded</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Configuration loading failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Fix:</strong> Check that includes/env.php and config.php exist and are readable</p>";
    exit;
}

echo "<h4>Configuration Details:</h4>";
echo "<ul>";
echo "<li>Environment: " . (defined('APP_ENV') ? APP_ENV : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "<li>SMTP Host: " . (defined('SMTP_HOST') ? '<span style="color: green;">' . SMTP_HOST . '</span>' : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "<li>SMTP Port: " . (defined('SMTP_PORT') ? SMTP_PORT : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "<li>SMTP User: " . (defined('SMTP_USER') ? '<span style="color: green;">' . SMTP_USER . '</span>' : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "<li>SMTP Secure: " . (defined('SMTP_SECURE') ? SMTP_SECURE : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "<li>Mail From: " . (defined('MAIL_FROM') ? MAIL_FROM : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "<li>Mail From Name: " . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '<span style="color: red;">NOT DEFINED</span>') . "</li>";
echo "</ul>";

if (!defined('SMTP_HOST') || !SMTP_HOST) {
    echo "<p style='color: red;'>❌ SMTP IS NOT CONFIGURED</p>";
    echo "<p><strong>Fix:</strong> Check your .env file and ensure SMTP_HOST, SMTP_USER, SMTP_PASS are defined</p>";
    exit;
}

echo "<hr>";

echo "<h3>2. PHPMailer Check</h3>";
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "<p style='color: green;'>✅ PHPMailer loaded successfully</p>";
} else {
    echo "<p style='color: red;'>❌ PHPMailer not found</p>";
    echo "<p><strong>Solutions:</strong></p>";
    echo "<ul>";
    echo "<li>Check if composer.json includes PHPMailer dependency</li>";
    echo "<li>Run <code>composer install</code> on your server</li>";
    echo "<li>Ensure vendor/autoload.php exists</li>";
    echo "<li>Manually upload PHPMailer library to phpmailer/ directory</li>";
    echo "</ul>";
    
    // Check for manual PHPMailer installation
    $phpmailer_path = __DIR__ . '/phpmailer/src/PHPMailer.php';
    if (file_exists($phpmailer_path)) {
        echo "<p style='color: orange;'>⚠️ Manual PHPMailer found. Check mailer.php includes.</p>";
    }
    exit;
}

echo "<hr>";

echo "<h3>3. SMTP Connection Test</h3>";
try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 10;
    
    echo "<p>Testing SMTP connection to <strong>" . SMTP_HOST . ":" . SMTP_PORT . "</strong></p>";
    echo "<p>Authentication user: <strong>" . SMTP_USER . "</strong></p>";
    
    $mail->smtpConnect();
    echo "<p style='color: green;'>✅ SMTP connection successful</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ SMTP connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    
    echo "<h4>Common SMTP Issues and Solutions:</h4>";
    echo "<ul>";
    echo "<li><strong>Authentication failed:</strong> Check SMTP_USER and SMTP_PASS credentials</li>";
    echo "<li><strong>Connection timeout:</strong> Try different ports (587, 465, 25)</li>";
    echo "<li><strong>Host not found:</strong> Verify SMTP_HOST is correct for your hosting provider</li>";
    echo "<li><strong>Security issues:</strong> Try different encryption methods (tls, ssl)</li>";
    echo "</ul>";
}

echo "<hr>";

echo "<h3>4. Email Sending Test</h3>";

if (function_exists('sendMail')) {
    $test_recipient = 'test@example.com';
    $test_subject = 'Live Server Email Test - ' . date('Y-m-d H:i:s');
    $test_html = '<h1>Email Test Successful</h1><p>This is a test email from your live server at ' . date('Y-m-d H:i:s') . '.</p><p>If you receive this, your email system is working!</p>';
    $test_text = "Email Test Successful\n\nThis is a test email from your live server at " . date('Y-m-d H:i:s') . ".\nIf you receive this, your email system is working!";
    
    echo "<p>Attempting to send test email to: <strong>{$test_recipient}</strong></p>";
    echo "<p>Subject: <strong>{$test_subject}</strong></p>";
    
    $result = sendMail($test_recipient, $test_subject, $test_html, $test_text, true);
    
    if ($result) {
        echo "<p style='color: green;'>✅ EMAIL SENT SUCCESSFULLY</p>";
        echo "<p>Check your email logs and the recipient inbox.</p>";
    } else {
        echo "<p style='color: red;'>❌ EMAIL SENDING FAILED</p>";
        echo "<p>Check the mail logs for error details.</p>";
    }
} else {
    echo "<p style='color: red;'>❌ sendMail function not found</p>";
    echo "<p><strong>Fix:</strong> Ensure mailer.php is included and the sendMail function exists</p>";
}

echo "<hr>";

echo "<h3>5. Log File Check</h3>";
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
    echo "<p style='color: orange;'>⚠️ Created logs directory</p>";
}

$log_files = [
    'mail.log' => 'System email logs',
    'email_deliveries_' . date('Y-m-d') . '.log' => 'Daily delivery report',
    'email_failures_' . date('Y-m-d') . '.log' => 'Failed delivery report'
];

foreach ($log_files as $log_file => $description) {
    $log_path = $log_dir . '/' . $log_file;
    if (file_exists($log_path)) {
        $size = filesize($log_path);
        echo "<p style='color: green;'>✅ {$description} exists ({$size} bytes)</p>";
        
        // Show last few lines
        if ($size > 0) {
            echo "<details><summary>Show last log entries</summary>";
            echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 200px; overflow: auto;'>";
            $lines = file($log_path);
            $recent_lines = array_slice($lines, -10);
            foreach ($recent_lines as $line) {
                echo htmlspecialchars($line);
            }
            echo "</pre></details>";
        }
    } else {
        echo "<p>{$description}: <span style='color: orange;'>⚠️ Not created yet</span></p>";
    }
}

echo "<hr>";

echo "<h3>6. Alternative SMTP Test</h3>";
echo "<p>If the main SMTP test failed, try these alternative Hostinger SMTP settings:</p>";

$alternative_smtps = [
    [
        'name' => 'Hostinger Alternative 1 (mx1)',
        'host' => 'mx1.hostinger.com',
        'port' => '587',
        'secure' => 'tls'
    ],
    [
        'name' => 'Hostinger Alternative 2 (mx2)',
        'host' => 'mx2.hostinger.com',
        'port' => '587',
        'secure' => 'tls'
    ],
    [
        'name' => 'Hostinger SSL',
        'host' => 'smtp.hostinger.com',
        'port' => '465',
        'secure' => 'ssl'
    ]
];

foreach ($alternative_smtps as $alt) {
    echo "<details><summary>Test {$alt['name']}</summary>";
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $alt['host'];
        $mail->Port = $alt['port'];
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = $alt['secure'];
        $mail->Timeout = 5;
        
        $mail->smtpConnect();
        echo "<p style='color: green;'>✅ {$alt['name']} connection successful</p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ {$alt['name']} failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo "</details>";
}

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>If SMTP connection works, check if emails are being received</li>";
echo "<li>If emails are not received, check spam folders and email server logs</li>";
echo "<li>If all SMTP tests fail, consider using PHP mail() as a fallback</li>";
echo "<li>Monitor the log files for email delivery status</li>";
echo "<li>Test the full registration process after email issues are resolved</li>";
echo "</ol>";
?>