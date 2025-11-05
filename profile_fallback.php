<?php
// Fallback Profile Page - More robust version for live server issues
// This version handles server differences gracefully

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set safe defaults
$profile_fields = [];
$user = [];
$displayName = 'User';
$email = '';
$roleText = 'Member';
$memberSince = 'Unknown';

// Try to load required files with error handling
try {
    // Load environment
    if (file_exists(__DIR__ . '/includes/env.php')) {
        require_once __DIR__ . '/includes/env.php';
    }
    
    // Load config
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } else {
        throw new Exception('config.php not found');
    }
    
    // Load functions (optional)
    if (file_exists(__DIR__ . '/includes/functions.php')) {
        require_once __DIR__ . '/includes/functions.php';
    }
    
} catch (Exception $e) {
    die('Configuration error: ' . $e->getMessage());
}

// Check login status
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];

// Load profile fields safely
try {
    if (file_exists(__DIR__ . '/profile_fields.php')) {
        $profile_fields = require __DIR__ . '/profile_fields.php';
        if (!is_array($profile_fields)) {
            $profile_fields = [];
        }
    }
} catch (Exception $e) {
    $profile_fields = [];
}

// Try to get user data with fallback
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        // Simple query to get basic user info
        $stmt = $mysqli->prepare("SELECT id, email, name, role, created_at FROM users WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : [];
        $stmt->close();
        
        if ($user) {
            $displayName = $user['name'] ?: 'User';
            $email = $user['email'] ?: '';
            $role = strtolower((string)($user['role'] ?? 'user'));
            $roleText = $role==='superadmin' ? 'Superadmin' : ($role==='admin' ? 'Admin' : 'Member');
            $memberSince = $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : 'Unknown';
        }
    }
} catch (Exception $e) {
    // Use session data as fallback
    $displayName = $_SESSION['username'] ?? $_SESSION['name'] ?? 'User';
    $email = $_SESSION['email'] ?? 'user@example.com';
    $roleText = !empty($_SESSION['is_admin']) ? 'Admin' : 'Member';
}

// Generate initials for avatar
$initials = '';
foreach (preg_split('/\s+/', $displayName) as $word) {
    if ($word !== '') { 
        $initials .= mb_strtoupper(mb_substr($word,0,1)); 
    }
}
$initials = mb_substr($initials,0,2);

// Handle form submission
$toast = ['type'=>null,'text'=>null];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    
    if ($name !== '' && !empty($user['id'])) {
        try {
            $stmt = $mysqli->prepare("UPDATE users SET name=? WHERE id=?");
            $stmt->bind_param('si', $name, $user['id']);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                $toast = ['type'=>'success','text'=>'Profile updated successfully.'];
                $displayName = $name;
                $user['name'] = $name;
            } else {
                $toast = ['type'=>'error','text'=>'Could not update profile.'];
            }
        } catch (Exception $e) {
            $toast = ['type'=>'error','text'=>'Database error: ' . $e->getMessage()];
        }
    }
}

// Load header and footer safely
function safe_include($file) {
    if (file_exists($file)) {
        include $file;
        return true;
    }
    return false;
}

if (!safe_include(__DIR__ . '/header.php')) {
    echo '<!DOCTYPE html><html><head><title>Profile</title></head><body>';
}
?>

<style>
  :root{
    --bg:#f6f7fb; --card:#fff; --muted:#6b7280;
    --ink:#111; --brand:#4f46e5; --green:#16a34a; --red:#ef4444; --amber:#f59e0b;
    --ring: rgba(79,70,229,.15);
  }
  body{background:var(--bg); margin:0; font-family:Inter,system-ui,Arial,sans-serif;}
  .container{max-width:980px;margin:24px auto;padding:0 16px}
  .grid{display:grid;grid-template-columns:1fr .8fr;gap:20px}
  @media (max-width:900px){ .grid{grid-template-columns:1fr} }

  .card{background:var(--card);border-radius:14px;box-shadow:0 10px 28px rgba(22,28,45,.06);padding:24px}
  .title{margin:0 0 16px 0;font-size:20px;font-weight:800}
  .muted{color:var(--muted)}
  .row{display:flex;gap:12px;flex-wrap:wrap}
  .avatar{
    width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#c7d2fe,#a78bfa); color:#1e1b4b; font-size:32px; font-weight:900;
    margin-right:16px
  }
  .field{margin-bottom:16px}
  .field label{display:block;font-size:14px;color:#374151;margin-bottom:8px;font-weight:600}
  .input{
    width:100%;padding:12px 16px;border:1px solid #d1d5db;border-radius:10px;
    background:#fff;font-size:14px;box-sizing:border-box
  }
  .input:focus{outline:0;box-shadow:0 0 0 3px var(--ring);border-color:#c7d2fe}
  .btn{
    display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:10px;
    border:1px solid var(--brand); background:var(--brand); color:#fff; font-weight:700; text-decoration:none;
    cursor:pointer
  }
  .btn.secondary{background:#eef2ff;color:#3730a3;border-color:#eef2ff}
  .badge{
    display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;font-weight:700;font-size:12px
  }
  .badge.role{background:#ede9fe;color:#4c1d95}
  .badge.amber{background:#fef3c7;color:#7c2d12}
  .toast{
    position:fixed;top:20px;right:20px;padding:12px 16px;border-radius:10px;color:white;font-weight:600;
    z-index:1000;animation:slideIn 0.3s ease
  }
  .toast.success{background:var(--green)}
  .toast.error{background:var(--red)}
  @keyframes slideIn { from{transform:translateX(100%);} to{transform:translateX(0);} }
</style>

<div class="container">
  <h1 style="margin:0 0 24px 0;color:#111;">Profile</h1>

  <div class="grid">
    <!-- Left: Account -->
    <div class="card">
      <div class="row" style="align-items:center;margin-bottom:24px">
        <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
        <div>
          <div style="font-size:24px;font-weight:900;color:#111;"><?php echo htmlspecialchars($displayName); ?></div>
          <div class="muted" style="font-size:14px;"><?php echo htmlspecialchars($email); ?></div>
          <div class="row" style="margin-top:8px">
            <span class="badge role"><?php echo htmlspecialchars($roleText); ?></span>
            <span class="badge amber">Since <?php echo htmlspecialchars($memberSince); ?></span>
          </div>
        </div>
      </div>

      <form method="post" action="">
        <h3 class="title">Account Information</h3>
        
        <div class="field">
          <label for="name">Full name</label>
          <input class="input" type="text" id="name" name="name" 
                 value="<?php echo htmlspecialchars($displayName); ?>" required>
        </div>

        <div class="field">
          <label>Email (read-only)</label>
          <input class="input" type="email" value="<?php echo htmlspecialchars($email); ?>" readonly>
        </div>

        <?php if (!empty($profile_fields)): ?>
        <h3 class="title" style="margin-top:24px">Profile Details</h3>
        <?php foreach ($profile_fields as $field => $config): ?>
          <div class="field">
            <label for="<?php echo htmlspecialchars($field); ?>">
              <?php echo htmlspecialchars($config['label']); ?>
              <?php if (!empty($config['required'])): ?><span style="color:red">*</span><?php endif; ?>
            </label>
            <input class="input" type="<?php echo htmlspecialchars($config['type'] ?? 'text'); ?>"
                   id="<?php echo htmlspecialchars($field); ?>" name="<?php echo htmlspecialchars($field); ?>"
                   value="<?php echo htmlspecialchars($user[$field] ?? ''); ?>"
                   <?php if (!empty($config['required'])) echo 'required'; ?>
                   placeholder="<?php echo htmlspecialchars($config['placeholder'] ?? ''); ?>">
          </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <div class="row" style="margin-top:24px">
          <button class="btn" type="submit">💾 Save Changes</button>
          <a class="btn secondary" href="dashboard.php">← Back to Dashboard</a>
        </div>
      </form>
    </div>

    <!-- Right: Info Panel -->
    <div class="card">
      <h3 class="title">Account Status</h3>
      <div style="margin-bottom:20px">
        <strong>Profile ID:</strong> <?php echo htmlspecialchars($uid); ?><br>
        <strong>Email Verified:</strong> <?php echo !empty($_SESSION['email_verified']) ? 'Yes' : 'No'; ?><br>
        <strong>Account Status:</strong> <?php echo htmlspecialchars($_SESSION['status'] ?? 'Unknown'); ?><br>
        <strong>Role:</strong> <?php echo htmlspecialchars($roleText); ?>
      </div>

      <?php if (!empty($toast['type'])): ?>
      <div class="toast <?php echo $toast['type']; ?>">
        <?php echo $toast['type'] === 'success' ? '✅' : '⚠️'; ?>
        <?php echo htmlspecialchars($toast['text']); ?>
      </div>
      <script>
        setTimeout(() => {
          const toast = document.querySelector('.toast');
          if (toast) toast.remove();
        }, 3000);
      </script>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
if (!safe_include(__DIR__ . '/footer.php')) {
    echo '</body></html>';
}
?>