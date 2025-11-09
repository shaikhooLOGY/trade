<?php
// profile_completion.php — Comprehensive profile completion after OTP verification
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Must be signed in and email verified
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php'); exit;
}

if ((int)($_SESSION['email_verified'] ?? 0) !== 1) {
    header('Location: /pending_approval.php'); exit;
}

$uid = (int)$_SESSION['user_id'];
$user = null;

// Load fresh user data
try {
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    app_log("Error loading user data: " . $e->getMessage());
    $_SESSION['flash'] = "Error loading user data. Please try again.";
    header('Location: /pending_approval.php'); exit;
}

if (!$user) {
    $_SESSION['flash'] = "User not found.";
    header('Location: /login.php'); exit;
}

// Load profile fields configuration
$profile_fields_config = require __DIR__ . '/profile_fields.php';

// Get current step
$current_step = (int)($_GET['step'] ?? 1);
$max_steps = count($profile_fields_config);

// CSRF Protection
if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['profile_csrf'];
// Handle auto-save AJAX requests
if (isset($_POST['auto_save']) && $_POST['auto_save'] === '1') {
    header('Content-Type: application/json');
    
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security validation failed']);
        exit;
    }
    
    // Save profile data silently
    $saved = save_profile_data($uid, $_POST);
    if ($saved) {
        echo json_encode(['success' => true, 'message' => 'Auto-saved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Auto-save failed']);
    }
    exit;
}

if (isset($_GET['auto_save']) && $_GET['auto_save'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid auto-save request']);
    exit;
}


// Handle form submission
$errors = [];
$success = false;
$validation_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step'])) {
    $submitted_step = (int)($_POST['current_step'] ?? 1);
    
    // CSRF validation
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $errors[] = "Security validation failed. Please reload the page and try again.";
    } else {
        // Validate and save current step
        $validation_result = validate_and_save_profile_step($submitted_step, $profile_fields_config, $_POST, $uid);
        
        if ($validation_result['success']) {
            $success = true;
            $_SESSION['flash'] = "Profile section saved successfully!";
            
            // Auto-save all data to session
            save_profile_to_session($_POST);
            
            // Save to database
            save_profile_data($uid, $_POST);
            
            // Determine next step
            $next_step = $submitted_step + 1;
            if ($next_step <= $max_steps) {
                // Redirect to next step
                header('Location: /profile_completion.php?step=' . $next_step);
                exit;
            } else {
                // All steps completed - update status and redirect
                update_user_status_admin_review($uid);
                update_profile_completion_status($uid, 'completed');
                
                // Clear session data
                unset($_SESSION['profile_data']);
                
                header('Location: /profile_completion.php?completed=1');
                exit;
            }
        } else {
            $errors = $validation_result['errors'];
            $validation_errors = $validation_result['validation_errors'] ?? [];
            $current_step = $submitted_step; // Stay on current step
        }
    }
}

// Load existing profile data from session or database
$profile_data = load_profile_from_session() ?: load_profile_from_database($uid);

// Get current step data
$current_step_data = array_values($profile_fields_config)[$current_step - 1] ?? null;

if (!$current_step_data) {
    header('Location: /profile_completion.php?step=1'); exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function validate_and_save_profile_step($step, $config, $post_data, $user_id) {
    $step_key = array_keys($config)[$step - 1] ?? null;
    if (!$step_key) {
        return ['success' => false, 'errors' => ['Invalid step']];
    }
    
    $step_config = $config[$step_key];
    $errors = [];
    $validation_errors = [];
    
    // Validate required fields
    foreach ($step_config['fields'] as $field_name => $field_config) {
        $value = trim($post_data[$field_name] ?? '');
        
        // Check required fields
        if (!empty($field_config['required']) && $value === '') {
            $errors[] = "Field '{$field_config['label']}' is required.";
            $validation_errors[$field_name] = "This field is required.";
            continue;
        }
        
        // Skip validation for empty optional fields
        if ($value === '') continue;
        
        // Field-specific validation
        switch ($field_config['type']) {
            case 'number':
                if (!is_numeric($value)) {
                    $errors[] = "Field '{$field_config['label']}' must be a number.";
                    $validation_errors[$field_name] = "Must be a valid number.";
                } else {
                    $num_value = (float)$value;
                    if (isset($field_config['min']) && $num_value < $field_config['min']) {
                        $errors[] = "Field '{$field_config['label']}' must be at least {$field_config['min']}.";
                        $validation_errors[$field_name] = "Must be at least {$field_config['min']}.";
                    }
                    if (isset($field_config['max']) && $num_value > $field_config['max']) {
                        $errors[] = "Field '{$field_config['label']}' must be at most {$field_config['max']}.";
                        $validation_errors[$field_name] = "Must be at most {$field_config['max']}.";
                    }
                }
                break;
                
            case 'checkbox_group':
                if (isset($field_config['min_selections']) && is_array($value)) {
                    if (count($value) < $field_config['min_selections']) {
                        $errors[] = "Please select at least {$field_config['min_selections']} options for '{$field_config['label']}'.";
                        $validation_errors[$field_name] = "Please select at least {$field_config['min_selections']} options.";
                    }
                }
                break;
                
            case 'textarea':
                if (isset($field_config['min_length']) && strlen($value) < $field_config['min_length']) {
                    $errors[] = "Field '{$field_config['label']}' must be at least {$field_config['min_length']} characters.";
                    $validation_errors[$field_name] = "Must be at least {$field_config['min_length']} characters.";
                }
                if (isset($field_config['max_length']) && strlen($value) > $field_config['max_length']) {
                    $errors[] = "Field '{$field_config['label']}' must be at most {$field_config['max_length']} characters.";
                    $validation_errors[$field_name] = "Must be at most {$field_config['max_length']} characters.";
                }
                break;
                
            case 'tel':
                if (!preg_match('/^\+?[\d\s\-\(\)]+$/', $value)) {
                    $errors[] = "Field '{$field_config['label']}' must be a valid phone number.";
                    $validation_errors[$field_name] = "Must be a valid phone number.";
                }
                break;
        }
    }
    
    if (empty($errors)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'errors' => $errors, 'validation_errors' => $validation_errors];
    }
}

function save_profile_to_session($post_data) {
    $_SESSION['profile_data'] = $post_data;
}

function load_profile_from_session() {
    return $_SESSION['profile_data'] ?? null;
}

function load_profile_from_database($user_id) {
    // This would load from user_profiles table when available
    // For now, return empty array
    return [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Complete Your Profile - Shaikhoology</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0; 
            min-height: 100vh; 
            color: #333;
        }
        .container { 
            max-width: 900px; 
            margin: 0 auto; 
            padding: 20px; 
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header { 
            text-align: center; 
            color: white; 
            margin-bottom: 30px;
        }
        .header h1 { 
            margin: 0 0 10px 0; 
            font-size: 2.5em; 
            font-weight: 700;
        }
        .header p { 
            margin: 0; 
            font-size: 1.1em; 
            opacity: 0.9;
        }
        .progress-container { 
            background: rgba(255,255,255,0.2); 
            border-radius: 15px; 
            padding: 20px; 
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }
        .progress-bar { 
            background: rgba(255,255,255,0.3); 
            height: 8px; 
            border-radius: 10px; 
            overflow: hidden; 
            margin-bottom: 15px;
        }
        .progress-fill { 
            background: linear-gradient(90deg, #4CAF50, #8BC34A); 
            height: 100%; 
            border-radius: 10px; 
            transition: width 0.3s ease;
        }
        .progress-text { 
            text-align: center; 
            color: white; 
            font-weight: 600; 
            margin-bottom: 10px;
        }
        .step-indicators { 
            display: flex; 
            justify-content: space-between; 
            margin-top: 10px;
        }
        .step-indicator { 
            width: 30px; 
            height: 30px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 600; 
            font-size: 12px;
            transition: all 0.3s ease;
        }
        .step-indicator.active { 
            background: #4CAF50; 
            color: white; 
        }
        .step-indicator.completed { 
            background: #2196F3; 
            color: white; 
        }
        .step-indicator.inactive { 
            background: rgba(255,255,255,0.3); 
            color: rgba(255,255,255,0.7);
        }
        .card { 
            background: white; 
            border-radius: 15px; 
            padding: 40px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            flex: 1;
        }
        .section-title { 
            color: #2c3e50; 
            margin: 0 0 10px 0; 
            font-size: 1.8em; 
            font-weight: 700;
        }
        .section-description { 
            color: #7f8c8d; 
            margin: 0 0 30px 0; 
            font-size: 1.1em;
        }
        .form-group { 
            margin-bottom: 25px; 
        }
        .form-row { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 20px; 
        }
        .form-group label { 
            display: block; 
            font-weight: 600; 
            margin-bottom: 8px; 
            color: #2c3e50; 
        }
        .required { color: #e74c3c; }
        .form-control { 
            width: 100%; 
            padding: 12px 16px; 
            border: 2px solid #ecf0f1; 
            border-radius: 8px; 
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        .form-control:focus { 
            border-color: #3498db; 
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        .form-control.error { 
            border-color: #e74c3c;
        }
        .help-text { 
            font-size: 12px; 
            color: #7f8c8d; 
            margin-top: 5px;
        }
        .error-text { 
            font-size: 12px; 
            color: #e74c3c; 
            margin-top: 5px;
        }
        .checkbox-group, .radio-group { 
            display: grid; 
            gap: 10px; 
        }
        .checkbox-item, .radio-item { 
            display: flex; 
            align-items: center; 
            gap: 10px;
        }
        .checkbox-item input, .radio-item input { 
            width: 18px; 
            height: 18px;
        }
        .checkbox-item label, .radio-item label { 
            margin: 0; 
            font-weight: normal;
        }
        .range-input { 
            -webkit-appearance: none;
            appearance: none;
            width: 100%; 
            height: 6px; 
            border-radius: 3px; 
            background: #ecf0f1; 
            outline: none;
        }
        .range-input::-webkit-slider-thumb { 
            -webkit-appearance: none; 
            appearance: none; 
            width: 20px; 
            height: 20px; 
            border-radius: 50%; 
            background: #3498db; 
            cursor: pointer;
        }
        .range-labels { 
            display: flex; 
            justify-content: space-between; 
            font-size: 12px; 
            color: #7f8c8d; 
            margin-top: 5px;
        }
        .alert { 
            padding: 15px 20px; 
            border-radius: 8px; 
            margin-bottom: 20px;
        }
        .alert-success { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        .alert-error { 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb;
        }
        .alert ul { 
            margin: 0; 
            padding-left: 20px;
        }
        .form-actions { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-top: 40px; 
            padding-top: 20px; 
            border-top: 1px solid #ecf0f1;
        }
        .btn { 
            padding: 12px 30px; 
            border: none; 
            border-radius: 8px; 
            font-size: 14px; 
            font-weight: 600; 
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { 
            background: linear-gradient(135deg, #3498db, #2980b9); 
            color: white;
        }
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        .btn-secondary { 
            background: #ecf0f1; 
            color: #2c3e50;
        }
        .btn-secondary:hover { 
            background: #d5dbdb;
        }
        .btn:disabled { 
            opacity: 0.6; 
            cursor: not-allowed;
        }
        .auto-save-status { 
            font-size: 12px; 
            color: #7f8c8d;
        }
        .footer { 
            text-align: center; 
            color: white; 
            margin-top: auto; 
            padding: 20px 0; 
            opacity: 0.8;
        }
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .card { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Complete Your Profile</h1>
            <p>Help us understand you better to provide the best trading experience</p>
        </div>

        <div class="progress-container">
            <div class="progress-text">
                Step <?= $current_step ?> of <?= $max_steps ?>: <?= h($current_step_data['title']) ?>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= ($current_step / $max_steps) * 100 ?>%"></div>
            </div>
            <div class="step-indicators">
                <?php for ($i = 1; $i <= $max_steps; $i++): ?>
                    <?php 
                    $step_class = 'inactive';
                    if ($i === $current_step) $step_class = 'active';
                    elseif ($i < $current_step) $step_class = 'completed';
                    ?>
                    <div class="step-indicator <?= $step_class ?>"><?= $i ?></div>
                <?php endfor; ?>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= h($_SESSION['flash']); unset($_SESSION['flash']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Please fix the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 class="section-title"><?= h($current_step_data['title']) ?></h2>
            <p class="section-description"><?= h($current_step_data['description']) ?></p>

            <form method="post" id="profileForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="current_step" value="<?= $current_step ?>">
                <input type="hidden" name="save_step" value="1">

                <div class="form-row">
                    <?php foreach ($current_step_data['fields'] as $field_name => $field_config): ?>
                        <div class="form-group <?= count($current_step_data['fields']) === 1 ? 'full-width' : '' ?>">
                            <label for="<?= h($field_name) ?>">
                                <?= h($field_config['label']) ?>
                                <?php if (!empty($field_config['required'])): ?>
                                    <span class="required">*</span>
                                <?php endif; ?>
                            </label>

                            <?php
                            $field_value = $profile_data[$field_name] ?? '';
                            $field_error = $validation_errors[$field_name] ?? '';
                            $field_class = 'form-control';
                            if ($field_error) $field_class .= ' error';
                            ?>

                            <?php if ($field_config['type'] === 'text' || $field_config['type'] === 'number' || $field_config['type'] === 'email' || $field_config['type'] === 'tel'): ?>
                                <input type="<?= h($field_config['type']) ?>" 
                                       id="<?= h($field_name) ?>" 
                                       name="<?= h($field_name) ?>" 
                                       class="<?= $field_class ?>"
                                       value="<?= h($field_value) ?>"
                                       placeholder="<?= h($field_config['placeholder'] ?? '') ?>"
                                       <?php if (isset($field_config['min'])) echo 'min="' . $field_config['min'] . '"'; ?>
                                       <?php if (isset($field_config['max'])) echo 'max="' . $field_config['max'] . '"'; ?>
                                       <?php if (isset($field_config['step'])) echo 'step="' . $field_config['step'] . '"'; ?>
                                       <?php if (!empty($field_config['required'])) echo 'required'; ?>>

                            <?php elseif ($field_config['type'] === 'textarea'): ?>
                                <textarea id="<?= h($field_name) ?>" 
                                          name="<?= h($field_name) ?>" 
                                          class="<?= $field_class ?>"
                                          rows="4"
                                          placeholder="<?= h($field_config['placeholder'] ?? '') ?>"
                                          <?php if (!empty($field_config['required'])) echo 'required'; ?>><?= h($field_value) ?></textarea>

                            <?php elseif ($field_config['type'] === 'select'): ?>
                                <select id="<?= h($field_name) ?>" 
                                        name="<?= h($field_name) ?>" 
                                        class="<?= $field_class ?>"
                                        <?php if (!empty($field_config['required'])) echo 'required'; ?>>
                                    <?php foreach ($field_config['options'] as $option_value => $option_label): ?>
                                        <option value="<?= h($option_value) ?>" <?= ($field_value === $option_value) ? 'selected' : ''; ?>>
                                            <?= h($option_label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($field_config['type'] === 'checkbox_group'): ?>
                                <div class="checkbox-group">
                                    <?php 
                                    $selected_values = is_array($field_value) ? $field_value : [];
                                    if (!is_array($selected_values) && $field_value) {
                                        $selected_values = [$field_value];
                                    }
                                    ?>
                                    <?php foreach ($field_config['options'] as $option_value => $option_label): ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" 
                                                   id="<?= h($field_name) ?>_<?= h($option_value) ?>" 
                                                   name="<?= h($field_name) ?>[]" 
                                                   value="<?= h($option_value) ?>"
                                                   <?= in_array($option_value, $selected_values) ? 'checked' : ''; ?>>
                                            <label for="<?= h($field_name) ?>_<?= h($option_value) ?>"><?= h($option_label) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($field_config['type'] === 'radio'): ?>
                                <div class="radio-group">
                                    <?php foreach ($field_config['options'] as $option_value => $option_label): ?>
                                        <div class="radio-item">
                                            <input type="radio" 
                                                   id="<?= h($field_name) ?>_<?= h($option_value) ?>" 
                                                   name="<?= h($field_name) ?>" 
                                                   value="<?= h($option_value) ?>"
                                                   <?= ($field_value === $option_value) ? 'checked' : ''; ?>>
                                            <label for="<?= h($field_name) ?>_<?= h($option_value) ?>"><?= h($option_label) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($field_config['type'] === 'range'): ?>
                                <input type="range" 
                                       id="<?= h($field_name) ?>" 
                                       name="<?= h($field_name) ?>" 
                                       class="range-input"
                                       value="<?= h($field_value ?: $field_config['min']) ?>"
                                       min="<?= $field_config['min'] ?>" 
                                       max="<?= $field_config['max'] ?>" 
                                       step="<?= $field_config['step'] ?? 1 ?>"
                                       <?php if (!empty($field_config['required'])) echo 'required'; ?>>
                                <div class="range-labels">
                                    <span><?= $field_config['min'] ?></span>
                                    <span id="<?= h($field_name) ?>_value"><?= h($field_value ?: $field_config['min']) ?></span>
                                    <span><?= $field_config['max'] ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($field_config['help_text'])): ?>
                                <div class="help-text"><?= h($field_config['help_text']) ?></div>
                            <?php endif; ?>

                            <?php if ($field_error): ?>
                                <div class="error-text"><?= h($field_error) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-actions">
                    <div class="auto-save-status" id="autoSaveStatus">
                        <i class="fas fa-save"></i> Auto-save enabled
                    </div>

                    <div style="display: flex; gap: 15px;">
                        <?php if ($current_step > 1): ?>
                            <a href="/profile_completion.php?step=<?= $current_step - 1 ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary" id="saveBtn">
                            <?php if ($current_step < $max_steps): ?>
                                <i class="fas fa-arrow-right"></i> Next Step
                            <?php else: ?>
                                <i class="fas fa-check"></i> Complete Profile
                            <?php endif; ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="footer">
            <p><i class="fas fa-lock"></i> Your information is secure and will only be used for profile verification</p>
            <p>© <?= date('Y') ?> Shaikhoology Trading Psychology</p>
        </div>
    </div>

    <script>
        // Auto-save functionality
        let autoSaveTimeout;
        const form = document.getElementById('profileForm');
        const autoSaveStatus = document.getElementById('autoSaveStatus');

        function showAutoSaveStatus(message, type = 'info') {
            autoSaveStatus.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-triangle' : 'save'}"></i> ${message}`;
            autoSaveStatus.style.color = type === 'success' ? '#27ae60' : type === 'error' ? '#e74c3c' : '#7f8c8d';
        }

        function autoSave() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                const formData = new FormData(form);
                formData.append('auto_save', '1');
                
                fetch('/profile_completion.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAutoSaveStatus('Changes saved automatically', 'success');
                    } else {
                        showAutoSaveStatus('Auto-save failed', 'error');
                    }
                })
                .catch(() => {
                    showAutoSaveStatus('Auto-save unavailable', 'error');
                });
            }, 2000); // Auto-save after 2 seconds of inactivity
        }

        // Add auto-save listeners to all form inputs
        form.addEventListener('input', autoSave);
        form.addEventListener('change', autoSave);

        // Range input value display
        document.querySelectorAll('input[type="range"]').forEach(input => {
            const valueDisplay = document.getElementById(input.id + '_value');
            if (valueDisplay) {
                input.addEventListener('input', () => {
                    valueDisplay.textContent = input.value;
                });
            }
        });

        // Form validation
        form.addEventListener('submit', function(e) {
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        });

        // Show completion message if all steps done
        <?php if (isset($_GET['completed']) && $_GET['completed'] == '1'): ?>
            setTimeout(() => {
                alert('Congratulations! You have successfully completed your profile. It will now be reviewed by our admin team. You will be notified once the review is complete.');
                window.location.href = '/pending_approval.php';
            }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>
