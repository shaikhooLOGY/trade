<?php
// competition/resend_verification.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';

// helper
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $err = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } else {
        // find user by email
        $stmt = $mysqli->prepare("SELECT id, email_verified FROM users WHERE email = ?");
        if (!$stmt) {
            $err = "Database error.";
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            if (!$res || $res->num_rows === 0) {
                $err = "No account found with that email.";
            } else {
                $row = $res->fetch_assoc();
                if (!empty($row['email_verified'])) {
                    $ok = "Your email is already verified. You may <a href='login.php'>log in</a>.";
                } else {
                    // generate a new token and save it
                    $token = bin2hex(random_bytes(32));
                    $upd = $mysqli->prepare("UPDATE users SET email_token = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param('si', $token, $row['id']);
                        if ($upd->execute()) {
                            // Build verification URL
                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $host = $_SERVER['HTTP_HOST'];
                            $path = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
                            // make link absolute to /competition/verify.php
                            $verifyUrl = $protocol . '://' . $host . '/competition/verify.php?token=' . $token;

                            // Send email (simple PHP mail). Replace or improve with your preferred mailer (SMTP).
                            $subject = "Verify your Shaikhoology email";
                            $message = "Hi,\n\nClick the link below to verify your email address for Shaikhoology:\n\n" . $verifyUrl . "\n\nIf you did not request this, ignore this message.\n\n— Shaikhoology";
                            $headers = "From: no-reply@" . $host . "\r\nReply-To: no-reply@" . $host;

                            // Attempt to send
                            if (@mail($email, $subject, $message, $headers)) {
                                $ok = "Verification email sent. Please check your inbox.";
                            } else {
                                // If mail() fails (common on many shared hosts), show the link so you can test.
                                $ok = "Couldn't send mail from server. Use this verification link (testing): <br><a href='" . h($verifyUrl) . "'>" . h($verifyUrl) . "</a>";
                            }
                        } else {
                            $err = "Failed to update user token.";
                        }
                    } else {
                        $err = "Database error (prepare).";
                    }
                }
            }
        }
    }
}

include __DIR__ . '/header.php';
?>

<div style="max-width:640px;margin:28px auto;background:#fff;padding:26px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.06)">
  <h2 style="margin-top:0">Resend verification email</h2>
  <p style="color:#666">Enter your account email and we'll resend the verification link.</p>

  <?php if ($err): ?>
    <div style="background:#ffecec;border:1px solid #f5c2c2;color:#8b0000;padding:10px;border-radius:6px;margin:12px 0;"><?= h($err) ?></div>
  <?php endif; ?>

  <?php if ($ok): ?>
    <div style="background:#edf7ed;border:1px solid #c8e6c9;color:#1f7a1f;padding:10px;border-radius:6px;margin:12px 0;"><?= $ok ?></div>
  <?php endif; ?>

  <form method="post" style="margin-top:12px">
    <input name="email" type="email" placeholder="your@email.com" required
           style="width:100%;padding:12px;border:1px solid #ddd;border-radius:8px;font-size:15px">
    <div style="margin-top:12px">
      <button type="submit" style="background:#5f27cd;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:700;cursor:pointer">
        Resend verification
      </button>
      <a href="login.php" style="margin-left:12px;color:#666;text-decoration:underline">Back to login</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/footer.php'; ?>