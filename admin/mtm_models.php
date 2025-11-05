<?php
// admin/mtm_models.php
// MTM Models listing and management for admins (clean single render)

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo 'Access denied - Admin privileges required';
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

$hasCreatedBy = function_exists('db_has_col') ? db_has_col($mysqli, 'mtm_models', 'created_by') : false;
$creatorSelect = $hasCreatedBy ? 'COALESCE(u.full_name, u.email) AS creator_name' : "'System' AS creator_name";
$creatorJoin = $hasCreatedBy ? 'LEFT JOIN users u ON m.created_by = u.id' : '';

$query = "
    SELECT
        m.*,
        {$creatorSelect},
        COALESCE(stats.total_enrollments, 0) AS total_enrollments,
        COALESCE(stats.approved_enrollments, 0) AS approved_enrollments,
        COALESCE(stats.pending_enrollments, 0) AS pending_enrollments
    FROM mtm_models m
    {$creatorJoin}
    LEFT JOIN (
        SELECT
            model_id,
            COUNT(*) AS total_enrollments,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_enrollments,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_enrollments
        FROM mtm_enrollments
        GROUP BY model_id
    ) stats ON stats.model_id = m.id
    ORDER BY m.display_order ASC, m.created_at DESC
";

$result = $mysqli->query($query);
$models = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$title = 'MTM Models — Admin';
include __DIR__ . '/../header.php';
?>

<style>
.mtm-models-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.model-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    overflow: hidden;
    transition: transform 0.2s ease;
}

.model-card:hover {
    transform: translateY(-2px);
}

.model-cover {
    height: 120px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    position: relative;
    overflow: hidden;
}

.model-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.model-content {
    padding: 16px;
}

.model-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 8px;
    color: #1a202c;
}

.model-meta {
    font-size: 14px;
    color: #718096;
    margin-bottom: 12px;
}

.model-stats {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
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
    font-size: 12px;
    color: #a0aec0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.model-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
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

.btn-warning {
    background: #d97706;
    color: white;
}

.btn-warning:hover {
    background: #b45309;
}

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-active { background: #ecfdf5; color: #065f46; }
.status-paused { background: #fef3c7; color: #92400e; }
.status-archived { background: #fee2e2; color: #991b1b; }
.status-draft { background: #f3f4f6; color: #374151; }

.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.page-title {
    margin: 0;
    color: #1a202c;
}

@media (max-width: 768px) {
    .mtm-models-grid {
        grid-template-columns: 1fr;
    }

    .page-header {
        flex-direction: column;
        gap: 16px;
        align-items: flex-start;
    }
}
</style>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <?php if ($flash): ?>
        <div class="flash-message">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h1 class="page-title">MTM Models</h1>
        <a href="/admin/mtm_model_edit.php" class="btn btn-primary">+ Create New Model</a>
    </div>

    <?php if (empty($models)): ?>
        <div style="text-align: center; padding: 60px 20px; color: #6b7280;">
            <h3>No MTM models found</h3>
            <p>Create your first model to get started with structured trading programs.</p>
            <a href="/admin/mtm_model_edit.php" class="btn btn-primary" style="margin-top: 20px;">Create First Model</a>
        </div>
    <?php else: ?>
        <div class="mtm-models-grid">
            <?php foreach ($models as $model): ?>
                <div class="model-card">
                    <div class="model-cover">
                        <?php if (!empty($model['cover_image_path'])): ?>
                            <img src="/<?= htmlspecialchars(ltrim($model['cover_image_path'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="Cover">
                        <?php endif; ?>
                        <div style="position: absolute; top: 8px; right: 8px;">
                            <span class="status-badge status-<?= htmlspecialchars($model['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($model['status'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>

                    <div class="model-content">
                        <h3 class="model-title"><?= htmlspecialchars($model['title'], ENT_QUOTES, 'UTF-8') ?></h3>

                        <div class="model-meta">
                            <?php
                                $diff = strtolower((string)$model['difficulty']);
                                $tierMap = [
                                    'easy' => 'Tier 1 (Easy)',
                                    'moderate' => 'Tier 2 (Moderate)',
                                    'hard' => 'Tier 3 (Hard)',
                                ];
                                $tierLabel = isset($tierMap[$diff]) ? $tierMap[$diff] : ucfirst($diff);
                            ?>
                            <div>Difficulty: <?= htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <div>Created by: <?= htmlspecialchars($model['creator_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div>Days: <?= $model['estimated_days'] ? (int)$model['estimated_days'] : 'TBD' ?></div>
                        </div>

                        <div class="model-stats">
                            <div class="stat-item">
                                <span class="stat-value"><?= (int)$model['total_enrollments'] ?></span>
                                <span class="stat-label">Total</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?= (int)$model['approved_enrollments'] ?></span>
                                <span class="stat-label">Active</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?= (int)$model['pending_enrollments'] ?></span>
                                <span class="stat-label">Pending</span>
                            </div>
                        </div>

                        <div class="model-actions">
                            <a href="/admin/mtm_model_view.php?id=<?= (int)$model['id'] ?>" class="btn btn-secondary">Manage Model</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
