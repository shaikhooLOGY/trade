<?php
// register.php — User registration with OTP email verification
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// CSRF Protection
if (empty($_SESSION['reg_csrf'])) {
    $_SESSION['reg_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['reg_csrf'];

// Rate Limiting (5 registrations per 10 minutes per IP)
if (!isset($_SESSION['reg_attempts'])) {
    $_SESSION['reg_attempts'] = [];
}
if (!isset($_SESSION['reg_first_attempt'])) {
    $_SESSION['reg_first_attempt'] = 0;
}
$MAX_REGISTRATIONS = 5;
$REGISTRATION_WINDOW = 600; // 10 minutes
$current_time = time();

// Clean old attempts
if (!empty($_SESSION['reg_attempts'])) {
    foreach ($_SESSION['reg_attempts'] as $timestamp) {
        if ($current_time - $timestamp > $REGISTRATION_WINDOW) {
            unset($_SESSION['reg_attempts']);
        }
    }
}
if (!empty($_SESSION['reg_attempts']) && count($_SESSION['reg_attempts']) >= $MAX_REGISTRATIONS) {
    $oldest_attempt = min($_SESSION['reg_attempts']);
    $cooldown_remaining = $REGISTRATION_WINDOW - ($current_time - $oldest_attempt);
    if ($cooldown_remaining > 0) {
        $rate_limit_error = "Too many registration attempts. Please try again in " . ceil($cooldown_remaining/60) . " minutes.";
    }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function column_exists(mysqli $db, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];

  $sql = "
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ";
  $stmt = $db->prepare($sql);
  if (!$stmt) { $cache[$key] = false; return false; }
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $exists = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  $cache[$key] = $exists;
  return $exists;
}

// If already logged in, go to dashboard
if (!empty($_SESSION['user_id'])) { header('Location: /dashboard.php'); exit; }

$err = '';
$ok = '';
$rate_limit_error = '';
$val = ['name'=>'','email'=>'','username'=>'','password'=>'','confirm'=>''];

// Database connection check for form
$db_error = false;
try {
    if (!isset($mysqli) || !$mysqli instanceof mysqli) {
        $db_error = true;
    } else {
        $connectionProbe = @$mysqli->query('SELECT 1');
        if ($connectionProbe === false) {
            $db_error = true;
        } elseif ($connectionProbe instanceof mysqli_result) {
            $connectionProbe->free();
        }
    }
} catch (Exception $e) {
    $db_error = true;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  error_log("=== REGISTRATION POST STARTED ===");
  error_log("POST data received: " . json_encode(array_keys($_POST)));
  error_log("CSRF check: Session csrf=" . ($_SESSION['reg_csrf'] ?? 'NOT SET') . ", Submitted csrf=" . ($_POST['csrf'] ?? 'NOT SET'));
  
  // CSRF check
  $submitted_csrf = $_POST['csrf'] ?? '';
  if (!hash_equals($csrf, $submitted_csrf)) {
    $err = 'Invalid request. Please reload and try again. (Galat request, page reload karke dubara koshish karein)';
    error_log("CSRF validation failed!");
  } else {
    error_log("CSRF validation passed");
    // Check rate limiting
    if (isset($rate_limit_error)) {
      $err = $rate_limit_error;
    } else {
      // Read and normalize inputs
      $name = trim($_POST['name'] ?? '');
      $email = strtolower(trim($_POST['email'] ?? ''));
      $username = trim($_POST['username'] ?? '');
      $password = (string)($_POST['password'] ?? '');
      $confirm = (string)($_POST['confirm'] ?? '');
      $val['name']=$name; $val['email']=$email; $val['username']=$username;
      $val['password']=$password; $val['confirm']=$confirm;
      $accept_terms = isset($_POST['accept_terms']) ? (bool)$_POST['accept_terms'] : false;
      $accept_legal_disclaimer = isset($_POST['accept_legal_disclaimer']) ? (bool)$_POST['accept_legal_disclaimer'] : false;
      $accept_privacy_policy = isset($_POST['accept_privacy_policy']) ? (bool)$_POST['accept_privacy_policy'] : false;
      $accept_email_access = isset($_POST['accept_email_access']) ? (bool)$_POST['accept_email_access'] : false;
      $accept_all = isset($_POST['accept_all']) ? (bool)$_POST['accept_all'] : false;

      // Validate inputs
      if ($name==='' || $email==='' || $username==='' || $password==='' || $confirm==='') {
        $err = 'All fields are required. (Sabhi fields zaroori hain.)';
      } elseif (!$accept_terms || !$accept_legal_disclaimer || !$accept_privacy_policy || !$accept_email_access) {
        $err = 'You must accept all required terms. (Sabhi required terms ko accept karna zaroori hai.)';
      } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email. (Email sahi nahi hai.)';
        error_log("Email validation failed for: " . $email);
      } elseif (!preg_match('/^[A-Za-z0-9_.]{3,32}$/', $username)) {
        $err = 'Username invalid. Use 3-32 chars: letters, numbers, underscore, dot. (Username galat hai.)';
        error_log("Username validation failed for: " . $username);
      } else {
        error_log("Basic validation passed, checking sensitive usernames");
        // Check against sensitive username blacklist
        $sensitive_usernames = [
          'admin', 'administrator', 'root', 'system', 'support', 'help', 'info',
          'contact', 'service', 'api', 'www', 'mail', 'email', 'noreply', 'do-not-reply',
          'security', 'privacy', 'terms', 'about', 'privacy-policy', 'terms-conditions',
          'disclaimer', 'refund', 'billing', 'sales', 'marketing', 'careers', 'jobs',
          'press', 'media', 'news', 'blog', 'forum', 'community', 'help-center',
          'support-center', 'livechat', 'chat', 'bot', 'test', 'testing', 'demo',
          'temp', 'temporary', 'guest', 'anonymous', 'user', 'default', 'null',
          'shaikhoology' // Special case - allowed for user claiming
        ];
        
        if (in_array(strtolower($username), $sensitive_usernames) && strtolower($username) !== 'shaikhoology') {
          $err = 'This username is protected and cannot be used. (Yeh username protected hai.)';
          error_log("Username in sensitive list: " . $username);
        } elseif (strlen($password) < 8) {
          $err = 'Password must be at least 8 characters. (Password 8 characters ka hona chahiye.)';
          error_log("Password too short: " . strlen($password) . " chars");
        } elseif ($password !== $confirm) {
          $err = 'Passwords do not match. (Passwords match nahi karte.)';
          error_log("Passwords do not match");
        } else {
          error_log("All password checks passed, starting database operations");
          try {
            $mysqli->begin_transaction();
            
            // Pre-check duplicates
            $checkEmail = $mysqli->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $checkEmail->bind_param('s', $email);
            $checkEmail->execute();
            $emailExists = $checkEmail->get_result()->fetch_assoc();
            $checkEmail->close();
            
            if ($emailExists) {
              $mysqli->rollback();
              $err = 'Email already registered. (Email pehle se registered hai.)';
              error_log("Email already exists: " . $email);
            } else {
              error_log("Email not found, checking username");
              $checkUsername = $mysqli->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
              $checkUsername->bind_param('s', $username);
              $checkUsername->execute();
              $usernameExists = $checkUsername->get_result()->fetch_assoc();
              $checkUsername->close();
              
              if ($usernameExists) {
                $mysqli->rollback();
                $err = 'Username already taken. (Username pehle se liya gaya hai.)';
              } else {
                // Insert user
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $initial_capital = 100000.00;
                
                // Insert user with pending status for admin approval
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $initial_capital = 100000.00;
                $user_status = 'pending'; // Set to pending for admin approval
                
                $ins = $mysqli->prepare("INSERT INTO users (name,email,username,password_hash,status,email_verified,created_at) VALUES (?,?,?,?,?,?,NOW())");
                $ins->bind_param('sssssi', $name, $email, $username, $hash, $user_status, 0); // email_verified = 0 initially
                
                if ($ins->execute()) {
                  $uid = $ins->insert_id;
                  $ins->close();
                  
                  // Record successful registration for rate limiting
                  $_SESSION['reg_attempts'][] = time();
                  
                  // Seed initial capital for new users when schema supports it
                  if (column_exists($mysqli, 'users', 'trading_capital')) {
                    if ($capStmt = $mysqli->prepare("UPDATE users SET trading_capital = ? WHERE id = ?")) {
                      $capStmt->bind_param('di', $initial_capital, $uid);
                      $capStmt->execute();
                      $capStmt->close();
                    }
                  }
                  
                  if (column_exists($mysqli, 'users', 'funds_available')) {
                    if ($faStmt = $mysqli->prepare("UPDATE users SET funds_available = ? WHERE id = ?")) {
                      $faStmt->bind_param('di', $initial_capital, $uid);
                      $faStmt->execute();
                      $faStmt->close();
                    }
                  }
                  
                  $mysqli->commit();
                  
                  // Automatically log in the user after successful registration
                  $_SESSION['user_id'] = (int)$uid;
                  $_SESSION['username'] = $name;
                  $_SESSION['email'] = $email;
                  $_SESSION['is_admin'] = 0;
                  $_SESSION['status'] = 'pending';
                  $_SESSION['email_verified'] = 0;
                  $_SESSION['role'] = 'user';
                  
                  // Send OTP verification email
                  $otp_sent = otp_send_verification_email($uid, $email, $name);
                  
                  if ($otp_sent) {
                      $_SESSION['flash'] = 'Registration successful! Please check your email for the verification code to complete your account setup.';
                      error_log("Registration SUCCESS! User ID: " . $uid . " - OTP sent, redirecting to email verification");
                      header('Location: /pending_approval.php?email=' . urlencode($email) . '&otp=1');
                      exit;
                  } else {
                      // OTP email failed, but still show success (user can request OTP later)
                      $_SESSION['flash'] = 'Registration successful! Please check your email for the verification code. If you don\'t receive it, you can request a new one.';
                      error_log("Registration SUCCESS! User ID: " . $uid . " - OTP email failed, redirecting to email verification");
                      header('Location: /pending_approval.php?email=' . urlencode($email) . '&otp=1&email_failed=1');
                      exit;
                  }
                  
                } else {
                  $mysqli->rollback();
                  $err = 'Registration failed. Please try again. (Register nahi hua; dubara koshish karein.)';
                }
              }
            }
          } catch (mysqli_sql_exception $e) {
            $mysqli->rollback();
            if ($e->getCode() == 1062) {
              if (strpos($e->getMessage(), 'email') !== false) {
                $err = 'Email already registered. (Email pehle se registered hai.)';
              } elseif (strpos($e->getMessage(), 'username') !== false) {
                $err = 'Username already taken. (Username pehle se liya gaya hai.)';
              } else {
                $err = 'Account already exists. (Account pehle se maujood hai.)';
              }
            } else {
              error_log("Registration error: " . $e->getMessage());
              $err = 'Registration failed. Please try again later. (Register nahi hua; baad mein koshish karein.)';
            }
          } catch (Exception $e) {
            $mysqli->rollback();
            error_log("Registration error: " . $e->getMessage());
            $err = 'Registration failed. Please try again later. (Register nahi hua; baad mein koshish karein.)';
          }
        }
      }
    }
  }
}

// Handle username availability check via AJAX
if (isset($_POST['check_username']) && $_POST['check_username'] === '1') {
  $username = trim($_POST['username'] ?? '');
  if (!empty($username) && preg_match('/^[A-Za-z0-9_.]{3,32}$/', $username)) {
    
    // Check against sensitive username blacklist
    $sensitive_usernames = [
      'admin', 'administrator', 'root', 'system', 'support', 'help', 'info',
      'contact', 'service', 'api', 'www', 'mail', 'email', 'noreply', 'do-not-reply',
      'security', 'privacy', 'terms', 'about', 'privacy-policy', 'terms-conditions',
      'disclaimer', 'refund', 'billing', 'sales', 'marketing', 'careers', 'jobs',
      'press', 'media', 'news', 'blog', 'forum', 'community', 'help-center',
      'support-center', 'livechat', 'chat', 'bot', 'test', 'testing', 'demo',
      'temp', 'temporary', 'guest', 'anonymous', 'user', 'default', 'null',
      'shaikhoology' // Special case - can be claimed by users
    ];
    
    // Check if username is in sensitive list
    if (in_array(strtolower($username), $sensitive_usernames)) {
      if (strtolower($username) === 'shaikhoology') {
        echo 'claimable'; // Special case - can be claimed
      } else {
        echo 'sensitive';
      }
    } else {
      try {
        $checkUsername = $mysqli->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $checkUsername->bind_param('s', $username);
        $checkUsername->execute();
        $usernameExists = $checkUsername->get_result()->fetch_assoc();
        $checkUsername->close();
        
        if ($usernameExists) {
          echo 'taken';
        } else {
          echo 'available';
        }
      } catch (Exception $e) {
        echo 'error';
      }
    }
  } else {
    echo 'invalid';
  }
  exit;
}

$hideNav = true; include __DIR__ . '/header.php';
?>
<div style="max-width:720px;margin:28px auto;padding:26px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(16,24,40,.06)">
  <h2 style="margin:0 0 6px;color:#222">📝 Create account</h2>
  
  <?php if($db_error): ?>
    <div style="background:#fff3cd;border:1px solid #ffeaa7;color:#856404;padding:10px;border-radius:8px;margin:12px 0">
      <strong>⚠️ System Status:</strong> Database connection issues detected. Registration may be temporarily unavailable. 
      <span style="font-size:12px;display:block;margin-top:5px;color:#6c757d;">Admin has been notified. Please try again in a few minutes.</span>
    </div>
  <?php endif; ?>
  
  <?php if($ok): ?>
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:8px;margin:12px 0"><?=h($ok)?></div>
  <?php endif; ?>
  <?php if($err): ?>
    <div style="background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:8px;margin:12px 0"><?=h($err)?></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label style="display:block;margin-top:10px;font-weight:600">Full name</label>
    <input name="name" value="<?=h($val['name'])?>" required
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    <label style="display:block;margin-top:12px;font-weight:600">Email</label>
    <input name="email" type="email" value="<?=h($val['email'])?>" required
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    <label style="display:block;margin-top:12px;font-weight:600">Username</label>
    <div style="position:relative">
      <input name="username" value="<?=h($val['username'])?>" required
             style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px;padding-right:40px">
      <div id="usernameStatus" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:16px;display:none;">
        <span id="usernameIcon"></span>
      </div>
    </div>
    <label style="display:block;margin-top:12px;font-weight:600">Password</label>
    <input name="password" type="password" minlength="8" required
           value="<?=h($val['password'])?>"
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    <label style="display:block;margin-top:12px;font-weight:600">Confirm password</label>
    <input name="confirm" type="password" minlength="8" required
           value="<?=h($val['confirm'])?>"
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    
    <!-- Compact Legal Consents -->
    <div style="margin-top:20px;padding:15px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px">
      <h4 style="margin:0 0 15px;color:#333">Legal Consents & Agreements</h4>
      
      <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
        <label style="display:flex;align-items:flex-start;gap:8px">
          <input type="checkbox" name="accept_terms" value="1" required style="margin-top:2px">
          <span>I agree to the <a href="/disclaimer.php" target="_blank" style="color:#4a22c8;text-decoration:underline">Terms & Conditions</a> and understand trading involves substantial risk. (All submissions required)</span>
        </label>
        
        <label style="display:flex;align-items:flex-start;gap:8px">
          <input type="checkbox" name="accept_legal_disclaimer" value="1" required style="margin-top:2px">
          <span>I accept the <a href="/disclaimer.php" target="_blank" style="color:#4a22c8;text-decoration:underline">Legal Disclaimer</a> and acknowledge this is educational content, not investment advice.</span>
        </label>
        
        <label style="display:flex;align-items:flex-start;gap:8px">
          <input type="checkbox" name="accept_privacy_policy" value="1" required style="margin-top:2px">
          <span>I agree to the <a href="/privacy_policy.php" target="_blank" style="color:#4a22c8;text-decoration:underline">Privacy Policy</a> and understand how my data will be used and protected.</span>
        </label>
        
        <label style="display:flex;align-items:flex-start;gap:8px">
          <input type="checkbox" name="accept_email_access" value="1" required style="margin-top:2px">
          <span>I consent to access my Gmail/email address for account verification and trading platform integration (required for full functionality).</span>
        </label>
      </div>
      
      <div style="margin-top:15px;padding-top:15px;border-top:1px solid #e9ecef">
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:14px">
          <input type="checkbox" name="accept_all" value="1" style="margin-top:2px">
          <span><strong>Select All</strong> - I agree to all terms above</span>
        </label>
      </div>
    </div>
    
    <button type="submit" id="createAccountBtn" disabled
            style="margin-top:16px;background:linear-gradient(90deg,#999,#999);color:#fff;padding:12px;border-radius:8px;border:0;font-weight:700;width:100%;cursor:not-allowed;opacity:0.6">
      <?= $db_error ? 'Database Unavailable - Try Later' : 'Create Account' ?>
    </button>
    <div id="submitHint" style="margin-top:8px;color:#666;font-size:12px;text-align:center;">
      Please accept all required terms to enable account creation
    </div>
  </form>
  <div style="text-align:center;margin-top:16px;color:#666">
    Already have an account? <a href="/login.php" style="color:#5f27cd;font-weight:600">Login</a>
  </div>
</div>

<script>
// JavaScript for "Select All" functionality and form validation
document.addEventListener('DOMContentLoaded', function() {
  const acceptAllCheckbox = document.querySelector('input[name="accept_all"]');
  const requiredCheckboxes = [
    'accept_terms',
    'accept_legal_disclaimer',
    'accept_privacy_policy',
    'accept_email_access'
  ];
  
  const submitBtn = document.getElementById('createAccountBtn');
  const submitHint = document.getElementById('submitHint');
  const form = document.querySelector('form');
  
  // Function to check if all required checkboxes are checked
  function checkAllRequired() {
    // console.log('Checking required checkboxes...');
    const allChecked = requiredCheckboxes.every(function(checkboxName) {
      const checkbox = document.querySelector('input[name="' + checkboxName + '"]');
      const isChecked = checkbox && checkbox.checked;
      // console.log(checkboxName + ': ' + isChecked);
      return isChecked;
    });
    
    // console.log('All required checked: ' + allChecked);
    
    if (allChecked) {
      submitBtn.disabled = false;
      submitBtn.style.background = 'linear-gradient(90deg,#5f27cd,#341f97)';
      submitBtn.style.cursor = 'pointer';
      submitBtn.style.opacity = '1';
      submitHint.textContent = 'All terms accepted - Account creation enabled';
      submitHint.style.color = '#4CAF50';
      // console.log('Button enabled');
    } else {
      submitBtn.disabled = true;
      submitBtn.style.background = 'linear-gradient(90deg,#999,#999)';
      submitBtn.style.cursor = 'not-allowed';
      submitBtn.style.opacity = '0.6';
      submitHint.textContent = 'Please accept all required terms to enable account creation';
      submitHint.style.color = '#666';
      // console.log('Button disabled');
    }
    
    return allChecked;
  }
  
  // Enable "Select All" functionality
  if (acceptAllCheckbox) {
    acceptAllCheckbox.addEventListener('change', function() {
      const isChecked = this.checked;
      requiredCheckboxes.forEach(function(checkboxName) {
        const checkbox = document.querySelector('input[name="' + checkboxName + '"]');
        if (checkbox) {
          checkbox.checked = isChecked;
        }
      });
      checkAllRequired();
    });
  }
  
  // Individual checkbox change events
  requiredCheckboxes.forEach(function(checkboxName) {
    const checkbox = document.querySelector('input[name="' + checkboxName + '"]');
    if (checkbox) {
      checkbox.addEventListener('change', function() {
        // Update "Select All" based on individual selections
        const allChecked = requiredCheckboxes.every(function(name) {
          const cb = document.querySelector('input[name="' + name + '"]');
          return cb && cb.checked;
        });
        
        if (acceptAllCheckbox) {
          acceptAllCheckbox.checked = allChecked;
        }
        checkAllRequired();
      });
    }
  });
  
  // Form submission handler with validation
  if (form) {
    form.addEventListener('submit', function(e) {
      console.log('Form submit triggered');
      
      // Check if all required fields are filled
      const name = document.querySelector('input[name="name"]').value.trim();
      const email = document.querySelector('input[name="email"]').value.trim();
      const username = document.querySelector('input[name="username"]').value.trim();
      const password = document.querySelector('input[name="password"]').value;
      const confirm = document.querySelector('input[name="confirm"]').value;
      
      console.log('Field values:', { name, email, username, password: password ? '***' : 'empty', confirm: confirm ? '***' : 'empty' });
      
      if (!name || !email || !username || !password || !confirm) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        console.log('Validation failed: missing fields');
        return false;
      }
      
      // Check if passwords match
      if (password !== confirm) {
        e.preventDefault();
        alert('Passwords do not match. Please check and try again.');
        console.log('Validation failed: password mismatch');
        return false;
      }
      
      // Check if all required checkboxes are checked
      const allRequiredChecked = checkAllRequired();
      console.log('All required checkboxes checked:', allRequiredChecked);
      
      if (!allRequiredChecked) {
        e.preventDefault();
        alert('Please accept all required terms and conditions.');
        console.log('Validation failed: missing checkboxes');
        return false;
      }
      
      console.log('All validations passed, allowing form submission');
      
      // Show loading state
      submitBtn.disabled = true;
      submitBtn.textContent = 'Creating Account...';
      submitBtn.style.opacity = '0.7';
    });
  }
  
  // Initial check with small delay to ensure DOM is ready
  setTimeout(checkAllRequired, 100);
  
  // Username availability check
  const usernameInput = document.querySelector('input[name="username"]');
  const usernameStatus = document.getElementById('usernameStatus');
  const usernameIcon = document.getElementById('usernameIcon');
  
  let usernameTimeout;
  usernameInput.addEventListener('input', function() {
    const username = this.value.trim();
    clearTimeout(usernameTimeout);
    
    if (username.length < 3) {
      showUsernameStatus('🔍', '#999', 'Username must be at least 3 characters');
      return;
    }
    
    if (!/^[A-Za-z0-9_.]{3,32}$/.test(username)) {
      showUsernameStatus('❌', '#d32f2f', 'Username can only contain letters, numbers, underscore, and dot');
      return;
    }
    
    showUsernameStatus('⏳', '#999', 'Checking username availability...');
    
    // Check username availability after user stops typing
    usernameTimeout = setTimeout(function() {
      checkUsernameAvailability(username);
    }, 500);
  });
  
  function showUsernameStatus(icon, color, title, isPremium = false) {
    usernameStatus.style.display = 'block';
    usernameIcon.textContent = icon;
    usernameIcon.style.color = color;
    usernameIcon.style.fontSize = isPremium ? '18px' : '16px';
    usernameIcon.style.textShadow = isPremium ? '0 0 8px rgba(76, 175, 80, 0.3)' : 'none';
    usernameIcon.style.filter = isPremium ? 'drop-shadow(0 0 2px rgba(76, 175, 80, 0.5))' : 'none';
    usernameStatus.title = title;
    
    if (isPremium) {
      // Premium animation effect
      usernameIcon.style.animation = 'none';
      setTimeout(() => {
        usernameIcon.style.animation = 'premiumPulse 0.6s ease-out';
      }, 10);
    }
  }
  
  function hideUsernameStatus() {
    usernameStatus.style.display = 'none';
  }
  
  function checkUsernameAvailability(username) {
    // Create a temporary form to check username without disrupting main form
    const formData = new FormData();
    formData.append('check_username', '1');
    formData.append('username', username);
    
    fetch(window.location.href, {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(data => {
      if (data.includes('available')) {
        showUsernameStatus('✓', '#1DA1F2', '✓ Username verified and available!', true);
        // Enhanced success animation
        usernameIcon.style.transform = 'scale(1.3)';
        usernameIcon.style.filter = 'drop-shadow(0 0 8px rgba(29, 161, 242, 0.8))';
        setTimeout(() => {
          usernameIcon.style.transform = 'scale(1)';
          usernameIcon.style.filter = 'drop-shadow(0 0 2px rgba(29, 161, 242, 0.5))';
        }, 400);
      } else if (data.includes('claimable')) {
        showUsernameStatus('👑', '#FFD700', '👑 Premium username claimable!', true);
        usernameIcon.style.transform = 'scale(1.2)';
        setTimeout(() => {
          usernameIcon.style.transform = 'scale(1)';
        }, 300);
      } else if (data.includes('sensitive')) {
        showUsernameStatus('🚫', '#d32f2f', 'This username is protected and cannot be used');
        usernameIcon.style.animation = 'none';
        setTimeout(() => {
          usernameIcon.style.animation = 'shake 0.5s ease-in-out';
        }, 10);
      } else if (data.includes('taken')) {
        showUsernameStatus('❌', '#d32f2f', 'Username is already taken');
      } else {
        showUsernameStatus('🔍', '#999', 'Username format is valid');
      }
    })
    .catch(error => {
      showUsernameStatus('🔍', '#999', 'Username format is valid');
    });
  }
});
</script>

<style>
@keyframes premiumPulse {
  0% { transform: scale(1); filter: drop-shadow(0 0 2px rgba(76, 175, 80, 0.5)); }
  50% { transform: scale(1.4); filter: drop-shadow(0 0 12px rgba(76, 175, 80, 1)); }
  100% { transform: scale(1.2); filter: drop-shadow(0 0 8px rgba(76, 175, 80, 0.8)); }
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  10%, 30%, 50%, 70%, 90% { transform: translateX(-2px); }
  20%, 40%, 60%, 80% { transform: translateX(2px); }
}
</style>

<?php include __DIR__ . '/footer.php'; ?>
