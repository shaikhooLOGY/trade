<?php
// admin/mtm_participants.php
// Manage MTM enrollment requests and participants

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$model_id = (int)($_GET['model_id'] ?? 0);
if (!$model_id) {
    header('Location: /admin/mtm_models.php');
    exit;
}

// Fetch model details
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

// 🔁 API Integration: replaced direct DB with /api/admin/enrollment/list.php
// Handle POST actions through API endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // 🔁 API Integration: replacing form-based approval workflow
    if ($action === 'approve' && isset($_POST['enrollment_id'])) {
        $enrollment_id = (int)$_POST['enrollment_id'];
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Make API call to approve enrollment
        $apiData = [
            'enrollment_id' => $enrollment_id,
            'admin_notes' => $admin_notes
        ];
        
        $response = make_admin_api_call('/api/admin/enrollment/approve.php', $apiData);
        
        if ($response && $response['success']) {
            $_SESSION['flash'] = 'Enrollment approved successfully';
        } else {
            $errorMsg = $response['message'] ?? 'Failed to approve enrollment';
            $_SESSION['flash'] = $errorMsg;
        }
        
        header("Location: /admin/mtm_participants.php?model_id={$model_id}&" . ($response && $response['success'] ? 'msg=approved' : 'err=approve_failed'));
        exit;
        
    } elseif ($action === 'reject' && isset($_POST['enrollment_id'])) {
        $enrollment_id = (int)$_POST['enrollment_id'];
        $reason = trim($_POST['reason'] ?? '');
        
        // Make API call to reject enrollment
        $apiData = [
            'enrollment_id' => $enrollment_id,
            'reason' => $reason
        ];
        
        $response = make_admin_api_call('/api/admin/enrollment/reject.php', $apiData);
        
        if ($response && $response['success']) {
            $_SESSION['flash'] = 'Enrollment rejected successfully';
        } else {
            $errorMsg = $response['message'] ?? 'Failed to reject enrollment';
            $_SESSION['flash'] = $errorMsg;
        }
        
        header("Location: /admin/mtm_participants.php?model_id={$model_id}&" . ($response && $response['success'] ? 'msg=rejected' : 'err=reject_failed'));
        exit;
        
    } elseif ($action === 'drop' && isset($_POST['enrollment_id'])) {
        $enrollment_id = (int)$_POST['enrollment_id'];
        
        // Make API call to drop participant
        $apiData = [
            'enrollment_id' => $enrollment_id
        ];
        
        $response = make_admin_api_call('/api/admin/enrollment/drop.php', $apiData);
        
        if ($response && $response['success']) {
            $_SESSION['flash'] = 'Participant dropped from MTM';
        } else {
            $errorMsg = $response['message'] ?? 'Failed to drop participant';
            $_SESSION['flash'] = $errorMsg;
        }
        
        header("Location: /admin/mtm_participants.php?model_id={$model_id}&" . ($response && $response['success'] ? 'msg=dropped' : 'err=drop_failed'));
        exit;
    }
    
    header("Location: /admin/mtm_participants.php?model_id={$model_id}");
    exit;
}

// 🔁 API Integration: helper function for admin API calls
function make_admin_api_call($endpoint, $data) {
    // 🔁 API Integration: CSRF protection for mutating operations
    $csrf = get_csrf_token();
    $data['csrf_token'] = $csrf;
    
    $url = $_SERVER['HTTP_HOST'] . $endpoint;
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'User-Agent: Admin-Panel/1.0'
            ],
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents('http://' . $url, false, $context);
    
    if ($response === false) {
        return ['success' => false, 'message' => 'API call failed'];
    }
    
    $decoded = json_decode($response, true);
    return $decoded;
}

// 🔁 API Integration: replaced direct DB with /api/admin/enrollment/list.php
// Fetch enrollments through API endpoint
$enrollments = [];
$groupedEnrollments = [
    'pending' => [],
    'approved' => [],
    'rejected' => [],
    'dropped' => [],
    'completed' => []
];

try {
    $url = $_SERVER['HTTP_HOST'] . '/api/admin/enrollment/list.php?model_id=' . $model_id . '&limit=1000';
    $response = file_get_contents('http://' . $url);
    
    if ($response) {
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['success']) && $decoded['success']) {
            $apiEnrollments = $decoded['data']['enrollments'] ?? [];
            
            // Convert API response to expected format
            $enrollments = $apiEnrollments['all'] ?? [];
            $groupedEnrollments = $apiEnrollments;
        }
    }
} catch (Exception $e) {
    // Fallback to empty array if API call fails
    $_SESSION['flash'] = 'Warning: Could not load enrollments from API';
}

// 🔁 API Integration: grouped enrollments already provided by API endpoint
$pending = $groupedEnrollments['pending'];
$approved = $groupedEnrollments['approved'];
$rejected = $groupedEnrollments['rejected'];
$dropped = $groupedEnrollments['dropped'];
$completed = $groupedEnrollments['completed'];

// Flash message
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Page title
$title = htmlspecialchars($model['title']) . ' Participants — Admin';
include __DIR__ . '/../header.php';
?>

<style>
.participants-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.model-header {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.model-title {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px;
    color: #1a202c;
}

.model-meta {
    color: #6b7280;
    margin: 0;
}

.tabs {
    display: flex;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 24px;
    background: white;
    border-radius: 8px 8px 0 0;
    overflow: hidden;
}

.tab {
    flex: 1;
    padding: 16px 24px;
    text-align: center;
    text-decoration: none;
    color: #6b7280;
    font-weight: 500;
    background: #f9fafb;
    border-bottom: 2px solid transparent;
    transition: all 0.2s ease;
    position: relative;
}

.tab.active {
    color: #4f46e5;
    background: white;
    border-bottom-color: #4f46e5;
}

.tab-count {
    display: inline-block;
    background: #e5e7eb;
    color: #374151;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 8px;
}

.tab.active .tab-count {
    background: #c7d2fe;
    color: #3730a3;
}

.enrollments-section {
    background: white;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    overflow: hidden;
}

.section-header {
    padding: 20px 24px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
    color: #1a202c;
}

.enrollment-list {
    max-height: 600px;
    overflow-y: auto;
}

.enrollment-card {
    padding: 20px 24px;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.2s ease;
}

.enrollment-card:hover {
    background: #f9fafb;
}

.enrollment-card:last-child {
    border-bottom: none;
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

.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #ecfdf5; color: #065f46; }
.status-rejected { background: #fee2e2; color: #991b1b; }
.status-dropped { background: #f3f4f6; color: #374151; }
.status-completed { background: #ecfdf5; color: #065f46; }

.enrollment-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
    font-size: 14px;
    color: #6b7280;
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

.btn-primary {
    background: #4f46e5;
    color: white;
}

.btn-primary:hover {
    background: #3730a3;
}

.btn-success {
    background: #059669;
    color: white;
}

.btn-success:hover {
    background: #047857;
}

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-warning {
    background: #d97706;
    color: white;
}

.btn-warning:hover {
    background: #b45309;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.page-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state h3 {
    margin: 0 0 8px;
    color: #374151;
}

@media (max-width: 768px) {
    .enrollment-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .enrollment-meta {
        grid-template-columns: 1fr;
    }

    .enrollment-actions {
        flex-direction: column;
    }

    .tabs {
        flex-direction: column;
    }

    .tab {
        text-align: left;
    }
}
</style>

<div class="participants-container">
    <?php if ($flash): ?>
        <div class="flash-message">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Model Header -->
    <div class="model-header">
        <h1 class="model-title"><?= htmlspecialchars($model['title']) ?></h1>
        <p class="model-meta">
            Difficulty: <?= htmlspecialchars(ucfirst($model['difficulty'])) ?> |
            Status: <?= htmlspecialchars(ucfirst($model['status'])) ?> |
            Total Participants: <?= count($enrollments) ?>
        </p>
    </div>

    <!-- Page Actions -->
    <div class="page-actions">
        <a href="/admin/mtm_models.php" class="btn btn-secondary">← Back to Models</a>
        <a href="/admin/mtm_model_view.php?id=<?= $model_id ?>" class="btn btn-secondary">View Model</a>
        <a href="/admin/mtm_model_edit.php?id=<?= $model_id ?>" class="btn btn-secondary">Edit Model</a>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?model_id=<?= $model_id ?>&tab=pending" class="tab <?= ($_GET['tab'] ?? 'pending') === 'pending' ? 'active' : '' ?>">
            Pending Requests
            <span class="tab-count"><?= count($pending) ?></span>
        </a>
        <a href="?model_id=<?= $model_id ?>&tab=approved" class="tab <?= ($_GET['tab'] ?? 'pending') === 'approved' ? 'active' : '' ?>">
            Active Participants
            <span class="tab-count"><?= count($approved) ?></span>
        </a>
        <a href="?model_id=<?= $model_id ?>&tab=completed" class="tab <?= ($_GET['tab'] ?? 'pending') === 'completed' ? 'active' : '' ?>">
            Completed
            <span class="tab-count"><?= count($completed) ?></span>
        </a>
        <a href="?model_id=<?= $model_id ?>&tab=rejected" class="tab <?= ($_GET['tab'] ?? 'pending') === 'rejected' ? 'active' : '' ?>">
            Rejected/Dropped
            <span class="tab-count"><?= count($rejected) + count($dropped) ?></span>
        </a>
    </div>

    <!-- Tab Content -->
    <div class="enrollments-section">
        <?php
        $active_tab = $_GET['tab'] ?? 'pending';
        $current_enrollments = [];

        switch ($active_tab) {
            case 'pending':
                $current_enrollments = $pending;
                $section_title = 'Pending Enrollment Requests';
                break;
            case 'approved':
                $current_enrollments = $approved;
                $section_title = 'Active Participants';
                break;
            case 'completed':
                $current_enrollments = $completed;
                $section_title = 'Completed Participants';
                break;
            case 'rejected':
                $current_enrollments = array_merge($rejected, $dropped);
                $section_title = 'Rejected/Dropped Participants';
                break;
        }
        ?>

        <div class="section-header">
            <h2 class="section-title"><?= $section_title ?></h2>
        </div>

        <div class="enrollment-list">
            <?php if (empty($current_enrollments)): ?>
                <div class="empty-state">
                    <h3>No participants in this category</h3>
                    <p>
                        <?php if ($active_tab === 'pending'): ?>
                            No pending enrollment requests at this time.
                        <?php elseif ($active_tab === 'approved'): ?>
                            No active participants in this MTM yet.
                        <?php elseif ($active_tab === 'completed'): ?>
                            No participants have completed this MTM yet.
                        <?php else: ?>
                            No rejected or dropped participants.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($current_enrollments as $enrollment): ?>
                    <div class="enrollment-card">
                        <div class="enrollment-header">
                            <h3 class="enrollment-user">
                                <a href="/admin/user_profile.php?id=<?= $enrollment['user_id'] ?>" style="color: inherit; text-decoration: none;">
                                    <?= htmlspecialchars($enrollment['user_name']) ?>
                                </a>
                            </h3>
                            <span class="enrollment-status status-<?= htmlspecialchars($enrollment['status']) ?>">
                                <?= htmlspecialchars($enrollment['status']) ?>
                            </span>
                        </div>

                        <div class="enrollment-meta">
                            <div>
                                <strong>Requested:</strong> <?= date('M j, Y H:i', strtotime($enrollment['requested_at'])) ?>
                            </div>
                            <?php if ($enrollment['approved_at']): ?>
                                <div>
                                    <strong>Approved:</strong> <?= date('M j, Y H:i', strtotime($enrollment['approved_at'])) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong>Email:</strong> <?= htmlspecialchars($enrollment['user_email']) ?>
                            </div>
                            <?php if ($enrollment['status'] === 'approved'): ?>
                                <div>
                                    <strong>Progress:</strong> <?= (int)($enrollment['task_progress']['passed'] ?? 0) ?>/<?= (int)($enrollment['task_progress']['total'] ?? 0) ?> tasks completed
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="enrollment-actions">
                            <?php if ($enrollment['status'] === 'pending'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                                    <input type="hidden" name="enrollment_id" value="<?= $enrollment['id'] ?>">
                                    <button type="submit" name="action" value="approve" class="btn btn-success"
                                            onclick="return confirm('Approve this enrollment request?')">Approve</button>
                                </form>

                                <button type="button" class="btn btn-danger" onclick="showRejectModal(<?= $enrollment['id'] ?>)">Reject</button>
                            <?php elseif ($enrollment['status'] === 'approved'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                                    <input type="hidden" name="enrollment_id" value="<?= $enrollment['id'] ?>">
                                    <button type="submit" name="action" value="drop" class="btn btn-danger"
                                            onclick="return confirm('Drop this participant from the MTM?')">Drop</button>
                                </form>
                            <?php endif; ?>

                            <a href="/admin/user_profile.php?id=<?= $enrollment['user_id'] ?>" class="btn btn-secondary">View Profile</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 24px; border-radius: 8px; max-width: 400px; width: 90%;">
        <h3 style="margin: 0 0 16px;">Reject Enrollment</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(get_csrf_token()) ?>">
            <input type="hidden" name="enrollment_id" id="reject_enrollment_id">
            <input type="hidden" name="action" value="reject">

            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Reason (optional)</label>
                <textarea name="reason" rows="3" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px;" placeholder="Optional reason for rejection"></textarea>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="hideRejectModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject Enrollment</button>
            </div>
        </form>
    </div>
</div>

<script>
function showRejectModal(enrollmentId) {
    document.getElementById('reject_enrollment_id').value = enrollmentId;
    document.getElementById('rejectModal').style.display = 'block';
}

function hideRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideRejectModal();
    }
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>