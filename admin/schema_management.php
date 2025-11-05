<?php
// admin/schema_management.php - Admin Schema Management Interface
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

require_once __DIR__ . '/../includes/schema_manager.php';

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $schema = new SchemaManager($GLOBALS['mysqli'], true);
        
        if ($_POST['action'] === 'scan') {
            // Just scanning, no fixes
            $issues = $schema->detectIssues(true);
            $flash = 'Scan completed. ' . count($issues) . ' issue(s) found.';
        } elseif ($_POST['action'] === 'fix_all') {
            $issues = $schema->detectIssues(false);
            $results = $schema->executeFixes($issues);
            
            $success_count = 0;
            $error_count = 0;
            
            foreach ($results as $result) {
                if ($result['success']) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
            
            $flash = "Schema fixes completed! {$success_count} successful, {$error_count} failed.";
        }
    }
}

// Always do a scan to show current status
$schema = new SchemaManager($GLOBALS['mysqli'], true);
$issues = $schema->detectIssues(false);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Schema Management - Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  body { font-family: Inter, system-ui, Arial, sans-serif; background: #f8fafc; margin: 0; }
  .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
  .header { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
  .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
  .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
  .btn-primary { background: #5a2bd9; color: white; }
  .btn-success { background: #10b981; color: white; }
  .btn-info { background: #3b82f6; color: white; }
  .btn-warning { background: #f59e0b; color: white; }
  .status-good { color: #10b981; font-weight: 600; }
  .status-warning { color: #f59e0b; font-weight: 600; }
  .status-error { color: #ef4444; font-weight: 600; }
  .table { width: 100%; border-collapse: collapse; }
  .table th, .table td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
  .table th { background: #f9fafb; font-weight: 600; }
  .schema-issues { background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white; padding: 20px; border-radius: 12px; }
  .schema-good { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; border-radius: 12px; }
  .sql-block { background: #1f2937; color: #00ff00; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 14px; overflow-x: auto; }
  .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
  @media (max-width: 768px) { .action-buttons { flex-direction: column; } }
</style>
</head>
<body>

<div class="container">
  <div class="header">
    <h1 style="margin: 0; color: #1f2937;">🔧 Database Schema Management</h1>
    <p style="color: #6b7280; margin: 8px 0 0 0;">Monitor and fix database schema issues across your website</p>
  </div>

  <?php if ($flash): ?>
  <div class="card" style="background: #ecfdf5; border: 1px solid #10b981; color: #065f46;">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- Schema Status Overview -->
  <div class="card">
    <h2 style="margin: 0 0 15px 0;">📊 Schema Health Status</h2>
    
    <?php if (empty($issues)): ?>
      <div class="schema-good">
        <h3 style="margin: 0 0 10px 0;">✅ Database Schema is Healthy</h3>
        <p style="margin: 0;">All required tables and columns are present and properly configured.</p>
      </div>
    <?php else: ?>
      <div class="schema-issues">
        <h3 style="margin: 0 0 10px 0;">⚠️ Schema Issues Detected</h3>
        <p style="margin: 0;">Found <?= count($issues) ?> issue(s) that need attention.</p>
      </div>
    <?php endif; ?>
  </div>

  <!-- Action Buttons -->
  <div class="card">
    <h2 style="margin: 0 0 15px 0;">🚀 Actions</h2>
    <div class="action-buttons">
      <form method="post" style="display: inline;">
        <input type="hidden" name="action" value="scan">
        <button type="submit" class="btn btn-info">🔍 Rescan Schema</button>
      </form>
      
      <?php if (!empty($issues)): ?>
      <form method="post" style="display: inline;" onsubmit="return confirm('This will execute SQL commands on your database. Are you sure?')">
        <input type="hidden" name="action" value="fix_all">
        <button type="submit" class="btn btn-success">🔧 Fix All Issues</button>
      </form>
      
      <button onclick="showSQLCommands()" class="btn btn-warning">📋 Show SQL Commands</button>
      <?php endif; ?>
      
      <a href="/dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
    </div>
  </div>

  <?php if (!empty($issues)): ?>
  <!-- Detailed Issues -->
  <div class="card">
    <h2 style="margin: 0 0 15px 0;">📋 Detailed Issues</h2>
    
    <?php foreach ($issues as $table => $table_issues): ?>
    <div style="margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
      <div style="background: #f9fafb; padding: 12px; border-bottom: 1px solid #e5e7eb;">
        <strong><?= ucfirst($table) ?> Table</strong> - <?= count($table_issues) ?> issue(s)
      </div>
      <div style="padding: 12px;">
        <table class="table">
          <thead>
            <tr>
              <th>Issue Type</th>
              <th>Details</th>
              <th>SQL Command</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($table_issues as $issue): ?>
            <tr>
              <td>
                <?php
                $icon = $issue['type'] === 'missing_column' ? '📝' : 
                       ($issue['type'] === 'missing_table' ? '📦' : '🔍');
                echo $icon . ' ' . ucwords(str_replace('_', ' ', $issue['type']));
                ?>
              </td>
              <td>
                <?php if ($issue['type'] === 'missing_column'): ?>
                  Missing column: <code><?= htmlspecialchars($issue['column']) ?></code>
                <?php elseif ($issue['type'] === 'missing_table'): ?>
                  Table: <code><?= htmlspecialchars($issue['table']) ?></code>
                <?php else: ?>
                  Index: <code><?= htmlspecialchars($issue['index']) ?></code>
                <?php endif; ?>
              </td>
              <td>
                <div class="sql-block" style="font-size: 12px; max-width: 400px;">
                  <?= htmlspecialchars($issue['sql']) ?>
                </div>
              </td>
              <td>
                <span class="status-error">❌ Needs Fix</span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- SQL Commands Section -->
  <div class="card" id="sql-commands" style="display: none;">
    <h2 style="margin: 0 0 15px 0;">📋 SQL Commands for Manual Execution</h2>
    <p style="color: #6b7280; margin-bottom: 15px;">
      Copy and run these commands in phpMyAdmin if you prefer manual execution:
    </p>
    
    <div class="sql-block">
      <?php foreach ($issues as $table => $table_issues): ?>
        <?php foreach ($table_issues as $issue): ?>
          <?= htmlspecialchars($issue['sql']) ?>;
          
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    
    <div style="margin-top: 15px;">
      <button onclick="copyToClipboard()" class="btn btn-info">📋 Copy All SQL</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Schema Info -->
  <div class="card">
    <h2 style="margin: 0 0 15px 0;">ℹ️ System Information</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
      <div>
        <strong>Database:</strong> <?= htmlspecialchars($GLOBALS['DB_NAME'] ?? 'Unknown') ?>
      </div>
      <div>
        <strong>Tables Checked:</strong> trades, users, deploy_notes
      </div>
      <div>
        <strong>Last Scan:</strong> <?= date('Y-m-d H:i:s') ?>
      </div>
      <div>
        <strong>Schema Manager:</strong> v1.0
      </div>
    </div>
  </div>
</div>

<script>
function showSQLCommands() {
  document.getElementById('sql-commands').style.display = 'block';
  document.getElementById('sql-commands').scrollIntoView({ behavior: 'smooth' });
}

function copyToClipboard() {
  const sqlBlock = document.querySelector('.sql-block');
  const text = sqlBlock.textContent;
  
  navigator.clipboard.writeText(text).then(() => {
    alert('SQL commands copied to clipboard!');
  }).catch(() => {
    // Fallback for older browsers
    const textArea = document.createElement('textarea');
    textArea.value = text;
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);
    alert('SQL commands copied to clipboard!');
  });
}

// Auto-show issues panel if issues exist
<?php if (!empty($issues)): ?>
document.addEventListener('DOMContentLoaded', function() {
  // Highlight the issues section
  const issuesCard = document.querySelector('.card:nth-child(4)');
  if (issuesCard) {
    issuesCard.style.border = '2px solid #f59e0b';
  }
});
<?php endif; ?>
</script>

</body>
</html>