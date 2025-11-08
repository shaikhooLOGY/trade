<?php
// admin/mtm_model_view.php
// MTM Model details view with tasks and participants tabs

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php'; // Admin guard

$model_id = (int)($_GET['id'] ?? 0);
if (!$model_id) {
    header('Location: /admin/mtm_models.php');
    exit;
}

// Fetch model details
$hasCreatedBy = function_exists('db_has_col') ? db_has_col($mysqli, 'mtm_models', 'created_by') : false;
$creatorSelect = $hasCreatedBy ? 'COALESCE(u.full_name, u.email) AS creator_name' : "'System' AS creator_name";
$creatorJoin = $hasCreatedBy ? 'LEFT JOIN users u ON m.created_by = u.id' : '';

$sql = "
    SELECT m.*, {$creatorSelect}
    FROM mtm_models m
    {$creatorJoin}
    WHERE m.id = ?
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $model_id);
$stmt->execute();
$result = $stmt->get_result();
$model = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$model) {
    $_SESSION['flash'] = 'Model not found';
    header('Location: /admin/mtm_models.php');
    exit;
}

$tierLabels = mtm_get_tier_labels($mysqli);

// Enrollment stats
$enrollStats = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0
];
$stmt = $mysqli->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
    FROM mtm_enrollments WHERE model_id = ?");
$stmt->bind_param('i', $model_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    if ($row) {
        $enrollStats['total'] = (int)($row['total'] ?? 0);
        $enrollStats['approved'] = (int)($row['approved'] ?? 0);
        $enrollStats['pending'] = (int)($row['pending'] ?? 0);
    }
}
$stmt->close();

// Handle model status actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && empty($_POST['task_id'])) {
    $action = $_POST['action'];

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'CSRF token invalid';
        header("Location: /admin/mtm_model_view.php?id={$model_id}");
        exit;
    }

    $valid = ['activate', 'pause', 'archive', 'delete'];
    if (!in_array($action, $valid, true)) {
        $_SESSION['flash'] = 'Invalid action';
        header("Location: /admin/mtm_model_view.php?id={$model_id}");
        exit;
    }

    $currentStatus = $model['status'];

    if ($action === 'delete') {
        if (!in_array($currentStatus, ['draft', 'archived'], true)) {
            $_SESSION['flash'] = 'Only draft or archived models can be deleted';
            header("Location: /admin/mtm_model_view.php?id={$model_id}");
            exit;
        }
        try {
            $mysqli->begin_transaction();
            if ($stmt = $mysqli->prepare("DELETE FROM mtm_tasks WHERE model_id = ?")) {
                $stmt->bind_param('i', $model_id);
                $stmt->execute();
                $stmt->close();
            }
            if ($stmt = $mysqli->prepare("DELETE FROM mtm_enrollments WHERE model_id = ?")) {
                $stmt->bind_param('i', $model_id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $mysqli->prepare("DELETE FROM mtm_models WHERE id = ?");
            $stmt->bind_param('i', $model_id);
            $stmt->execute();
            $stmt->close();
            $mysqli->commit();
            $_SESSION['flash'] = 'Model deleted permanently';
            header('Location: /admin/mtm_models.php');
            exit;
        } catch (Throwable $e) {
            $mysqli->rollback();
            $_SESSION['flash'] = 'Failed to delete model';
            header("Location: /admin/mtm_model_view.php?id={$model_id}");
            exit;
        }
    } else {
        $status_map = [
            'activate' => 'active',
            'pause' => 'paused',
            'archive' => 'archived'
        ];
        $new_status = $status_map[$action];
        $hasUpdatedAt = function_exists('db_has_col') ? db_has_col($mysqli, 'mtm_models', 'updated_at') : false;
        $sql = $hasUpdatedAt
            ? "UPDATE mtm_models SET status = ?, updated_at = NOW() WHERE id = ?"
            : "UPDATE mtm_models SET status = ? WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('si', $new_status, $model_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = "Model status updated to {$new_status}";
        header("Location: /admin/mtm_model_view.php?id={$model_id}");
        exit;
    }
}

// Handle reorder requests (level-scoped)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['task_id']) && !empty($_POST['task_id'])) {
    $action = $_POST['action'];
    $task_id = (int)$_POST['task_id'];

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'CSRF token invalid';
        header("Location: /admin/mtm_model_view.php?id={$model_id}&tab=tasks");
        exit;
    }

    if (in_array($action, ['move_up', 'move_down'], true) && $task_id > 0) {
        $stmt = $mysqli->prepare("SELECT level, sort_order FROM mtm_tasks WHERE id = ? AND model_id = ?");
        $stmt->bind_param('ii', $task_id, $model_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($current) {
            $level = $current['level'];
            $currentOrder = (int)$current['sort_order'];
            $neighborSql = $action === 'move_up'
                ? "SELECT id, sort_order FROM mtm_tasks WHERE model_id = ? AND level = ? AND sort_order < ? ORDER BY sort_order DESC, id DESC LIMIT 1"
                : "SELECT id, sort_order FROM mtm_tasks WHERE model_id = ? AND level = ? AND sort_order > ? ORDER BY sort_order ASC, id ASC LIMIT 1";

            $stmt = $mysqli->prepare($neighborSql);
            $stmt->bind_param('isi', $model_id, $level, $currentOrder);
            $stmt->execute();
            $result = $stmt->get_result();
            $neighbor = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($neighbor) {
                $neighborId = (int)$neighbor['id'];
                $neighborOrder = (int)$neighbor['sort_order'];

                $mysqli->begin_transaction();
                try {
                    $update = $mysqli->prepare("UPDATE mtm_tasks SET sort_order = ? WHERE id = ?");
                    $newOrder = $neighborOrder;
                    $targetId = $task_id;
                    $update->bind_param('ii', $newOrder, $targetId);
                    $update->execute();

                    $newOrder = $currentOrder;
                    $targetId = $neighborId;
                    $update->execute();
                    $update->close();

                    $mysqli->commit();
                    $_SESSION['flash'] = 'Task order updated';
                } catch (Throwable $e) {
                    $mysqli->rollback();
                    $_SESSION['flash'] = 'Unable to reorder task';
                }
            } else {
                $_SESSION['flash'] = 'Task already at edge of this level';
            }
        }
        header("Location: /admin/mtm_model_view.php?id={$model_id}&tab=tasks");
        exit;
    }
}

// Get active tab
$tab = $_GET['tab'] ?? 'tasks';
if ($tab === 'participants') {
    header('Location: /admin/mtm_participants.php?model_id=' . $model_id);
    exit;
}

// Fetch data based on tab
// Fetch tasks
$stmt = $mysqli->prepare("
    SELECT id, title, level, sort_order
    FROM mtm_tasks
    WHERE model_id = ?
    ORDER BY sort_order ASC, id ASC
");
$stmt->bind_param('i', $model_id);
$stmt->execute();
$result = $stmt->get_result();
$tasks = [
    'easy' => [],
    'moderate' => [],
    'hard' => [],
    'other' => []
];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $levelKey = strtolower((string)($row['level'] ?? ''));
        if (!in_array($levelKey, ['easy','moderate','hard'], true)) {
            $levelKey = 'other';
        }
        $tasks[$levelKey][] = $row;
    }
}
$stmt->close();

// Flash message

$csrfToken = $_SESSION['csrf'] ?? '';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Page title
$title = htmlspecialchars($model['title']) . ' — MTM Admin';
include __DIR__ . '/../header.php';
?>

<style>
.model-header {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.model-cover-large {
    width: 100%;
    height: 200px;
    border-radius: 8px;
    object-fit: cover;
    margin-bottom: 16px;
}

.model-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 8px;
    color: #1a202c;
}

.model-meta {
    display: flex;
    gap: 24px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.header-layout {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    align-items: flex-start;
}

.header-main {
    flex: 1 1 420px;
    min-width: 280px;
}

.header-side {
    flex: 0 0 260px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
}

.header-side-title {
    font-size: 14px;
    font-weight: 600;
    color: #1a202c;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.participant-stats {
    display: grid;
    gap: 12px;
    margin: 16px 0;
}

.participant-stat {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.participant-stat .label {
    font-size: 13px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.participant-stat .value {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
}

.header-side .btn {
    display: block;
    width: 100%;
    text-align: center;
}

.tasks-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
    margin-top: 32px;
}

.tasks-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
}

.tasks-title {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: #1a202c;
}

.tasks-subtitle {
    margin: 6px 0 0;
    color: #6b7280;
    font-size: 13px;
}

.tasks-actions {
    display: flex;
    gap: 10px;
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

.tabs {
    display: flex;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 24px;
}

.tab {
    padding: 12px 24px;
    text-decoration: none;
    color: #718096;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    transition: all 0.2s ease;
}

.tab.active {
    color: #4f46e5;
    border-bottom-color: #4f46e5;
}

.tab:hover {
    color: #4f46e5;
}

.tasks-list {
    display: grid;
    gap: 16px;
}

.task-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.task-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
    color: #1a202c;
}

.task-level {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.level-easy { background: #ecfdf5; color: #065f46; }
.level-moderate { background: #fef3c7; color: #92400e; }
.level-hard { background: #fee2e2; color: #991b1b; }
.level-other { background: #e2e8f0; color: #2d3748; }

.task-rules {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
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
    gap: 8px;
    justify-content: flex-end;
}

.enrollments-list {
    display: grid;
    gap: 16px;
}

.enrollment-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
}

.enrollment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.enrollment-user {
    font-size: 18px;
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
.status-rejected { background: #fee2e2; color: #991b1b; }
.status-dropped { background: #f3f4f6; color: #374151; }
.status-completed { background: #ecfdf5; color: #065f46; }

.enrollment-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.enrollment-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.btn {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-primary { background: #4f46e5; color: white; }
.btn-primary:hover { background: #3730a3; }

.btn-secondary { background: #6b7280; color: white; }
.btn-secondary:hover { background: #4b5563; }

.btn-success { background: #059669; color: white; }
.btn-success:hover { background: #047857; }

.btn-danger { background: #dc2626; color: white; }
.btn-danger:hover { background: #b91c1c; }

.btn-warning { background: #d97706; color: white; }
.btn-warning:hover { background: #b45309; }

.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.page-top-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    justify-content: flex-end;
}

.header-controls {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-bottom: 16px;
}

@media (max-width: 768px) {
    .model-meta {
        flex-direction: column;
        gap: 12px;
    }

    .task-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .enrollment-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .header-layout {
        flex-direction: column;
    }

    .header-side {
        width: 100%;
    }

    .participant-stats {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    }

    .tasks-header {
        flex-direction: column;
        align-items: stretch;
    }

    .page-top-actions,
    .header-controls {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <?php if ($flash): ?>
        <div class="flash-message">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <div class="page-top-actions">
        <a href="/admin/mtm_models.php" class="btn btn-secondary">← Back to Models</a>
    </div>

    <!-- Model Header -->
    <div class="model-header">
        <div class="header-controls">
            <a href="/admin/mtm_model_edit.php?id=<?= $model_id ?>" class="btn btn-primary">Edit Model</a>
        </div>
        <?php if (!empty($model['cover_image_path'])): ?>
            <img src="/<?= htmlspecialchars(ltrim($model['cover_image_path'], '/')) ?>" alt="Cover" class="model-cover-large">
        <?php endif; ?>

        <div class="header-layout">
            <div class="header-main">
                <h1 class="model-title"><?= htmlspecialchars($model['title']) ?></h1>

                <?php if (!empty($model['description'])): ?>
                    <p style="color: #4a5568; margin: 8px 0 16px;"><?= htmlspecialchars($model['description']) ?></p>
                <?php endif; ?>

                <div class="model-meta">
                    <div class="meta-item">
                        <div class="meta-label">Status</div>
                        <div class="meta-value"><?= htmlspecialchars(ucfirst($model['status'])) ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Difficulty</div>
                        <div class="meta-value"><?= htmlspecialchars(mtm_format_tier_label($tierLabels, (string)$model['difficulty'])) ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Estimated Days</div>
                        <div class="meta-value"><?= (int)$model['estimated_days'] ?: 'TBD' ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Created By</div>
                        <div class="meta-value"><?= htmlspecialchars($model['creator_name'] ?? 'System') ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Created</div>
                        <div class="meta-value"><?= date('M j, Y', strtotime($model['created_at'])) ?></div>
                    </div>
                </div>

                <div style="margin-top:16px; display:flex; gap:8px; flex-wrap:wrap;">
                    <?php if ($model['status'] === 'active'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="pause">
                            <button type="submit" class="btn btn-warning" onclick="return confirm('Pause this model? Users won&#39;t be able to enroll.')">Pause</button>
                        </form>
                    <?php elseif (in_array($model['status'], ['paused', 'draft'], true)): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="activate">
                            <button type="submit" class="btn btn-success">Activate</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($model['status'] !== 'archived'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="archive">
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Archive this model? It will be hidden from users.')">Archive</button>
                        </form>
                    <?php elseif ($model['status'] === 'archived'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="activate">
                            <button type="submit" class="btn btn-success">Restore</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($model['status'], ['draft','archived'], true)): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Permanently delete this model? This cannot be undone.')">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="header-side">
                <div class="header-side-title">Enrollment Overview</div>
                <div class="participant-stats">
                    <div class="participant-stat">
                        <span class="label">Total</span>
                        <span class="value"><?= $enrollStats['total'] ?></span>
                    </div>
                    <div class="participant-stat">
                        <span class="label">Active</span>
                        <span class="value"><?= $enrollStats['approved'] ?></span>
                    </div>
                    <div class="participant-stat">
                        <span class="label">Pending</span>
                        <span class="value"><?= $enrollStats['pending'] ?></span>
                    </div>
                </div>
                <a href="/admin/mtm_participants.php?model_id=<?= $model_id ?>" class="btn btn-secondary">Manage Participants</a>
            </div>
        </div>
    </div>

    <!-- Tasks -->
    <section class="tasks-section" id="model-tasks">
        <div class="tasks-header">
            <div>
                <h2 class="tasks-title">Tasks</h2>
                <p class="tasks-subtitle">Review tasks by difficulty, adjust their order, or jump into the manager for bulk edits and reordering.</p>
            </div>
            <div class="tasks-actions">
                <a href="/admin/mtm_tasks.php?model_id=<?= $model_id ?>" class="btn btn-secondary">Open Tasks Manager</a>
                <a href="/admin/mtm_tasks.php?model_id=<?= $model_id ?>#create" class="btn btn-primary">+ Add New Task</a>
            </div>
        </div>

    <?php
    $sections = [
        'easy' => mtm_format_tier_label($tierLabels, 'easy'),
        'moderate' => mtm_format_tier_label($tierLabels, 'moderate'),
        'hard' => mtm_format_tier_label($tierLabels, 'hard'),
        'other' => 'Other'
    ];
    ?>

    <?php foreach ($sections as $levelKey => $heading): ?>
        <?php $sectionTasks = $tasks[$levelKey] ?? []; ?>
        <?php if (empty($sectionTasks)) continue; ?>

        <div style="margin-bottom:24px;">
            <h3 style="margin:0 0 12px;color:#4f46e5;"><?= htmlspecialchars($heading) ?></h3>
            <div class="tasks-list" data-level="<?= $levelKey ?>">
                <?php $sectionCount = count($sectionTasks); ?>
                <?php foreach ($sectionTasks as $index => $task): ?>
                    <?php $isFirst = ($index === 0); ?>
                    <?php $isLast = ($index === $sectionCount - 1); ?>
                    <div class="task-card" id="task-<?= (int)$task['id'] ?>">
                        <div class="task-header">
                            <h3 class="task-title" style="margin-bottom:4px;"><?= htmlspecialchars($task['title']) ?></h3>
                            <?php
                                $taskLevelKey = strtolower((string)$task['level']);
                                $taskLabel = $sections[$taskLevelKey] ?? ucfirst($taskLevelKey);
                            ?>
                            <span class="task-level level-<?= htmlspecialchars($taskLevelKey) ?>">
                                <?= htmlspecialchars($taskLabel) ?>
                            </span>
                        </div>
                        <div style="font-size:12px;color:#718096;margin-bottom:12px;">Sort Order: <?= (int)$task['sort_order'] ?></div>
                        <div class="task-actions" style="gap:6px; flex-wrap:wrap;">
                            <?php if (!$isFirst): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
                                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                    <button type="submit" name="action" value="move_up" class="btn btn-secondary" title="Move up within <?= htmlspecialchars($heading) ?>">↑ Move Up</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$isLast): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
                                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                    <button type="submit" name="action" value="move_down" class="btn btn-secondary" title="Move down within <?= htmlspecialchars($heading) ?>">↓ Move Down</button>
                                </form>
                            <?php endif; ?>
                            <a href="/admin/mtm_tasks.php?model_id=<?= $model_id ?>&mode=edit&id=<?= (int)$task['id'] ?>" class="btn btn-secondary">Edit Task</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($tasks['easy']) && empty($tasks['moderate']) && empty($tasks['hard']) && empty($tasks['other'])): ?>
        <div style="text-align: center; padding: 40px 20px; color: #6b7280;">
            <h3 style="margin-bottom:8px;">No tasks defined</h3>
            <p style="margin-bottom:16px;">Use the buttons above to add tasks and build this model’s playbook.</p>
            <a href="/admin/mtm_tasks.php?model_id=<?= $model_id ?>#create" class="btn btn-primary">+ Add New Task</a>
        </div>
    <?php endif; ?>
    </section>

<?php include __DIR__ . '/../footer.php'; ?>
