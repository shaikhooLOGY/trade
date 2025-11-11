<?php
// register.php — Simplified registration with OTP email verification

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function column_exists(mysqli $db, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];

  $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
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
$success = '';
$val = ['name'=>'','email'=>'','username'=>'','password'=>'','confirm'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Simple validation
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $accept_terms = isset($_POST['accept_terms']);
    
    $val['name'] = $name;
    $val['email'] = $email;
    $val['username'] = $username;
    $val['password'] = $password;
    $val['confirm'] = $confirm;
    
    if (empty($name) || empty($email) || empty($username) || empty($password) || empty($confirm)) {
        $err = 'All fields are required.';
    } elseif (!$accept_terms) {
        $err = 'You must accept the terms and conditions.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
        $err = 'Username must be 3-20 characters, letters, numbers, and underscore only.';
    } elseif (strlen($password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $err = 'Passwords do not match.';
    } else {
        try {
            // Check for existing email
            $checkEmail = $mysqli->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $checkEmail->bind_param('s', $email);
            $checkEmail->execute();
            $emailExists = $checkEmail->get_result()->fetch_assoc();
            $checkEmail->close();
            
            if ($emailExists) {
                $err = 'This email is already registered.';
            } else {
                // Check for existing username
                $checkUsername = $mysqli->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
                $checkUsername->bind_param('s', $username);
                $checkUsername->execute();
                $usernameExists = $checkUsername->get_result()->fetch_assoc();
                $checkUsername->close();
                
                if ($usernameExists) {
                    $err = 'This username is already taken.';
                } else {
                    // Insert new user
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $mysqli->prepare("INSERT INTO users (name, email, username, password_hash, status, email_verified, created_at) VALUES (?, ?, ?, ?, 'pending', 0, NOW())");
                    $ins->bind_param('ssss', $name, $email, $username, $password_hash);
                    
                    if ($ins->execute()) {
                        $user_id = $ins->insert_id;
                        $ins->close();
                        
                        // Set session
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['username'] = $name;
                        $_SESSION['email'] = $email;
                        $_SESSION['is_admin'] = 0;
                        $_SESSION['status'] = 'pending';
                        $_SESSION['email_verified'] = 0;
                        $_SESSION['role'] = 'user';
                        
                        // Send OTP email
                        if (function_exists('otp_send_verification_email')) {
                            otp_send_verification_email($user_id, $email, $name);
                        }
                        
                        // Redirect to pending approval
                        header('Location: /pending_approval.php');
                        exit;
                    } else {
                        $err = 'Registration failed. Please try again.';
                        $ins->close();
                    }
                }
            }
        } catch (Exception $e) {
            $err = 'Registration error: ' . $e->getMessage();
        }
    }
}

$hideNav = true; include __DIR__ . '/header.php';
?>

<div style="max-width:500px;margin:50px auto;padding:30px;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,0.1);">
  <h2 style="text-align:center;margin-bottom:30px;color:#333;">Create Account</h2>
  
  <?php if ($err): ?>
    <div style="background:#ffe6e6;color:#cc0000;padding:12px;border-radius:5px;margin-bottom:20px;">
      <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>
  
  <form method="post" style="display:flex;flex-direction:column;gap:15px;">
    <div>
      <label style="display:block;margin-bottom:5px;font-weight:bold;">Full Name</label>
      <input type="text" name="name" value="<?= htmlspecialchars($val['name']) ?>" required
             style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
    </div>
    
    <div>
      <label style="display:block;margin-bottom:5px;font-weight:bold;">Email</label>
      <input type="email" name="email" value="<?= htmlspecialchars($val['email']) ?>" required
             style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
    </div>
    
    <div>
      <label style="display:block;margin-bottom:5px;font-weight:bold;">Username</label>
      <input type="text" name="username" value="<?= htmlspecialchars($val['username']) ?>" required
             style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
    </div>
    
    <div>
      <label style="display:block;margin-bottom:5px;font-weight:bold;">Password</label>
      <input type="password" name="password" required
             style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
    </div>
    
    <div>
      <label style="display:block;margin-bottom:5px;font-weight:bold;">Confirm Password</label>
      <input type="password" name="confirm" required
             style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
    </div>
    
    <div style="display:flex;align-items:flex-start;gap:8px;">
      <input type="checkbox" name="accept_terms" value="1" required style="margin-top:3px;">
      <label style="font-size:14px;color:#666;">
        I agree to the <a href="/disclaimer.php" target="_blank" style="color:#007bff;">Terms & Conditions</a>
      </label>
    </div>
    
    <button type="submit" style="background:#007bff;color:#fff;padding:12px;border:none;border-radius:5px;font-weight:bold;cursor:pointer;">
      Create Account
    </button>
  </form>
  
  <div style="text-align:center;margin-top:20px;color:#666;">
    Already have an account? <a href="/login.php" style="color:#007bff;">Login here</a>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
