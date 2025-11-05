<?php
// mtm_leaderboard.php
// MTM-specific leaderboard for enrolled participants

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_active_user();

$user_id = (int)$_SESSION['user_id'];
$model_id = (int)($_GET['model_id'] ?? 0);

if (!$model_id) {
    header('Location: /mtm.php');
    exit;
}

// Verify user is enrolled in this model
$stmt = $mysqli->prepare("
    SELECT e.*, m.title, m.difficulty
    FROM mtm_enrollments e
    JOIN mtm_models m ON e.model_id = m.id
    WHERE e.model_id = ? AND e.user_id = ? AND e.status = 'approved'
");
$stmt->bind_param('ii', $model_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$enrollment = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$enrollment) {
    $_SESSION['flash'] = 'You must be enrolled in this MTM program to view its leaderboard.';
    header('Location: /mtm.php');
    exit;
}

// Fetch all enrolled participants for this model
$stmt = $mysqli->prepare("
    SELECT
        u.id,
        COALESCE(u.full_name, u.email) as display_name,
        u.email,
        COUNT(t.id) as total_trades,
        COUNT(CASE WHEN t.outcome NOT IN ('OPEN', '') THEN 1 END) as closed_trades,
        COALESCE(SUM(t.points), 0) as total_points,
        AVG(CASE WHEN t.pl_percent IS NOT NULL THEN t.pl_percent END) as avg_return,
        SUM(CASE WHEN t.compliance_status = 'pass' THEN 1 ELSE 0 END) as compliant_trades,
        COUNT(CASE WHEN t.compliance_status = 'override' THEN 1 END) as override_trades,
        COUNT(CASE WHEN t.compliance_status = 'fail' THEN 1 END) as failed_trades
    FROM mtm_enrollments e
    JOIN users u ON e.user_id = u.id
    LEFT JOIN trades t ON e.id = t.enrollment_id AND t.deleted_at IS NULL
    WHERE e.model_id = ? AND e.status = 'approved'
    GROUP BY u.id, u.full_name, u.email
    ORDER BY total_points DESC, total_trades DESC
");
$stmt->bind_param('i', $model_id);
$stmt->execute();
$result = $stmt->get_result();
$participants = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Calculate additional metrics
foreach ($participants as &$participant) {
    $participant['compliance_rate'] = $participant['total_trades'] > 0
        ? round(($participant['compliant_trades'] / $participant['total_trades']) * 100, 1)
        : 0;

    $participant['win_rate'] = $participant['closed_trades'] > 0
        ? round((($participant['total_points'] / max($participant['closed_trades'], 1)) / 10) * 100, 1)
        : 0; // Rough estimate based on points system
}

// Find current user's position
$current_user_rank = null;
foreach ($participants as $index => $participant) {
    if ($participant['id'] == $user_id) {
        $current_user_rank = $index + 1;
        break;
    }
}

// Flash message
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Page title
$title = htmlspecialchars($enrollment['title']) . ' — MTM Leaderboard';
include __DIR__ . '/header.php';
?>

<style>
.mtm-leaderboard-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
}

.leaderboard-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 32px;
    text-align: center;
}

.leaderboard-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 8px;
}

.leaderboard-subtitle {
    opacity: 0.9;
    margin: 0 0 16px;
}

.user-rank {
    background: rgba(255,255,255,0.2);
    border-radius: 20px;
    padding: 8px 16px;
    display: inline-block;
    font-weight: 600;
}

.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    text-align: center;
    border: 1px solid #e5e7eb;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #4f46e5;
    display: block;
    margin-bottom: 4px;
}

.stat-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.participants-table {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.table-header {
    background: #f9fafb;
    padding: 16px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.table-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
    color: #1a202c;
}

.table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.participants-table table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.participants-table th,
.participants-table td {
    padding: 16px 24px;
    text-align: left;
    border-bottom: 1px solid #f3f4f6;
}

.participants-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.participants-table tbody tr:hover {
    background: #f9fafb;
}

.participants-table tbody tr.current-user {
    background: #fef7ff;
    border-left: 4px solid #4f46e5;
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-weight: 700;
    font-size: 14px;
}

.rank-1 { background: linear-gradient(135deg, #ffd700, #ffb347); color: #8b4513; }
.rank-2 { background: linear-gradient(135deg, #c0c0c0, #a8a8a8); color: #2f4f4f; }
.rank-3 { background: linear-gradient(135deg, #cd7f32, #a0522d); color: white; }
.rank-other { background: #e5e7eb; color: #6b7280; }

.participant-name {
    font-weight: 600;
    color: #1a202c;
    margin-bottom: 4px;
}

.participant-email {
    color: #6b7280;
    font-size: 12px;
}

.compliance-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
}

.compliance-high { background: #10b981; }
.compliance-medium { background: #f59e0b; }
.compliance-low { background: #ef4444; }

.metric-value {
    font-weight: 600;
    color: #1a202c;
}

.metric-subtext {
    color: #6b7280;
    font-size: 12px;
    margin-top: 2px;
}

.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.no-data h3 {
    margin: 0 0 8px;
    color: #374151;
}

.page-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
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

.flash-message {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .mtm-leaderboard-container {
        padding: 16px;
    }

    .leaderboard-header {
        padding: 24px;
    }

    .stats-overview {
        grid-template-columns: repeat(2, 1fr);
    }

    .participants-table th,
    .participants-table td {
        padding: 12px 16px;
    }

    .table-scroll {
        font-size: 14px;
    }
}
</style>

<div class="mtm-leaderboard-container">
    <?php if ($flash): ?>
        <div class="flash-message">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="leaderboard-header">
        <h1 class="leaderboard-title">🏆 MTM Leaderboard</h1>
        <p class="leaderboard-subtitle"><?= htmlspecialchars($enrollment['title']) ?></p>

        <?php if ($current_user_rank): ?>
            <div class="user-rank">
                Your Rank: #<?= $current_user_rank ?> of <?= count($participants) ?> participants
            </div>
        <?php endif; ?>
    </div>

    <!-- Page Actions -->
    <div class="page-actions">
        <a href="/mtm_model_user.php?id=<?= $model_id ?>" class="btn btn-secondary">← Back to Program</a>
        <a href="/mtm.php" class="btn btn-secondary">View All Programs</a>
    </div>

    <!-- Stats Overview -->
    <div class="stats-overview">
        <div class="stat-card">
            <span class="stat-value"><?= count($participants) ?></span>
            <span class="stat-label">Participants</span>
        </div>
        <div class="stat-card">
            <span class="stat-value">
                <?php
                $total_trades = array_sum(array_column($participants, 'total_trades'));
                echo number_format($total_trades);
                ?>
            </span>
            <span class="stat-label">Total Trades</span>
        </div>
        <div class="stat-card">
            <span class="stat-value">
                <?php
                $avg_compliance = count($participants) > 0
                    ? round(array_sum(array_column($participants, 'compliance_rate')) / count($participants), 1)
                    : 0;
                echo $avg_compliance . '%';
                ?>
            </span>
            <span class="stat-label">Avg Compliance</span>
        </div>
        <div class="stat-card">
            <span class="stat-value">
                <?php
                $total_points = array_sum(array_column($participants, 'total_points'));
                echo number_format($total_points);
                ?>
            </span>
            <span class="stat-label">Total Points</span>
        </div>
    </div>

    <!-- Participants Table -->
    <div class="participants-table">
        <div class="table-header">
            <h2 class="table-title">Participant Rankings</h2>
        </div>

        <div class="table-scroll">
            <?php if (empty($participants)): ?>
                <div class="no-data">
                    <h3>No participants yet</h3>
                    <p>Be the first to start trading in this MTM program!</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Participant</th>
                            <th>Trades</th>
                            <th>Points</th>
                            <th>Compliance</th>
                            <th>Win Rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $index => $participant):
                            $rank = $index + 1;
                            $is_current_user = $participant['id'] == $user_id;
                            $compliance_class = $participant['compliance_rate'] >= 80 ? 'high' :
                                             ($participant['compliance_rate'] >= 60 ? 'medium' : 'low');
                        ?>
                            <tr class="<?= $is_current_user ? 'current-user' : '' ?>">
                                <td>
                                    <div class="rank-badge rank-<?= $rank <= 3 ? $rank : 'other' ?>">
                                        <?= $rank ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="participant-name">
                                        <?= htmlspecialchars($participant['display_name']) ?>
                                        <?php if ($is_current_user): ?>
                                            <span style="color: #4f46e5; font-size: 12px;">(You)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="participant-email">
                                        <?= htmlspecialchars($participant['email']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="metric-value">
                                        <?= number_format($participant['total_trades']) ?>
                                    </div>
                                    <div class="metric-subtext">
                                        <?= $participant['closed_trades'] ?> closed
                                    </div>
                                </td>
                                <td>
                                    <div class="metric-value">
                                        <?= number_format($participant['total_points']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="metric-value">
                                        <span class="compliance-indicator compliance-<?= $compliance_class ?>"></span>
                                        <?= $participant['compliance_rate'] ?>%
                                    </div>
                                    <div class="metric-subtext">
                                        <?= $participant['compliant_trades'] ?> compliant
                                    </div>
                                </td>
                                <td>
                                    <div class="metric-value">
                                        <?= $participant['win_rate'] ?>%
                                    </div>
                                    <div class="metric-subtext">
                                        Est. based on points
                                    </div>
                                </td>
                                <td>
                                    <?php if ($participant['total_trades'] == 0): ?>
                                        <span style="color: #6b7280; font-size: 12px;">Not started</span>
                                    <?php elseif ($participant['compliance_rate'] >= 80): ?>
                                        <span style="color: #10b981; font-weight: 600;">Excellent</span>
                                    <?php elseif ($participant['compliance_rate'] >= 60): ?>
                                        <span style="color: #f59e0b; font-weight: 600;">Good</span>
                                    <?php else: ?>
                                        <span style="color: #ef4444; font-weight: 600;">Needs improvement</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div style="margin-top: 24px; text-align: center; color: #6b7280; font-size: 14px;">
        <p>💡 Rankings are based on points earned through compliant MTM trading. Higher compliance rates improve your standing!</p>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>