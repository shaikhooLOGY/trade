<?php
// Ensure CSRF token exists in session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Use unified CSRF helper
require_once __DIR__ . '/includes/security/csrf_unify.php';
$csrf = get_csrf_token();
?>
<?php
/**
 * MTM Enrollment Page
 *
 * Simple form to enroll in MTM models
 */

// Include required files - bootstrap.php now handles all dependencies
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/guard.php';

// Include MTM modules
require_once __DIR__ . '/includes/mtm/mtm_rules.php';

// Require authentication and active user
require_login();
require_active_user();

include __DIR__ . '/header.php';

// Get available models
$availableModels = get_available_models($GLOBALS['mysqli']);

// Flash messages
flash_out();
?>

<style>
.mtm-enroll-container {
    max-width: 800px;
    margin: 30px auto;
    padding: 0 16px;
}

.enrollment-form {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    padding: 24px;
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #1e293b;
}

.form-select, .form-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 16px;
}

.form-select:focus, .form-input:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.btn-submit {
    background: #4f46e5;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease;
}

.btn-submit:hover {
    background: #3730a3;
}

.btn-submit:disabled {
    background: #9ca3af;
    cursor: not-allowed;
}

.models-info {
    background: #f8fafc;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.model-card {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.model-card h4 {
    margin: 0 0 8px;
    color: #1e293b;
}

.tier-info {
    font-size: 14px;
    color: #64748b;
    margin: 4px 0;
}

.loading {
    display: none;
    color: #4f46e5;
    margin-left: 10px;
}

.success-message {
    background: #dcfce7;
    border: 1px solid #14532d;
    color: #14532d;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.error-message {
    background: #fee2e2;
    border: 1px solid #991b1b;
    color: #991b1b;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 20px;
}
</style>

<div class="mtm-enroll-container">
    <div class="enrollment-form">
        <h2>🎯 Enroll in MTM Model</h2>
        <p>Choose a trading model and tier to get started with your trading journey.</p>
        
        <form id="enrollmentForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            
            <div class="form-group">
                <label class="form-label" for="model_id">Select Model</label>
                <select class="form-select" id="model_id" name="model_id" required>
                    <option value="">Choose a model...</option>
                    <?php foreach ($availableModels as $model): ?>
                        <option value="<?= (int)$model['id'] ?>">
                            <?= htmlspecialchars($model['name']) ?> (<?= htmlspecialchars($model['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="tier">Select Tier</label>
                <select class="form-select" id="tier" name="tier" required>
                    <option value="">Choose a tier...</option>
                    <option value="basic">Basic - Perfect for beginners</option>
                    <option value="intermediate">Intermediate - For experienced traders</option>
                    <option value="advanced">Advanced - For expert traders</option>
                </select>
            </div>
            
            <button type="submit" class="btn-submit">
                Enroll Now
                <span class="loading" id="loadingIndicator">⏳</span>
            </button>
        </form>
        
        <div id="mtmEnrollError"></div>
        
        <div id="responseMessage"></div>
    </div>
    
    <div class="models-info">
        <h3>Available Models</h3>
        <?php foreach ($availableModels as $model): ?>
            <div class="model-card">
                <h4><?= h($model['name']) ?></h4>
                <p><strong>Code:</strong> <?= h($model['code']) ?></p>
                <?php if (isset($model['tiering']) && is_array($model['tiering'])): ?>
                    <div class="tier-info">
                        <strong>Tier Details:</strong><br>
                        <?php foreach ($model['tiering'] as $tier => $details): ?>
                            • <strong><?= ucfirst($tier) ?>:</strong> 
                            Max Trades: <?= $details['max_trades'] ?? 'N/A' ?>, 
                            Max Volume: <?= number_format($details['max_volume'] ?? 0) ?><br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.getElementById('enrollmentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('.btn-submit');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const responseMessage = document.getElementById('responseMessage');
    const errorBox = document.getElementById('mtmEnrollError');
    
    // Disable form and show loading
    submitBtn.disabled = true;
    loadingIndicator.style.display = 'inline';
    responseMessage.innerHTML = '';
    errorBox.innerHTML = '';
    
    try {
        // Get CSRF token from form
        const csrfToken = form.querySelector('input[name="csrf_token"]').value;
        
        // Prepare request data
        const modelId = parseInt(form.model_id.value);
        const tier = form.tier.value;
        const requestData = {
            model_id: modelId,
            tier: tier,
            csrf_token: csrfToken
        };
        
        // Console log the payload just before fetch
        console.log('Enroll payload:', { model_id: Number(modelId), tier, csrf_token: csrfToken });
        
        // Make API request with absolute URL and credentials
        const response = await fetch(`${window.location.origin}/api/mtm/enroll.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(requestData)
        });
        
        // Handle HTTP errors first
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({
                message: `HTTP ${response.status}: ${response.statusText}`
            }));
            
            errorBox.innerHTML = `
                <div class="error-message">
                    ❌ HTTP Error ${response.status}: ${errorData.message || errorData.error || 'Request failed'}
                </div>
            `;
            return;
        }
        
        const result = await response.json();
        
        if (result.success) {
            responseMessage.innerHTML = `
                <div class="success-message">
                    ✅ Enrollment successful! Enrollment ID: ${result.enrollment_id}
                    ${result.unlocked_task_id ? `, Unlocked Task ID: ${result.unlocked_task_id}` : ''}
                </div>
            `;
            // Reset form
            form.reset();
        } else {
            // Handle API errors with proper code mapping
            let errorMsg = 'Enrollment failed. Please try again.';
            if (result.code === 'CSRF_MISMATCH') {
                errorMsg = 'Security token mismatch. Please refresh the page and try again.';
            } else if (result.code === 'ALREADY_ENROLLED') {
                errorMsg = result.message || 'You are already enrolled in this model.';
            } else if (result.code === 'VALIDATION_ERROR') {
                errorMsg = 'Please check your input and try again.';
            } else if (result.message) {
                errorMsg = result.message;
            }
            
            errorBox.innerHTML = `
                <div class="error-message">
                    ❌ ${errorMsg}
                </div>
            `;
        }
        
    } catch (error) {
        // True network error (not HTTP response)
        errorBox.innerHTML = `
            <div class="error-message">
                ❌ Network error: ${error.message}. Please check your connection and try again.
            </div>
        `;
    } finally {
        // Re-enable form and hide loading
        submitBtn.disabled = false;
        loadingIndicator.style.display = 'none';
    }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
