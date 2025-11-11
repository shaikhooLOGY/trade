<?php
// register1.php — Simple, clean registration page
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$err = '';
$success = '';

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $accept_terms = isset($_POST['accept_terms']);
    
    // Basic validation
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
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->fetch_assoc()) {
                $err = 'This email is already registered.';
                $stmt->close();
            } else {
                $stmt->close();
                
                // Check for existing username
                $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->fetch_assoc()) {
                    $err = 'This username is already taken.';
                    $stmt->close();
                } else {
                    $stmt->close();
                    
                    // Hash password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert new user with pending status
                    $stmt = $mysqli->prepare("INSERT INTO users (name, email, username, password_hash, status, email_verified, created_at) VALUES (?, ?, ?, ?, 'pending', 0, NOW())");
                    $stmt->bind_param('ssss', $name, $email, $username, $password_hash);
                    
                    if ($stmt->execute()) {
                        $user_id = $stmt->insert_id;
                        $stmt->close();
                        
                        // Set session
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['username'] = $name;
                        $_SESSION['email'] = $email;
                        $_SESSION['is_admin'] = 0;
                        $_SESSION['status'] = 'pending';
                        $_SESSION['email_verified'] = 0;
                        $_SESSION['role'] = 'user';
                        
                        // Generate and send OTP code
                        $otp_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $otp_hash = password_hash($otp_code, PASSWORD_DEFAULT);
                        $expires_at = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
                        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        
                        // Store OTP in database for verification
                        try {
                            $stmt = $mysqli->prepare("INSERT INTO user_otps (user_id, otp_hash, expires_at, created_at, email_sent_at, ip_address) VALUES (?, ?, ?, NOW(), NOW(), ?)");
                            $stmt->bind_param('isss', $user_id, $otp_hash, $expires_at, $ip_address);
                            $stmt->execute();
                            $stmt->close();
                            error_log("register1.php: OTP stored in database for user $user_id");
                        } catch (Exception $e) {
                            error_log("register1.php: Failed to store OTP in database: " . $e->getMessage());
                        }
                        
                        // Store OTP in session for fallback verification
                        $_SESSION['register_otp'] = $otp_code;
                        $_SESSION['register_otp_user_id'] = $user_id;
                        $_SESSION['register_otp_expires'] = time() + 1800; // 30 minutes
                        
                        // Email content
                        $subject = 'Your Shaikhoology Verification Code';
                        $html = '
<div style="font-family:Inter,Roboto,Arial,sans-serif;max-width:560px;margin:0 auto;line-height:1.45">
  <h2 style="margin:0 0 10px">Verify your email address</h2>
  <p>Hi ' . htmlspecialchars($name) . ',</p>
  <p>Your verification code is:</p>
  <div style="background:#f0f9ff;border:1px solid #0ea5e9;border-radius:8px;padding:20px;margin:20px 0;text-align:center">
    <div style="font-size:32px;font-weight:bold;color:#0c4a6e;letter-spacing:4px;">' . $otp_code . '</div>
    <p style="margin:10px 0 0 0;color:#0c4a6e;font-size:14px">Valid for 30 minutes</p>
  </div>
  <p><em>(Yeh code 30 minutes tak valid rahega)</em></p>
  <hr style="border:none;border-top:1px solid #eee;margin:16px 0">
  <small style="color:#6b7280">
    Didn\'t request this? You can ignore this email. 
    (Agar aapne yeh request nahi ki, to is email ko ignore kar dein.)
  </small>
</div>';
                        $text = "Your verification code: $otp_code (Valid for 30 minutes)";

                        // Send email using the working method
                        $sent = sendMail($email, $subject, $html, $text);
                        if ($sent) {
                            error_log("register1.php: OTP email sent successfully for user $user_id ($email)");
                        } else {
                            error_log("register1.php: OTP email failed for user $user_id ($email)");
                        }
                        
                        // Redirect to OTP verification
                        header('Location: /verify_profile.php?email=' . urlencode($email));
                        exit;
                    } else {
                        $err = 'Registration failed. Please try again.';
                        $stmt->close();
                    }
                }
            }
        } catch (Exception $e) {
            $err = 'Registration error: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/header.php';
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
            <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required
                   style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
        </div>
        
        <div>
            <label style="display:block;margin-bottom:5px;font-weight:bold;">Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required
                   style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
        </div>
        
        <div>
            <label style="display:block;margin-bottom:5px;font-weight:bold;">Username</label>
            <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required
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