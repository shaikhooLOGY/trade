<?php
// admin/mtm_model_edit.php
// Focused editor for MTM model metadata only

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$user_id = (int)$_SESSION['user_id'];
$model_id = (int)($_GET['id'] ?? 0);
$is_edit = $model_id > 0;

$model = [
    'title' => '',
    'description' => '',
    'difficulty' => 'easy',
    'status' => 'draft',
    'estimated_days' => 0,
    'display_order' => 0,
    'cover_image_path' => null,
];

if ($is_edit) {
    $stmt = $mysqli->prepare("SELECT * FROM mtm_models WHERE id = ?");
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = 'CSRF token invalid';
        header("Location: /admin/mtm_model_edit.php" . ($is_edit ? "?id={$model_id}" : ""));
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $difficultyInput = strtolower((string)($_POST['difficulty'] ?? 'easy'));
    switch ($difficultyInput) {
        case 'tier1':
        case 'tier_1':
        case 'tier-1':
            $difficulty = 'easy';
            break;
        case 'tier2':
        case 'tier_2':
        case 'tier-2':
            $difficulty = 'moderate';
            break;
        case 'tier3':
        case 'tier_3':
        case 'tier-3':
            $difficulty = 'hard';
            break;
        case 'easy':
        case 'moderate':
        case 'hard':
            $difficulty = $difficultyInput;
            break;
        default:
            $difficulty = 'easy';
            break;
    }
    $status = $_POST['status'] ?? 'draft';
    $estimated_days = max(0, (int)($_POST['estimated_days'] ?? 0));
    $display_order = (int)($_POST['display_order'] ?? 0);

    if ($title === '') {
        $_SESSION['flash'] = 'Title is required';
        header("Location: /admin/mtm_model_edit.php" . ($is_edit ? "?id={$model_id}" : ""));
        exit;
    }

    $cover_path = $model['cover_image_path'] ?? null;
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/mtm/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            $_SESSION['flash'] = 'Invalid image format. Use JPG, PNG, GIF, or WEBP.';
            header("Location: /admin/mtm_model_edit.php" . ($is_edit ? "?id={$model_id}" : ""));
            exit;
        }

        if (!empty($cover_path) && file_exists(__DIR__ . '/../' . ltrim($cover_path, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($cover_path, '/'));
        }

        $filename = 'cover_' . ($is_edit ? $model_id : 'new') . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_dir . $filename)) {
            $cover_path = 'uploads/mtm/' . $filename;
        }
    }

    $hasCreatedBy = function_exists('db_has_col') ? db_has_col($mysqli, 'mtm_models', 'created_by') : false;
    $hasUpdatedAt = function_exists('db_has_col') ? db_has_col($mysqli, 'mtm_models', 'updated_at') : false;

    if ($is_edit) {
        $sql = "
            UPDATE mtm_models
               SET title = ?,
                   description = ?,
                   difficulty = ?,
                   status = ?,
                   estimated_days = ?,
                   display_order = ?,
                   cover_image_path = ?" .
                   ($hasUpdatedAt ? ", updated_at = NOW()" : "") .
             " WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            'ssssiisi',
            $title,
            $description,
            $difficulty,
            $status,
            $estimated_days,
            $display_order,
            $cover_path,
            $model_id
        );
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = 'Model updated successfully';
        header("Location: /admin/mtm_model_view.php?id={$model_id}");
        exit;
    }

    $columns = [
        'title',
        'description',
        'difficulty',
        'status',
        'estimated_days',
        'display_order',
        'cover_image_path'
    ];
    $placeholders = array_fill(0, count($columns), '?');
    if ($cover_path === null) {
        $cover_path = null;
    }
    $types = 'ssssiis';
    $values = [$title, $description, $difficulty, $status, $estimated_days, $display_order, $cover_path];

    if ($hasCreatedBy) {
        $columns[] = 'created_by';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $user_id;
    }

    $sql = "INSERT INTO mtm_models (" . implode(', ', $columns) . ", created_at) VALUES (" . implode(', ', $placeholders) . ", NOW())";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $new_id = $stmt->insert_id;
    $stmt->close();

    $_SESSION['flash'] = 'Model created successfully';
    header("Location: /admin/mtm_model_view.php?id={$new_id}");
    exit;
}

$csrf = $_SESSION['csrf'] ?? '';
$title_text = $is_edit ? 'Edit MTM Model' : 'Create MTM Model';
$subtitle = $is_edit ? 'Update model details and presentation' : 'Set up a new structured trading program';

include __DIR__ . '/../header.php';
?>

<style>
.form-container {
    max-width: 800px;
    margin: 0 auto;
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.form-group { margin-bottom: 24px; }
.form-label {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}
.form-input, .form-textarea, .form-select {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}
.form-input:focus, .form-textarea:focus, .form-select:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}
.form-textarea { min-height: 120px; resize: vertical; }
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}
.btn {
    padding: 12px 24px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-primary { background: #4f46e5; color: white; }
.btn-primary:hover { background: #3730a3; }
.btn-secondary { background: #6b7280; color: white; }
.btn-secondary:hover { background: #4b5563; }
.cover-preview {
    max-width: 200px;
    border-radius: 8px;
    margin-top: 8px;
}
.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.page-header {
    text-align: center;
    margin-bottom: 32px;
}
.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #1a202c;
    margin: 0 0 8px;
}
.page-subtitle {
    color: #6b7280;
    margin: 0;
}
@media (max-width: 768px) {
    .form-container { margin: 16px; padding: 24px; }
    .form-grid { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
}
</style>

<div style="max-width: 1000px; margin: 0 auto; padding: 20px;">
    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="flash-message">
            <?= htmlspecialchars($_SESSION['flash']) ?>
        </div>
    <?php unset($_SESSION['flash']); endif; ?>

    <div class="page-header">
        <h1 class="page-title"><?= htmlspecialchars($title_text) ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($subtitle) ?></p>
    </div>

    <form method="post" enctype="multipart/form-data" class="form-container">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="form-group">
            <label class="form-label">Model Title *</label>
            <input type="text" name="title" class="form-input" value="<?= htmlspecialchars($model['title'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-textarea"><?= htmlspecialchars($model['description'] ?? '') ?></textarea>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Tier</label>
                <select name="difficulty" class="form-select">
                    <option value="easy" <?= ($model['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Tier 1</option>
                    <option value="moderate" <?= ($model['difficulty'] ?? '') === 'moderate' ? 'selected' : '' ?>>Tier 2</option>
                    <option value="hard" <?= ($model['difficulty'] ?? '') === 'hard' ? 'selected' : '' ?>>Tier 3</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="draft" <?= ($model['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="active" <?= ($model['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="paused" <?= ($model['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                    <option value="archived" <?= ($model['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Estimated Days</label>
                <input type="number" name="estimated_days" class="form-input" value="<?= (int)($model['estimated_days'] ?? 0) ?>" min="0">
            </div>
            <div class="form-group">
                <label class="form-label">Display Order</label>
                <input type="number" name="display_order" class="form-input" value="<?= (int)($model['display_order'] ?? 0) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Cover Image</label>
            <input type="file" name="cover_image" accept="image/*" class="form-input">
            <?php if (!empty($model['cover_image_path'])): ?>
                <div style="margin-top: 8px;">
                    <img src="/<?= htmlspecialchars(ltrim($model['cover_image_path'], '/')) ?>" alt="Current cover" class="cover-preview">
                    <p style="color: #6b7280; font-size: 12px; margin: 4px 0;">Upload a new image to replace the current cover.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <a href="<?= $is_edit ? "/admin/mtm_model_view.php?id={$model_id}" : '/admin/mtm_models.php' ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $is_edit ? 'Update Model' : 'Create Model' ?>
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
