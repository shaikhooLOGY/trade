<?php
// admin/admin_dashboard.php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Access denied'); }
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin(); // Use the function from config.php

// --- Data Fetching ---
// Fetch all required stats in a single, efficient query if possible.
$stats = [
    'pending_concerns' => 0,
    'active_mtm_models' => 0,
    'schema_issues' => 0,
];

// 1. Get pending trade concerns count
try {
    $q_concerns = $mysqli->query("SELECT COUNT(*) AS c FROM trade_concerns WHERE status='open'");
    if ($q_concerns && ($row = $q_concerns->fetch_assoc())) {
        $stats['pending_concerns'] = (int)$row['c'];
    }
} catch (Exception $e) { /* Ignore if table doesn't exist */ }

// 2. Get active MTM models count
try {
    $q_models = $mysqli->query("SELECT COUNT(*) AS c FROM mtm_models WHERE status='active'");
    if ($q_models && ($row = $q_models->fetch_assoc())) {
        $stats['active_mtm_models'] = (int)$row['c'];
    }
} catch (Exception $e) { /* Ignore if table doesn't exist */ }

// 3. Get schema issue count
try {
    require_once __DIR__ . '/../includes/schema_manager.php';
    $schema = new SchemaManager($mysqli, true);
    $issues = $schema->detectIssues(false);
    foreach ($issues as $table_issues) {
        $stats['schema_issues'] += count($table_issues);
    }
} catch (Exception $e) { /* Ignore if schema manager fails */ }

// --- Page Setup ---
include __DIR__ . '/../header.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Dashboard — Shaikhoology</title>
<style>
  body {
    font-family: Inter, Roboto, Arial, sans-serif;
    margin: 0;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: #fff;
  }
  .hero {
    text-align: center;
    padding: 40px 20px;
    background: rgba(0,0,0,0.3);
    margin-bottom: 30px;
  }
  .hero h1 {
    font-size: 32px;
    margin: 0 0 16px;
    background: linear-gradient(90deg, #ff6b6b, #4ecdc4, #45b7d1);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .hero p {
    font-size: 18px;
    opacity: 0.9;
    max-width: 700px;
    margin: 0 auto;
  }
  .row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 24px;
    padding: 0 20px 30px;
    max-width: 1200px;
    margin: 0 auto;
  }
  .card {
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(10px);
    border-radius: 16px;
    overflow: hidden;
    position: relative;
    cursor: pointer;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    border: 1px solid rgba(255,255,255,0.1);
  }
  .card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    background: rgba(255,255,255,0.12);
  }
  .card h3 {
    font-size: 22px;
    margin: 0 0 12px;
    color: #fff;
  }
  .card p {
    font-size: 15px;
    color: #ccc;
    margin: 0 0 16px;
    line-height: 1.5;
  }
  .card-content {
    padding: 24px;
  }
  .btn {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 8px;
    background: linear-gradient(90deg, #ff6b6b, #ee5a52);
    color: #fff;
    font-weight: 700;
    font-size: 15px;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
  }
  .btn:hover {
    transform: scale(1.05);
    box-shadow: 0 8px 20px rgba(238, 90, 82, 0.4);
  }
  .badge {
    position: absolute;
    top: 16px;
    right: 16px;
    background: linear-gradient(90deg, #4ecdc4, #45b7d1);
    color: #000;
    font-size: 13px;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  }
  .motivation {
    text-align: center;
    padding: 20px;
    font-style: italic;
    color: #4ecdc4;
    font-size: 16px;
    margin-top: 20px;
    border-top: 1px solid rgba(255,255,255,0.1);
  }
</style>
</head>
<body>
  <div class="hero">
    <h1>👑 Admin Dashboard — Trading Champions Builder</h1>
    <p>You're not just managing accounts — you're shaping the future of disciplined traders. Every approval you give is a step toward excellence!</p>
  </div>

  <div class="row">
    <!-- Users -->
    <div class="card" onclick="window.location='users.php'">
      <div class="card-content">
        <h3>👥 Users Management</h3>
        <p>Review, approve, or promote/demote traders in the championship league.</p>
        <a href="users.php" class="btn">Manage Users</a>
      </div>
    </div>

    <!-- Trade Management -->
    <div class="card" onclick="window.location='trade_center.php'">
      <div class="card-content">
        <h3>⚠️ Trade Management Center</h3>
        <p>Review and resolve trades flagged by users for validation.</p>
        <a href="trade_center.php" class="btn">Manage</a>
        <?php if ($stats['pending_concerns'] > 0) echo "<span class='badge'>{$stats['pending_concerns']}</span>"; ?>
      </div>
    </div>

    <!-- Profile Fields -->
    <div class="card" onclick="window.location='view_profile_fields.php'">
      <div class="card-content">
        <h3>📝 Profile Fields</h3>
        <p>View current user profile requirements and configuration.</p>
        <a href="view_profile_fields.php" class="btn">View Fields</a>
      </div>
    </div>

    <!-- MTM Manager (NEW) -->
    <div class="card" onclick="window.location='mtm_models.php'">
      <div class="card-content">
        <h3>🧠 MTM Manager</h3>
        <p>Create & manage Mental Trading Models (DTP and custom modules). Levels & tasks coming next.</p>
        <a href="mtm_models.php" class="btn">Open MTM Manager</a>
        <?php
        // Optional: show active models count if table exists
        $activeCount = null;
        if ($st = $mysqli->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mtm_models'")) {
          $st->execute(); $st->bind_result($has); $st->fetch(); $st->close();
          if ((int)$has > 0) {
            $r = $mysqli->query("SELECT COUNT(*) AS c FROM mtm_models WHERE status='active'");
            if ($r && ($rc = $r->fetch_assoc())) $activeCount = (int)$rc['c'];
          }
        }
        if ($activeCount !== null && $activeCount > 0) {
          echo "<span class='badge'>{$activeCount} Active</span>";
        }
        ?>
        <?php if ($stats['active_mtm_models'] > 0) echo "<span class='badge'>{$stats['active_mtm_models']} Active</span>"; ?>
      </div>
    </div>

    <!-- Schema Management -->
    <div class="card" onclick="window.location='schema_management.php'">
      <div class="card-content">
        <h3>🔧 Schema Management</h3>
        <p>Monitor and fix database schema issues automatically. Check tables, columns, and indexes.</p>
        <a href="schema_management.php" class="btn">Manage Schema</a>
        <?php
        // Check for schema issues and show badge
        require_once __DIR__ . '/../includes/schema_manager.php';
        $schema = new SchemaManager($mysqli, true);
        $issues = $schema->detectIssues(false);
        $issueCount = 0;
        foreach ($issues as $table_issues) {
            $issueCount += count($table_issues);
        }
        if ($issueCount > 0) {
            echo "<span class='badge'>{$issueCount} Issues</span>";
        }
        ?>
        <?php if ($stats['schema_issues'] > 0) echo "<span class='badge'>{$stats['schema_issues']} Issues</span>"; ?>
      </div>
    </div>
  </div>

  <div class="motivation">
    "The best traders aren't born — they're built through discipline, review, and guidance. You're the architect of excellence!"
  </div>
<?php include __DIR__ . '/../footer.php'; ?>
</body>
</html>