<?php
// mailer.php  — SMTP mail with robust logging
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1) Composer autoload (if present)
$autoload1 = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload1)) require_once $autoload1;

// 2) Manual include (bundled phpmailer/src)
if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
    $base = __DIR__ . '/phpmailer/src';
    if (file_exists($base . '/PHPMailer.php')) {
        require_once $base . '/PHPMailer.php';
        require_once $base . '/SMTP.php';
        require_once $base . '/Exception.php';
    }
}

/* ---------------- Logging helpers ---------------- */
function mail_log_dir(): string {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}
function mail_log(string $line): void {
    $file = mail_log_dir() . '/mail.log';
    $stamp = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$stamp] $line\n", FILE_APPEND);
}
/* -------------------------------------------------- */

/**
 * Send email via SMTP (PHPMailer) with fallback to mail().
 * - Uses SMTP_* and MAIL_FROM constants from config.php
 * - Logs every attempt to /logs/mail.log
 */
function sendMail(string $to, string $subject, string $htmlBody, string $textBody = '', bool $debug=false): bool
{
    $fromEmail = defined('MAIL_FROM') ? MAIL_FROM : (defined('SMTP_USER') ? SMTP_USER : 'no-reply@example.com');
    $fromName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Shaikhoology';

    $hasSMTP = defined('SMTP_HOST') && SMTP_HOST && class_exists('\PHPMailer\PHPMailer\PHPMailer');

    mail_log("BEGIN sendMail -> to={$to}, subj=\"{$subject}\", via=" . ($hasSMTP ? 'SMTP' : 'mail()'));

    if ($hasSMTP) {
        try {
            $mail = new PHPMailer(true);

            // Production email configuration
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->Port       = SMTP_PORT ?: 587;
            
            // Production SMTP settings
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
            $mail->Timeout    = 30; // 30 second timeout
            
            // Production email headers
            $mail->CharSet = 'UTF-8';
            $mail->XMailer = 'Shaikhoology Email System';
            $mail->MessageID = 'shaikhoology-' . time() . '-' . uniqid() . '@tradersclub.shaikhoology.com';
            
            // Email addresses
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($fromEmail, $fromName);
            $mail->Sender = $fromEmail;
            
            // Professional email settings
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody ?: strip_tags($htmlBody);
            
            // Add email headers for proper delivery
            
            // Production logging
            if ($debug) {
                $mail->SMTPDebug = 2; // Enable debug output
                $mail->Debugoutput = function($str, $level) {
                    mail_log("SMTP Debug [L{$level}]: " . trim($str));
                };
            }

            $ok = $mail->send();
            
            // Detailed production logging
            $responseCode = $ok ? 'SUCCESS' : 'ERROR';
            $mailLogMsg = "SMTP send {$responseCode}: to={$to} subject=\"{$subject}\" host=" . SMTP_HOST . " user=" . SMTP_USER . " error=" . $mail->ErrorInfo;
            mail_log($mailLogMsg);
            
            // Log delivery result for production monitoring
            $deliveryLog = [
                'timestamp' => date('Y-m-d H:i:s'),
                'email' => $to,
                'subject' => $subject,
                'status' => $ok ? 'sent' : 'failed',
                'smtp_host' => SMTP_HOST,
                'error' => $ok ? null : $mail->ErrorInfo
            ];
            $deliveryFile = mail_log_dir() . '/email_deliveries_' . date('Y-m-d') . '.log';
            @file_put_contents($deliveryFile, json_encode($deliveryLog) . "\n", FILE_APPEND);
            
            return $ok;
        } catch (Exception $e) {
            $errorMsg = "SMTP send FAILURE: to={$to} error=" . $e->getMessage() . " host=" . SMTP_HOST;
            mail_log($errorMsg);
            
            // Log failure for monitoring
            $failureLog = [
                'timestamp' => date('Y-m-d H:i:s'),
                'email' => $to,
                'subject' => $subject,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'smtp_host' => SMTP_HOST
            ];
            $failureFile = mail_log_dir() . '/email_failures_' . date('Y-m-d') . '.log';
            @file_put_contents($failureFile, json_encode($failureLog) . "\n", FILE_APPEND);
            
            return false; // Don't fall back to mail() in production
        }
    }

    // Development mode - log email to file and handle OTP display
    if (defined('DEV_MODE_EMAIL') && DEV_MODE_EMAIL) {
        $logContent = [
            'timestamp' => date('Y-m-d H:i:s'),
            'to' => $to,
            'subject' => $subject,
            'html' => $htmlBody,
            'status' => 'DEV_MODE'
        ];
        
        $logFile = defined('DEV_EMAIL_LOG') ? DEV_EMAIL_LOG : mail_log_dir() . '/dev_emails.log';
        @file_put_contents($logFile, json_encode($logContent) . "\n" . str_repeat('=', 80) . "\n", FILE_APPEND);
        mail_log("DEV email logged to: {$logFile} -> to={$to}");
        
        // Extract OTP from email if it's a verification email
        if (preg_match('/Your Shaikhoology verification code/i', $subject) && preg_match('/(\d{6})/', $htmlBody, $matches)) {
            $_SESSION['dev_otp'] = $matches[1];
            $_SESSION['dev_email'] = $to;
            mail_log("DEV OTP extracted and stored: " . $matches[1]);
        }
        
        return true; // Always return true in dev mode
    }

    // Fallback: PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";

    $ok = @mail($to, $subject, $htmlBody, $headers);
    mail_log("mail() fallback result: " . ($ok ? 'OK' : 'FALSE') . " to={$to}");
    return $ok;
}