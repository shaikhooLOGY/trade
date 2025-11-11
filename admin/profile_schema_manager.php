<?php
/**
 * profile_schema_manager.php — Enhanced Profile Manager with Real-Time Schema Sync
 * Combines profile field management with automatic database schema synchronization
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid = (int)($_SESSION['user_id'] ?? 0);
$is_admin = (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1);

if (!$is_admin) {
    http_response_code(403);
    die('Access Denied - Admin Only');
}

$error = '';
$success = '';
$profile_fields_file = __DIR__ . '/../profile_fields.php';
$backup_dir = __DIR__ . '/../backups/profile_fields';

// Create backup directory if it doesn't exist
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Load current profile fields
$profile_fields = [];
if (file_exists($profile_fields_file)) {
    $profile_fields = include $profile_fields_file;
}

// Function to save profile fields
function save_profile_fields($fields, $file) {
    $php_content = "<?php\n// profile_fields.php — Profile configuration\nreturn [\n";
    
    foreach ($fields as $section_key => $section_data) {
        $php_content .= "    '{$section_key}' => [\n";
        $php_content .= "        'title' => " . var_export($section_data['title'], true) . ",\n";
        $php_content .= "        'description' => " . var_export($section_data['description'] ?? '', true) . ",\n";
        $php_content .= "        'fields' => [\n";
        
        if (isset($section_data['fields'])) {
            foreach ($section_data['fields'] as $field_key => $field_config) {
                $php_content .= "            '{$field_key}' => [\n";
                foreach ($field_config as $key => $value) {
                    if ($key === 'required') {
                        $php_content .= "                '{$key}' => " . ($value ? 'true' : 'false') . ",\n";
                    } else {
                        $php_content .= "                '{$key}' => " . var_export($value, true) . ",\n";
                    }
                }
                $php_content .= "            ],\n";
            }
        }
        
        $php_content .= "        ]\n";
        $php_content .= "    ],\n";
    }
    
    $php_content .= "];\n";
    return file_put_contents($file, $php_content);
}

// Function to create PHP config backup
function create_backup($file, $backup_dir) {
    if (file_exists($file)) {
        $timestamp = date('Y-m-d_H-i-s');
        $backup_file = $backup_dir . '/profile_fields_' . $timestamp . '.php';
        copy($file, $backup_file);
        
        // Keep only last 10 backups
        $backups = glob($backup_dir . '/profile_fields_*.php');
        if (count($backups) > 10) {
            usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($backups, 0, -10) as $old_backup) {
                unlink($old_backup);
            }
        }
        
        return basename($backup_file);
    }
    return false;
}

// Function to create SQL schema backup
function create_sql_backup($mysqli, $backup_dir) {
    $timestamp = date('Y-m-d_H-i-s');
    $backup_file = $backup_dir . '/profile_fields_' . $timestamp . '.sql';
    
    // Get CREATE TABLE for users
    $create_result = $mysqli->query("SHOW CREATE TABLE users");
    $create_row = $create_result->fetch_array();
    $backup_content = "-- Profile Fields Schema Backup: " . date('Y-m-d H:i:s') . "\n\n";
    $backup_content .= "-- Table: users\n";
    $backup_content .= $create_row[1] . ";\n\n";
    
    file_put_contents($backup_file, $backup_content);
    
    // Keep only last 10 SQL backups
    $backups = glob($backup_dir . '/profile_fields_*.sql');
    if (count($backups) > 10) {
        usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
        foreach (array_slice($backups, 0, -10) as $old_backup) {
            unlink($old_backup);
        }
    }
    
    return basename($backup_file);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_section':
            $backup_name = create_backup($profile_fields_file, $backup_dir);
            $key = trim($_POST['section_key'] ?? '');
            $title = trim($_POST['section_title'] ?? '');
            if ($key && $title) {
                $profile_fields[$key] = [
                    'title' => $title,
                    'description' => trim($_POST['section_desc'] ?? ''),
                    'fields' => []
                ];
                if (save_profile_fields($profile_fields, $profile_fields_file)) {
                    $success = "✅ Section '{$title}' added! Backup: {$backup_name}";
                }
            }
            break;
            
        case 'delete_section':
            $backup_name = create_backup($profile_fields_file, $backup_dir);
            $key = $_POST['section_key'] ?? '';
            if (isset($profile_fields[$key])) {
                $title = $profile_fields[$key]['title'];
                unset($profile_fields[$key]);
                if (save_profile_fields($profile_fields, $profile_fields_file)) {
                    $success = "✅ Section '{$title}' deleted! Backup: {$backup_name}";
                }
            }
            break;
            
        case 'add_field':
            $backup_name = create_backup($profile_fields_file, $backup_dir);
            $section = $_POST['section'] ?? '';
            $field_key = trim($_POST['field_key'] ?? '');
            $field_label = trim($_POST['field_label'] ?? '');
            $field_type = $_POST['field_type'] ?? 'text';
            
            if ($section && $field_key && $field_label && isset($profile_fields[$section])) {
                $profile_fields[$section]['fields'][$field_key] = [
                    'label' => $field_label,
                    'type' => $field_type,
                    'required' => isset($_POST['field_required']),
                    'placeholder' => trim($_POST['field_placeholder'] ?? '')
                ];
                if (save_profile_fields($profile_fields, $profile_fields_file)) {
                    $success = "✅ Field '{$field_label}' added! Backup: {$backup_name}. Go to Schema Sync tab to add to database.";
                }
            }
            break;
            
        case 'delete_field':
            $backup_name = create_backup($profile_fields_file, $backup_dir);
            $section = $_POST['section'] ?? '';
            $field_key = $_POST['field_key'] ?? '';
            if (isset($profile_fields[$section]['fields'][$field_key])) {
                $label = $profile_fields[$section]['fields'][$field_key]['label'];
                unset($profile_fields[$section]['fields'][$field_key]);
                if (save_profile_fields($profile_fields, $profile_fields_file)) {
                    $success = "✅ Field '{$label}' deleted! Backup: {$backup_name}";
                }
            }
            break;
            
        case 'sync_schema':
            // Auto-sync: Add missing columns to database
            $sql_backup = create_sql_backup($mysqli, $backup_dir);
            $added = 0;
            $errors = [];
            
            // Get all field names from profile_fields
            $all_fields = [];
            foreach ($profile_fields as $section) {
                if (isset($section['fields'])) {
                    foreach ($section['fields'] as $field_key => $field_config) {
                        $all_fields[$field_key] = $field_config;
                    }
                }
            }
            
            // Get database columns
            $db_columns = [];
            $result = $mysqli->query("SHOW COLUMNS FROM users");
            while ($row = $result->fetch_assoc()) {
                $db_columns[] = $row['Field'];
            }
            
            $missing_in_db = array_diff(array_keys($all_fields), $db_columns);
            
            foreach ($missing_in_db as $field) {
                $type = ($all_fields[$field]['type'] ?? 'text') === 'textarea' ? 'TEXT' : 'VARCHAR(500)';
                $sql = "ALTER TABLE users ADD COLUMN `{$field}` {$type} NULL";
                try {
                    $mysqli->query($sql);
                    $added++;
                } catch (Exception $e) {
                    $errors[] = $field . ': ' . $e->getMessage();
                }
            }
            
            if ($added > 0) {
                $success = "✅ {$added} column(s) added to database! Backup: {$sql_backup}. Changes are now live across all pages!";
            } else {
                $success = "✅ Schema already in sync! No changes needed.";
            }
            if (!empty($errors)) {
                $error = "⚠️ Some errors: " . implode(', ', $errors);
            }
            break;
            
        case 'rollback_sql':
            $backup_file = basename($_POST['backup_file'] ?? '');
            $backup_path = $backup_dir . '/' . $backup_file;
            
            if (file_exists($backup_path) && strpos($backup_file, 'profile_fields_') === 0 && strpos($backup_file, '.sql') !== false) {
                // Create backup before rollback
                $pre_rollback = create_sql_backup($mysqli, $backup_dir);
                
                $sql_content = file_get_contents($backup_path);
                $statements = array_filter(array_map('trim', explode(';', $sql_content)));
                
                $executed = 0;
                foreach ($statements as $statement) {
                    if (!empty($statement) && !str_starts_with($statement, '--')) {
                        try {
                            $mysqli->query($statement);
                            $executed++;
                        } catch (Exception $e) {
                            // Continue on error
                        }
                    }
                }
                
                $success = "✅ Rollback complete! {$executed} statement(s) executed. Pre-rollback backup: {$pre_rollback}";
            } else {
                $error = "❌ Invalid backup file";
            }
            break;
    }
    
    // Reload fields after changes
    if (file_exists($profile_fields_file)) {
        $profile_fields = include $profile_fields_file;
    }
}

// Get database columns
$db_columns = [];
try {
    $result = $mysqli->query("SHOW COLUMNS FROM users");
    while ($row = $result->fetch_assoc()) {
        $db_columns[] = $row['Field'];
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get all field names from profile_fields
$all_fields = [];
foreach ($profile_fields as $section) {
    if (isset($section['fields'])) {
        foreach ($section['fields'] as $field_key => $field_config) {
            $all_fields[$field_key] = $field_config;
        }
    }
}

// Find missing and unused columns
// IMPORTANT: Protected system columns that should NEVER be dropped
$system_columns = [
    // Core user fields
    'id', 'name', 'email', 'username', 'password_hash', 'status', 'email_verified', 'created_at', 'updated_at',
    // Admin & roles
    'is_admin', 'role', 'is_superadmin',
    // Trading system
    'trading_capital', 'funds_available', 'reserved_capital', 'total_profit_loss',
    // OTP & verification
    'otp_code', 'otp_expires_at', 'otp_attempts', 'otp_verified_at',
    // Password reset
    'reset_token', 'reset_token_expires', 'reset_requested_at',
    // Profile status
    'profile_status', 'profile_field_status', 'profile_comments', 'rejection_reason',
    // Timestamps
    'last_login', 'last_activity', 'deleted_at',
    // Other system fields
    'verification_token', 'verified_at', 'remember_token'
];

$missing_in_db = array_diff(array_keys($all_fields), $db_columns);
$unused_in_config = array_diff($db_columns, array_keys($all_fields), $system_columns);

// Get backups list (both .php and .sql)
$php_backups = glob($backup_dir . '/profile_fields_*.php');
$sql_backups = glob($backup_dir . '/profile_fields_*.sql');
$all_backups = array_merge($php_backups, $sql_backups);
usort($all_backups, function($a, $b) { return filemtime($b) - filemtime($a); });
$backups = array_slice($all_backups, 0, 10);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Profile Schema Manager</title>
<style>
body{font-family:Inter,system-ui,sans-serif;background:#f5f5f5;margin:0;padding:20px}
.container{max-width:1200px;margin:0 auto}
.header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:30px;border-radius:12px;margin-bottom:20px}
.tabs{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.tab{padding:12px 24px;background:#fff;border-radius:8px;cursor:pointer;font-weight:600;transition:all 0.3s}
.tab:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.1)}
.tab.active{background:#667eea;color:#fff}
.tab-content{display:none}
.tab-content.active{display:block}
.card{background:#fff;padding:20px;border-radius:12px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
.section{border:1px solid #e0e0e0;border-radius:8px;padding:15px;margin-bottom:15px}
.section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px}
.section-title{font-size:18px;font-weight:700;color:#333}
.field-item{display:flex;justify-content:space-between;align-items:center;padding:8px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:10px}
.field-item:last-child{border-bottom:none}
.btn{padding:8px 16px;border-radius:6px;border:none;cursor:pointer;font-weight:600;margin:2px;transition:all 0.3s}
.btn:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,0.15)}
.btn-primary{background:#667eea;color:#fff}
.btn-danger{background:#ef4444;color:#fff}
.btn-success{background:#10b981;color:#fff}
.btn-secondary{background:#6b7280;color:#fff}
.btn-warning{background:#f59e0b;color:#fff}
.form-group{margin-bottom:15px}
.form-group label{display:block;font-weight:600;margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-family:inherit}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media (max-width:768px){.grid{grid-template-columns:1fr}}
.alert{padding:12px;border-radius:8px;margin-bottom:15px;animation:slideIn 0.3s}
@keyframes slideIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #10b981}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #ef4444}
.alert-warning{background:#fef3c7;color:#92400e;border:1px solid #f59e0b}
.sql-box{background:#1e293b;color:#e2e8f0;padding:15px;border-radius:8px;font-family:'Courier New',monospace;font-size:13px;margin:10px 0;max-height:300px;overflow-y:auto}
.badge{display:inline-block;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;margin:2px}
.badge-warning{background:#fef3c7;color:#92400e}
.badge-success{background:#d1fae5;color:#065f46}
.badge-info{background:#e0e7ff;color:#3730a3}
.backup-item{padding:10px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.sync-status{padding:20px;border-radius:8px;text-align:center;margin-bottom:20px}
.sync-ok{background:#d1fae5;border:2px solid #10b981;color:#065f46}
.sync-warning{background:#fee2e2;border:2px solid #ef4444;color:#991b1b}
details{margin:15px 0}
summary{cursor:pointer;font-weight:600;padding:10px;background:#f9fafb;border-radius:6px;user-select:none}
summary:hover{background:#f3f4f6}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🔄 Profile Schema Manager</h1>
        <p>Manage profile fields with real-time database synchronization</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($missing_in_db)): ?>
    <div class="alert alert-warning">
        ⚠️ <strong><?= count($missing_in_db) ?> field(s) missing in database!</strong> 
        Schema is out of sync. Go to <strong>Schema Sync</strong> tab to fix automatically.
    </div>
    <?php endif; ?>

    <div class="tabs">
        <div class="tab active" onclick="showTab('overview')">📊 Fields Manager</div>
        <div class="tab" onclick="showTab('schema')">🔄 Schema Sync</div>
        <div class="tab" onclick="showTab('backups')">💾 Backups</div>
    </div>

    <!-- Fields Manager -->
    <div id="overview" class="tab-content active">
        <div class="card">
            <h2>➕ Quick Add</h2>
            <div class="grid">
                <div>
                    <h3>Add Section</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_section">
                        <div class="form-group">
                            <input type="text" name="section_key" placeholder="Section Key (e.g., personal_info)" required>
                        </div>
                        <div class="form-group">
                            <input type="text" name="section_title" placeholder="Section Title" required>
                        </div>
                        <div class="form-group">
                            <input type="text" name="section_desc" placeholder="Description (optional)">
                        </div>
                        <button type="submit" class="btn btn-primary">Add Section</button>
                    </form>
                </div>
                <div>
                    <h3>Add Field</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_field">
                        <div class="form-group">
                            <select name="section" required>
                                <option value="">Select Section</option>
                                <?php foreach ($profile_fields as $key => $section): ?>
                                    <option value="<?= h($key) ?>"><?= h($section['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="text" name="field_key" placeholder="Field Key (e.g., full_name)" required>
                        </div>
                        <div class="form-group">
                            <input type="text" name="field_label" placeholder="Field Label" required>
                        </div>
                        <div class="form-group">
                            <select name="field_type">
                                <option value="text">Text</option>
                                <option value="email">Email</option>
                                <option value="tel">Phone</option>
                                <option value="number">Number</option>
                                <option value="textarea">Textarea</option>
                                <option value="select">Select</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="text" name="field_placeholder" placeholder="Placeholder (optional)">
                        </div>
                        <div class="form-group">
                            <label><input type="checkbox" name="field_required"> Required</label>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Field</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>📋 All Sections & Fields</h2>
            <?php foreach ($profile_fields as $section_key => $section): ?>
                <div class="section">
                    <div class="section-header">
                        <div>
                            <span class="section-title"><?= h($section['title']) ?></span>
                            <span class="badge badge-success"><?= count($section['fields'] ?? []) ?> fields</span>
                        </div>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this section and all its fields?')">
                            <input type="hidden" name="action" value="delete_section">
                            <input type="hidden" name="section_key" value="<?= h($section_key) ?>">
                            <button type="submit" class="btn btn-danger">🗑️ Delete Section</button>
                        </form>
                    </div>
                    <?php if (!empty($section['fields'])): ?>
                        <?php foreach ($section['fields'] as $field_key => $field): ?>
                            <div class="field-item">
                                <div>
                                    <strong><?= h($field_key) ?></strong> - <?= h($field['label']) ?>
                                    <span style="color:#666;font-size:12px">(<?= h($field['type']) ?>)</span>
                                    <?php if (!empty($field['required'])): ?>
                                        <span style="color:#ef4444;font-size:12px">*Required</span>
                                    <?php endif; ?>
                                    <?php if (in_array($field_key, $missing_in_db)): ?>
                                        <span class="badge badge-warning">Not in DB</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">✓ Synced</span>
                                    <?php endif; ?>
                                </div>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this field?')">
                                    <input type="hidden" name="action" value="delete_field">
                                    <input type="hidden" name="section" value="<?= h($section_key) ?>">
                                    <input type="hidden" name="field_key" value="<?= h($field_key) ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:#999;font-style:italic">No fields in this section</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Schema Sync -->
    <div id="schema" class="tab-content">
        <div class="card">
            <h2>🔄 Real-Time Schema Sync</h2>
            <p style="color:#666;margin-bottom:20px">
                Keep profile_fields.php and database schema in perfect sync. Changes reflect immediately across all pages.
            </p>
            
            <?php if (empty($missing_in_db) && empty($unused_in_config)): ?>
                <div class="sync-status sync-ok">
                    <h3 style="margin:0 0 10px 0">✅ Schema is Perfectly Synced!</h3>
                    <p style="margin:0">All profile fields are aligned with database columns.</p>
                </div>
            <?php else: ?>
                <div class="sync-status sync-warning">
                    <h3 style="margin:0 0 10px 0">⚠️ Schema Out of Sync</h3>
                    <p style="margin:0">
                        <?= count($missing_in_db) ?> field(s) missing in database, 
                        <?= count($unused_in_config) ?> unused column(s) in database
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="grid">
                <div>
                    <h3>📝 Missing in Database (<?= count($missing_in_db) ?>)</h3>
                    <?php if (empty($missing_in_db)): ?>
                        <p style="color:#10b981">✅ All fields exist in database</p>
                    <?php else: ?>
                        <div style="margin-bottom:15px">
                            <?php foreach ($missing_in_db as $field): ?>
                                <div class="badge badge-warning" style="margin:3px"><?= h($field) ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:15px;border-radius:8px;margin-bottom:15px">
                            <strong>⚡ Quick Sync:</strong>
                            <p style="margin:5px 0 0 0;font-size:13px">
                                Automatically add all missing columns to database with one click.
                            </p>
                        </div>
                        
                        <form method="post" onsubmit="return confirmSync(<?= count($missing_in_db) ?>)">
                            <input type="hidden" name="action" value="sync_schema">
                            <button type="submit" class="btn btn-success" style="width:100%;padding:12px;font-size:16px">
                                🔄 Auto-Sync Schema (Add <?= count($missing_in_db) ?> Columns)
                            </button>
                        </form>
                        
                        <details style="margin-top:15px">
                            <summary>📋 View SQL Commands</summary>
                            <div class="sql-box" style="margin-top:10px">
<?php foreach ($missing_in_db as $field): 
    $type = ($all_fields[$field]['type'] ?? 'text') === 'textarea' ? 'TEXT' : 'VARCHAR(500)';
?>ALTER TABLE users ADD COLUMN `<?= h($field) ?>` <?= $type ?> NULL;
<?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
                
                <div>
                    <h3>🗑️ Unused in Config (<?= count($unused_in_config) ?>)</h3>
                    <?php if (empty($unused_in_config)): ?>
                        <p style="color:#10b981">✅ No unused columns</p>
                    <?php else: ?>
                        <div style="margin-bottom:15px">
                            <?php foreach ($unused_in_config as $col): ?>
                                <div class="badge badge-warning" style="margin:3px"><?= h($col) ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="background:#fee2e2;border:1px solid #ef4444;padding:15px;border-radius:8px;margin-bottom:15px">
                            <strong>⚠️ Warning:</strong>
                            <p style="margin:5px 0 0 0;font-size:13px">
                                These columns exist in database but not in profile_fields.php. 
                                They are likely system columns or legacy fields.
                            </p>
                        </div>
                        
                        <p style="color:#666;font-size:13px">
                            <strong>Note:</strong> These columns are protected and should not be dropped unless you're absolutely sure they're not needed.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>ℹ️ How Real-Time Sync Works</h2>
            <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:15px;border-radius:4px;margin-bottom:15px">
                <strong style="color:#1e40af">📊 Workflow:</strong>
                <ol style="margin:10px 0 0 20px;color:#1e40af;line-height:1.8">
                    <li>Add/edit fields in profile_fields.php (Fields Manager tab)</li>
                    <li>Schema Sync detects missing columns automatically</li>
                    <li>Click "Auto-Sync" to add columns to database</li>
                    <li>Changes reflect immediately on all pages</li>
                    <li>Automatic backup created before each change</li>
                </ol>
            </div>
            
            <div style="background:#d1fae5;border-left:4px solid #10b981;padding:15px;border-radius:4px">
                <strong style="color:#065f46">✅ Benefits:</strong>
                <ul style="margin:10px 0 0 20px;color:#065f46;line-height:1.8">
                    <li>No manual SQL needed</li>
                    <li>Zero downtime updates</li>
                    <li>Automatic backups</li>
                    <li>Instant reflection across all pages</li>
                    <li>Safe rollback available</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Backups -->
    <div id="backups" class="tab-content">
        <div class="card">
            <h2>💾 Backup History (Last 10)</h2>
            <p style="color:#666;margin-bottom:15px">
                Automatic backups created before any changes. Both config (.php) and schema (.sql) backups available.
            </p>
            
            <?php if (empty($backups)): ?>
                <p style="color:#999;font-style:italic">No backups yet. Backups will be created automatically when you make changes.</p>
            <?php else: ?>
                <?php foreach ($backups as $backup): 
                    $is_sql = strpos($backup, '.sql') !== false;
                    $icon = $is_sql ? '📄' : '⚙️';
                    $type = $is_sql ? 'SQL Schema' : 'PHP Config';
                ?>
                    <div class="backup-item">
                        <div>
                            <strong><?= $icon ?> <?= basename($backup) ?></strong>
                            <span class="badge badge-info"><?= $type ?></span>
                            <span style="color:#666;font-size:12px;margin-left:10px">
                                <?= date('Y-m-d H:i:s', filemtime($backup)) ?>
                                (<?= number_format(filesize($backup) / 1024, 2) ?> KB)
                            </span>
                        </div>
                        <div>
                            <a href="<?= h($backup) ?>" download class="btn btn-secondary">📥 Download</a>
                            <?php if ($is_sql): ?>
                                <button onclick="confirmRollback('<?= basename($backup) ?>')" class="btn btn-warning">🔄 Rollback</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>ℹ️ Backup Types</h2>
            <div class="grid">
                <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:15px;border-radius:4px">
                    <strong style="color:#1e40af">📄 SQL Schema Backups:</strong>
                    <ul style="margin:10px 0 0 20px;color:#1e40af;font-size:13px;line-height:1.6">
                        <li>Database structure (CREATE TABLE)</li>
                        <li>Created before schema changes</li>
                        <li>Use for database rollback</li>
                        <li>Run in phpMyAdmin to restore</li>
                    </ul>
                </div>
                <div style="background:#f0fdf4;border-left:4px solid #10b981;padding:15px;border-radius:4px">
                    <strong style="color:#065f46">⚙️ PHP Config Backups:</strong>
                    <ul style="margin:10px 0 0 20px;color:#065f46;font-size:13px;line-height:1.6">
                        <li>profile_fields.php configuration</li>
                        <li>Created before field changes</li>
                        <li>Use for config rollback</li>
                        <li>Replace file to restore</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>ℹ️ System Information</h2>
        <div class="grid">
            <div>
                <strong>Database:</strong> <?= htmlspecialchars($GLOBALS['DB_NAME'] ?? 'Unknown') ?>
            </div>
            <div>
                <strong>Total Sections:</strong> <?= count($profile_fields) ?>
            </div>
            <div>
                <strong>Total Fields:</strong> <?= count($all_fields) ?>
            </div>
            <div>
                <strong>Database Columns:</strong> <?= count($db_columns) ?>
            </div>
            <div>
                <strong>Protected Columns:</strong> <?= count($system_columns) ?> system columns
            </div>
            <div>
                <strong>Configuration File:</strong> profile_fields.php
            </div>
            <div>
                <strong>Backups Available:</strong> <?= count($backups) ?>
            </div>
            <div>
                <strong>Schema Status:</strong> 
                <?php if (empty($missing_in_db)): ?>
                    <span style="color:#10b981">✅ Synced</span>
                <?php else: ?>
                    <span style="color:#ef4444">⚠️ Out of Sync</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" style="text-align:center">
        <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>
</div>

<script>
function showTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById(name).classList.add('active');
    event.target.classList.add('active');
}

function confirmSync(count) {
    const message = `🔄 Auto-Sync Schema\n\n` +
                   `This will add ${count} column(s) to the database:\n\n` +
                   `✅ Safe operation (no data loss)\n` +
                   `✅ Automatic backup created\n` +
                   `✅ Changes reflect immediately\n` +
                   `✅ Can rollback if needed\n\n` +
                   `Proceed with sync?`;
    return confirm(message);
}

function confirmRollback(backupFile) {
    const message = `🔄 Rollback to Backup\n\n` +
                   `File: ${backupFile}\n\n` +
                   `⚠️ This will restore database structure from backup\n` +
                   `✅ Current schema will be backed up first\n` +
                   `✅ Can rollback the rollback if needed\n\n` +
                   `Proceed with rollback?`;
    
    if (confirm(message)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="rollback_sql">
            <input type="hidden" name="backup_file" value="${backupFile}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>