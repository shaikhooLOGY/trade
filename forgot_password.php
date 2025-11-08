<?php
// forgot_password.php  — copy-paste ready (refresh-persistent UI)
// -------------------------------------------------
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --------- CSRF ----------
if (empty($_SESSION['fp_csrf'])) {
    $_SESSION['fp_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['fp_csrf'];

// --------- Cooldown (1 minute) ----------
if (!isset($_SESSION['fp_next_ok_at'])) $_SESSION['fp_next_ok_at'] = 0;
$COOLDOWN_SECONDS = 1 * 60;     // 1 minute
$TOKEN_LIFETIME   = 60 * 60;     // 1 hour link validity

$err = '';
$ok  = '';
$cooldown_until = (int)$_SESSION['fp_next_ok_at']; // echo to JS

// --------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $token_csrf = $_POST['csrf'] ?? '';

    if (!hash_equals($csrf, $token_csrf)) {
        $err = 'Invalid request. Please reload and try again. (Galat request, page reload karke dubara koshish karein)';
    } elseif (time() < (int)$_SESSION['fp_next_ok_at']) {
        $remain = (int)$_SESSION['fp_next_ok_at'] - time();
        $mins = floor($remain/60);
        $secs = $remain%60;
        $err = "You already requested a reset. Please wait before requesting again. "
             . "(Aapne abhi request ki thi, {$mins}m {$secs}s baad dubara koshish karein.)";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email. (Sahi email daliyé)';
    } else {
        // Neutral success message (no user enumeration)
        $ok = "If this email is registered, we've sent a password reset link. Check your inbox or spam. "
            . "(Agar yeh email registered hai, humne reset link bhej diya hai. Inbox/Spam check karein.) "
            . "You can request a new link after 1 minute. (Naya link 1 minute ke baad hi mang sakte hain.)";

        // Start cooldown
        $_SESSION['fp_next_ok_at'] = time() + $COOLDOWN_SECONDS;
        $cooldown_until = (int)$_SESSION['fp_next_ok_at'];

        // Look up user; only send mail if registered
        $user = null;
        if ($stmt = $mysqli->prepare("SELECT id, email, name FROM users WHERE email = ? LIMIT 1")) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        } else {
            error_log("forgot_password prepare(users) failed: " . $mysqli->error);
        }

        if ($user && !empty($user['id'])) {
            $user_id = (int)$user['id'];

            // Fresh token + hash (reset_password.php checks token_hash)
            try {
                $token = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $token = bin2hex(openssl_random_pseudo_bytes(32));
            }
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', time() + $TOKEN_LIFETIME);

            // Delete old tokens
            if ($del = $mysqli->prepare("DELETE FROM password_resets WHERE user_id = ?")) {
                $del->bind_param('i', $user_id);
                if (!$del->execute()) error_log("forgot_password delete tokens failed: " . $mysqli->error);
                $del->close();
            } else {
                error_log("forgot_password prepare(delete) failed: " . $mysqli->error);
            }

            // Insert new token (token + token_hash)
            if ($ins = $mysqli->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")) {
                $ins->bind_param('isss', $user_id, $token, $token_hash, $expires_at);
                if (!$ins->execute()) error_log("forgot_password insert token failed: " . $mysqli->error);
                $ins->close();
            } else {
                error_log("forgot_password prepare(insert) failed: " . $mysqli->error);
            }

            // Absolute reset link
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
            $reset_link = $scheme . '://' . $host . $base . '/reset_password.php?token=' . urlencode($token);

            // Send email (EN + Hinglish)
            $subject = 'Reset your password — Shaikhoology';
            $html = '
<div style="font-family:Inter,Roboto,Arial,sans-serif;max-width:560px;margin:0 auto;line-height:1.45">
  <h2 style="margin:0 0 10px">Reset your password</h2>
  <p>Click the button below. This link stays valid for <strong>1 hour</strong>.</p>
  <p><em>(Neeche wale button par click karein. Link <strong>1 ghante</strong> tak valid rahega.)</em></p>
  <p style="margin:16px 0">
    <a href="'.$reset_link.'" 
       style="background:#4f46e5;color:#fff;padding:12px 16px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block">
       Reset Password
    </a>
  </p>
  <p style="color:#555">If the button doesn’t work, paste this link:</p>
  <p style="word-break:break-all"><a href="'.$reset_link.'">'.$reset_link.'</a></p>
  <hr style="border:none;border-top:1px solid #eee;margin:16px 0">
  <small style="color:#6b7280">
    Didn’t request this? You can ignore this email. 
    (Agar aapne yeh request nahi ki, to is email ko ignore kar dein.)
  </small>
</div>';
            $text = "Reset your password (valid 1h): $reset_link";

            $sent = sendMail($user['email'], $subject, $html, $text);
            if (!$sent) error_log("forgot_password sendMail failed to {$user['email']}");
        }
    }
}

// ---------- UI ----------
$hideNav = true; include __DIR__ . '/header.php';
?>
<style>
  .fp-card{max-width:820px;margin:32px auto;background:#fff;padding:28px;border-radius:12px;
           box-shadow:0 12px 30px rgba(15,20,40,0.06)}
  .note{color:#666;margin:0 0 16px}
  .alert-ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:6px;margin:12px 0;}
  .alert-err{background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:6px;margin:12px 0;}
  .disabled{opacity:.6;pointer-events:none}
  .zikr{margin-top:16px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px}
  .zikr h4{margin:0 0 8px;font-size:14px}
  .zikr ul{margin:0;padding-left:18px}
  /* circular countdown */
  .clock-wrap{display:flex;align-items:center;gap:14px;margin:12px 0}
  .circle{width:60px;height:60px;transform:rotate(-90deg)}
  .circle circle{fill:none;stroke:#e5e7eb;stroke-width:6}
  .circle .progress{stroke:#4f46e5;stroke-linecap:round;stroke-width:6;stroke-dasharray:188.5;stroke-dashoffset:0;transition:stroke-dashoffset .3s linear}
  .clock-text{font-weight:800}
</style>

<main class="fp-card">
  <h2 style="margin:0 0 6px;color:#1a1a1a">🛟 Forgot your password?</h2>
  <p class="note">Enter your email and we’ll send a password reset link.<br>
  <em>(Email daliyé, reset link aapke email par bheja jayega)</em></p>

  <?php if ($ok): ?>
    <div class="alert-ok" id="okBox">
      <ul style="margin:0;padding-left:18px">
        <li>If this email is registered, we've sent a password reset link. Check your inbox or spam.</li>
        <li><em>(Agar yeh email registered hai, humne reset link bhej diya hai. Inbox/Spam check karein.)</em></li>
        <li>You can request a new link after <strong>1 minute</strong>. <em>(Naya link 1 minute ke baad hi mang sakte hain.)</em></li>
      </ul>
      <div class="clock-wrap" id="cooldownUI">
        <svg class="circle" viewBox="0 0 64 64">
          <circle cx="32" cy="32" r="30"></circle>
          <circle class="progress" cx="32" cy="32" r="30"></circle>
        </svg>
        <div class="clock-text">
          Next request in <span id="clock">01:00</span>
        </div>
      </div>
      <div class="zikr">
        <h4>Tabtak Padhte raho :</h4>
        <ul>
          <li>Darood Shareef: <em>“Allahumma salli ‘ala Muhammad…”</em></li>
          <li>Istighfar: <em>“Astaghfirullah”</em> (33 times)</li>
          <li>Short gratitude: <em>“Alhamdulillah”</em> for today’s wins</li>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="alert-err"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- Hidden success template to show after refresh if cooldown active -->
  <div id="okTemplate" style="display:none">
    <div class="alert-ok" id="okBoxTpl">
      <ul style="margin:0;padding-left:18px">
        <li>If this email is registered, we've sent a password reset link. Check your inbox or spam.</li>
        <li><em>(Agar yeh email registered hai, humne reset link bhej diya hai. Inbox/Spam check karein.)</em></li>
        <li>You can request a new link after <strong>1 minute</strong>. <em>(Naya link 1 minute ke baad hi mang sakte hain.)</em></li>
      </ul>
      <div class="clock-wrap" id="cooldownUITpl">
        <svg class="circle" viewBox="0 0 64 64">
          <circle cx="32" cy="32" r="30"></circle>
          <circle class="progress" cx="32" cy="32" r="30"></circle>
        </svg>
        <div class="clock-text">
          Next request in <span class="clock">01:00</span>
        </div>
      </div>
      <div class="zikr">
        <h4>Tabtak Padhte Raho:</h4>
        <ul>
          <li>Darood Shareef: <em>“Allahumma salli ‘ala Muhammad…”</em></li>
          <li>Istighfar: <em>“Astaghfirullah”</em> (33 times)</li>
          <li>"SubhanAllah" <em>“Alhamdulillah”</em>"AllahuAkbar"</li>
        </ul>
      </div>
    </div>
  </div>

  <form method="post" id="fpForm" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label style="display:block;font-weight:600;margin-bottom:6px">Email</label>
    <input name="email" type="email" required
           style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px;font-size:15px">

    <button id="submitBtn" type="submit"
            style="display:block;width:100%;background:linear-gradient(90deg,#6a3af7,#2d1fb7);color:#fff;padding:12px;border-radius:8px;border:0;font-weight:700;cursor:pointer;margin-top:14px">
      Send reset link
    </button>
  </form>

  <p style="margin-top:18px;font-size:14px;color:#666;text-align:center">
    Remembered it? <a href="login.php" style="color:#4a22c8;font-weight:600;text-decoration:none">Back to login</a>
  </p>
</main>

<script>
// ---------- persistent cooldown (localStorage + server echo) ----------
const COOLDOWN_KEY = 'fp_cooldown_until';
const serverUntil = <?= json_encode($cooldown_until) ?>; // Unix ts or 0
const nowTs = Math.floor(Date.now()/1000);

// Use the later of server or localStorage
let stored = parseInt(localStorage.getItem(COOLDOWN_KEY) || '0', 10);
let until = Math.max(stored, serverUntil);

// If server has newer, persist it
if (serverUntil > stored) {
  localStorage.setItem(COOLDOWN_KEY, String(serverUntil));
  until = serverUntil;
}

const form     = document.getElementById('fpForm');
const submit   = document.getElementById('submitBtn');
const okBox    = document.getElementById('okBox');            // may exist (after POST)
const okTpl    = document.getElementById('okTemplate');       // hidden template

const COOLDOWN_SECONDS = 60; // 1 min
const CIRC = 188.5;           // circumference for r=30

function setDisabled(d){
  if (d) { form.classList.add('disabled'); submit.disabled = true; }
  else   { form.classList.remove('disabled'); submit.disabled = false; }
}

function startClock(container){
  const clockSpan = container.querySelector('#clock') || container.querySelector('.clock');
  const prog = container.querySelector('.progress');

  function tick(){
    const now = Math.floor(Date.now()/1000);
    const remain = until - now;
    if (remain > 0) {
      const m = String(Math.floor(remain/60)).padStart(2,'0');
      const s = String(remain%60).padStart(2,'0');
      if (clockSpan) clockSpan.textContent = `${m}:${s}`;
      const used = COOLDOWN_SECONDS - Math.min(COOLDOWN_SECONDS, remain);
      if (prog) prog.style.strokeDashoffset = String(CIRC * (used / COOLDOWN_SECONDS));
      requestAnimationFrame(tick);
    } else {
      localStorage.removeItem(COOLDOWN_KEY);
      setDisabled(false);
      // hide success box when done (optional)
      // container.parentElement?.removeChild(container);
    }
  }
  tick();
}

// If cooldown active and we DON'T have okBox (fresh page load), inject template
if (until > Math.floor(Date.now()/1000)) {
  setDisabled(true);

  if (!okBox && okTpl) {
    // Insert the template block before the form
    const clone = okTpl.firstElementChild.cloneNode(true);
    form.parentNode.insertBefore(clone, form);
    startClock(clone);
  } else if (okBox) {
    startClock(okBox);
  }
} else {
  setDisabled(false);
}

// On submit, pre-set client cooldown (UX)
form?.addEventListener('submit', () => {
  const u = Math.floor(Date.now()/1000) + COOLDOWN_SECONDS;
  localStorage.setItem(COOLDOWN_KEY, String(u));
});
</script>

<?php include __DIR__ . '/footer.php'; ?>