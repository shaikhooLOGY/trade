<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
require_active_user();

include __DIR__ . '/header.php';
?>
<style>
.dashboard-container {
    max-width: 1000px;
    margin: 30px auto;
    padding: 0 16px;
}
.dashboard-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    padding: 24px;
    margin-bottom: 24px;
}
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.action-card {
    background: #f8fafc;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    transition: transform 0.2s ease;
    border: 1px solid #e2e8f0;
}
.action-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
}
.action-card h3 {
    margin: 0 0 12px;
    color: #1e293b;
}
.action-card p {
    color: #64748b;
    margin: 0 0 16px;
    font-size: 14px;
}
.action-btn {
    display: inline-block;
    padding: 8px 16px;
    background: #4f46e5;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 600;
    transition: background 0.2s ease;
}
.action-btn:hover {
    background: #3730a3;
}
</style>

<div class="dashboard-container">
    <div class="dashboard-card">
        <h2>Welcome to Your Trading Dashboard! 🎯</h2>
        <p>You're now part of the <strong>Shaikhoology Trading Championship</strong>. Start competing, submitting trades, and climb the leaderboard!</p>
    </div>

    <div class="dashboard-card">
        <h3>Quick Actions</h3>
        <div class="dashboard-grid">
            <div class="action-card">
                <h3>➕ Create League</h3>
                <p>Start a private/public league. Define rules, duration, and prize.</p>
                <a href="/league_create.php" class="action-btn">Create League →</a>
            </div>
            <div class="action-card">
                <h3>🔍 Browse Leagues</h3>
                <p>Join open leagues or request to join private ones.</p>
                <a href="/leaderboard.php" class="action-btn">Browse →</a>
            </div>
            <div class="action-card">
                <h3>📤 Submit Proof</h3>
                <p>Upload trade screenshots. Admin will validate and update scores.</p>
                <a href="/trade_new.php" class="action-btn">Submit Trade →</a>
            </div>
        </div>
    </div>

    <div class="dashboard-card">
        <h3>How it works</h3>
        <p>Create or join trading leagues, submit proof, and compete.</p>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>