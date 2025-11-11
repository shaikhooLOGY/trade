<?php
/**
 * profile_manager.php — Comprehensive User Profile Manager
 * Single Source of Truth (SOT) for user profile management
 * Add/Edit/Delete fields with automatic database schema sync
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid = (int)($_SESSION['user_id'] ?? 0);
$is_admin = (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) || (defined('APP_ENV') && APP_ENV !== 'prod');

// Only allow admins to use this tool
if (!$is_admin) {
    die("❌ Access denied. Admin privileges required.");
}

$error = '';
$success = '';
$profile_fields_file = __DIR__ . '/profile_fields.php';
$existing_columns = [];
$missing_fields = [];
$unused_columns = [];
$generated_sql = [];

// Load existing database columns
try {
    $columns_result = $mysqli->query("SHOW COLUMNS FROM users");
    while ($row = $columns_result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }
} catch (Exception $e) {
    $error = "Error getting database columns: " . $e->getMessage();
}

// Load current profile fields
$profile_fields = [];
if (file_exists($profile_fields_file)) {
    $profile_fields = include $profile_fields_file;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_section':
                $section_name = trim($_POST['section_name'] ?? '');
                $section_title = trim($_POST['section_title'] ?? '');
                $section_description = trim($_POST['section_description'] ?? '');
                
                if (!empty($section_name) && !empty($section_title)) {
                    $profile_fields[$section_name] = [
                        'title' => $section_title,
                        'description' => $section_description,
                        'fields' => []
                    ];
                    $success = "Section '{$section_title}' added successfully!";
                } else {
                    $error = "Section name and title are required.";
                }
                break;
                
            case 'add_field':
                $section = $_POST['section'] ?? '';
                $field_name = trim($_POST['field_name'] ?? '');
                $field_label = trim($_POST['field_label'] ?? '');
                $field_type = $_POST['field_type'] ?? 'text';
                $field_required = isset($_POST['field_required']);
                $field_placeholder = trim($_POST['field_placeholder'] ?? '');
                
                if (!empty($section) && !empty($field_name) && !empty($field_label)) {
                    // Validate field name
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field_name)) {
                        $error = "Field name must be alphanumeric and start with a letter or underscore.";
                        break;
                    }
                    
                    // Check if field already exists
                    $field_exists = false;
                    foreach ($profile_fields as $sec) {
                        if (isset($sec['fields'][$field_name])) {
                            $field_exists = true;
                            break;
                        }
                    }
                    
                    if ($field_exists) {
                        $error = "Field '{$field_name}' already exists.";
                        break;
                    }
                    
                    // Add field configuration
                    $field_config = [
                        'label' => $field_label,
                        'type' => $field_type,
                        'required' => $field_required
                    ];
                    
                    if (!empty($field_placeholder)) {
                        $field_config['placeholder'] = $field_placeholder;
                    }
                    
                    // Add type-specific configurations
                    switch ($field_type) {
                        case 'number':
                        case 'range':
                            if (!empty($_POST['field_min'])) $field_config['min'] = (int)$_POST['field_min'];
                            if (!empty($_POST['field_max'])) $field_config['max'] = (int)$_POST['field_max'];
                            if (!empty($_POST['field_step'])) $field_config['step'] = (float)$_POST['field_step'];
                            break;
                            
                        case 'select':
                        case 'radio':
                            $options = [];
                            $option_lines = explode("\n", trim($_POST['field_options'] ?? ''));
                            foreach ($option_lines as $line) {
                                $line = trim($line);
                                if (!empty($line) && strpos($line, '|') !== false) {
                                    [$value, $label] = array_map('trim', explode('|', $line, 2));
                                    $options[$value] = $label;
                                }
                            }
                            if (!empty($options)) {
                                $field_config['options'] = ['' => 'Select an option'] + $options;
                            }
                            break;
                            
                        case 'checkbox_group':
                            $options = [];
                            $option_lines = explode("\n", trim($_POST['field_options'] ?? ''));
                            foreach ($option_lines as $line) {
                                $line = trim($line);
                                if (!empty($line) && strpos($line, '|') !== false) {
                                    [$value, $label] = array_map('trim', explode('|', $line, 2));
                                    $options[$value] = $label;
                                }
                            }
                            if (!empty($options)) {
                                $field_config['options'] = $options;
                                if (!empty($_POST['field_min_selections'])) {
                                    $field_config['min_selections'] = (int)$_POST['field_min_selections'];
                                }
                            }
                            break;
                    }
                    
                    if (isset($profile_fields[$section]) && isset($profile_fields[$section]['fields'])) {
                        $profile_fields[$section]['fields'][$field_name] = $field_config;
                        $success = "Field '{$field_label}' added to '{$profile_fields[$section]['title']}' section!";
                    } else {
                        $error = "Selected section not found.";
                    }
                } else {
                    $error = "Field name, label, and section are required.";
                }
                break;
                
            case 'delete_field':
                $section = $_POST['section'] ?? '';
                $field_name = $_POST['field_name'] ?? '';
                
                if (isset($profile_fields[$section]['fields'][$field_name])) {
                    $field_label = $profile_fields[$section]['fields'][$field_name]['label'] ?? $field_name;
                    unset($profile_fields[$section]['fields'][$field_name]);
                    $success = "Field '{$field_label}' deleted successfully!";
                } else {
                    $error = "Field not found.";
                }
                break;
                
            case 'delete_section':
                $section = $_POST['section'] ?? '';
                
                if (isset($profile_fields[$section])) {
                    $section_title = $profile_fields[$section]['title'] ?? $section;
                    unset($profile_fields[$section]);
                    $success = "Section '{$section_title}' deleted successfully!";
                } else {
                    $error = "Section not found.";
                }
                break;
                
            case 'save_profile':
                // Save updated profile_fields.php
                $php_content = "<?php\n";
                $php_content .= "// profile_fields.php — Enhanced comprehensive profile fields for completion\n";
                $php_content .= "return [\n";
                
                foreach ($profile_fields as $section_key => $section_data) {
                    $php_content .= "    // Section: " . $section_data['title'] . "\n";
                    $php_content .= "    '{$section_key}' => [\n";
                    $php_content .= "        'title' => " . var_export($section_data['title'], true) . ",\n";
                    $php_content .= "        'description' => " . var_export($section_data['description'] ?? '', true) . ",\n";
                    $php_content .= "        'fields' => [\n";
                    
                    if (isset($section_data['fields'])) {
                        foreach ($section_data['fields'] as $field_key => $field_config) {
                            $php_content .= "            '{$field_key}' => [\n";
                            $php_content .= "                'label' => " . var_export($field_config['label'], true) . ",\n";
                            $php_content .= "                'type' => " . var_export($field_config['type'], true) . ",\n";
                            $php_content .= "                'required' => " . ($field_config['required'] ? 'true' : 'false') . ",\n";
                            $php_content .= "                'placeholder' => " . var_export($field_config['placeholder'] ?? '', true) . ",\n";
                            
                            if (isset($field_config['options'])) {
                                $php_content .= "                'options' => " . var_export($field_config['options'], true) . ",\n";
                            }
                            
                            if (isset($field_config['min'])) {
                                $php_content .= "                'min' => " . $field_config['min'] . ",\n";
                            }
                            
                            if (isset($field_config['max'])) {
                                $php_content .= "                'max' => " . $field_config['max'] . ",\n";
                            }
                            
                            if (isset($field_config['step'])) {
                                $php_content .= "                'step' => " . $field_config['step'] . ",\n";
                            }
                            
                            if (isset($field_config['min_selections'])) {
                                $php_content .= "                'min_selections' => " . $field_config['min_selections'] . ",\n";
                            }
                            
                            $php_content .= "            ],\n";
                        }
                    }
                    
                    $php_content .= "        ]\n";
                    $php_content .= "    ],\n\n";
                }
                
                $php_content .= "];\n";
                
                if (file_put_contents($profile_fields_file, $php_content)) {
                    $success = "Profile fields configuration saved successfully!";
                } else {
                    $error = "Failed to save profile fields configuration.";
                }
                break;
                
            case 'generate_sql':
                // Generate SQL for all missing fields
                $all_profile_fields = [];
                foreach ($profile_fields as $section) {
                    if (isset($section['fields'])) {
                        foreach ($section['fields'] as $field_name => $field_config) {
                            $all_profile_fields[$field_name] = $field_config;
                        }
                    }
                }
                
                // Find missing fields
                foreach ($all_profile_fields as $field_name => $field_config) {
                    if (!in_array($field_name, $existing_columns)) {
                        $missing_fields[] = ['name' => $field_name, 'config' => $field_config];
                    }
                }
                
                // Find unused columns
                foreach ($existing_columns as $column) {
                    if (!isset($all_profile_fields[$column]) && !in_array($column, ['id', 'name', 'email', 'username', 'password_hash', 'status', 'email_verified', 'created_at', 'is_admin', 'trading_capital', 'funds_available'])) {
                        $unused_columns[] = $column;
                    }
                }
                
                // Generate SQL statements
                foreach ($missing_fields as $field) {
                    $field_name = $field['name'];
                    $config = $field['config'];
                    
                    $sql_type = '';
                    $sql_default = '';
                    $sql_nullable = ' NOT NULL';
                    
                    switch ($config['type'] ?? 'text') {
                        case 'number':
                        case 'range':
                            $sql_type = 'DECIMAL(10,2)';
                            $sql_default = ' DEFAULT 0.00';
                            break;
                            
                        case 'tel':
                            $sql_type = 'VARCHAR(20)';
                            break;
                            
                        case 'email':
                            $sql_type = 'VARCHAR(255)';
                            break;
                            
                        case 'textarea':
                            $sql_type = 'TEXT';
                            $sql_nullable = '';
                            break;
                            
                        case 'checkbox_group':
                            $sql_type = 'JSON';
                            $sql_nullable = '';
                            break;
                            
                        default:
                            if (($config['type'] ?? '') === 'select' || ($config['type'] ?? '') === 'radio') {
                                $sql_type = 'VARCHAR(255)';
                            } else {
                                $sql_type = 'VARCHAR(500)';
                            }
                            break;
                    }
                    
                    if (($config['type'] ?? '') === 'checkbox_group') {
                        $sql_default = ' DEFAULT NULL';
                    }
                    
                    $generated_sql[] = "ALTER TABLE `users` ADD COLUMN `{$field_name}` {$sql_type}{$sql_nullable}{$sql_default};";
                }
                
                // Generate DROP statements for unused columns
                foreach ($unused_columns as $column) {
                    $generated_sql[] = "-- WARNING: This will permanently delete data in column '{$column}'\n";
                    $generated_sql[] = "ALTER TABLE `users` DROP COLUMN `{$column}`;";
                }
                
                if (empty($generated_sql)) {
                    $success = "Database schema is already in sync with profile configuration!";
                } else {
                    $success = "SQL statements generated! Review and execute them below.";
                }
                break;
                
            case 'execute_sql':
                $sql_statements = $_POST['sql_statements'] ?? '';
                if (!empty($sql_statements)) {
                    $statements = explode(';', $sql_statements);
                    $executed = 0;
                    $errors = [];
                    
                    foreach ($statements as $statement) {
                        $statement = trim($statement);
                        if (!empty($statement) && !str_starts_with($statement, '--')) {
                            try {
                                if ($mysqli->query($statement)) {
                                    $executed++;
                                } else {
                                    $errors[] = "Failed: " . $statement . " - " . $mysqli->error;
                                }
                            } catch (Exception $e) {
                                $errors[] = "Error: " . $statement . " - " . $e->getMessage();
                            }
                        }
                    }
                    
                    if ($executed > 0) {
                        $success = "✅ Successfully executed {$executed} SQL statements!";
                    }
                    
                    if (!empty($errors)) {
                        $error = "❌ Errors occurred:\n" . implode("\n", $errors);
                    }
                }
                break;
        }
    }
}

// Refresh database columns after potential changes
if (!empty($success)) {
    $existing_columns = [];
    try {
        $columns_result = $mysqli->query("SHOW COLUMNS FROM users");
        while ($row = $columns_result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
    } catch (Exception $e) {
        // Ignore refresh errors
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>User Profile Manager — SOT Configuration</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body{font-family:Inter,sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:20px}
        .container{max-width:1400px;margin:0 auto}
        .header{background:linear-gradient(135deg,#1e293b,#4f46e5);color:#fff;border-radius:12px;padding:30px;margin-bottom:24px;text-align:center}
        .card{background:#fff;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.05);padding:24px;margin-bottom:24px}
        .btn{display:inline-block;padding:10px 16px;border-radius:8px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:700;margin-top:12px;border:0;cursor:pointer}
        .btn:hover{background:#3730a3}
        .btn-danger{background:#dc2626}
        .btn-success{background:#16a34a}
        .btn-secondary{background:#6b7280}
        .btn-warning{background:#d97706}
        .ok{background:#dcfdf7;border:1px solid #16a34a;color:#065f46;padding:12px;border-radius:8px;margin:12px 0;white-space:pre-wrap}
        .err{background:#fef2f2;border:1px solid #dc2626;color:#dc2626;padding:12px;border-radius:8px;margin:12px 0;white-space:pre-wrap}
        .tabs{display:flex;background:#e2e8f0;border-radius:8px;padding:4px;margin-bottom:20px}
        .tab{padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600}
        .tab.active{background:#4f46e5;color:#fff}
        .tab-content{display:none}
        .tab-content.active{display:block}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
        .form-group{margin-bottom:15px}
        .form-group label{display:block;font-weight:600;margin-bottom:5px}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px}
        .field-list{background:#f8fafc;border-radius:8px;padding:15px;margin:10px 0}
        .field-item{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e5e7eb}
        .field-item:last-child{border-bottom:none}
        .field-name{font-weight:600;color:#1e40af}
        .field-type{color:#059669;font-size:12px}
        .field-required{color:#dc2626;font-size:12px}
        .sql-box{background:#1e293b;color:#e2e8f0;padding:15px;border-radius:8px;font-family:monospace;white-space:pre-wrap;margin:10px 0;max-height:400px;overflow-y:auto;font-size:12px}
        .status-badge{display:inline-block;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:bold}
        .status-ok{background:#dcfdf7;color:#065f46}
        .status-missing{background:#fef2f2;color:#dc2626}
        .status-unused{background:#fef3cd;color:#7c2d12}
        .section-header{background:#f1f5f9;padding:10px 15px;border-radius:8px;margin:15px 0 10px 0;font-weight:600}
        textarea{height:80px}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👤 User Profile Manager</h1>
            <p>Single Source of Truth (SOT) for User Profile Configuration</p>
            <p>Add, Edit, Delete fields and sync with database schema automatically</p>
        </div>

        <?php if ($error): ?>
            <div class="err"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="ok"><?= h($success) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <div class="tab active" onclick="showTab('overview')">📊 Overview</div>
            <div class="tab" onclick="showTab('manage')">⚙️ Manage Fields</div>
            <div class="tab" onclick="showTab('sections')">📋 Sections</div>
            <div class="tab" onclick="showTab('schema')">🗄️ Schema Sync</div>
        </div>

        <div id="overview" class="tab-content active">
            <div class="card">
                <h2>📊 Current Status</h2>
                <div class="grid">
                    <div>
                        <h3>Profile Fields</h3>
                        <p><strong><?= array_reduce($profile_fields ?? [], function($carry, $item) { return $carry + (isset($item['fields']) ? count($item['fields']) : 0); }, 0) ?></strong> total fields</p>
                        <p><strong><?= count($profile_fields) ?></strong> sections</p>
                    </div>
                    <div>
                        <h3>Database</h3>
                        <p><strong><?= count($existing_columns) ?></strong> columns in users table</p>
                        <p><strong><?= count($existing_columns) ?></strong> columns exist</p>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>📋 Field Sections</h2>
                <?php foreach ($profile_fields as $section_key => $section): ?>
                    <div class="section-header">
                        <?= h($section['title']) ?> (<?= count($section['fields'] ?? []) ?> fields)
                    </div>
                    <div class="field-list">
                        <?php if (!empty($section['fields'])): ?>
                            <?php foreach ($section['fields'] as $field_name => $field_config): ?>
                                <div class="field-item">
                                    <div>
                                        <span class="field-name"><?= h($field_name) ?></span>
                                        <span class="field-type"><?= h($field_config['type']) ?></span>
                                        <?php if (!empty($field_config['required'])): ?>
                                            <span class="field-required">Required</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span style="color:#6b7280;font-size:12px"><?= h($field_config['label']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color:#6b7280;font-style:italic">No fields in this section</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="manage" class="tab-content">
            <div class="card">
                <h2>➕ Add New Field</h2>
                <form method="post">
                    <input type="hidden" name="action" value="add_field">
                    
                    <div class="grid">
                        <div>
                            <div class="form-group">
                                <label>Section</label>
                                <select name="section" required>
                                    <option value="">Select Section</option>
                                    <?php foreach ($profile_fields as $section_key => $section): ?>
                                        <option value="<?= h($section_key) ?>"><?= h($section['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Field Name (database column)</label>
                                <input type="text" name="field_name" placeholder="e.g., full_name" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Field Label (display name)</label>
                                <input type="text" name="field_label" placeholder="e.g., Full Name" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Field Type</label>
                                <select name="field_type" required>
                                    <option value="text">Text</option>
                                    <option value="email">Email</option>
                                    <option value="tel">Phone</option>
                                    <option value="number">Number</option>
                                    <option value="textarea">Textarea</option>
                                    <option value="select">Select Dropdown</option>
                                    <option value="radio">Radio Buttons</option>
                                    <option value="checkbox_group">Checkbox Group</option>
                                    <option value="range">Range Slider</option>
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <div class="form-group">
                                <label>Placeholder</label>
                                <input type="text" name="field_placeholder" placeholder="Placeholder text">
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="field_required"> Required Field
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <label>Options (for select/radio/checkbox_group)</label>
                                <textarea name="field_options" placeholder="value|Label&#10;option1|First Option&#10;option2|Second Option"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Min Selections (for checkbox_group)</label>
                                <input type="number" name="field_min_selections" placeholder="1" min="1">
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid">
                        <div class="form-group">
                            <label>Min Value (for number/range)</label>
                            <input type="number" name="field_min" placeholder="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Max Value (for number/range)</label>
                            <input type="number" name="field_max" placeholder="100">
                        </div>
                        
                        <div class="form-group">
                            <label>Step (for number/range)</label>
                            <input type="number" name="field_step" placeholder="1" step="0.1">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">➕ Add Field</button>
                </form>
            </div>
        </div>

        <div id="sections" class="tab-content">
            <div class="card">
                <h2>📋 Add New Section</h2>
                <form method="post">
                    <input type="hidden" name="action" value="add_section">
                    
                    <div class="grid">
                        <div class="form-group">
                            <label>Section Key (technical name)</label>
                            <input type="text" name="section_name" placeholder="e.g., personal_info" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Section Title (display name)</label>
                            <input type="text" name="section_title" placeholder="e.g., Personal Information" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="section_description" placeholder="Brief description of this section">
                    </div>
                    
                    <button type="submit" class="btn">➕ Add Section</button>
                </form>
            </div>

            <div class="card">
                <h2>🗑️ Delete Section</h2>
                <form method="post" onsubmit="return confirm('Are you sure you want to delete this section? All fields in it will be removed.')">
                    <input type="hidden" name="action" value="delete_section">
                    
                    <div class="form-group">
                        <label>Select Section to Delete</label>
                        <select name="section" required>
                            <option value="">Select Section</option>
                            <?php foreach ($profile_fields as $section_key => $section): ?>
                                <option value="<?= h($section_key) ?>"><?= h($section['title']) ?> (<?= count($section['fields'] ?? []) ?> fields)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-danger">🗑️ Delete Section</button>
                </form>
            </div>
        </div>

        <div id="schema" class="tab-content">
            <div class="card">
                <h2>🗄️ Database Schema Synchronization</h2>
                <p>Compare profile fields with database and generate SQL for synchronization.</p>
                
                <form method="post" style="margin:20px 0">
                    <input type="hidden" name="action" value="generate_sql">
                    <button type="submit" class="btn">🔍 Generate Schema SQL</button>
                </form>
                
                <?php if (!empty($generated_sql)): ?>
                    <h3>📝 Generated SQL Statements</h3>
                    <p>Review the following SQL statements and execute them to sync your database:</p>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="execute_sql">
                        <input type="hidden" name="sql_statements" value="<?= h(implode(";\n", $generated_sql) . ';') ?>">
                        
                        <div class="sql-box"><?= h(implode("\n", $generated_sql)) ?></div>
                        
                        <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to execute these SQL statements? This will modify your database structure.')">
                            🚀 Execute SQL Statements
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>📊 Database Status</h2>
                <h3>Existing Columns</h3>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin:10px 0">
                    <?php foreach ($existing_columns as $column): ?>
                        <span class="status-badge status-ok"><?= h($column) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>💾 Save Configuration</h2>
            <p>Save all changes to profile_fields.php. This will update the configuration file used throughout the application.</p>
            <form method="post" onsubmit="return confirm('Save all changes to profile_fields.php?')">
                <input type="hidden" name="action" value="save_profile">
                <button type="submit" class="btn btn-success">💾 Save Configuration</button>
            </form>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
    </script>
</body>
</html>