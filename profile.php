<?php
// Enhanced Profile Page - Works with Profile Schema Manager
// Supports dynamic sections and all field types with real-time schema sync

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    die('❌ Error: Could not find config.php');
}

// Load required files
try {
    require_once $base_path . '/config.php';
    
    if (file_exists($base_path . '/guard.php')) {
        $_SERVER['SCRIPT_NAME'] = 'profile.php';
        require_once $base_path . '/guard.php';
    }
    
    if (file_exists($base_path . '/includes/functions.php')) {
        require_once $base_path . '/includes/functions.php';
    }
    
} catch (Exception $e) {
    die('❌ Configuration Error: ' . $e->getMessage());
}

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (empty($_SESSION['user_id'])) {
    header('Location: ' . ($base_path . '/login.php'));
    exit;
}

$uid = (int)$_SESSION['user_id'];

// Load profile fields with sections
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

// Get user data
$user = [];
$displayName = 'User';
$email = '';
$roleText = 'Member';
$memberSince = 'Unknown';

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        // Get all columns from users table
        $columns = ['id', 'email'];
        $result = $mysqli->query("SHOW COLUMNS FROM users");
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        $columns = array_unique($columns);
        
        $sql = "SELECT " . implode(', ', $columns) . " FROM users WHERE id=? LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        
        if ($user) {
            $displayName = $user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User';
            $email = $user['email'] ?? '';
            $role = strtolower((string)($user['role'] ?? 'user'));
            $roleText = $role==='superadmin' ? 'Superadmin' : ($role==='admin' ? 'Admin' : 'Member');
            $memberSince = !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : 'Unknown';
        }
    }
} catch (Exception $e) {
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

// Handle form submission
$toast = ['type'=>null,'text'=>null];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $updates = [];
    $types = '';
    $values = [];
    
    // Process all sections and fields
    foreach ($profile_fields as $section_key => $section) {
        if (!isset($section['fields'])) continue;
        
        foreach ($section['fields'] as $field_key => $field_config) {
            $value = $_POST[$field_key] ?? '';
            
            // Handle different field types
            if ($field_config['type'] === 'checkbox_group') {
                // Checkbox groups are stored as comma-separated values
                $value = isset($_POST[$field_key]) && is_array($_POST[$field_key]) 
                    ? implode(',', $_POST[$field_key]) 
                    : '';
            }
            
            // Validation
            if (!empty($field_config['required']) && empty($value)) {
                $errors[] = $field_config['label'] . ' is required';
                continue;
            }
            
            // Add to update query
            $updates[] = "`$field_key` = ?";
            $types .= 's';
            $values[] = $value;
        }
    }
    
    if (empty($errors)) {
        try {
            // Build and execute update query
            if (!empty($updates)) {
                $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                $types .= 'i';
                $values[] = $uid;
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param($types, ...$values);
                
                if ($stmt->execute()) {
                    $toast = ['type'=>'success','text'=>'✅ Profile updated successfully!'];
                    
                    // Reload user data
                    $columns = ['id', 'email'];
                    $result = $mysqli->query("SHOW COLUMNS FROM users");
                    while ($row = $result->fetch_assoc()) {
                        $columns[] = $row['Field'];
                    }
                    $columns = array_unique($columns);
                    
                    $sql = "SELECT " . implode(', ', $columns) . " FROM users WHERE id=? LIMIT 1";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $user = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                } else {
                    $toast = ['type'=>'error','text'=>'❌ Could not update profile'];
                }
            }
        } catch (Exception $e) {
            $toast = ['type'=>'error','text'=>'❌ Database error: ' . $e->getMessage()];
        }
    } else {
        $toast = ['type'=>'error','text'=>'❌ ' . implode(', ', $errors)];
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
    echo '<!DOCTYPE html><html><head><title>Profile</title><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,sans-serif;margin:0;background:#f6f7fb;}</style></head><body>';
}

?>

<style>
:root{
  --bg:#f6f7fb; --card:#fff; --muted:#6b7280;
  --ink:#111; --brand:#4f46e5; --green:#16a34a; --red:#ef4444; --amber:#f59e0b;
}
body{background:var(--bg);font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif}
.container{max-width:1200px;margin:24px auto;padding:0 16px}
.header-section{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:30px;border-radius:14px;margin-bottom:24px;box-shadow:0 10px 28px rgba(102,126,234,0.3)}
.card{background:var(--card);border-radius:14px;box-shadow:0 10px 28px rgba(22,28,45,.06);padding:24px;margin-bottom:20px}
.section-title{font-size:20px;font-weight:800;margin:0 0 8px 0;color:#111;display:flex;align-items:center;gap:10px}
.section-desc{color:#6b7280;font-size:14px;margin:0 0 20px 0}
.field{margin-bottom:20px}
.field label{display:block;font-size:14px;color:#374151;margin-bottom:8px;font-weight:600}
.field label .required{color:#ef4444;margin-left:4px}
.field .help-text{font-size:12px;color:#6b7280;margin-top:4px}
.input,.textarea,.select{width:100%;padding:12px 16px;border:1px solid #d1d5db;border-radius:10px;background:#fff;font-size:14px;box-sizing:border-box;font-family:inherit}
.textarea{min-height:100px;resize:vertical}
.input:focus,.textarea:focus,.select:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,0.1)}
.radio-group,.checkbox-group{display:flex;flex-direction:column;gap:10px}
.radio-item,.checkbox-item{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;transition:all 0.2s}
.radio-item:hover,.checkbox-item:hover{background:#f9fafb;border-color:#4f46e5}
.radio-item input,.checkbox-item input{cursor:pointer}
.range-container{display:flex;align-items:center;gap:15px}
.range-input{flex:1}
.range-value{min-width:40px;text-align:center;font-weight:700;color:#4f46e5;font-size:16px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:10px;border:none;background:var(--brand);color:#fff;font-weight:700;text-decoration:none;cursor:pointer;transition:all 0.3s}
.btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(79,70,229,0.3)}
.btn.secondary{background:#eef2ff;color:#3730a3}
.btn.secondary:hover{background:#e0e7ff}
.avatar{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#c7d2fe,#a78bfa);color:#1e1b4b;font-size:32px;font-weight:900}
.badge{display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;font-weight:700;font-size:12px;margin:4px}
.badge.role{background:#ede9fe;color:#4c1d95}
.badge.amber{background:#fef3c7;color:#7c2d12}
.toast{position:fixed;top:20px;right:20px;padding:16px 20px;border-radius:10px;color:white;font-weight:600;z-index:1000;animation:slideIn 0.3s ease;box-shadow:0 10px 28px rgba(0,0,0,0.2)}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
.profile-header{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.profile-info{flex:1}
@media (max-width:768px){.profile-header{flex-direction:column;text-align:center}}
</style>

<div class="container">
    <!-- Header Section -->
    <div class="header-section">
        <div class="profile-header">
            <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h1 style="margin:0 0 8px 0;font-size:32px;font-weight:900"><?php echo htmlspecialchars($displayName); ?></h1>
                <div style="font-size:16px;opacity:0.9;margin-bottom:8px"><?php echo htmlspecialchars($email); ?></div>
                <div>
                    <span class="badge role"><?php echo htmlspecialchars($roleText); ?></span>
                    <span class="badge amber">Member since <?php echo htmlspecialchars($memberSince); ?></span>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="">
        <?php if (!empty($profile_fields)): ?>
            <?php foreach ($profile_fields as $section_key => $section): ?>
                <div class="card">
                    <h2 class="section-title">
                        <?php 
                        // Section icons
                        $icons = [
                            'personal_info' => '👤',
                            'trading_experience' => '📈',
                            'investment_goals' => '🎯',
                            'financial_info' => '💰',
                            'psychology_assessment' => '🧠',
                            'why_join' => '💡',
                            'references' => '📝'
                        ];
                        echo $icons[$section_key] ?? '📋';
                        ?>
                        <?php echo htmlspecialchars($section['title']); ?>
                    </h2>
                    <?php if (!empty($section['description'])): ?>
                        <p class="section-desc"><?php echo htmlspecialchars($section['description']); ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($section['fields'])): ?>
                        <?php foreach ($section['fields'] as $field_key => $field_config): ?>
                            <div class="field">
                                <label for="<?php echo htmlspecialchars($field_key); ?>">
                                    <?php echo htmlspecialchars($field_config['label']); ?>
                                    <?php if (!empty($field_config['required'])): ?>
                                        <span class="required">*</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php
                                $field_type = $field_config['type'] ?? 'text';
                                $field_value = $user[$field_key] ?? '';
                                $required = !empty($field_config['required']) ? 'required' : '';
                                $placeholder = htmlspecialchars($field_config['placeholder'] ?? '');
                                
                                switch ($field_type):
                                    case 'textarea':
                                ?>
                                    <textarea class="textarea" 
                                              id="<?php echo htmlspecialchars($field_key); ?>" 
                                              name="<?php echo htmlspecialchars($field_key); ?>"
                                              placeholder="<?php echo $placeholder; ?>"
                                              <?php echo $required; ?>><?php echo htmlspecialchars($field_value); ?></textarea>
                                <?php
                                        break;
                                    
                                    case 'select':
                                ?>
                                    <select class="select" 
                                            id="<?php echo htmlspecialchars($field_key); ?>" 
                                            name="<?php echo htmlspecialchars($field_key); ?>"
                                            <?php echo $required; ?>>
                                        <?php foreach ($field_config['options'] as $opt_value => $opt_label): ?>
                                            <option value="<?php echo htmlspecialchars($opt_value); ?>"
                                                    <?php echo ($field_value == $opt_value) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($opt_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php
                                        break;
                                    
                                    case 'radio':
                                ?>
                                    <div class="radio-group">
                                        <?php foreach ($field_config['options'] as $opt_value => $opt_label): ?>
                                            <label class="radio-item">
                                                <input type="radio" 
                                                       name="<?php echo htmlspecialchars($field_key); ?>" 
                                                       value="<?php echo htmlspecialchars($opt_value); ?>"
                                                       <?php echo ($field_value == $opt_value) ? 'checked' : ''; ?>
                                                       <?php echo $required; ?>>
                                                <span><?php echo htmlspecialchars($opt_label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php
                                        break;
                                    
                                    case 'checkbox_group':
                                        $selected_values = !empty($field_value) ? explode(',', $field_value) : [];
                                ?>
                                    <div class="checkbox-group">
                                        <?php foreach ($field_config['options'] as $opt_value => $opt_label): ?>
                                            <label class="checkbox-item">
                                                <input type="checkbox" 
                                                       name="<?php echo htmlspecialchars($field_key); ?>[]" 
                                                       value="<?php echo htmlspecialchars($opt_value); ?>"
                                                       <?php echo in_array($opt_value, $selected_values) ? 'checked' : ''; ?>>
                                                <span><?php echo htmlspecialchars($opt_label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php
                                        break;
                                    
                                    case 'range':
                                        $min = $field_config['min'] ?? 1;
                                        $max = $field_config['max'] ?? 10;
                                        $step = $field_config['step'] ?? 1;
                                        $value = $field_value ?: $min;
                                ?>
                                    <div class="range-container">
                                        <input type="range" 
                                               class="range-input" 
                                               id="<?php echo htmlspecialchars($field_key); ?>" 
                                               name="<?php echo htmlspecialchars($field_key); ?>"
                                               min="<?php echo $min; ?>" 
                                               max="<?php echo $max; ?>" 
                                               step="<?php echo $step; ?>"
                                               value="<?php echo htmlspecialchars($value); ?>"
                                               oninput="document.getElementById('<?php echo htmlspecialchars($field_key); ?>_value').textContent = this.value"
                                               <?php echo $required; ?>>
                                        <span class="range-value" id="<?php echo htmlspecialchars($field_key); ?>_value"><?php echo htmlspecialchars($value); ?></span>
                                    </div>
                                <?php
                                        break;
                                    
                                    case 'number':
                                        $min = isset($field_config['min']) ? 'min="' . $field_config['min'] . '"' : '';
                                        $max = isset($field_config['max']) ? 'max="' . $field_config['max'] . '"' : '';
                                        $step = isset($field_config['step']) ? 'step="' . $field_config['step'] . '"' : '';
                                ?>
                                    <input class="input" 
                                           type="number" 
                                           id="<?php echo htmlspecialchars($field_key); ?>" 
                                           name="<?php echo htmlspecialchars($field_key); ?>"
                                           value="<?php echo htmlspecialchars($field_value); ?>"
                                           placeholder="<?php echo $placeholder; ?>"
                                           <?php echo $min . ' ' . $max . ' ' . $step; ?>
                                           <?php echo $required; ?>>
                                <?php
                                        break;
                                    
                                    default: // text, email, tel, etc.
                                ?>
                                    <input class="input" 
                                           type="<?php echo htmlspecialchars($field_type); ?>" 
                                           id="<?php echo htmlspecialchars($field_key); ?>" 
                                           name="<?php echo htmlspecialchars($field_key); ?>"
                                           value="<?php echo htmlspecialchars($field_value); ?>"
                                           placeholder="<?php echo $placeholder; ?>"
                                           <?php echo $required; ?>>
                                <?php
                                        break;
                                endswitch;
                                ?>
                                
                                <?php if (!empty($field_config['help_text'])): ?>
                                    <div class="help-text"><?php echo htmlspecialchars($field_config['help_text']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <p style="color:#6b7280;text-align:center">No profile fields configured yet. Please contact administrator.</p>
            </div>
        <?php endif; ?>
        
        <div class="card" style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
            <button class="btn" type="submit">💾 Save Profile</button>
            <a class="btn secondary" href="<?php echo $base_path; ?>/dashboard.php">← Back to Dashboard</a>
        </div>
    </form>
</div>

<?php if (!empty($toast['type'])): ?>
<div class="toast <?php echo $toast['type']; ?>">
    <?php echo htmlspecialchars($toast['text']); ?>
</div>
<script>
setTimeout(() => {
    const toast = document.querySelector('.toast');
    if (toast) {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }
}, 3000);
</script>
<style>
@keyframes slideOut{to{transform:translateX(100%);opacity:0}}
</style>
<?php endif; ?>

<?php
if (!safe_include($footer_path)) {
    echo '</body></html>';
}
?>