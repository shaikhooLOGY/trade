<?php
// pending_approval.php — Account review gate with OTP email verification

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
  
  // 2) Profile completed -> main pending approval page
  // For live server, we just show the pending approval page without complex profile checks
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
  // Fallback fields with proper sections structure
  $profile_fields = [
    'basic_info' => [
      'title' => 'Basic Information',
      'description' => 'Required basic information',
      'fields' => [
        'full_name' => ['label' => 'Full Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Apna full name daliye'],
        'phone' => ['label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'placeholder' => '+91 XXXXX XXXXX'],
        'trading_experience' => ['label' => 'Trading Experience (Years)', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 50, 'placeholder' => 'Kitne saal ka experience?'],
        'platform_used' => ['label' => 'Trading Platform Used', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g., Zerodha, Upstox, MetaTrader'],
        'why_join' => ['label' => 'Why do you want to join?', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Kyoon join karna chahte hain?']
      ]
    ]
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

// Handle OTP verification
$otp_err = '';
$otp_success = '';
if ($show_otp_verification && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
  $otp_code = trim((string)($_POST['otp_code'] ?? ''));
  
  if (empty($otp_code)) {
    $otp_err = "Please enter the verification code.";
  } elseif (!preg_match('/^\d{6}$/', $otp_code)) {
    $otp_err = "Verification code must be 6 digits.";
  } else {
    // Verify OTP
    $verification_result = otp_verify_code($uid, $otp_code);
    
    if ($verification_result['success']) {
      $otp_success = $verification_result['message'];
      // Refresh user data to get updated email_verified status
      if ($st = $mysqli->prepare("SELECT * FROM users WHERE id=? LIMIT 1")) {
        $st->bind_param('i', $uid);
        $st->execute();
        $user = $st->get_result()->fetch_assoc();
        $st->close();
      }
      // Redirect to refresh the page and show profile form
      header('Location: /pending_approval.php?verified=1');
      exit;
    } else {
      $otp_err = $verification_result['message'];
    }
  }
}

// Handle resend OTP
if ($show_otp_verification && isset($_POST['resend_otp'])) {
  // Check rate limiting
  $rate_limit = otp_rate_limit_check($uid, $user['email']);
  
  if (!$rate_limit['allowed']) {
    $otp_err = $rate_limit['message'];
  } else {
    // Resend OTP
    $resent = otp_send_verification_email($uid, $user['email'], $user['name']);
    if ($resent) {
      $otp_success = "New verification code sent to your email!";
    } else {
      $otp_err = "Failed to send verification code. Please try again later.";
    }
  }
}

// Handle profile update/submit
$err = '';
$success = '';
$val = [];
if (!$show_otp_verification && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  // Collect and validate all profile field data
  $has_required = false;
  $all_valid = true;
  
  // Process all fields from profile_fields.php
  foreach ($profile_fields as $section => $sectionData) {
    if (isset($sectionData['fields'])) {
      foreach ($sectionData['fields'] as $field => $cfg) {
        $val[$field] = trim((string)($_POST[$field] ?? ''));
        
        // Check if required field is filled
        if (!empty($cfg['required']) && $val[$field] === '') {
          $err = "Please fill in all required fields.";
          $all_valid = false;
          break 2; // Break both loops
        }
        
        // Basic validation for different field types
        if (!empty($val[$field]) && $all_valid) {
          if (($cfg['type'] ?? '') === 'number' && isset($cfg['min'], $cfg['max'])) {
            $num = (float)$val[$field];
            if ($num < $cfg['min'] || $num > $cfg['max']) {
              $err = "Invalid value for " . ($cfg['label'] ?? $field);
              $all_valid = false;
              break 2;
            }
          }
          
          if (($cfg['type'] ?? '') === 'checkbox_group' && !is_array($_POST[$field] ?? [])) {
            $val[$field] = is_array($_POST[$field] ?? []) ? $_POST[$field] : [];
          }
          
          if (!empty($cfg['required'])) {
            $has_required = true;
          }
        }
      }
    }
  }
  
  if ($all_valid && $has_required) {
    try {
      // Build safe UPDATE query - only update fields that exist in the database
      $set = ["status='pending'"];
      $types = '';
      $params = [];
      
      // Check which columns exist and build query accordingly
      foreach ($val as $field => $value) {
        // Only add fields that we know exist in the database
        if (in_array($field, ['name', 'email', 'phone', 'trading_experience', 'platform_used', 'why_join', 'age', 'location', 'education_level', 'institution', 'graduation_year', 'trading_experience_years', 'trading_markets', 'trading_strategies', 'previous_trading_results', 'investment_goals', 'risk_tolerance', 'investment_timeframe', 'trading_capital', 'monthly_income', 'net_worth', 'trading_budget_percentage', 'emotional_control_rating', 'discipline_rating', 'patience_rating', 'trading_psychology_questions', 'expectations', 'commitment_level', 'time_availability', 'reference_name', 'reference_contact', 'reference_relationship', 'reference_details'])) {
          $set[] = "`{$field}`=?";
          if (is_array($value)) {
            $types .= 's';
            $params[] = json_encode($value);
          } else {
            $types .= 's';
            $params[] = $value;
          }
        }
      }
      
      if (count($set) > 1) { // If we have fields to update
        $types .= 'i';
        $params[] = $uid;
        
        $sql = "UPDATE users SET " . implode(',', $set) . " WHERE id=?";
        if ($st = $mysqli->prepare($sql)) {
          $st->bind_param($types, ...$params);
          if ($st->execute()) {
            $success = "Profile updated successfully! Your application is now pending admin review.";
            $st->close();
            
            // Update session if name was updated
            if (!empty($val['name'])) {
              $_SESSION['username'] = $val['name'];
            }
            
            header('Location: /pending_approval.php'); exit;
          } else {
            $st->close();
            $err = "Failed to update profile. Please try again.";
          }
        } else {
          $err = "Database error: ".$mysqli->error;
        }
      } else {
        $success = "Profile information saved successfully! Your application is now pending admin review.";
        header('Location: /pending_approval.php'); exit;
      }
    } catch (Exception $e) {
      $err = "Error updating profile: " . $e->getMessage();
    }
  }
  
  // Pre-fill form data
  foreach ($profile_fields as $section => $sectionData) {
    if (isset($sectionData['fields'])) {
      foreach ($sectionData['fields'] as $field => $cfg) {
        if (!isset($val[$field])) {
          $val[$field] = $user[$field] ?? '';
        }
      }
    }
  }
  
  // Pre-fill name field
  if (!isset($val['name'])) {
    $val['name'] = $user['name'] ?? '';
  }
} else {
  // Pre-fill from DB for all profile fields
  foreach ($profile_fields as $section => $sectionData) {
    if (isset($sectionData['fields'])) {
      foreach ($sectionData['fields'] as $field => $cfg) {
        $val[$field] = $user[$field] ?? '';
        
        // Handle checkbox groups (stored as JSON)
        if (($cfg['type'] ?? '') === 'checkbox_group' && !empty($val[$field])) {
          $val[$field] = json_decode($val[$field], true) ?: [];
        }
      }
    }
  }
  
  // Pre-fill name field
  $val['name'] = $user['name'] ?? '';
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
          <?php
            $badgeClass = $profile_status==='needs_update'?'b-needs':($profile_status==='approved'?'b-approved':($profile_status==='under_review'?'b-review':'b-pending'));
            $badgeText  = $profile_status==='needs_update'?'Needs Update':($profile_status==='approved'?'Approved':($profile_status==='under_review'?'Under Review':'Pending'));
          ?>
          <span class="badge <?= $badgeClass ?>"><?= h($badgeText) ?></span>
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

    <?php if ($otp_err): ?>
      <div class="err"><?= h($otp_err) ?></div>
    <?php endif; ?>

    <?php if ($otp_success): ?>
      <div class="ok"><?= h($otp_success) ?></div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="err"><?= h($err) ?></div>
    <?php endif; ?>

    <?php if ($show_otp_verification): ?>
      <!-- OTP Verification Form -->
      <div class="card" style="background:#f0f9ff;border-left:4px solid #0ea5e9">
        <h3 style="margin-top:0;color:#0c4a6e">📧 Email Verification Required</h3>
        <p style="color:#0c4a6e;margin:0 0 20px 0">
          To complete your registration, please verify your email address. We've sent a 6-digit verification code to:
          <strong><?= h($user['email']) ?></strong>
        </p>
        
        <form method="post" style="margin-top:20px" novalidate>
          <div class="field">
            <label for="otp_code" style="color:#0c4a6e">Enter 6-digit verification code:</label>
            <input class="input" id="otp_code" name="otp_code" type="text" maxlength="6" pattern="[0-9]{6}"
                   placeholder="123456" required
                   style="font-size:18px;text-align:center;letter-spacing:4px;font-weight:bold">
          </div>
          
          <button type="submit" name="verify_otp" class="btn" style="background:#0ea5e9;margin-right:10px">
            ✅ Verify Email
          </button>
          
          <button type="submit" name="resend_otp" class="btn" style="background:#6b7280">
            📧 Resend Code
          </button>
        </form>
        
        <div style="margin-top:15px;padding:10px;background:#e0f2fe;border-radius:6px;font-size:13px;color:#0c4a6e">
          <strong>💡 Tips:</strong>
          <ul style="margin:5px 0 0 20px;padding:0">
            <li>Check your spam/junk folder if you don't see the email</li>
            <li>Code expires in 30 minutes</li>
            <li>You have 3 attempts to verify</li>
            <li>Wait 5 minutes before requesting a new code</li>
          </ul>
        </div>
      </div>
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
          // Process each section to find fields with admin feedback
          foreach ($profile_fields as $section_key => $section_data) {
            if (isset($section_data['fields'])) {
              foreach ($section_data['fields'] as $field => $cfg) {
                $st = $field_status[$field] ?? '';
                $cm = $field_comments[$field] ?? '';
                if ($st === 'ok'): $shown = true; ?>
                  <div class="feedback fb-ok"><strong><?= h($cfg['label']) ?>:</strong> ✅ OK (Admin approved)</div>
                <?php elseif ($cm): $shown = true; ?>
                  <div class="feedback fb-need"><strong><?= h($cfg['label']) ?>:</strong> <?= h($cm) ?></div>
                <?php endif;
              }
            }
          }
          if (!$shown): ?>
            <div class="feedback fb-need">Please update your profile details and submit again.</div>
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

        <?php if ($success): ?>
          <div class="ok"><?= h($success) ?></div>
        <?php endif; ?>

        <form method="post" style="margin-top:16px" novalidate>
          <input type="hidden" name="update_profile" value="1">
          
          <?php foreach ($profile_fields as $section_key => $section_data): ?>
            <?php if (isset($section_data['fields']) && !empty($section_data['fields'])): ?>
              <div style="margin:30px 0 20px 0;padding:20px;background:#f8f9fa;border-radius:8px;border-left:4px solid #4f46e5">
                <h4 style="margin:0 0 8px 0;color:#1e293b"><?= h($section_data['title']) ?></h4>
                <?php if (!empty($section_data['description'])): ?>
                  <p style="margin:0 0 15px 0;color:#6b7280;font-size:14px"><?= h($section_data['description']) ?></p>
                <?php endif; ?>
              </div>
              
              <?php foreach ($section_data['fields'] as $field_name => $field_config): ?>
                <div class="field">
                  <label for="<?= h($field_name) ?>">
                    <?= h($field_config['label']) ?>
                    <?= !empty($field_config['required'])?'<span style="color:#dc2626">*</span>':'' ?>
                    <?php if (!empty($field_config['help_text'])): ?>
                      <br><small style="color:#6b7280;font-weight:normal"><?= h($field_config['help_text']) ?></small>
                    <?php endif; ?>
                  </label>
                  
                  <?php
                    $field_value = $val[$field_name] ?? '';
                    $is_required = !empty($field_config['required']);
                    
                    switch ($field_config['type'] ?? 'text'):
                      case 'select':
                        ?>
                        <select class="input" id="<?= h($field_name) ?>" name="<?= h($field_name) ?>" <?= $is_required?'required':'' ?>>
                          <?php foreach (($field_config['options'] ?? []) as $opt_value => $opt_label): ?>
                            <option value="<?= h($opt_value) ?>" <?= ($field_value===$opt_value)?'selected':'' ?>>
                              <?= h($opt_label) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <?php
                        break;
                        
                      case 'textarea':
                        ?>
                        <textarea class="input" id="<?= h($field_name) ?>" name="<?= h($field_name) ?>"
                                  placeholder="<?= h($field_config['placeholder'] ?? '') ?>"
                                  <?= $is_required?'required':'' ?>
                                  <?= !empty($field_config['min_length'])?'minlength="'.$field_config['min_length'].'"':'' ?>
                                  <?= !empty($field_config['max_length'])?'maxlength="'.$field_config['max_length'].'"':'' ?>
                                  style="height:100px"><?= h($field_value) ?></textarea>
                        <?php
                        break;
                        
                      case 'checkbox_group':
                        ?>
                        <div style="display:flex;flex-direction:column;gap:8px;padding:8px 0">
                          <?php foreach (($field_config['options'] ?? []) as $opt_value => $opt_label): ?>
                            <label style="display:flex;align-items:flex-start;gap:8px;font-weight:normal">
                              <input type="checkbox" name="<?= h($field_name) ?>[]" value="<?= h($opt_value) ?>"
                                     <?= in_array($opt_value, (array)$field_value)?'checked':'' ?>
                                     style="margin-top:2px">
                              <span><?= h($opt_label) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                        <?php
                        break;
                        
                      case 'radio':
                        ?>
                        <div style="display:flex;flex-direction:column;gap:8px;padding:8px 0">
                          <?php foreach (($field_config['options'] ?? []) as $opt_value => $opt_label): ?>
                            <label style="display:flex;align-items:flex-start;gap:8px;font-weight:normal">
                              <input type="radio" name="<?= h($field_name) ?>" value="<?= h($opt_value) ?>"
                                     <?= $field_value===$opt_value?'checked':'' ?>
                                     style="margin-top:2px">
                              <span><?= h($opt_label) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                        <?php
                        break;
                        
                      case 'range':
                        ?>
                        <div style="display:flex;align-items:center;gap:10px">
                          <input class="input" id="<?= h($field_name) ?>" name="<?= h($field_name) ?>" type="range"
                                 value="<?= h($field_value) ?>"
                                 <?= $is_required?'required':'' ?>
                                 min="<?= h($field_config['min'] ?? 0) ?>" max="<?= h($field_config['max'] ?? 100) ?>" step="<?= h($field_config['step'] ?? 1) ?>">
                          <span style="font-weight:bold;min-width:30px;text-align:center"><?= h($field_value) ?></span>
                        </div>
                        <?php
                        break;
                        
                      case 'number':
                        ?>
                        <input class="input" id="<?= h($field_name) ?>" name="<?= h($field_name) ?>" type="number"
                               value="<?= h($field_value) ?>"
                               placeholder="<?= h($field_config['placeholder'] ?? '') ?>"
                               <?= $is_required?'required':'' ?>
                               <?= !empty($field_config['min'])?'min="'.$field_config['min'].'"':'' ?>
                               <?= !empty($field_config['max'])?'max="'.$field_config['max'].'"':'' ?>
                               <?= !empty($field_config['step'])?'step="'.$field_config['step'].'"':'' ?>>
                        <?php
                        break;
                        
                      default: // text, tel, email, etc.
                        ?>
                        <input class="input" id="<?= h($field_name) ?>" name="<?= h($field_name) ?>"
                               type="<?= h($field_config['type'] ?? 'text') ?>"
                               value="<?= h($field_value) ?>"
                               placeholder="<?= h($field_config['placeholder'] ?? '') ?>"
                               <?= $is_required?'required':'' ?>
                               <?= !empty($field_config['min'])?'minlength="'.$field_config['min'].'"':'' ?>
                               <?= !empty($field_config['max'])?'maxlength="'.$field_config['max'].'"':'' ?>>
                        <?php
                        break;
                    endswitch;
                  ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>

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