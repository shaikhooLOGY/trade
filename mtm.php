<?php
// mtm.php
// User MTM programs listing and enrollment discovery

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/env.php';
// In non-prod, enforce expected DB to avoid accidental prod hits
db_assert_database($mysqli, $DB_NAME, APP_ENV !== 'prod');

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user_id = (int)$_SESSION['user_id'];
$user_status = strtolower((string)($_SESSION['status'] ?? ''));
$email_verified = (int)($_SESSION['email_verified'] ?? 0);
$user_can_enroll = ($email_verified === 1 && in_array($user_status, ['active', 'approved'], true));

// Check if user already has approved enrollment (One-MTM policy)
$has_approved_enrollment = false;
$approved_enrollment = null;
$stmt = $mysqli->prepare("
    SELECT e.*, m.title, m.difficulty
    FROM mtm_enrollments e
    JOIN mtm_models m ON e.model_id = m.id
    WHERE e.user_id = ? AND e.status = 'approved'
    LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $has_approved_enrollment = true;
    $approved_enrollment = $row;
}
$stmt->close();

// Fetch user's enrollments
$user_enrollments = [];
$stmt = $mysqli->prepare("
    SELECT e.*, m.title, m.difficulty, m.cover_image_path,
           COUNT(tp.id) as total_tasks,
           COUNT(CASE WHEN tp.status = 'passed' THEN 1 END) as completed_tasks
    FROM mtm_enrollments e
    JOIN mtm_models m ON e.model_id = m.id
    LEFT JOIN mtm_task_progress tp ON e.id = tp.enrollment_id
    WHERE e.user_id = ?
    GROUP BY e.id
    ORDER BY e.requested_at DESC
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $user_enrollments[] = $row;
    }
}
$stmt->close();

// Group enrollments
$approved_enrollments = array_filter($user_enrollments, fn($e) => $e['status'] === 'approved');
$pending_enrollments = array_filter($user_enrollments, fn($e) => $e['status'] === 'pending');
$completed_enrollments = array_filter($user_enrollments, fn($e) => in_array($e['status'], ['completed', 'dropped']));

// Fetch available models (not enrolled by user)
$available_models = [];
$stmt = $mysqli->prepare("
    SELECT m.*,
           COUNT(DISTINCT e.id) as total_participants,
           COUNT(DISTINCT CASE WHEN e.status = 'approved' THEN e.id END) as active_participants
    FROM mtm_models m
    LEFT JOIN mtm_enrollments e ON m.id = e.model_id
    WHERE m.status = 'active'
    AND m.id NOT IN (
        SELECT model_id FROM mtm_enrollments
        WHERE user_id = ? AND status IN ('approved', 'pending')
    )
    GROUP BY m.id
    ORDER BY m.display_order ASC, m.created_at DESC
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $available_models[] = $row;
    }
}
$stmt->close();

// Flash message
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Page title
$title = 'MTM Programs — Shaikhoology';
include __DIR__ . '/header.php';
?>

<style>
.mtm-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    text-align: center;
    margin-bottom: 40px;
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    color: #1a202c;
    margin: 0 0 8px;
}

.page-subtitle {
    color: #6b7280;
    font-size: 18px;
    margin: 0;
}

.section {
    margin-bottom: 48px;
}

.section-title {
    font-size: 24px;
    font-weight: 600;
    color: #1a202c;
    margin: 0 0 24px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e5e7eb;
}

.models-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
}

.model-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
}

.model-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}

.model-cover {
    height: 160px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    position: relative;
    overflow: hidden;
}

.model-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.model-status {
    position: absolute;
    top: 12px;
    right: 12px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.model-content {
    padding: 20px;
}

.model-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0 0 8px;
    color: #1a202c;
}

.model-description {
    color: #6b7280;
    margin: 0 0 16px;
    line-height: 1.5;
}

.model-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    font-size: 14px;
}

.model-difficulty {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.difficulty-easy { background: #ecfdf5; color: #065f46; }
.difficulty-moderate { background: #fef3c7; color: #92400e; }
.difficulty-hard { background: #fee2e2; color: #991b1b; }

.model-stats {
    color: #6b7280;
    font-size: 14px;
}

.model-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
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

.btn-success {
    background: #059669;
    color: white;
}

.btn-success:hover {
    background: #047857;
}

.btn-outline {
    background: transparent;
    border: 2px solid #d1d5db;
    color: #374151;
}

.btn-outline:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.btn-disabled {
    background: #e5e7eb;
    color: #9ca3af;
    cursor: not-allowed;
}

.btn-disabled:hover {
    background: #e5e7eb;
}

.enrollment-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border: 1px solid #e5e7eb;
}

.enrollment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.enrollment-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    color: #1a202c;
}

.enrollment-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-approved { background: #ecfdf5; color: #065f46; }
.status-pending { background: #fef3c7; color: #92400e; }
.status-completed { background: #ecfdf5; color: #065f46; }
.status-dropped { background: #f3f4f6; color: #374151; }

.enrollment-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
    font-size: 14px;
    color: #6b7280;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
    margin: 8px 0;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #4f46e5, #7c3aed);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.policy-notice {
    background: #fef3c7;
    border: 1px solid #f59e0b;
    color: #92400e;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 24px;
}

.policy-notice h4 {
    margin: 0 0 8px;
    font-size: 16px;
    font-weight: 600;
}

@media (max-width: 768px) {
    .mtm-container {
        padding: 16px;
    }

    .models-grid {
        grid-template-columns: 1fr;
    }

    .model-meta {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .model-actions {
        flex-direction: column;
    }

    .enrollment-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }

    .enrollment-meta {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="mtm-container">
    <?php if ($flash): ?>
        <div class="flash-message">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h1 class="page-title">MTM Programs</h1>
        <p class="page-subtitle">Mental Trading Models — Structured learning paths for disciplined trading</p>
    </div>

    <?php if (!$user_can_enroll): ?>
        <div class="policy-notice">
            <h4>🔒 Account Pending Activation</h4>
            <p>Your MTM enrollment will unlock once your email is verified and your trading account is approved.
               Complete the onboarding steps or contact support if you believe this is an error.</p>
        </div>
    <?php endif; ?>

    <?php if ($has_approved_enrollment): ?>
        <div class="policy-notice">
            <h4>📚 Active MTM Enrollment</h4>
            <p>You are currently enrolled in "<strong><?= htmlspecialchars($approved_enrollment['title']) ?></strong>".
               You can only have one active MTM at a time. Complete or drop your current enrollment to join other programs.</p>
        </div>
    <?php endif; ?>

    <!-- Your Programs Section -->
    <?php if (!empty($approved_enrollments) || !empty($pending_enrollments)): ?>
        <div class="section">
            <h2 class="section-title">Your Programs</h2>

            <?php if (!empty($approved_enrollments)): ?>
                <div style="margin-bottom: 32px;">
                    <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin: 0 0 16px;">Active Enrollment</h3>
                    <?php foreach ($approved_enrollments as $enrollment): ?>
                        <div class="enrollment-card">
                            <div class="enrollment-header">
                                <h3 class="enrollment-title"><?= htmlspecialchars($enrollment['title']) ?></h3>
                                <span class="enrollment-status status-approved">Active</span>
                            </div>

                            <div class="enrollment-meta">
                                <div>
                                    <strong>Difficulty:</strong> <?= htmlspecialchars(ucfirst($enrollment['difficulty'])) ?>
                                </div>
                                <div>
                                    <strong>Enrolled:</strong> <?= date('M j, Y', strtotime($enrollment['joined_at'] ?? $enrollment['approved_at'])) ?>
                                </div>
                                <div>
                                    <strong>Progress:</strong> <?= (int)$enrollment['completed_tasks'] ?>/<?= (int)$enrollment['total_tasks'] ?> tasks
                                </div>
                            </div>

                            <?php
                            $progress_pct = $enrollment['total_tasks'] > 0 ? (($enrollment['completed_tasks'] / $enrollment['total_tasks']) * 100) : 0;
                            ?>
                            <div>
                                <div style="display: flex; justify-content: space-between; font-size: 14px; color: #6b7280; margin-bottom: 4px;">
                                    <span>Progress</span>
                                    <span><?= round($progress_pct) ?>%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $progress_pct ?>%"></div>
                                </div>
                            </div>

                            <div style="margin-top: 20px;">
                                <a href="/mtm_model_user.php?id=<?= $enrollment['model_id'] ?>" class="btn btn-primary">
                                    Continue Learning →
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($pending_enrollments)): ?>
                <div>
                    <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin: 0 0 16px;">Pending Requests</h3>
                    <?php foreach ($pending_enrollments as $enrollment): ?>
                        <div class="enrollment-card">
                            <div class="enrollment-header">
                                <h3 class="enrollment-title"><?= htmlspecialchars($enrollment['title']) ?></h3>
                                <span class="enrollment-status status-pending">Pending Approval</span>
                            </div>

                            <div class="enrollment-meta">
                                <div>
                                    <strong>Requested:</strong> <?= date('M j, Y', strtotime($enrollment['requested_at'])) ?>
                                </div>
                                <div>
                                    <strong>Difficulty:</strong> <?= htmlspecialchars(ucfirst($enrollment['difficulty'])) ?>
                                </div>
                            </div>

                            <div style="margin-top: 20px; color: #6b7280; font-size: 14px;">
                                Your enrollment request is being reviewed by administrators. You'll receive a notification once approved.
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Available Programs Section -->
    <div class="section">
        <h2 class="section-title">Available Programs</h2>

        <?php if (empty($available_models)): ?>
            <div style="text-align: center; padding: 60px 20px; color: #6b7280;">
                <h3>No programs available</h3>
                <p>All available MTM programs are currently full or you've already enrolled in them.</p>
            </div>
        <?php else: ?>
            <div class="models-grid">
                <?php foreach ($available_models as $model): ?>
                    <div class="model-card">
                        <div class="model-cover">
                            <?php if (!empty($model['cover_image_path'])): ?>
                                <img src="/<?= htmlspecialchars(ltrim($model['cover_image_path'], '/')) ?>" alt="Cover">
                            <?php endif; ?>
                        </div>

                        <div class="model-content">
                            <h3 class="model-title"><?= htmlspecialchars($model['title']) ?></h3>

                            <?php if (!empty($model['description'])): ?>
                                <p class="model-description">
                                    <?= htmlspecialchars(substr($model['description'], 0, 120)) ?>
                                    <?= strlen($model['description']) > 120 ? '...' : '' ?>
                                </p>
                            <?php endif; ?>

                            <div class="model-meta">
                                <span class="model-difficulty difficulty-<?= htmlspecialchars($model['difficulty']) ?>">
                                    <?= htmlspecialchars($model['difficulty']) ?>
                                </span>
                                <span class="model-stats">
                                    <?= (int)$model['active_participants'] ?>/<?= (int)$model['total_participants'] ?> enrolled
                                </span>
                            </div>

                            <div class="model-actions">
                                <a href="/mtm_model_user.php?id=<?= $model['id'] ?>" class="btn btn-outline">
                                    Preview
                                </a>

                                <?php if (!$user_can_enroll): ?>
                                    <span class="btn btn-disabled" title="Complete your onboarding to request enrollment">
                                        Enrollment Locked
                                    </span>
                                <?php elseif ($has_approved_enrollment): ?>
                                    <span class="btn btn-disabled" title="Complete your current MTM first">
                                        Enroll
                                    </span>
                                <?php else: ?>
                                    <a href="/mtm_enroll.php?id=<?= $model['id'] ?>" class="btn btn-primary">
                                        Enroll Now
                                    </a>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Completed Programs Section -->
    <?php if (!empty($completed_enrollments)): ?>
        <div class="section">
            <h2 class="section-title">Completed Programs</h2>

            <div class="models-grid">
                <?php foreach ($completed_enrollments as $enrollment): ?>
                    <div class="model-card">
                        <div class="model-cover">
                            <?php if (!empty($enrollment['cover_image_path'])): ?>
                                <img src="/<?= htmlspecialchars(ltrim($enrollment['cover_image_path'], '/')) ?>" alt="Cover">
                            <?php endif; ?>
                        </div>

                        <div class="model-content">
                            <h3 class="model-title"><?= htmlspecialchars($enrollment['title']) ?></h3>

                            <div class="model-meta">
                                <span class="model-difficulty difficulty-<?= htmlspecialchars($enrollment['difficulty']) ?>">
                                    <?= htmlspecialchars($enrollment['difficulty']) ?>
                                </span>
                                <span class="enrollment-status status-<?= htmlspecialchars($enrollment['status']) ?>">
                                    <?= htmlspecialchars($enrollment['status']) ?>
                                </span>
                            </div>

                            <div class="model-actions">
                                <a href="/mtm_model_user.php?id=<?= $enrollment['model_id'] ?>" class="btn btn-secondary">
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
