<?php
// pending_approval.php — Account review gate (copy-paste ready)

require_once __DIR__ . '/config.php';
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
// 1) Unverified -> verify page
if ((int)$user['email_verified'] !== 1) {
  header('Location: /verify_profile.php?email=' . urlencode($user['email']));
  exit;
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

// Profile fields source (same directory preferred)
$profile_fields_file = __DIR__ . '/profile_fields.php';
if (file_exists($profile_fields_file)) {
  $profile_fields = include $profile_fields_file;
} else {
  // Fallback fields
  $profile_fields = [
    'full_name' => ['label' => 'Full Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Apna full name daliye'],
    'phone' => ['label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'placeholder' => '+91 XXXXX XXXXX'],
    'country' => ['label' => 'Country', 'type' => 'select', 'required' => true,
                  'options' => ['' => 'Select Country', 'IN' => 'India', 'Other' => 'Other']],
    'trading_experience' => ['label' => 'Trading Experience (Years)', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 50, 'placeholder' => 'Kitne saal ka experience?'],
    'platform_used' => ['label' => 'Trading Platform Used', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g., Zerodha, Upstox, MetaTrader'],
    'why_join' => ['label' => 'Why do you want to join?', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Kyoon join karna chahte hain?']
  ];
}

// Decode admin per-field feedback (if any)
$field_status   = [];
$field_comments = [];
if (!empty($user['profile_field_status'])) {
  $field_status = json_decode((string)$user['profile_field_status'], true) ?: [];
}
if (!empty($user['profile_comments'])) {
  $field_comments = json_decode((string)$user['profile_comments'], true) ?: [];
}

// Flash on “just verified”
$flash_ok = '';
if (isset($_GET['just_verified'])) {
  $flash_ok = "✅ Email verified! Ab apna profile complete/refresh karke 'Save' karein. Admin review ke baad aapko approve kiya jayega.";
}

// Handle profile update/submit
$err = '';
$val = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  foreach ($profile_fields as $field => $cfg) {
    $val[$field] = trim((string)($_POST[$field] ?? ''));
    if (!empty($cfg['required']) && $val[$field] === '') {
      $err = "Kripya saare zaroori fields bhar dein.";
      break;
    }
  }

  if (!$err) {
    // Build dynamic update
    $set = ["status='pending'", "profile_status='pending'"];
    $types = '';
    $params = [];

    foreach ($profile_fields as $field => $cfg) {
      $set[]   = "`$field`=?";
      $types  .= 's';
      $params[] = $val[$field];
    }

    $types .= 'i';
    $params[] = $uid;

    $sql = "UPDATE users SET ".implode(',', $set)." WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param($types, ...$params);
      if ($st->execute()) {
        $_SESSION['flash'] = "Profile save ho gaya! Admin review ke baad aapko email se notify kiya jayega.";
        $st->close();
        header('Location: /pending_approval.php'); exit;
      }
      $err = "Profile save nahi ho paya. Kripya dubara koshish karein.";
      $st->close();
    } else {
      $err = "Database error: ".$mysqli->error;
    }
  }
} else {
  // Pre-fill from DB
  foreach ($profile_fields as $field => $cfg) {
    $val[$field] = $user[$field] ?? '';
  }
}

// Prepare rejection reason (if any)
$rejection_reason = '';
if (isset($user['rejection_reason']) && trim((string)$user['rejection_reason']) !== '') {
  $rejection_reason = (string)$user['rejection_reason'];
}
// Normalize profile_status for badge
$profile_status = strtolower((string)($user['profile_status'] ?? 'pending'));
if (!in_array($profile_status, ['pending','needs_update','approved','under_review'], true)) {
  $profile_status = 'pending';
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
  .feedback{margin-top:6px;padding:8px;border-radius:6px;font-size:13px}
  .fb-ok{background:#dcfce7;color:#14532d;border-left:3px solid #16a34a}
  .fb-need{background:#fffbeb;color:#7c2d12;border-left:3px solid #d97706}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:6px;margin:12px 0}
  .err{background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:6px;margin:12px 0}
  .footer{text-align:center;padding:16px;background:#111;color:#fff;font-size:14px;margin-top:24px}
</style>
</head>
<body>
  <div class="container">
    <div class="card">
      <h2 style="margin:0 0 8px">
        Account Review
        <?php
          $badgeClass = $profile_status==='needs_update'?'b-needs':($profile_status==='approved'?'b-approved':($profile_status==='under_review'?'b-review':'b-pending'));
          $badgeText  = $profile_status==='needs_update'?'Needs Update':($profile_status==='approved'?'Approved':($profile_status==='under_review'?'Under Review':'Pending'));
        ?>
        <span class="badge <?= $badgeClass ?>"><?= h($badgeText) ?></span>
      </h2>
      <p>User: <strong><?= h($user['name']) ?></strong> · Email: <strong><?= h($user['email']) ?></strong></p>
      <p>Email Status: <span style="color:#16a34a">✅ Verified</span></p>
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

    <?php if ($status === 'rejected'): ?>
      <div class="card" style="background:#fff5f5;border-left:4px solid #ef4444">
        <h3 style="margin-top:0">❌ Your request was rejected</h3>
        <p>Kripya neeche wale points ko theek karke dubara submit karein.</p>
        <?php if ($rejection_reason): ?>
          <p><strong>Reason:</strong> <?= h($rejection_reason) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($profile_status === 'needs_update'): ?>
      <div class="card" style="background:#fffbeb;border-left:4px solid #d97706">
        <h4 style="margin-top:0">Admin Feedback — Kripya in points ko update karein:</h4>
        <?php
          $shown = false;
          foreach ($profile_fields as $field => $cfg):
            $st = $field_status[$field] ?? '';
            $cm = $field_comments[$field] ?? '';
            if ($st === 'ok'): $shown = true; ?>
              <div class="feedback fb-ok"><strong><?= h($cfg['label']) ?>:</strong> ✅ OK (Admin approved)</div>
            <?php elseif ($cm): $shown = true; ?>
              <div class="feedback fb-need"><strong><?= h($cfg['label']) ?>:</strong> <?= h($cm) ?></div>
            <?php endif;
          endforeach;
          if (!$shown): ?>
            <div class="feedback fb-need">Please update your profile details and submit again.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3 style="margin-top:0">Complete/Update Your Profile</h3>
      <p>Aapka account approve hone ke liye niche form sahi tarah se fill karke <strong>save</strong> karein. (Admin review karega.)</p>

      <form method="post" style="margin-top:16px" novalidate>
        <input type="hidden" name="update_profile" value="1">
        <?php foreach ($profile_fields as $field => $cfg): ?>
          <div class="field">
            <label for="<?= h($field) ?>">
              <?= h($cfg['label']) ?> <?= !empty($cfg['required'])?'<span style="color:#dc2626">*</span>':'' ?>
            </label>
            <?php if (($cfg['type'] ?? '') === 'select'): ?>
              <select class="input" id="<?= h($field) ?>" name="<?= h($field) ?>" <?= !empty($cfg['required'])?'required':'' ?>>
                <?php foreach (($cfg['options'] ?? []) as $valOpt => $labelOpt): ?>
                  <option value="<?= h($valOpt) ?>" <?= (($val[$field] ?? '')===$valOpt)?'selected':'' ?>>
                    <?= h($labelOpt) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php elseif (($cfg['type'] ?? '') === 'textarea'): ?>
              <textarea class="input" id="<?= h($field) ?>" name="<?= h($field) ?>" placeholder="<?= h($cfg['placeholder'] ?? '') ?>" <?= !empty($cfg['required'])?'required':'' ?> style="height:90px"><?= h($val[$field] ?? '') ?></textarea>
            <?php else: ?>
              <input class="input" id="<?= h($field) ?>" type="<?= h($cfg['type'] ?? 'text') ?>" name="<?= h($field) ?>"
                     value="<?= h($val[$field] ?? '') ?>" placeholder="<?= h($cfg['placeholder'] ?? '') ?>"
                     <?= !empty($cfg['required'])?'required':'' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <button type="submit" class="btn">💾 Save & Resubmit</button>
        <a href="/logout.php" class="btn btn-logout">🚪 Logout</a>
      </form>
    </div>
  </div>

  <div class="footer">© Shaikhoology — Trading Psychology | Since 2021.</div>
</body>
</html>