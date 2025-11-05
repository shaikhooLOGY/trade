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

            // collect SMTP debug output in buffer (optional)
            $smtpBuffer = '';
            if ($debug) {
                $mail->SMTPDebug = 2; // 0=off, 2=client+server
                $mail->Debugoutput = function($str, $level) use (&$smtpBuffer) {
                    $smtpBuffer .= "[L{$level}] " . $str . "\n";
                };
            }

            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->Port       = SMTP_PORT ?: 587;
            
            // FIXED: Proper SMTP encryption handling
            if (defined('SMTP_SECURE')) {
                $secure = strtolower(SMTP_SECURE);
                if ($secure === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($secure === 'tls' || $secure === 'starttls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // default
                }
            } else {
                // Auto-detect based on port
                if ($mail->Port == 465) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($fromEmail, $fromName);
            $mail->Sender = $fromEmail;

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody ?: strip_tags($htmlBody);

            $ok = $mail->send();
            mail_log("SMTP send result: " . ($ok ? 'OK' : 'FALSE') . " to={$to} host=" . SMTP_HOST . " user=" . SMTP_USER);
            if ($debug && $smtpBuffer) {
                // store SMTP conversation in its own file (rotates daily)
                $dbgFile = mail_log_dir() . '/smtp_' . date('Ymd_His') . '.log';
                @file_put_contents($dbgFile, $smtpBuffer);
                mail_log("SMTP debug saved -> {$dbgFile}");
            }
            return $ok;
        } catch (Exception $e) {
            mail_log("SMTP exception: " . $e->getMessage());
            // fall through to mail()
        }
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