<?php
// verify.php — Legacy token-link email verification (kept for backward compatibility)
// If your new flow uses OTP (verify_profile.php), this file will:
//  - Try to verify via email_token only if that column exists and the token matches
//  - Otherwise, guide user to the OTP page / login
//  - After a successful legacy verify, keep status 'pending' and send to pending_approval.php

// Session and security handling centralized via bootstrap.php
require_once __DIR__ . '/includes/bootstrap.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Helper: does a column exist?
function col_exists(mysqli $db, string $table, string $col): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    if ($st = $db->prepare($sql)) {
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $ok = ($res = $st->get_result()) && $res->num_rows > 0;
        $st->close();
        return $ok;
    }
    return false;
}

$title = "Email Verification (Legacy)";
$msg   = '';
$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';

$hasEmailToken = col_exists($mysqli, 'users', 'email_token');
$ok = false;

if (!$hasEmailToken) {
    // New installations won’t have email_token. Direct users to the OTP screen.
    $msg = "This project uses the new OTP verification. Please verify via the 6-digit code sent to your email.";
} else {
    if ($token === '') {
        $msg = "Invalid verification link. (Missing token)";
    } else {
        // Try lookup by token
        if ($st = $mysqli->prepare("SELECT id, email, email_verified, status FROM users WHERE email_token=? LIMIT 1")) {
            $st->bind_param('s', $token);
            $st->execute();
            $u = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$u) {
                $msg = "Invalid or expired verification link.";
            } else {
                if ((int)$u['email_verified'] === 1) {
                    // Already verified — route to pending/dashboard based on status
                    if (in_array(strtolower((string)$u['status']), ['active','approved'], true)) {
                        $_SESSION['flash'] = "Your email was already verified. Welcome back!";
                        header('Location: /dashboard.php');
                        exit;
                    }
                    $_SESSION['flash'] = "Your email was already verified. Your account is pending admin approval.";
                    header('Location: /pending_approval.php');
                    exit;
                }

                // Mark verified (keep status pending so that admin still approves)
                if ($up = $mysqli->prepare("UPDATE users SET email_verified=1, status='pending', email_token=NULL WHERE id=?")) {
                    $up->bind_param('i', $u['id']);
                    if ($up->execute()) {
                        $up->close();
                        // If this user is currently signed in, reflect flags in session
                        if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$u['id']) {
                            $_SESSION['email_verified'] = 1;
                            $_SESSION['status'] = 'pending';
                        }
                        $_SESSION['flash'] = "🎉 Email verified successfully. Your account is pending admin approval.";
                        header('Location: /pending_approval.php');
                        exit;
                    } else {
                        $up->close();
                        $msg = "Verification failed — please try again later.";
                    }
                } else {
                    $msg = "Database error (update).";
                }
            }
        } else {
            $msg = "Database error (prepare).";
        }
    }
}

// Minimal page (no full nav) to guide to OTP verify when token flow isn’t available
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= h($title) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  body{font-family:Inter,system-ui,Arial,sans-serif;background:#f6f7fb;color:#111;margin:0}
  .bar{background:#000;color:#fff;text-align:center;padding:24px 12px}
  .wrap{max-width:760px;margin:32px auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08)}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:6px;margin:10px 0}
  .err{background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:6px;margin:10px 0}
  a.btn{display:inline-block;padding:10px 16px;border-radius:8px;background:#5f27cd;color:#fff;text-decoration:none;font-weight:700;margin-right:8px}
  a.link{color:#5f27cd}
  footer{color:#999;text-align:center;padding:18px}
</style>
</head>
<body>
  <div class="bar">
    <div style="font-size:24px;font-weight:800">Shaikhoology — Trading Psychology</div>
    <div style="opacity:.8">Trading Champion League</div>
  </div>

  <main class="wrap">
    <h2 style="margin:0 0 10px"><?= h($title) ?></h2>
    <?php if ($msg): ?>
      <div class="err"><?= $msg ?></div>
      <p>Use the new OTP flow:</p>
      <p>
        <a class="btn" href="/login.php">Go to Login</a>
        <a class="link" href="/register.php">Create an account</a>
      </p>
    <?php endif; ?>
  </main>

  <footer>© Shaikhoology — Trading Psychology</footer>
</body>
</html>