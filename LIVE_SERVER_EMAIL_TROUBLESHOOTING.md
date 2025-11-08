# Live Server Email Troubleshooting Guide

## PROBLEM SUMMARY
- Files 1-8 uploaded to live server
- OTP working on local server ✅
- OTP NOT working on live server ❌
- User expecting email delivery on live server

## ROOT CAUSE ANALYSIS

Based on the configuration review, the issue is likely in one of these areas:

1. **Configuration Loading Issue**: The `.env` file or `includes/env.php` may not be loading properly on the live server
2. **SMTP Credentials Problem**: Hostinger SMTP settings may differ from local configuration
3. **Environment Detection**: The live server may not be detecting the correct environment
4. **PHPMailer Missing**: PHPMailer library may not be installed on live server
5. **File Permissions**: `.env` file may not be readable on live server

---

## A) CONFIGURATION VERIFICATION CHECKLIST

### Step 1: Verify .env File Loading
Create a diagnostic file `config_check.php` to run on your live server:

```php
<?php
echo "=== Live Server Configuration Check ===\n\n";

// Check if .env file exists and is readable
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    echo "✅ .env file exists\n";
    if (is_readable($envFile)) {
        echo "✅ .env file is readable\n";
        echo "✅ File size: " . filesize($envFile) . " bytes\n";
    } else {
        echo "❌ .env file exists but is not readable\n";
        echo "   Fix: Set file permissions to 644: chmod 644 .env\n";
    }
} else {
    echo "❌ .env file does not exist\n";
    echo "   Fix: Upload .env file to the same directory as includes/env.php\n";
}

// Check environment loading
require_once __DIR__ . '/includes/env.php';

echo "\n=== Environment Variables ===\n";
echo "APP_ENV: " . (defined('APP_ENV') ? APP_ENV : 'NOT DEFINED') . "\n";
echo "DB_HOST: " . (isset($GLOBALS['DB_HOST']) ? $GLOBALS['DB_HOST'] : 'NOT LOADED') . "\n";
echo "SMTP_HOST: " . (isset($GLOBALS['SMTP_HOST']) ? $GLOBALS['SMTP_HOST'] : 'NOT LOADED') . "\n";
echo "SMTP_USER: " . (isset($GLOBALS['SMTP_USER']) ? $GLOBALS['SMTP_USER'] : 'NOT LOADED') . "\n";

echo "\n=== Configuration Loading ===\n";
if (defined('SMTP_HOST')) {
    echo "✅ SMTP constants are defined\n";
    echo "   Host: " . SMTP_HOST . "\n";
    echo "   Port: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT DEFINED') . "\n";
    echo "   User: " . (defined('SMTP_USER') ? SMTP_USER : 'NOT DEFINED') . "\n";
} else {
    echo "❌ SMTP constants are not defined\n";
    echo "   This means .env file is not loading properly\n";
}
?>
```

### Step 2: Database Connection Test
```php
<?php
// db_check.php
require_once __DIR__ . '/includes/env.php';

echo "=== Database Connection Test ===\n\n";

if (isset($GLOBALS['DB_HOST'])) {
    echo "Database host: " . $GLOBALS['DB_HOST'] . "\n";
    echo "Database user: " . $GLOBALS['DB_USER'] . "\n";
    echo "Database name: " . $GLOBALS['DB_NAME'] . "\n\n";
    
    $mysqli = @new mysqli($GLOBALS['DB_HOST'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $GLOBALS['DB_NAME']);
    if ($mysqli->connect_errno) {
        echo "❌ Database connection failed: " . $mysqli->connect_error . "\n";
    } else {
        echo "✅ Database connected successfully\n";
        $mysqli->close();
    }
} else {
    echo "❌ Database credentials not loaded from .env file\n";
}
?>
```

### Step 3: SMTP Settings Verification
Check if your live server can connect to Hostinger SMTP:

```bash
# Test SMTP connection from command line on live server
telnet smtp.hostinger.com 587
# Or use nc (netcat)
nc -zv smtp.hostinger.com 587
```

---

## B) SMTP TESTING SCRIPT FOR LIVE SERVER

Create `smtp_test_live.php` for your live server:

```php
<?php
/**
 * smtp_test_live.php - Live Server SMTP Test
 * Upload this to your live server and run it
 */

echo "=== Live Server SMTP Test ===\n\n";

// Load configuration
require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/config.php';

echo "1. Configuration Check:\n";
echo "   - Environment: " . (defined('APP_ENV') ? APP_ENV : 'unknown') . "\n";
echo "   - SMTP Host: " . (defined('SMTP_HOST') ? SMTP_HOST : 'NOT DEFINED') . "\n";
echo "   - SMTP Port: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT DEFINED') . "\n";
echo "   - SMTP User: " . (defined('SMTP_USER') ? SMTP_USER : 'NOT DEFINED') . "\n";
echo "   - Mail From: " . (defined('MAIL_FROM') ? MAIL_FROM : 'NOT DEFINED') . "\n\n";

if (!defined('SMTP_HOST') || !SMTP_HOST) {
    echo "❌ SMTP NOT CONFIGURED\n";
    echo "   Check your .env file and includes/env.php\n\n";
    exit(1);
}

echo "2. PHPMailer Check:\n";
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "   ✅ PHPMailer loaded\n\n";
} else {
    echo "   ❌ PHPMailer not found\n";
    echo "   Check if composer.json includes PHPMailer\n";
    echo "   Run: composer require phpmailer/phpmailer\n\n";
    exit(1);
}

echo "3. SMTP Connection Test:\n";
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
    
    echo "   - Connecting to " . SMTP_HOST . ":" . SMTP_PORT . "\n";
    echo "   - Testing authentication...\n";
    
    $mail->smtpConnect();
    echo "   ✅ SMTP connection successful\n\n";
    
} catch (Exception $e) {
    echo "   ❌ SMTP connection failed: " . $e->getMessage() . "\n\n";
}

echo "4. Email Test:\n";
$test_result = sendMail('test@example.com', 'Live Server Email Test', '<h1>Test</h1><p>This is a test email from your live server.</p>');
echo "   - Test email result: " . ($test_result ? "SUCCESS" : "FAILED") . "\n\n";

echo "=== Test Complete ===\n";
?>
```

---

## C) LIVE SERVER ENVIRONMENT DIAGNOSTIC

Create `env_diagnostic.php`:

```php
<?php
/**
 * env_diagnostic.php - Complete environment diagnostic for live server
 */

echo "=== Live Server Environment Diagnostic ===\n\n";

echo "1. PHP Information:\n";
echo "   - PHP Version: " . PHP_VERSION . "\n";
echo "   - Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "   - Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
echo "   - Current Directory: " . __DIR__ . "\n\n";

echo "2. File System Check:\n";
$required_files = ['.env', 'includes/env.php', 'config.php', 'mailer.php'];
foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "   ✅ {$file} exists\n";
    } else {
        echo "   ❌ {$file} missing\n";
    }
}

echo "\n3. .env File Analysis:\n";
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env_vars = [];
    foreach ($lines as $line) {
        if (!preg_match('/^\s*[#;]/', $line) && strpos($line, '=') !== false) {
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $env_vars[$key] = $value;
        }
    }
    
    echo "   - Total lines: " . count($lines) . "\n";
    echo "   - Valid key=value pairs: " . count($env_vars) . "\n";
    echo "   - APP_ENV: " . ($env_vars['APP_ENV'] ?? 'NOT SET') . "\n";
    echo "   - SMTP_HOST: " . ($env_vars['SMTP_HOST'] ?? 'NOT SET') . "\n";
    echo "   - DB_HOST: " . ($env_vars['DB_HOST'] ?? 'NOT SET') . "\n";
} else {
    echo "   ❌ .env file not found\n";
}

echo "\n4. Include Path Test:\n";
try {
    require_once __DIR__ . '/includes/env.php';
    echo "   ✅ includes/env.php loaded successfully\n";
    echo "   - APP_ENV defined: " . (defined('APP_ENV') ? 'YES' : 'NO') . "\n";
} catch (Exception $e) {
    echo "   ❌ includes/env.php failed to load: " . $e->getMessage() . "\n";
}

echo "\n5. Composer/PHPMailer Check:\n";
$autoload_file = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_file)) {
    echo "   ✅ Composer autoload exists\n";
    require_once $autoload_file;
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        echo "   ✅ PHPMailer loaded from composer\n";
    } else {
        echo "   ❌ PHPMailer class not found\n";
    }
} else {
    echo "   ❌ Composer autoload not found\n";
    echo "   - Check if vendor/ directory exists\n";
    echo "   - Run: composer install\n";
}

echo "\n6. Log Directory Check:\n";
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
if (is_dir($logDir) && is_writable($logDir)) {
    echo "   ✅ Logs directory is writable\n";
} else {
    echo "   ❌ Logs directory is not writable\n";
    echo "   - Fix: chmod 755 logs/\n";
}

echo "\n=== Diagnostic Complete ===\n";
?>
```

---

## D) ALTERNATIVE SMTP SOLUTIONS

### Option 1: Hostinger Webmail SMTP
If your current SMTP settings are failing, try using Hostinger's webmail:

```php
// Add to your .env file
SMTP_HOST=mx1.hostinger.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=help@shaikhoology.com
SMTP_PASS=TC@Shaikhoology25
```

### Option 2: Hostinger SMTP Alternative Ports
Try different port combinations:

```php
// Port 465 with SSL
SMTP_PORT=465
SMTP_SECURE=ssl

// Port 25 with STARTTLS
SMTP_PORT=25
SMTP_SECURE=tls
```

### Option 3: PHP Native Mail as Fallback
Modify your `mailer.php` to use PHP mail() as backup:

```php
// Add this function to mailer.php
function sendMailFallback($to, $subject, $htmlBody, $textBody = '') {
    $fromEmail = defined('MAIL_FROM') ? MAIL_FROM : 'help@shaikhoology.com';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Shaikhoology';
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    
    return @mail($to, $subject, $htmlBody, $headers);
}
```

---

## E) DEBUGGING STEPS

### Step 1: Enable Debug Mode
Add this to your live server's `config.php`:

```php
// Enable debug logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
ini_set('display_errors', 0);
error_reporting(E_ALL);
```

### Step 2: Create Simple Email Test
Create `simple_email_test.php`:

```php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

echo "Testing basic PHP mail() function:\n";

$to = 'test@example.com';
$subject = 'Test from Live Server';
$message = 'This is a test email from the live server.';

if (mail($to, $subject, $message)) {
    echo "✅ mail() function works\n";
} else {
    echo "❌ mail() function failed\n";
    echo "Server may not have mail() enabled or properly configured.\n";
}
?>
```

### Step 3: Check Error Logs
Monitor these log files on your live server:
- `/logs/mail.log` - Email delivery logs
- `/logs/php_errors.log` - PHP error logs
- Server error logs (usually in cPanel or hosting dashboard)

---

## F) HOSTINGER-SPECIFIC SOLUTIONS

### Hostinger SMTP Requirements
1. **Authentication**: Must use full email address as username
2. **Security**: Always use STARTTLS or SSL
3. **Ports**: Try 587 (STARTTLS) or 465 (SSL)
4. **Password**: Use the email account password, not hosting password

### Hostinger Email Settings
For Hostinger, use these SMTP settings:
- **Server**: `smtp.hostinger.com`
- **Port**: `587` (STARTTLS) or `465` (SSL)
- **Encryption**: `tls` or `ssl`
- **Authentication**: Required

---

## G) QUICK FIXES TO TRY IMMEDIATELY

1. **Check .env file permissions**:
   ```bash
   chmod 644 .env
   ```

2. **Verify file structure**:
   - Ensure `.env` is in the same directory as `config.php`
   - Ensure `includes/env.php` exists and is readable

3. **Test with different SMTP ports**:
   - Try port 587 with STARTTLS
   - Try port 465 with SSL
   - Try port 25 with STARTTLS

4. **Use PHP mail() as backup**:
   - Modify `mailer.php` to fall back to `mail()` function
   - This may work even if SMTP fails

5. **Check Hostinger email configuration**:
   - Ensure the email account `help@shaikhoology.com` exists
   - Verify the password is correct
   - Check if email quota is not exceeded

---

## H) MONITORING AND VERIFICATION

### Log File Locations
- `logs/mail.log` - All email sending attempts
- `logs/email_deliveries_YYYY-MM-DD.log` - Daily delivery reports
- `logs/email_failures_YYYY-MM-DD.log` - Failed delivery reports

### Test Email Delivery
Use this URL structure to test:
```
https://tradersclub.shaikhoology.com/register.php
```

### Check Registration Process
1. Register a new user
2. Check if OTP email is sent
3. Monitor log files for errors
4. Verify database OTP storage

---

## I) EMERGENCY CONTACT INFO

If emails are still not working after all fixes:
1. Contact Hostinger support for SMTP issues
2. Check hosting account email settings
3. Verify domain DNS settings for email
4. Consider using a third-party email service (SendGrid, Mailgun, etc.)

---

**PRIORITY**: Get email working on live server immediately so user can complete registration process.

**NEXT STEPS**:
1. Upload `config_check.php` to live server
2. Run the diagnostic and check results
3. Apply fixes based on diagnostic findings
4. Test with `smtp_test_live.php`
5. Monitor logs for email delivery status