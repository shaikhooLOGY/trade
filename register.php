<?php
// register.php — send OTP, but DO NOT create user beyond 'unverified' until email is verified
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
if (session_status() === PHP_SESSION_NONE) session_start();

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
$val = ['name'=>'','email'=>''];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim($_POST['name'] ?? '');
  $email= trim($_POST['email'] ?? '');
  $pass = (string)($_POST['password'] ?? '');
  $conf = (string)($_POST['confirm'] ?? '');
  $val['name']=$name; $val['email']=$email;

  if ($name===''||$email===''||$pass===''||$conf==='') {
    $err = 'All fields are required.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Invalid email.';
  } elseif (strlen($pass)<6) {
    $err = 'Password must be at least 6 characters.';
  } elseif ($pass!==$conf) {
    $err = 'Passwords do not match.';
  } else {
    // Refuse duplicate email (whether unverified/verified)
    if ($st=$mysqli->prepare("SELECT id, status, email_verified FROM users WHERE email=? LIMIT 1")){
      $st->bind_param('s',$email); $st->execute();
      $ex = $st->get_result()->fetch_assoc();
      $st->close();
      if ($ex) {
        $err = 'Email already registered. Please login or reset password.';
      } else {
        // Create minimal user as 'unverified'
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $status = 'unverified';
        $created_at = date('Y-m-d H:i:s');
        $initial_capital = 100000.00;

        $ins = $mysqli->prepare("
          INSERT INTO users (name,email,password_hash,status,email_verified,verification_attempts,created_at,profile_status)
          VALUES (?,?,?,?,0,0,?, 'pending')
        ");
        if (!$ins){ $err = 'DB error: '.$mysqli->error; }
        else{
          $ins->bind_param('sssss',$name,$email,$hash,$status,$created_at);
          if ($ins->execute()){
            $uid = $ins->insert_id;
            $ins->close();

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

            // Make OTP
            $otp = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
            $exp = date('Y-m-d H:i:s', time()+ 15*60); // 15 min validity

            if ($up=$mysqli->prepare("UPDATE users SET otp_code=?, otp_expires=? WHERE id=?")){
              $up->bind_param('ssi',$otp,$exp,$uid);
              $up->execute(); $up->close();
            }

            // Email OTP (EN + Hinglish)
            $subject = 'Your Shaikhoology verification code';
            $html = '
              <div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;line-height:1.45">
                <h2 style="margin:0 0 8px">Verify your email</h2>
                <p>Use this 6-digit code to verify your account (valid for 15 minutes):</p>
                <div style="font-size:28px;font-weight:800;letter-spacing:4px;background:#f3f4f6;padding:10px 14px;border-radius:10px;display:inline-block">'.$otp.'</div>
                <p style="margin-top:12px"><em>(Is 6 digit code se verify karein — 15 minutes tak valid hai.)</em></p>
                <hr style="border:none;border-top:1px solid #eee;margin:16px 0">
                <small>If you didn’t request this, ignore this email.</small>
              </div>';
            // Best-effort; even if send fails, keep UX moving to verification page
            @sendMail($email,$subject,$html);

            // to verify page
            header('Location: /verify_profile.php?email='.urlencode($email).'&otp_sent=1');
            exit;
          } else {
            $err='Insert failed: '.$ins->error; $ins->close();
          }
        }
      }
    } else { $err='DB error: '.$mysqli->error; }
  }
}

$hideNav = true; include __DIR__ . '/header.php';
?>
<div style="max-width:720px;margin:28px auto;padding:26px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(16,24,40,.06)">
  <h2 style="margin:0 0 6px;color:#222">📝 Create account</h2>
  <?php if($err): ?>
    <div style="background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:8px;margin:12px 0"><?=h($err)?></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <label style="display:block;margin-top:10px;font-weight:600">Full name</label>
    <input name="name" value="<?=h($val['name'])?>" required
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    <label style="display:block;margin-top:12px;font-weight:600">Email</label>
    <input name="email" type="email" value="<?=h($val['email'])?>" required
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    <label style="display:block;margin-top:12px;font-weight:600">Password</label>
    <input name="password" type="password" minlength="6" required
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    <label style="display:block;margin-top:12px;font-weight:600">Confirm password</label>
    <input name="confirm" type="password" minlength="6" required
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px">
    <button type="submit"
            style="margin-top:16px;background:linear-gradient(90deg,#5f27cd,#341f97);color:#fff;padding:12px;border-radius:8px;border:0;font-weight:700;width:100%;cursor:pointer">
      Register & Verify
    </button>
  </form>
  <div style="text-align:center;margin-top:16px;color:#666">
    Already have an account? <a href="/login.php" style="color:#5f27cd;font-weight:600">Login</a>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
