<?php
// pending_approval_live.php — Fixed version for live server
// Compatible with existing database schema

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Must be signed in
if (empty($_SESSION['user_id'])) {
  header('Location: /login.php'); exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid  = (int)$_SESSION['user_id'];
$user = null;

// Load fresh user row
if ($st = $mysqli->prepare("SELECT * FROM users WHERE id=? LIMIT 1")) {
  $st->bind_param('i', $uid);
  $st->execute();
  $user = $st->get_result()->fetch_assoc();
  $st->close();
}

if (!$user) {
  $_SESSION['flash'] = "User not found.";
  header('Location: /login.php'); exit;
}

// Flow enforcement
// 1) Email not verified -> OTP verification step
if ((int)$user['email_verified'] !== 1) {
  // This is the OTP verification step
  $show_otp_verification = true;
} else {
  $show_otp_verification = false;
}

// 2) Approved/Active -> dashboard
$status = strtolower((string)($user['status'] ?? 'pending'));
if (in_array($status, ['active','approved'], true)) {
  $_SESSION['flash'] = "✅ Mubarak ho! Aapka account approve ho gaya hai. Welcome to the Traders Club";
  header('Location: /dashboard.php'); exit;
}

// (Optional) If admin logs in and lands here, push them to admin dashboard
if (!empty($user['is_admin']) && (int)$user['is_admin'] === 1) {
  header('Location: /admin/admin_dashboard.php'); exit;
}

// Flash on "just verified"
$flash_ok = '';
if (isset($_GET['just_verified'])) {
  $flash_ok = "✅ Email verified! Your account is now pending admin approval. You will be notified once your application is reviewed.";
}

// Handle profile update/submit
$err = '';
$success = '';
$val = [];
if (!$show_otp_verification && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  // For live server, we'll just update the name field (which should exist)
  $name = trim((string)($_POST['name'] ?? ''));
  
  if (empty($name)) {
    $err = "Name is required.";
  } else {
    try {
      // Only update fields that actually exist in the users table
      $sql = "UPDATE users SET name=?, status='pending' WHERE id=?";
      if ($st = $mysqli->prepare($sql)) {
        $st->bind_param('si', $name, $uid);
        if ($st->execute()) {
          $success = "Profile updated successfully! Your application is now pending admin review.";
          $st->close();
          
          // Update session
          $_SESSION['username'] = $name;
          
          header('Location: /pending_approval.php'); exit;
        } else {
          $st->close();
          $err = "Failed to update profile. Please try again.";
        }
      } else {
        $err = "Database error: ".$mysqli->error;
      }
    } catch (Exception $e) {
      $err = "Error updating profile: " . $e->getMessage();
    }
  }
  $val['name'] = $name;
} else {
  // Pre-fill from DB
  $val['name'] = $user['name'] ?? '';
}

// Prepare rejection reason (if any)
$rejection_reason = '';
if (isset($user['rejection_reason']) && trim((string)$user['rejection_reason']) !== '') {
  $rejection_reason = (string)$user['rejection_reason'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Account Review — Shaikhoology</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  body{font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;background:#f8fafc;color:#1e293b;margin:0}
  .container{max-width:800px;margin:24px auto;padding:0 16px}
  .card{background:#fff;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.05);padding:24px;margin-bottom:24px}
  .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;margin-left:8px}
  .b-pending{background:#fef3c7;color:#7c2d12}
  .b-needs{background:#ffedd5;color:#c2410c}
  .b-approved{background:#dcfce7;color:#14532d}
  .b-review{background:#e0e7ff;color:#1e40af}
  .btn{display:inline-block;padding:10px 16px;border-radius:8px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:700;margin-top:12px}
  .btn:hover{background:#3730a3}
  .btn-logout{background:#dc2626}
  .field{margin-bottom:16px}
  label{display:block;font-weight:600;margin-bottom:6px}
  .input{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:6px;margin:12px 0}
  .err{background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:6px;margin:12px 0}
  .footer{text-align:center;padding:16px;background:#111;color:#fff;font-size:14px;margin-top:24px}
  .status-info{background:#e0f2fe;border:1px solid #0ea5e9;color:#0c4a6e;padding:15px;border-radius:8px;margin:20px 0}
</style>
</head>
<body>
  <div class="container">
    <div class="card">
      <h2 style="margin:0 0 8px">
        Account Review
        <?php if ($show_otp_verification): ?>
          <span class="badge b-pending">Email Verification Required</span>
        <?php else: ?>
          <span class="badge b-pending">Pending Admin Approval</span>
        <?php endif; ?>
      </h2>
      <p>User: <strong><?= h($user['name']) ?></strong> · Email: <strong><?= h($user['email']) ?></strong></p>
      <?php if ($show_otp_verification): ?>
        <p>Email Status: <span style="color:#dc2626">⏳ Verification Required</span></p>
      <?php else: ?>
        <p>Email Status: <span style="color:#16a34a">✅ Verified</span></p>
      <?php endif; ?>
    </div>

    <?php if ($flash_ok): ?>
      <div class="ok"><?= h($flash_ok) ?></div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="ok"><?= h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="err"><?= h($err) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="ok"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($status === 'rejected'): ?>
      <div class="card" style="background:#fff5f5;border-left:4px solid #ef4444">
        <h3 style="margin-top:0">❌ Your request was rejected</h3>
        <p>Kripya neeche wale points ko theek karke dubara submit karein.</p>
        <?php if ($rejection_reason): ?>
          <p><strong>Reason:</strong> <?= h($rejection_reason) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$show_otp_verification): ?>
      <div class="card">
        <h3 style="margin-top:0">Profile Information</h3>
        <p>Your account is pending admin approval. You can update your profile information below:</p>

        <div class="status-info">
          <h4 style="margin:0 0 10px 0;">📋 Application Status</h4>
          <p style="margin:0;">Your registration is complete and your email is verified. Your application is now under review by our admin team. You will be notified once a decision is made.</p>
        </div>

        <form method="post" style="margin-top:16px" novalidate>
          <input type="hidden" name="update_profile" value="1">
          
          <div class="field">
            <label for="name">Full Name</label>
            <input class="input" id="name" name="name" type="text" 
                   value="<?= h($val['name'] ?? '') ?>" placeholder="Your full name"
                   required>
          </div>

          <button type="submit" class="btn">💾 Update Profile</button>
          <a href="/logout.php" class="btn btn-logout">🚪 Logout</a>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <h3 style="margin-top:0">Complete Your Profile</h3>
        <p style="color:#6b7280">Please verify your email address first to complete your profile.</p>
        <a href="/logout.php" class="btn btn-logout">🚪 Logout</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="footer">© Shaikhoology — Trading Psychology | Since 2021.</div>
</body>
</html>