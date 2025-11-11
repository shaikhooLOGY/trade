<?php
// verify_profile.php — Email OTP verification (no public nav; shows only a small Logout link)
// FULL VERSION — copy-paste ready

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';   // $mysqli
require_once __DIR__ . '/mailer.php';   // sendMail($to,$subject,$html,$text='')

// ---------- helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function only_digits($s){ return preg_replace('/\D+/', '', (string)$s); }

// CSRF
if (empty($_SESSION['vp_csrf'])) {
    $_SESSION['vp_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['vp_csrf'];

// ---------- Rate Limiting (OTP Verification Attempts) ----------
$MAX_OTP_ATTEMPTS = 5;
$OTP_WINDOW = 300; // 5 minutes
$current_time = time();

if (!isset($_SESSION['otp_attempts'])) {
    $_SESSION['otp_attempts'] = [];
}

// Clean old attempts
if (!empty($_SESSION['otp_attempts'])) {
    foreach ($_SESSION['otp_attempts'] as $timestamp) {
        if ($current_time - $timestamp > $OTP_WINDOW) {
            unset($_SESSION['otp_attempts']);
        }
    }
}
$otp_rate_limited = false;
if (!empty($_SESSION['otp_attempts']) && count($_SESSION['otp_attempts']) >= $MAX_OTP_ATTEMPTS) {
    $oldest_attempt = min($_SESSION['otp_attempts']);
    $cooldown_remaining = $OTP_WINDOW - ($current_time - $oldest_attempt);
    if ($cooldown_remaining > 0) {
        $otp_rate_limited = true;
        $err_msgs[] = "Too many OTP attempts. Please wait " . ceil($cooldown_remaining/60) . " minutes before trying again.";
    }
}

// Inputs
$email  = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';

// Messages
$ok_msgs  = [];
$err_msgs = [];

// Basic guard: need email
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err_msgs[] = "Bad request. Missing or invalid email.";
}

// Load user
$user = null;
if (!$err_msgs) {
    if ($st = $mysqli->prepare("SELECT id,name,email,status,email_verified,otp_code,otp_expires,verification_attempts,is_admin FROM users WHERE email=? LIMIT 1")) {
        $st->bind_param('s', $email);
        $st->execute();
        $res = $st->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $st->close();
    } else {
        $err_msgs[] = "Database error while loading user.";
    }
}

if (!$err_msgs && !$user) {
    $err_msgs[] = "Account not found. Please register again or check the email.";
}

// If already verified, route based on approval status
if (!$err_msgs && $user && (int)$user['email_verified'] === 1) {
    $status = strtolower((string)$user['status']);
    if (in_array($status, ['active','approved'], true)) {
        header('Location: ' . ((int)$user['is_admin'] ? '/admin/admin_dashboard.php' : '/dashboard.php'));
        exit;
    }
    header('Location: /pending_approval.php');
    exit;
}

// ---------- RESEND ----------
$RESEND_COOLDOWN = 60; // seconds
if (!$err_msgs && $user) {
    $now = time();
    if (!isset($_SESSION['vp_next_resend'])) $_SESSION['vp_next_resend'] = 0;

    $resendRequested =
        ($action === 'resend') ||
        (isset($_POST['resend']) && isset($_POST['csrf']) && hash_equals($csrf, (string)$_POST['csrf']));

    if ($resendRequested) {
        if ($now < (int)$_SESSION['vp_next_resend']) {
            $left = (int)$_SESSION['vp_next_resend'] - $now;
            $err_msgs[] = "Please wait {$left}s before requesting a new code. (Naye code ke liye {$left}s rukiyé.)";
        } else {
            // generate & store code (10 min validity)
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $exp  = date('Y-m-d H:i:s', $now + 10*60);
            $attempts = (int)$user['verification_attempts'] + 1;

            if ($up = $mysqli->prepare("UPDATE users SET otp_code=?, otp_expires=?, verification_attempts=? WHERE id=?")) {
                $up->bind_param('ssii', $code, $exp, $attempts, $user['id']);
                $ok = $up->execute();
                $up->close();

                if ($ok) {
                    // send email
                    $subject = 'Your Shaikhoology verification code';
                    $html = '
<div style="font-family:Inter,Roboto,Arial,sans-serif;line-height:1.45;max-width:560px;margin:0 auto">
  <h2 style="margin:0 0 10px">Email Verification Code</h2>
  <p>Your 6-digit code (valid 10 minutes):</p>
  <p style="font-size:22px;font-weight:800;letter-spacing:4px">'.$code.'</p>
  <p><em>(Yeh code 10 minutes ke liye valid hai.)</em></p>
  <hr style="border:none;border-top:1px solid #eee;margin:18px 0">
  <small style="color:#666">If you didn’t request this, ignore this email.</small>
</div>';
                    $text = "Your 6-digit code (valid 10 minutes): {$code}";
                    $sent = sendMail($user['email'], $subject, $html, $text);

                    if ($sent) {
                        $ok_msgs[] = "We’ve sent a new code. (Naya code bhej diya gaya hai.)";
                        $_SESSION['vp_next_resend'] = $now + $RESEND_COOLDOWN;

                        // refresh user row for latest fields
                        if ($st = $mysqli->prepare("SELECT otp_code, otp_expires, verification_attempts FROM users WHERE id=?")) {
                            $st->bind_param('i', $user['id']);
                            $st->execute();
                            $fresh = $st->get_result()->fetch_assoc();
                            $st->close();
                            if ($fresh) {
                                $user['otp_code'] = $fresh['otp_code'];
                                $user['otp_expires'] = $fresh['otp_expires'];
                                $user['verification_attempts'] = $fresh['verification_attempts'];
                            }
                        }
                    } else {
                        $err_msgs[] = "Could not send email. Please try again later.";
                    }
                } else {
                    $err_msgs[] = "Could not generate a new code. Try again.";
                }
            } else {
                $err_msgs[] = "Database error while generating code.";
            }
        }
    }
}

// ---------- VERIFY ----------
if (!$err_msgs && $user && isset($_POST['verify']) && isset($_POST['csrf']) && hash_equals($csrf, (string)$_POST['csrf'])) {
    if ($otp_rate_limited) {
        $err_msgs[] = "Too many attempts. Please wait before trying again.";
    } else {
        $code = only_digits($_POST['otp'] ?? '');
        if ($code === '' || strlen($code) !== 6) {
            $err_msgs[] = "Please enter a 6-digit code. (6 huroof ka code daliyé.)";
        } else {
            $isValid = false;
            
            // Method 1: Check user_otps table (new system)
            error_log("verify_profile.php: Method 1 - Attempting database verification");
            try {
                // Simple query with basic columns that should exist
                $st = $mysqli->prepare("SELECT id, otp_hash, expires_at FROM user_otps WHERE user_id=? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
                if ($st === false) {
                    error_log("verify_profile.php: Method 1 - FAILED: SQL prepare failed: " . $mysqli->error);
                } else {
                    $st->bind_param('i', $user['id']);
                    $st->execute();
                    $result = $st->get_result();
                    $otp_record = $result->fetch_assoc();
                    $st->close();
                    
                    error_log("verify_profile.php: Method 1 - OTP record found: " . ($otp_record ? "YES" : "NO"));
                    if ($otp_record) {
                        error_log("verify_profile.php: Method 1 - id: " . $otp_record['id'] . ", expires_at: " . $otp_record['expires_at']);
                        error_log("verify_profile.php: Method 1 - code: $code");
                    }
                    
                    if ($otp_record) {
                        if (password_verify($code, $otp_record['otp_hash'])) {
                            $isValid = true;
                            error_log("verify_profile.php: Method 1 - SUCCESS: password_verify matched");
                            // Mark OTP as used (simple update)
                            $update_stmt = $mysqli->prepare("UPDATE user_otps SET email_sent_at=NULL WHERE id=?");
                            if ($update_stmt) {
                                $update_stmt->bind_param('i', $otp_record['id']);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }
                        } else {
                            error_log("verify_profile.php: Method 1 - FAILED: password_verify failed");
                        }
                    } else {
                        error_log("verify_profile.php: Method 1 - FAILED: no valid OTP record found");
                    }
                }
            } catch (Exception $e) {
                error_log("verify_profile.php: Method 1 - Exception: " . $e->getMessage());
            }
            
            // Method 2: Check session fallback (register1.php)
            if (!$isValid) {
                error_log("verify_profile.php: Method 2 - checking session");
                error_log("verify_profile.php: Method 2 - session vars: register_otp=" . (isset($_SESSION['register_otp']) ? "SET" : "NOT SET") . ", user_id=" . ($_SESSION['register_otp_user_id'] ?? "NOT SET") . ", expires=" . ($_SESSION['register_otp_expires'] ?? "NOT SET"));
                error_log("verify_profile.php: Method 2 - expected user_id=" . $user['id'] . ", session user_id=" . ($_SESSION['register_otp_user_id'] ?? "NOT SET"));
                error_log("verify_profile.php: Method 2 - current time=" . time() . ", expiry time=" . ($_SESSION['register_otp_expires'] ?? "NOT SET"));
            }
            
            if (!$isValid && isset($_SESSION['register_otp']) && $_SESSION['register_otp_user_id'] == $user['id'] && $_SESSION['register_otp_expires'] > time()) {
                if ($code === $_SESSION['register_otp']) {
                    $isValid = true;
                    error_log("verify_profile.php: Method 2 - SUCCESS: session match");
                    // Clear session OTP
                    unset($_SESSION['register_otp'], $_SESSION['register_otp_user_id'], $_SESSION['register_otp_expires']);
                } else {
                    error_log("verify_profile.php: Method 2 - FAILED: session code mismatch. Expected: " . $_SESSION['register_otp'] . ", Got: $code");
                }
            } else if (!$isValid) {
                error_log("verify_profile.php: Method 2 - FAILED: session conditions not met");
            }

            if (!$isValid) {
                // Track failed attempt
                $_SESSION['otp_attempts'][] = time();
                $err_msgs[] = "Invalid or expired code. (Code galat ya expire ho chuka hai.)";
            } else {
                // Successful verification - clear attempts
                unset($_SESSION['otp_attempts']);
                
                // mark verified but keep status pending for admin approval
                if ($up = $mysqli->prepare("UPDATE users SET email_verified=1, status='pending' WHERE id=?")) {
                    $up->bind_param('i', $user['id']);
                    if ($up->execute()) {
                        $up->close();
                        $_SESSION['email_verified'] = 1;
                        $_SESSION['status'] = 'pending';
                        header('Location: /pending_approval.php?just_verified=1');
                        exit;
                    } else {
                        $up->close();
                        $err_msgs[] = "Could not verify now. Please try again.";
                    }
                } else {
                    $err_msgs[] = "Database error while verifying.";
                }
            }
        }
    }
}

// ---------- PAGE (minimal header, no public nav) ----------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email Verification — Shaikhoology</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  body{font-family:Inter,system-ui,Arial,sans-serif;background:#f6f7fb;color:#111;margin:0}
  .bar{background:#000;color:#fff;text-align:center;padding:24px 12px}
  .sub{background:#5b21b6;height:10px}
  .logout-row{max-width:820px;margin:8px auto 0;display:flex;justify-content:flex-end;padding:0 12px}
  .logout-row a{color:#4f46e5;font-weight:700;text-decoration:none}
  .card{max-width:820px;margin:20px auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 12px 30px rgba(15,20,40,0.06)}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:6px;margin:10px 0}
  .err{background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:6px;margin:10px 0}
  .input{width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px;font-size:15px}
  .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:0;font-weight:700;cursor:pointer;text-decoration:none}
  .btn-primary{background:#4f46e5;color:#fff}
  .btn-ghost{background:#fff;border:1px solid #e5e7eb}
  footer{color:#999;text-align:center;padding:18px}
</style>
</head>
<body>
  <div class="bar">
    <div style="font-size:24px;font-weight:800">Shaikhoology — Trading Psychology</div>
    <div style="opacity:.8">Trading Champion League</div>
  </div>
  <div class="sub"></div>

  <div class="logout-row">
    <a href="/logout.php">Logout</a>
  </div>

  <main class="card">
    <h2 style="margin:0 0 6px">✅ Email Verification &amp; Profile</h2>
    <p style="color:#555;margin:0 0 10px">Email: <strong><?= h(strtoupper($email)) ?></strong></p>

    <?php foreach ($ok_msgs as $m): ?>
      <div class="ok"><?= h($m) ?></div>
    <?php endforeach; ?>
    <?php foreach ($err_msgs as $m): ?>
      <div class="err"><?= h($m) ?></div>
    <?php endforeach; ?>

    <?php if ($user): ?>
      <form method="post" style="margin-top:12px" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label style="display:block;font-weight:600;margin-bottom:6px">Enter 6-digit code (Email par bheja gaya hai)</label>
        <input class="input" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="e.g. 123456" required>
        <div style="display:flex;gap:10px;margin-top:14px">
          <button class="btn btn-primary" type="submit" name="verify" value="1">Verify</button>
          <button class="btn btn-ghost" type="submit" name="resend" value="1">Resend code</button>
        </div>
      </form>
      
      <?php if (defined('DEV_MODE_EMAIL') && DEV_MODE_EMAIL && defined('DEV_SHOW_OTP') && DEV_SHOW_OTP): ?>
        <div style="margin-top:16px;padding:12px;background:#fff3cd;border:1px solid #ffeaa7;border-radius:8px;color:#856404">
          <strong>🔧 Development Mode Active</strong>
          <p style="margin:8px 0 0 0;font-size:14px">
            <strong>Your OTP Code: </strong>
            <span style="font-size:18px;font-weight:800;color:#d63031;background:#fff;padding:4px 8px;border-radius:4px;margin-left:8px">
              <?= h($user['otp_code'] ?? 'N/A') ?>
            </span>
          </p>
          <p style="margin:4px 0 0 0;font-size:12px;opacity:0.8">
            This OTP is shown only in development mode. Check the developer console for email logs.
          </p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <footer>© Shaikhoology — Trading Psychology</footer>
</body>
</html>