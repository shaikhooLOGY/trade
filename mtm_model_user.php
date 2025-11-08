<?php
// mtm_model_user.php
// User view of MTM model details and progress

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/env.php';
// In non-prod, enforce expected DB to avoid accidental prod hits
db_assert_database($mysqli, $DB_NAME, APP_ENV !== 'prod');

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_active_user();

$user_id = (int)$_SESSION['user_id'];
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

// Check user's enrollment status
$enrollment = null;
$stmt = $mysqli->prepare("SELECT * FROM mtm_enrollments WHERE model_id = ? AND user_id = ?");
$stmt->bind_param('ii', $model_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $enrollment = $row;
}
$stmt->close();

// Fetch tasks with progress
$tasks = [];
if ($enrollment) {
    $stmt = $mysqli->prepare("
        SELECT
            mt.*,
            COALESCE(mtp.status, 'locked') as progress_status,
            COALESCE(mtp.attempts, 0) as attempts
        FROM mtm_tasks mt
        LEFT JOIN mtm_task_progress mtp ON mt.id = mtp.task_id AND mtp.enrollment_id = ?
        WHERE mt.model_id = ?
        ORDER BY mt.sort_order ASC, mt.id ASC
    ");
    $stmt->bind_param('ii', $enrollment['id'], $model_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
    }
    $stmt->close();
} else {
    // Show preview for non-enrolled users
    $stmt = $mysqli->prepare("SELECT * FROM mtm_tasks WHERE model_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param('i', $model_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['progress_status'] = 'preview';
            $tasks[] = $row;
        }
    }
    $stmt->close();
}

// Calculate progress stats
$total_tasks = count($tasks);
$completed_tasks = 0;
$unlocked_tasks = 0;
$current_task = null;

if ($enrollment) {
    foreach ($tasks as $task) {
        if ($task['progress_status'] === 'passed') {
            $completed_tasks++;
        }
        if (in_array($task['progress_status'], ['unlocked', 'in_progress', 'passed'])) {
            $unlocked_tasks++;
        }
        if ($task['progress_status'] === 'unlocked' && !$current_task) {
            $current_task = $task;
        }
    }
}

$progress_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

// Flash message
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Page title
$title = htmlspecialchars($model['title']) . ' — MTM Program';
include __DIR__ . '/header.php';
?>

<style>
.mtm-detail-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
}

.model-header {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    margin-bottom: 32px;
}

.model-cover-large {
    height: 240px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    position: relative;
    overflow: hidden;
}

.model-cover-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.model-header-content {
    padding: 32px;
}

.model-title {
    font-size: 32px;
    font-weight: 700;
    color: #1a202c;
    margin: 0 0 8px;
}

.model-subtitle {
    font-size: 18px;
    color: #6b7280;
    margin: 0 0 16px;
}

.model-description {
    color: #4a5568;
    line-height: 1.6;
    margin: 0 0 24px;
}

.model-meta {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}

.meta-item {
    display: flex;
    flex-direction: column;
}

.meta-label {
    font-size: 12px;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.meta-value {
    font-size: 16px;
    font-weight: 600;
    color: #2d3748;
}

.enrollment-banner {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 32px;
    text-align: center;
}

.enrollment-banner h3 {
    margin: 0 0 8px;
    font-size: 20px;
}

.enrollment-banner p {
    margin: 0 0 16px;
    opacity: 0.9;
}

.progress-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    margin-bottom: 32px;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.progress-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    color: #1a202c;
}

.progress-percentage {
    font-size: 24px;
    font-weight: 700;
    color: #4f46e5;
}

.progress-bar {
    width: 100%;
    height: 12px;
    background: #e5e7eb;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 16px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #4f46e5, #7c3aed);
    border-radius: 6px;
    transition: width 0.3s ease;
}

.progress-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 16px;
    font-size: 14px;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #2d3748;
    display: block;
}

.stat-label {
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tasks-section {
    margin-bottom: 32px;
}

.section-title {
    font-size: 24px;
    font-weight: 600;
    color: #1a202c;
    margin: 0 0 24px;
}

.tasks-list {
    display: grid;
    gap: 16px;
}

.task-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border: 1px solid #e5e7eb;
    transition: all 0.2s ease;
}

.task-card.locked {
    opacity: 0.6;
    background: #f9fafb;
}

.task-card.preview {
    opacity: 0.8;
    background: #fefefe;
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.task-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    color: #1a202c;
}

.task-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-locked { background: #f3f4f6; color: #374151; }
.status-unlocked { background: #fef3c7; color: #92400e; }
.status-passed { background: #ecfdf5; color: #065f46; }
.status-preview { background: #e0e7ff; color: #3730a3; }

.task-meta {
    display: flex;
    gap: 16px;
    margin-bottom: 16px;
    font-size: 14px;
    color: #6b7280;
}

.task-description {
    color: #4a5568;
    line-height: 1.6;
    margin-bottom: 20px;
}

.task-rules {
    background: #f9fafb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.task-rules h4 {
    margin: 0 0 12px;
    font-size: 16px;
    font-weight: 600;
    color: #1a202c;
}

.rules-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 8px;
}

.rule-item {
    font-size: 14px;
    color: #4a5568;
}

.rule-label {
    font-weight: 600;
    color: #2d3748;
}

.task-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
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

.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.current-task-highlight {
    border: 2px solid #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

@media (max-width: 768px) {
    .mtm-detail-container {
        padding: 16px;
    }

    .model-header-content {
        padding: 24px;
    }

    .model-meta {
        flex-direction: column;
        gap: 12px;
    }

    .progress-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .progress-stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .task-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .task-meta {
        flex-direction: column;
        gap: 8px;
    }

    .rules-grid {
        grid-template-columns: 1fr;
    }

    .task-actions {
        flex-direction: column;
    }
}
</style>

<div class="mtm-detail-container">
    <?php if ($flash): ?>
        <div class="flash-message">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Model Header -->
    <div class="model-header">
        <div class="model-cover-large">
            <?php if (!empty($model['cover_image_path'])): ?>
                <img src="/<?= htmlspecialchars(ltrim($model['cover_image_path'], '/')) ?>" alt="Cover">
            <?php endif; ?>
        </div>

        <div class="model-header-content">
            <h1 class="model-title"><?= htmlspecialchars($model['title']) ?></h1>
            <p class="model-subtitle">Mental Trading Model Program</p>

            <?php if (!empty($model['description'])): ?>
                <p class="model-description"><?= htmlspecialchars($model['description']) ?></p>
            <?php endif; ?>

            <div class="model-meta">
                <div class="meta-item">
                    <div class="meta-label">Difficulty</div>
                    <div class="meta-value"><?= htmlspecialchars(ucfirst($model['difficulty'])) ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Duration</div>
                    <div class="meta-value">
                        <?php if ($model['estimated_days'] > 0): ?>
                            <?= $model['estimated_days'] ?> days
                        <?php else: ?>
                            Self-paced
                        <?php endif; ?>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Tasks</div>
                    <div class="meta-value"><?= $total_tasks ?> total</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enrollment Banner (for non-enrolled users) -->
    <?php if (!$enrollment): ?>
        <div class="enrollment-banner">
            <h3>Ready to start your trading journey?</h3>
            <p>This structured program will help you develop disciplined trading habits through progressive challenges.</p>
            <a href="/mtm_enroll.php?id=<?= $model_id ?>" class="btn btn-primary" style="background: white; color: #059669; border: 2px solid white;">
                Enroll Now →
            </a>
        </div>
    <?php endif; ?>

    <!-- Progress Section (for enrolled users) -->
    <?php if ($enrollment && $enrollment['status'] === 'approved'): ?>
        <div class="progress-section">
            <div class="progress-header">
                <h2 class="progress-title">Your Progress</h2>
                <div class="progress-percentage"><?= $progress_percentage ?>%</div>
            </div>

            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $progress_percentage ?>%"></div>
            </div>

            <div class="progress-stats">
                <div class="stat-item">
                    <span class="stat-value"><?= $completed_tasks ?></span>
                    <span class="stat-label">Completed</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= $unlocked_tasks ?></span>
                    <span class="stat-label">Unlocked</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= $total_tasks - $completed_tasks ?></span>
                    <span class="stat-label">Remaining</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tasks Section -->
    <div class="tasks-section">
        <h2 class="section-title">
            <?php if ($enrollment): ?>
                Learning Tasks
            <?php else: ?>
                Program Preview
            <?php endif; ?>
        </h2>

        <div class="tasks-list">
            <?php foreach ($tasks as $index => $task): ?>
                <div class="task-card <?= htmlspecialchars($task['progress_status']) ?> <?= $task['progress_status'] === 'unlocked' ? 'current-task-highlight' : '' ?>">
                    <div class="task-header">
                        <h3 class="task-title">
                            Task <?= $index + 1 ?>: <?= htmlspecialchars($task['title']) ?>
                        </h3>
                        <span class="task-status status-<?= htmlspecialchars($task['progress_status']) ?>">
                            <?php
                            switch ($task['progress_status']) {
                                case 'locked': echo 'Locked'; break;
                                case 'unlocked': echo 'Current Task'; break;
                                case 'passed': echo 'Completed'; break;
                                case 'preview': echo 'Preview'; break;
                                default: echo htmlspecialchars($task['progress_status']);
                            }
                            ?>
                        </span>
                    </div>

                    <div class="task-meta">
                        <span><strong>Level:</strong> <?= htmlspecialchars(ucfirst($task['level'])) ?></span>
                        <?php if ($task['progress_status'] !== 'preview' && isset($task['attempts'])): ?>
                            <span><strong>Attempts:</strong> <?= (int)$task['attempts'] ?></span>
                        <?php endif; ?>
                        <?php if ($task['progress_status'] === 'passed' && isset($task['passed_at'])): ?>
                            <span><strong>Completed:</strong> <?= date('M j, Y', strtotime($task['passed_at'])) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($task['description'])): ?>
                        <p class="task-description"><?= htmlspecialchars($task['description']) ?></p>
                    <?php endif; ?>

                    <div class="task-rules">
                        <h4>Requirements</h4>
                        <div class="rules-grid">
                            <?php
                            $rules = [];
                            if ($task['min_trades'] > 0) $rules[] = "Complete {$task['min_trades']} trade" . ($task['min_trades'] > 1 ? 's' : '');
                            if ($task['time_window_days'] > 0) $rules[] = "Within {$task['time_window_days']} days";
                            if ($task['require_sl']) $rules[] = "Must use Stop Loss";
                            if ($task['max_risk_pct'] > 0) $rules[] = "Risk ≤ {$task['max_risk_pct']}% per trade";
                            if ($task['max_position_pct'] > 0) $rules[] = "Position ≤ {$task['max_position_pct']}%";
                            if ($task['min_rr'] > 0) $rules[] = "Min Risk:Reward {$task['min_rr']}";
                            if ($task['require_analysis_link']) $rules[] = "Include analysis link";
                            if ($task['weekly_min_trades'] > 0) $rules[] = "Min {$task['weekly_min_trades']} trades/week";
                            if ($task['weeks_consistency'] > 0) $rules[] = "Consistent for {$task['weeks_consistency']} weeks";

                            if (empty($rules)) {
                                $rules[] = "Complete trading task as specified";
                            }
                            ?>

                            <?php foreach ($rules as $rule): ?>
                                <div class="rule-item">• <?= htmlspecialchars($rule) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="task-actions">
                        <?php if ($task['progress_status'] === 'preview'): ?>
                            <span class="btn btn-disabled">Enroll to unlock</span>
                        <?php elseif ($task['progress_status'] === 'locked'): ?>
                            <span class="btn btn-disabled">Complete previous tasks</span>
                        <?php elseif ($task['progress_status'] === 'passed'): ?>
                            <span class="btn btn-disabled">✓ Completed</span>
                        <?php elseif ($task['progress_status'] === 'unlocked'): ?>
                            <a href="/trade_new.php?mtm_task=<?= $task['id'] ?>" class="btn btn-primary">
                                Start Trading Task →
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>