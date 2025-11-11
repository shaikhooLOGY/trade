<?php
/**
 * schema_validator.php — Profile Fields Schema Validator & Migration Assistant
 * Single Source of Truth (SOT) validation system
 * Compares profile_fields.php with live database and suggests missing columns
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid = (int)($_SESSION['user_id'] ?? 0);
$is_admin = (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) || (defined('APP_ENV') && APP_ENV !== 'prod');

// Only allow admins or developers to use this tool
if (!$is_admin) {
    die("❌ Access denied. Admin privileges required.");
}

$missing_fields = [];
$existing_columns = [];
$profile_fields = [];
$error = '';
$success = '';

// Load profile fields from profile_fields.php
try {
    $profile_fields_file = __DIR__ . '/profile_fields.php';
    if (file_exists($profile_fields_file)) {
        $profile_fields = include $profile_fields_file;
    } else {
        $error = "profile_fields.php not found!";
    }
} catch (Exception $e) {
    $error = "Error loading profile_fields.php: " . $e->getMessage();
}

// Get existing database columns
try {
    $columns_result = $mysqli->query("SHOW COLUMNS FROM users");
    while ($row = $columns_result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }
} catch (Exception $e) {
    $error = "Error getting database columns: " . $e->getMessage();
}

// Collect all field names from profile_fields.php
$profile_field_names = [];
if (!empty($profile_fields) && is_array($profile_fields)) {
    foreach ($profile_fields as $section) {
        if (isset($section['fields']) && is_array($section['fields'])) {
            foreach ($section['fields'] as $field_name => $field_config) {
                $profile_field_names[] = $field_name;
                
                // Check if field exists in database
                if (!in_array($field_name, $existing_columns)) {
                    $missing_fields[] = [
                        'name' => $field_name,
                        'config' => $field_config
                    ];
                }
            }
        }
    }
}

// Generate SQL for missing fields
$sql_statements = [];
if (!empty($missing_fields)) {
    foreach ($missing_fields as $field) {
        $field_name = $field['name'];
        $config = $field['config'];
        
        $sql_type = '';
        $sql_default = '';
        $sql_nullable = ' NOT NULL';
        
        // Map PHP types to MySQL types
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
                $sql_nullable = ''; // TEXT can be NULL by default
                break;
                
            case 'checkbox_group':
                $sql_type = 'JSON';
                $sql_nullable = ''; // JSON can be NULL by default
                break;
                
            default: // text, select, radio, etc.
                if (($config['type'] ?? '') === 'select' || ($config['type'] ?? '') === 'radio') {
                    $sql_type = 'VARCHAR(255)';
                } else {
                    $sql_type = 'VARCHAR(500)';
                }
                break;
        }
        
        // Add DEFAULT for checkbox_group
        if (($config['type'] ?? '') === 'checkbox_group') {
            $sql_default = ' DEFAULT NULL';
        }
        
        $sql_statements[] = "ALTER TABLE `users` ADD COLUMN `{$field_name}` {$sql_type}{$sql_nullable}{$sql_default};";
    }
}

// Handle manual SQL execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_sql']) && !empty($_POST['sql_statements'])) {
    $statements = explode(';', trim($_POST['sql_statements']));
    $executed = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
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
        // Refresh the page to show updated columns
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if (!empty($errors)) {
        $error = "❌ Errors occurred:\n" . implode("\n", $errors);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Schema Validator — Profile Fields SOT</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body{font-family:Inter,sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:20px}
        .container{max-width:1200px;margin:0 auto}
        .card{background:#fff;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.05);padding:24px;margin-bottom:24px}
        .header{background:#1e293b;color:#fff;border-radius:12px;padding:30px;margin-bottom:24px;text-align:center}
        .btn{display:inline-block;padding:10px 16px;border-radius:8px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:700;margin-top:12px;border:0;cursor:pointer}
        .btn:hover{background:#3730a3}
        .btn-danger{background:#dc2626}
        .btn-success{background:#16a34a}
        .btn-secondary{background:#6b7280}
        .ok{background:#dcfdf7;border:1px solid #16a34a;color:#065f46;padding:12px;border-radius:8px;margin:12px 0}
        .err{background:#fef2f2;border:1px solid #dc2626;color:#dc2626;padding:12px;border-radius:8px;margin:12px 0}
        .sql-box{background:#1e293b;color:#e2e8f0;padding:15px;border-radius:8px;font-family:monospace;white-space:pre-wrap;margin:10px 0;max-height:300px;overflow-y:auto}
        .field-info{padding:15px;background:#f1f5f9;border-radius:8px;margin:10px 0}
        .field-name{font-weight:bold;color:#1e40af}
        .field-type{color:#059669;font-family:monospace}
        .field-required{color:#dc2626;font-weight:bold}
        .status-badge{display:inline-block;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:bold}
        .status-ok{background:#dcfdf7;color:#065f46}
        .status-missing{background:#fef2f2;color:#dc2626}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
        .small{font-size:14px;color:#6b7280}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 Schema Validator & Migration Assistant</h1>
            <p>Single Source of Truth (SOT) validation for profile_fields.php</p>
        </div>

        <?php if ($error): ?>
            <div class="err"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="ok"><?= h($success) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>📊 Schema Status</h2>
            
            <div class="grid">
                <div>
                    <h3>Profile Fields Found</h3>
                    <p><strong><?= count($profile_field_names) ?></strong> fields defined in profile_fields.php</p>
                    <span class="status-badge status-ok">All fields found</span>
                </div>
                <div>
                    <h3>Database Columns</h3>
                    <p><strong><?= count($existing_columns) ?></strong> columns in users table</p>
                </div>
            </div>
        </div>

        <?php if (!empty($missing_fields)): ?>
            <div class="card">
                <h2>❌ Missing Fields Detected</h2>
                <p>The following <?= count($missing_fields) ?> fields are defined in profile_fields.php but missing in the database:</p>

                <?php foreach ($missing_fields as $field): ?>
                    <div class="field-info">
                        <div class="field-name"><?= h($field['name']) ?></div>
                        <div class="small">
                            <span class="field-type">Type: <?= h($field['config']['type'] ?? 'text') ?></span>
                            <?php if (!empty($field['config']['required'])): ?>
                                <span class="field-required"> • Required</span>
                            <?php endif; ?>
                            <?php if (!empty($field['config']['label'])): ?>
                                <br>Label: <?= h($field['config']['label']) ?>
                            <?php endif; ?>
                            <?php if (!empty($field['config']['description'])): ?>
                                <br>Description: <?= h($field['config']['description']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <h3>🗄️ Generated SQL Statements</h3>
                <p>Copy and execute these SQL statements in your database:</p>
                
                <form method="post">
                    <input type="hidden" name="sql_statements" value="<?= h(implode(";\n", $sql_statements) . ';') ?>">
                    <div class="sql-box"><?= h(implode("\n", $sql_statements) . "\n") ?></div>
                    <button type="submit" name="execute_sql" class="btn btn-success" onclick="return confirm('Are you sure you want to execute these SQL statements?')">
                        🚀 Execute All SQL Statements
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>✅ All Fields Present</h2>
                <p>All fields from profile_fields.php are present in the database! 🎉</p>
                <div class="ok">Schema is up to date and ready for production.</div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>📋 Database Schema Overview</h2>
            <p><strong>Existing columns in users table:</strong></p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
                <?php foreach ($existing_columns as $column): ?>
                    <span class="status-badge status-ok"><?= h($column) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>🔍 Field Analysis</h2>
            <p><strong>Fields from profile_fields.php:</strong></p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
                <?php foreach ($profile_field_names as $field): ?>
                    <?php $exists = in_array($field, $existing_columns); ?>
                    <span class="status-badge <?= $exists ? 'status-ok' : 'status-missing' ?>">
                        <?= h($field) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>🚀 Next Steps</h2>
            <?php if (!empty($missing_fields)): ?>
                <ol>
                    <li>Review the generated SQL statements above</li>
                    <li>Execute them in your database (preferably in a test environment first)</li>
                    <li>Verify all fields were added successfully</li>
                    <li>Return to this page to confirm all fields are now present</li>
                </ol>
                <div class="ok">
                    <strong>💡 Pro Tip:</strong> Always backup your database before executing ALTER TABLE statements!
                </div>
            <?php else: ?>
                <p>Your schema is perfectly aligned with profile_fields.php. You can now:</p>
                <ul>
                    <li>Add new fields to profile_fields.php</li>
                    <li>Run this validator to check for missing columns</li>
                    <li>Execute the generated SQL to stay in sync</li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>