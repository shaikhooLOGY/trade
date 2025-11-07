<?php
// Universal Profile Page - API Integrated Version
// Session and security handling centralized via bootstrap.php
require_once __DIR__ . '/includes/bootstrap.php';

// Auto-detect correct base path
$base_path = __DIR__;
$search_paths = [
    __DIR__,
    dirname(__DIR__),
    getcwd(),
    dirname(getcwd())
];

$config_found = false;
foreach ($search_paths as $path) {
    if (file_exists($path . '/config.php')) {
        $base_path = $path;
        $config_found = true;
        break;
    }
}

if (!$config_found) {
    die('❌ Error: Could not find config.php in any directory.
         <br>Searched paths: ' . implode(', ', $search_paths) . '
         <br>Current dir: ' . __DIR__ . '
         <br>Parent dir: ' . dirname(__DIR__));
}

// Load required files
try {
    // Load guard.php with protection
    if (file_exists($base_path . '/guard.php')) {
        $_SERVER['SCRIPT_NAME'] = 'profile.php'; // Mock for guard
        require_once $base_path . '/guard.php';
    }
    
    // Load functions if available
    if (file_exists($base_path . '/includes/functions.php')) {
        require_once $base_path . '/includes/functions.php';
    }
    
} catch (Exception $e) {
    die('❌ Configuration Error: ' . $e->getMessage() .
        '<br>Base path: ' . $base_path);
}

// Check login
if (empty($_SESSION['user_id'])) {
    header('Location: ' . ($base_path . '/login.php'));
    exit;
}

$uid = (int)$_SESSION['user_id'];

// 🔁 API Integration: Get CSRF token for API calls
$csrf_token = $_SESSION['csrf_token'] ?? '';

// Load profile fields
$profile_fields = [];
$profile_fields_paths = [
    $base_path . '/profile_fields.php',
    $base_path . '/includes/profile_fields.php',
    __DIR__ . '/profile_fields.php'
];

foreach ($profile_fields_paths as $path) {
    if (file_exists($path)) {
        try {
            $profile_fields = require $path;
            break;
        } catch (Exception $e) {
            continue;
        }
    }
}

if (!is_array($profile_fields)) {
    $profile_fields = [];
}

// 🔁 API Integration: Get user data from API
$user = [];
$displayName = 'User';
$email = '';
$roleText = 'Member';
$memberSince = 'Unknown';
$phone = '';
$timezone = '';
$trading_capital = 0.0;
$funds_available = 0.0;

try {
    // Make API call to get profile data
    $api_url = $base_path . '/api/profile/me.php';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'Content-Type: application/json',
                'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? ''
            ]
        ]
    ]);
    
    $response = @file_get_contents($api_url, false, $context);
    
    if ($response !== false) {
        $api_data = json_decode($response, true);
        
        if ($api_data && $api_data['success'] ?? false) {
            $user = $api_data['data'] ?? [];
            
            if ($user) {
                $displayName = $user['name'] ?? 'User';
                $email = $user['email'] ?? '';
                $role = strtolower((string)($user['role'] ?? 'user'));
                $roleText = $role==='superadmin' ? 'Superadmin' : ($role==='admin' ? 'Admin' : 'Member');
                $memberSince = !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : 'Unknown';
                $phone = $user['phone'] ?? '';
                $timezone = $user['timezone'] ?? '';
                $trading_capital = (float)($user['trading_capital'] ?? 0.0);
                $funds_available = (float)($user['funds_available'] ?? 0.0);
            }
        }
    }
} catch (Exception $e) {
    // Fallback to session data
    $displayName = $_SESSION['username'] ?? $_SESSION['name'] ?? 'User';
    $email = $_SESSION['email'] ?? 'user@example.com';
    $roleText = !empty($_SESSION['is_admin']) ? 'Admin' : 'Member';
}

// Generate initials
$initials = '';
foreach (preg_split('/\s+/', $displayName) as $word) {
    if ($word !== '') { 
        $initials .= mb_strtoupper(mb_substr($word,0,1)); 
    }
}
$initials = mb_substr($initials,0,2);

// 🔁 API Integration: Handle form submission via JavaScript
// Removed direct database processing - now handled by profile_update.php API call
$toast = ['type'=>null,'text'=>null];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Redirect to handle via API (progressive enhancement)
    if (!empty($_POST['name'])) {
        header('Location: profile.php?updated=1');
        exit;
    }
}

// Safe include function
function safe_include($file) {
    if (file_exists($file)) {
        include $file;
        return true;
    }
    return false;
}

// Load templates
$header_path = $base_path . '/header.php';
$footer_path = $base_path . '/footer.php';

if (!safe_include($header_path)) {
    // Minimal header if template doesn't exist
    echo '<!DOCTYPE html><html><head><title>Profile</title><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,sans-serif;margin:0;background:#f6f7fb;}</style></head><body>';
}

?>

<style>
:root{
  --bg:#f6f7fb; --card:#fff; --muted:#6b7280;
  --ink:#111; --brand:#4f46e5; --green:#16a34a; --red:#ef4444; --amber:#f59e0b;
}
.container{max-width:980px;margin:24px auto;padding:0 16px}
.grid{display:grid;grid-template-columns:1fr .8fr;gap:20px}
@media (max-width:900px){ .grid{grid-template-columns:1fr} }
.card{background:var(--card);border-radius:14px;box-shadow:0 10px 28px rgba(22,28,45,.06);padding:24px}
.title{margin:0 0 16px 0;font-size:20px;font-weight:800}
.row{display:flex;gap:12px;flex-wrap:wrap}
.avatar{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#c7d2fe,#a78bfa);color:#1e1b4b;font-size:32px;font-weight:900;margin-right:16px}
.field{margin-bottom:16px}
.field label{display:block;font-size:14px;color:#374151;margin-bottom:8px;font-weight:600}
.input{width:100%;padding:12px 16px;border:1px solid #d1d5db;border-radius:10px;background:#fff;font-size:14px;box-sizing:border-box}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:10px;border:1px solid var(--brand);background:var(--brand);color:#fff;font-weight:700;text-decoration:none;cursor:pointer}
.btn.secondary{background:#eef2ff;color:#3730a3;border-color:#eef2ff}
.badge{display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;font-weight:700;font-size:12px}
.badge.role{background:#ede9fe;color:#4c1d95}
.badge.amber{background:#fef3c7;color:#7c2d12}
.toast{position:fixed;top:20px;right:20px;padding:12px 16px;border-radius:10px;color:white;font-weight:600;z-index:1000;animation:slideIn 0.3s ease}
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
          <div style="color:#6b7280;font-size:14px;"><?php echo htmlspecialchars($email); ?></div>
          <div class="row" style="margin-top:8px">
            <span class="badge role"><?php echo htmlspecialchars($roleText); ?></span>
            <span class="badge amber">Since <?php echo htmlspecialchars($memberSince); ?></span>
          </div>
        </div>
      </div>

      <form id="profileForm" method="post">
        <h3 class="title">Account Information</h3>
        
        <div class="field">
          <label for="name">Full name</label>
          <input class="input" type="text" id="name" name="name"
                 value="<?php echo htmlspecialchars($displayName); ?>" required>
        </div>

        <div class="field">
          <label for="phone">Phone</label>
          <input class="input" type="text" id="phone" name="phone"
                 value="<?php echo htmlspecialchars($phone); ?>"
                 placeholder="+1234567890">
        </div>

        <div class="field">
          <label for="timezone">Timezone</label>
          <input class="input" type="text" id="timezone" name="timezone"
                 value="<?php echo htmlspecialchars($timezone); ?>"
                 placeholder="America/New_York">
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
          <button class="btn" type="submit" id="saveBtn">💾 Save Changes</button>
          <a class="btn secondary" href="<?php echo $base_path; ?>/dashboard.php">← Back to Dashboard</a>
        </div>
        <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
      </form>
    </div>

    <!-- Right: Info Panel -->
    <div class="card">
      <h3 class="title">Account Status</h3>
      <div style="margin-bottom:20px">
        <strong>Profile ID:</strong> <?php echo htmlspecialchars($uid); ?><br>
        <strong>Email Verified:</strong> <?php echo !empty($_SESSION['email_verified']) ? 'Yes' : 'No'; ?><br>
        <strong>Account Status:</strong> <?php echo htmlspecialchars($_SESSION['status'] ?? 'Unknown'); ?><br>
        <strong>Role:</strong> <?php echo htmlspecialchars($roleText); ?><br>
        <strong>Server Config:</strong> Base path: <?php echo htmlspecialchars($base_path); ?>
      </div>

      <?php if (!empty($toast['type']) && !empty($toast['text'])): ?>
      <div class="toast <?php echo htmlspecialchars($toast['type']); ?>">
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

<script>
// 🔁 API Integration: Handle profile form submission via API
document.getElementById('profileForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const saveBtn = document.getElementById('saveBtn');
    const originalText = saveBtn.textContent;
    saveBtn.textContent = '⏳ Saving...';
    saveBtn.disabled = true;
    
    try {
        // Collect form data
        const formData = {
            name: document.getElementById('name').value.trim(),
            phone: document.getElementById('phone').value.trim(),
            timezone: document.getElementById('timezone').value.trim()
        };
        
        // Get CSRF token
        const csrfToken = document.getElementById('csrf_token').value;
        
        // Make API call
        const response = await fetch('./api/profile/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(formData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Show success message
            showToast('success', 'Profile updated successfully');
            
            // Update display name if changed
            if (formData.name && formData.name !== '<?php echo htmlspecialchars($displayName); ?>') {
                document.querySelector('.avatar').textContent = formData.name.split(' ').map(word => word[0]).join('').toUpperCase().slice(0, 2);
                document.querySelector('[style*="font-size:24px"]').textContent = formData.name;
            }
        } else {
            // Show error message
            const errorMsg = data.message || 'Failed to update profile';
            showToast('error', errorMsg);
        }
    } catch (error) {
        console.error('Profile update error:', error);
        showToast('error', 'Network error occurred');
    } finally {
        saveBtn.textContent = originalText;
        saveBtn.disabled = false;
    }
});

function showToast(type, message) {
    // Remove existing toasts
    const existingToast = document.querySelector('.toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    // Create new toast
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `${type === 'success' ? '✅' : '⚠️'} ${message}`;
    document.body.appendChild(toast);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 3000);
}
</script>

<?php
if (!safe_include($footer_path)) {
    echo '</body></html>';
}
?>