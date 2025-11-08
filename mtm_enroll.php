<?php
// mtm_enroll.php
// MTM enrollment disclaimer and confirmation

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user_id = (int)$_SESSION['user_id'];
$user_status = strtolower((string)($_SESSION['status'] ?? ''));
$email_verified = (int)($_SESSION['email_verified'] ?? 0);
$user_can_enroll = ($email_verified === 1 && in_array($user_status, ['active', 'approved'], true));
$model_id = (int)($_GET['id'] ?? 0);

if (!$model_id) {
    header('Location: /mtm.php');
    exit;
}

// Fetch model details
$stmt = $mysqli->prepare("SELECT * FROM mtm_models WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $model_id);
$stmt->execute();
$result = $stmt->get_result();
$model = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$model) {
    $_SESSION['flash'] = 'MTM program not found or not available';
    header('Location: /mtm.php');
    exit;
}

// Check if user already has approved enrollment (One-MTM policy)
$has_approved_enrollment = false;
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM mtm_enrollments WHERE user_id = ? AND status = 'approved'");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $has_approved_enrollment = (int)$row['COUNT(*)'] > 0;
}
$stmt->close();

if ($has_approved_enrollment) {
    $_SESSION['flash'] = 'You already have an active MTM enrollment. Complete it first before joining another program.';
    header('Location: /mtm.php');
    exit;
}

// Check if user already has a pending request for this model
$has_pending_request = false;
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM mtm_enrollments WHERE user_id = ? AND model_id = ? AND status = 'pending'");
$stmt->bind_param('ii', $user_id, $model_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $has_pending_request = (int)$row['COUNT(*)'] > 0;
}
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'agree_disclaimer') {
    if (!$user_can_enroll) {
        $_SESSION['flash_error'] = 'Your account must be approved before you can submit an enrollment request.';
        header('Location: /mtm_enroll.php?id='.(int)($_GET['id'] ?? 0));
        exit;
    }
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['flash_error'] = 'Security check failed. Please try again.';
        header('Location: /mtm_enroll.php?id='.(int)($_GET['id'] ?? 0));
        exit;
    }
    if (empty($_POST['agree'])) {
        $_SESSION['flash_error'] = 'Please agree to all terms.';
        header('Location: mtm_enroll.php?id='.(int)($_GET['id'] ?? 0));
        exit;
    }

    if (!$has_pending_request) {
        // Create new enrollment request
        $stmt = $mysqli->prepare("
            INSERT INTO mtm_enrollments (model_id, user_id, status, requested_at)
            VALUES (?, ?, 'pending', NOW())
            ON DUPLICATE KEY UPDATE status = 'pending', requested_at = NOW()
        ");
        $stmt->bind_param('ii', $model_id, $user_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash'] = 'Enrollment request submitted! An administrator will review your application.';
    } else {
        $_SESSION['flash'] = 'You already have a pending request for this program.';
    }

    header("Location: /mtm.php");
    exit;
}

if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-danger" style="max-width:960px;margin:16px auto;">
    <?= htmlspecialchars($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']);
endif; ?>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="max-width:960px;margin:16px auto;">
    <?= htmlspecialchars($_SESSION['flash']) ?>
  </div>
  <?php unset($_SESSION['flash']);
endif; ?>

<?php
$flash = $_SESSION['flash'] ?? '';
// Page title
$title = 'Enroll in ' . htmlspecialchars($model['title']) . ' — MTM Program';
include __DIR__ . '/header.php';
?>

<style>
.enroll-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.enroll-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    overflow: hidden;
}

.model-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 32px;
    text-align: center;
}

.model-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 8px;
}

.model-subtitle {
    font-size: 18px;
    opacity: 0.9;
    margin: 0 0 16px;
}

.model-meta {
    display: flex;
    justify-content: center;
    gap: 24px;
    flex-wrap: wrap;
}

.meta-item {
    text-align: center;
}

.meta-label {
    font-size: 12px;
    opacity: 0.8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.meta-value {
    font-size: 16px;
    font-weight: 600;
}

.disclaimer-content {
    padding: 32px;
}

.disclaimer-section {
    margin-bottom: 32px;
}

.disclaimer-title {
    font-size: 20px;
    font-weight: 600;
    color: #1a202c;
    margin: 0 0 16px;
}

.disclaimer-text {
    color: #4a5568;
    line-height: 1.6;
    margin: 0 0 16px;
}

.requirements-list {
    background: #f9fafb;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.requirements-title {
    font-size: 16px;
    font-weight: 600;
    color: #1a202c;
    margin: 0 0 12px;
}

.requirements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 12px;
}

.requirement-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 14px;
    color: #4a5568;
}

.requirement-item::before {
    content: "✓";
    color: #10b981;
    font-weight: bold;
    flex-shrink: 0;
}

.commitment-section {
    background: #fef3c7;
    border: 1px solid #f59e0b;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.commitment-title {
    font-size: 16px;
    font-weight: 600;
    color: #92400e;
    margin: 0 0 8px;
}

.commitment-text {
    color: #92400e;
    margin: 0;
    line-height: 1.5;
}

.policy-notice {
    background: #fee2e2;
    border: 1px solid #dc2626;
    border-radius: 8px;
    padding: 16px;
    margin: 20px 0;
}

.policy-title {
    font-size: 16px;
    font-weight: 600;
    color: #991b1b;
    margin: 0 0 8px;
}

.policy-text {
    color: #991b1b;
    margin: 0;
    line-height: 1.5;
}

.enroll-form {
    border-top: 1px solid #e5e7eb;
    padding: 32px;
    background: #f9fafb;
}

.checkbox-group {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 24px;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    flex-shrink: 0;
}

.checkbox-label {
    font-size: 14px;
    color: #4a5568;
    line-height: 1.5;
    margin: 0;
}

.form-actions {
    display: flex;
    gap: 16px;
    justify-content: center;
}

.btn {
    padding: 14px 32px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
}

.btn-primary {
    background: #4f46e5;
    color: white;
}

.btn-primary:hover {
    background: #3730a3;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.btn:disabled {
    background: #e5e7eb;
    color: #9ca3af;
    cursor: not-allowed;
}

.btn:disabled:hover {
    background: #e5e7eb;
}

.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .enroll-container {
        padding: 16px;
    }

    .model-header {
        padding: 24px;
    }

    .model-meta {
        flex-direction: column;
        gap: 12px;
    }

    .requirements-grid {
        grid-template-columns: 1fr;
    }

    .disclaimer-content {
        padding: 24px;
    }

    .enroll-form {
        padding: 24px;
    }

    .form-actions {
        flex-direction: column;
    }
}
</style>

<div class="enroll-container">
    <?php if ($flash): ?>
        <div class="flash-message">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <div class="enroll-card">
        <?php if (!$user_can_enroll): ?>
            <div class="policy-notice" style="margin:0 24px 16px;">
                <h3 class="policy-title" style="margin-top:0;">Account Pending Approval</h3>
                <p class="policy-text" style="margin:0;">
                    You can review the MTM program details, but enrollment requests are available once your email is verified and your profile is approved.
                </p>
            </div>
        <?php endif; ?>

        <!-- Model Header -->
        <div class="model-header">
            <h1 class="model-title">Enroll in <?= htmlspecialchars($model['title']) ?></h1>
            <p class="model-subtitle">Mental Trading Model Program</p>

            <div class="model-meta">
                <div class="meta-item">
                    <div class="meta-label">Difficulty</div>
                    <div class="meta-value"><?= htmlspecialchars(ucfirst($model['difficulty'])) ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Commitment</div>
                    <div class="meta-value">
                        <?php if ($model['estimated_days'] > 0): ?>
                            <?= $model['estimated_days'] ?> days
                        <?php else: ?>
                            Self-paced
                        <?php endif; ?>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Program Type</div>
                    <div class="meta-value">Structured Learning</div>
                </div>
            </div>
        </div>

        <!-- Disclaimer Content -->
        <div class="disclaimer-content">
            <div class="disclaimer-section">
                <h2 class="disclaimer-title">About This Program</h2>
                <p class="disclaimer-text">
                    This Mental Trading Model (MTM) program is designed to help you develop disciplined, rule-based trading habits.
                    Through structured challenges and progressive learning, you'll build the psychological foundation needed for consistent trading success.
                </p>

                <?php if (!empty($model['description'])): ?>
                    <p class="disclaimer-text">
                        <strong>Program Focus:</strong> <?= htmlspecialchars($model['description']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="requirements-list">
                <h3 class="requirements-title">What You'll Need</h3>
                <div class="requirements-grid">
                    <div class="requirement-item">Active trading account with real or paper trading access</div>
                    <div class="requirement-item">Commitment to following program rules and guidelines</div>
                    <div class="requirement-item">Time for daily trading activities and analysis</div>
                    <div class="requirement-item">Willingness to maintain detailed trading records</div>
                    <div class="requirement-item">Access to trading analysis tools and platforms</div>
                    <div class="requirement-item">Dedication to learning and improving trading discipline</div>
                </div>
            </div>

            <div class="commitment-section">
                <h3 class="commitment-title">Time Commitment</h3>
                <p class="commitment-text">
                    This program requires consistent effort. You'll need to dedicate time for:
                </p>
                <ul style="color: #92400e; margin: 8px 0 0; padding-left: 20px;">
                    <li>Daily trading activities and analysis</li>
                    <li>Completing assigned tasks and challenges</li>
                    <li>Maintaining detailed trading journals</li>
                    <li>Reviewing and reflecting on your trading decisions</li>
                    <li>Participating in program discussions and feedback</li>
                </ul>
            </div>

            <div class="policy-notice">
                <h3 class="policy-title">Important Policies</h3>
                <ul style="color: #991b1b; margin: 8px 0 0; padding-left: 20px;">
                    <li><strong>One Program at a Time:</strong> You can only be enrolled in one MTM program simultaneously</li>
                    <li><strong>Trading Restrictions:</strong> During enrollment, you can only submit trades through this program</li>
                    <li><strong>Admin Approval:</strong> Your enrollment requires administrator approval</li>
                    <li><strong>Program Rules:</strong> All trades must follow the specific rules and guidelines of each task</li>
                    <li><strong>Accountability:</strong> Regular progress tracking and adherence to program requirements</li>
                </ul>
            </div>

            <div class="disclaimer-section">
                <h2 class="disclaimer-title">By Enrolling, You Agree To:</h2>
                <ul style="color: #4a5568; margin: 8px 0 0; padding-left: 20px; line-height: 1.6;">
                    <li>Follow all program rules and trading guidelines strictly</li>
                    <li>Maintain honest and accurate trading records</li>
                    <li>Complete assigned tasks within specified timeframes</li>
                    <li>Participate actively in the learning process</li>
                    <li>Accept constructive feedback from program administrators</li>
                    <li>Respect the program's code of conduct and community guidelines</li>
                    <li>Understand that trading involves financial risk and results are not guaranteed</li>
                </ul>
            </div>
        </div>

        <!-- Enrollment Form -->
        <form method="post" class="enroll-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
            <input type="hidden" name="action" value="agree_disclaimer">

            <div class="checkbox-group">
                <input type="checkbox" id="agree_rules" name="agree_rules" required>
                <label for="agree_rules" class="checkbox-label">
                    I understand and agree to follow all program rules, guidelines, and requirements
                </label>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="agree_commitment" name="agree_commitment" required>
                <label for="agree_commitment" class="checkbox-label">
                    I commit to dedicating the necessary time and effort to complete this program successfully
                </label>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="agree" name="agree" value="1" required>
                <label for="agree" class="checkbox-label">
                    I understand that trading involves financial risk and that program participation does not guarantee profits.
                </label>
            </div>

            <div style="text-align:center; margin-bottom: 24px;">
                <button type="button" class="btn btn-secondary" id="accept-all-btn">Accept All</button>
            </div>

            <div class="form-actions">
                <a href="/mtm.php" class="btn btn-secondary">Cancel</a>
                <button type="submit"
                        class="btn btn-primary"
                        id="enroll-btn"
                        disabled
                        data-locked="<?= (!$user_can_enroll || $has_pending_request) ? '1' : '0' ?>">
                    <?php if (!$user_can_enroll): ?>
                        Enrollment Locked
                    <?php elseif ($has_pending_request): ?>
                        Request Already Submitted
                    <?php else: ?>
                        Submit Enrollment Request
                    <?php endif; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Enable/disable submit button based on checkboxes
function updateSubmitButton() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][required]');
    const submitBtn = document.getElementById('enroll-btn');
    if (!submitBtn) return;

    const isLocked = submitBtn.dataset.locked === '1';
    if (isLocked) {
        submitBtn.disabled = true;
        return;
    }

    const allChecked = Array.from(checkboxes).every(cb => cb.checked);

    submitBtn.disabled = !allChecked;
    submitBtn.textContent = allChecked ? 'Submit Enrollment Request' : 'Please agree to all terms';
}

document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][required]');
    const acceptAllBtn = document.getElementById('accept-all-btn');

    checkboxes.forEach(cb => cb.addEventListener('change', updateSubmitButton));
    updateSubmitButton();

    if (acceptAllBtn) {
        acceptAllBtn.addEventListener('click', function() {
            checkboxes.forEach(cb => {
                cb.checked = true;
            });
            updateSubmitButton();
        });
    }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
