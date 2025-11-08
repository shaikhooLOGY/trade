# Quick Reference: Live Server Email Troubleshooting

## IMMEDIATE ACTION PLAN

### Step 1: Upload Diagnostic Files
Upload these files to your live server:
- `config_check.php`
- `db_check.php` 
- `smtp_test_live.php`
- `env_diagnostic.php`
- `simple_email_test.php`

### Step 2: Run Tests in Order

1. **Configuration Check**: 
   ```
   https://tradersclub.shaikhoology.com/config_check.php
   ```
   - Look for ❌ (red) issues
   - Fix .env file loading problems first

2. **Database Check**:
   ```
   https://tradersclub.shaikhoology.com/db_check.php
   ```
   - Verify database connection
   - Check if required tables exist

3. **Environment Diagnostic**:
   ```
   https://tradersclub.shaikhoology.com/env_diagnostic.php
   ```
   - Complete system overview
   - Check PHP extensions, permissions, etc.

4. **Simple Email Test**:
   ```
   https://tradersclub.shaikhoology.com/simple_email_test.php
   ```
   - Test basic PHP mail() function
   - Check if hosting provider allows email sending

5. **SMTP Test**:
   ```
   https://tradersclub.shaikhoology.com/smtp_test_live.php
   ```
   - Test SMTP connection to Hostinger
   - Try alternative SMTP settings
   - Test actual email sending

### Step 3: Common Issues and Quick Fixes

#### Issue 1: .env file not loading
**Symptom**: SMTP_HOST shows "NOT DEFINED"
**Fix**: 
- Check .env file exists in correct location
- Run: `chmod 644 .env`
- Verify .env file format (no extra spaces)

#### Issue 2: Database connection failed
**Symptom**: ❌ Database connection error
**Fix**:
- Verify database credentials in .env
- Check database user permissions
- Ensure database exists on hosting

#### Issue 3: PHPMailer not found
**Symptom**: ❌ PHPMailer not found
**Fix**:
- Run `composer install` on server
- Or manually upload PHPMailer library
- Check vendor/autoload.php exists

#### Issue 4: SMTP authentication failed
**Symptom**: SMTP connection authentication error
**Fix**:
- Verify SMTP_USER and SMTP_PASS in .env
- Try different Hostinger SMTP servers:
  - `smtp.hostinger.com` (port 587)
  - `mx1.hostinger.com` (port 587)
  - `mx2.hostinger.com` (port 587)
  - Use SSL on port 465

#### Issue 5: PHP mail() not working
**Symptom**: mail() function returns false
**Fix**:
- Contact hosting provider about email sending
- Use SMTP instead of PHP mail()
- Check hosting email policies

### Step 4: Hostinger-Specific SMTP Settings

Try these SMTP configurations in your .env file:

**Option 1 (Recommended)**:
```
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=help@shaikhoology.com
SMTP_PASS=TC@Shaikhoology25
```

**Option 2 (Alternative)**:
```
SMTP_HOST=mx1.hostinger.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=help@shaikhoology.com
SMTP_PASS=TC@Shaikhoology25
```

**Option 3 (SSL)**:
```
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=465
SMTP_SECURE=ssl
SMTP_USER=help@shaikhoology.com
SMTP_PASS=TC@Shaikhoology25
```

### Step 5: Emergency Fallback

If SMTP still doesn't work, modify `mailer.php` to use PHP mail():

```php
// Add this at the top of mailer.php function
function sendMail(string $to, string $subject, string $htmlBody, string $textBody = '', bool $debug=false): bool
{
    // Force PHP mail() as fallback
    $fromEmail = defined('MAIL_FROM') ? MAIL_FROM : 'help@shaikhoology.com';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Shaikhoology';
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    
    $ok = @mail($to, $subject, $htmlBody, $headers);
    mail_log("mail() fallback result: " . ($ok ? 'OK' : 'FALSE') . " to={$to}");
    return $ok;
}
```

### Step 6: Verification

After fixes, test registration:
1. Go to: `https://tradersclub.shaikhoology.com/register.php`
2. Register with a real email address
3. Check if OTP email arrives
4. Check log files in `/logs/` directory

### Step 7: Log Monitoring

Check these log files for errors:
- `/logs/mail.log` - All email attempts
- `/logs/email_deliveries_YYYY-MM-DD.log` - Daily report
- `/logs/email_failures_YYYY-MM-DD.log` - Failures
- Server error logs in cPanel/hosting dashboard

## SUCCESS CRITERIA

✅ **Email Working** when:
- config_check.php shows all green checkmarks
- smtp_test_live.php shows "EMAIL SENT SUCCESSFULLY"
- Registration emails arrive in inbox
- Log files show successful deliveries

## GET HELP

If troubleshooting doesn't work:
1. Contact Hostinger support about SMTP settings
2. Check if email account `help@shaikhoology.com` exists
3. Verify hosting account email sending limits
4. Consider using third-party email service (SendGrid, Mailgun)

## FILES CREATED

- `LIVE_SERVER_EMAIL_TROUBLESHOOTING.md` - Complete guide
- `config_check.php` - Configuration verification
- `db_check.php` - Database connectivity test
- `smtp_test_live.php` - SMTP testing script
- `env_diagnostic.php` - Environment diagnostics
- `simple_email_test.php` - Basic mail() test
- `QUICK_REFERENCE.md` - This quick reference

**Upload all files to your live server and run the tests in order!**