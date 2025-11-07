<?php
// /admin/deploy_notes.php - Auto-Mode Enhanced Deployment Notes System
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

// ----- Helpers -----
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post($k,$d=null){ return $_POST[$k] ?? $d; }
function getv($k,$d=null){ return $_GET[$k] ?? $d; }

// ----- Server-side AJAX Handlers -----
if (getv('action') === 'scan_files') {
    csrf_verify(get_csrf_token());
    
    $hours = max(2, min(24, (int)getv('hours', 4)));
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/home/u613260542/public_html';
    $scan_dirs = [$docroot, $docroot.'/admin', $docroot.'/includes'];
    
    $files = [];
    $cutoff = time() - ($hours * 3600);
    
    foreach ($scan_dirs as $dir) {
        if (!is_dir($dir) || !is_readable($dir)) continue;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['php', 'css', 'js'])) continue;
            
            $mtime = $file->getMTime();
            if ($mtime >= $cutoff) {
                $path = $file->getPathname();
                $relative = str_replace($docroot, '', $path);
                $files[] = [
                    'path' => $relative,
                    'mtime_iso' => date('c', $mtime)
                ];
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'files' => $files,
        'scanned' => count($files),
        'window_hours' => $hours
    ]);
    exit;
}

if (getv('action') === 'create_table') {
    csrf_verify(get_csrf_token());
    
    $ddl = "
    CREATE TABLE IF NOT EXISTS deploy_notes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      env ENUM('local','staging','prod') NOT NULL DEFAULT 'prod',
      title VARCHAR(255) NOT NULL,
      body TEXT NULL,
      note_type ENUM('feature','hotfix','migration','maintenance') DEFAULT 'feature',
      impact ENUM('low','medium','high','critical') DEFAULT 'low',
      status ENUM('planned','in_progress','deployed','rolled_back') DEFAULT 'planned',
      sql_up MEDIUMTEXT NULL,
      sql_down MEDIUMTEXT NULL,
      files_json JSON NULL,
      links_json JSON NULL,
      tags VARCHAR(255) NULL,
      created_by INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      deployed_at DATETIME NULL,
      INDEX idx_env (env),
      INDEX idx_status (status),
      INDEX idx_type (note_type),
      INDEX idx_created_by (created_by),
      INDEX idx_created_at (created_at),
      INDEX idx_deployed_at (deployed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    
    $result = $GLOBALS['mysqli']->query($ddl);
    header('Content-Type: application/json');
    echo json_encode(['success' => $result, 'error' => $result ? null : $GLOBALS['mysqli']->error]);
    exit;
}

// ----- Data Migration and Table Check -----
$mysqli = $GLOBALS['mysqli'];
$table_exists = $mysqli->query("SHOW TABLES LIKE 'deploy_notes'")->num_rows > 0;

// Check if we have the new structure
$has_new_fields = false;
if ($table_exists) {
    $fields_result = $mysqli->query("SHOW COLUMNS FROM deploy_notes");
    $existing_fields = [];
    while ($field = $fields_result->fetch_assoc()) {
        $existing_fields[] = $field['Field'];
    }
    $has_new_fields = in_array('title', $existing_fields) && in_array('note_type', $existing_fields);
}

// ----- Export handlers -----
if (getv('export') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $sql = "SELECT * FROM deploy_notes ORDER BY created_at DESC, id DESC";
    $res = $mysqli->query($sql);
    $rows = [];
    while($r = $res->fetch_assoc()){ $rows[] = $r; }
    echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}
if (getv('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=deploy_notes.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','env','title','note_type','impact','status','created_at','created_by']);
    $sql = "SELECT * FROM deploy_notes ORDER BY created_at DESC, id DESC";
    $res = $mysqli->query($sql);
    while($r=$res->fetch_assoc()){ fputcsv($out, $r); }
    fclose($out); exit;
}

// ----- Create / Update -----
$flash = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && validate_csrf(post('csrf'))) {
    $id = (int)post('id',0);
    
    // Handle both old and new field formats
    $env = post('env','prod');
    $title = trim((string)post('title',''));
    $body = trim((string)post('body',''));
    $note_type = post('note_type','feature');
    $impact = post('impact','low');
    $status = post('status','planned');
    $sql_up = (string)post('sql_up','');
    $sql_down = (string)post('sql_down','');
    $files_json = (string)post('files_json','');
    $links_json = (string)post('links_json','');
    $tags = trim((string)post('tags',''));
    
    // Migration: map old fields to new if needed
    if (!$has_new_fields && $title === '') {
        $title = trim((string)post('summary',''));
        $body = trim((string)post('known_issues',''));
        $sql_up = (string)post('sql_migration','');
    }
    
    // Validate inputs
    if (!$title) {
        $flash = 'Title is required.';
    } else {
        // Normalize JSON fields
        if ($files_json && !json_decode($files_json)) {
            // Try to parse as comma/newline separated list
            $files_array = preg_split('/[\n,]+/', $files_json);
            $files_array = array_filter(array_map('trim', $files_array));
            $files_json = json_encode($files_array);
        }
        
        if ($links_json && !json_decode($links_json)) {
            // Try to parse as comma/newline separated list
            $links_array = preg_split('/[\n,]+/', $links_json);
            $links_array = array_filter(array_map('trim', $links_array));
            $links_json = json_encode($links_array);
        }
        
        if ($tags) {
            // Normalize tags
            $tag_array = preg_split('/[\s,]+/', $tags);
            $tag_array = array_filter(array_map('trim', $tag_array));
            $tags = implode(',', array_unique(array_map('strtolower', $tag_array)));
        }
        
        $created_by = (int)($_SESSION['user_id'] ?? 0);
        
        if ($id>0) {
            $stmt = $mysqli->prepare("
                UPDATE deploy_notes 
                   SET env=?, title=?, body=?, note_type=?, impact=?, status=?,
                       sql_up=?, sql_down=?, files_json=?, links_json=?, tags=?
                 WHERE id=? LIMIT 1
            ");
            $stmt->bind_param('sssssssssssi', 
                $env, $title, $body, $note_type, $impact, $status,
                $sql_up, $sql_down, $files_json, $links_json, $tags, $id
            );
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO deploy_notes 
                  (env, title, body, note_type, impact, status, sql_up, sql_down, 
                   files_json, links_json, tags, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param('sssssssssssi',
                $env, $title, $body, $note_type, $impact, $status,
                $sql_up, $sql_down, $files_json, $links_json, $tags, $created_by
            );
        }
        
        if ($stmt && $stmt->execute()) {
            $flash = $id>0 ? 'Note updated successfully.' : 'Note created successfully.';
        } else {
            $flash = 'Database error: '.h($mysqli->error);
        }
    }
}

// ----- Edit fetch -----
$edit = null;
if ((int)getv('edit',0) > 0) {
    $id = (int)getv('edit');
    $res = $mysqli->prepare("SELECT * FROM deploy_notes WHERE id=? LIMIT 1");
    $res->bind_param('i',$id);
    $res->execute();
    $edit = $res->get_result()->fetch_assoc();
}

// ----- Duplicate functionality -----
if (getv('duplicate') && (int)getv('duplicate') > 0) {
    $id = (int)getv('duplicate');
    $res = $mysqli->prepare("SELECT * FROM deploy_notes WHERE id=? LIMIT 1");
    $res->bind_param('i',$id);
    $res->execute();
    $source = $res->get_result()->fetch_assoc();
    
    if ($source) {
        $edit = $source;
        $edit['title'] = '(copy) ' . $edit['title'];
        $edit['status'] = 'planned';
        unset($edit['id']);
    }
}

// ----- List -----
$rows = [];
$search = trim((string)getv('q',''));
$sql = "SELECT * FROM deploy_notes";
$params = []; $types='';

if ($search !== '') {
    $like = "%{$search}%";
    $sql .= " WHERE (title LIKE ? OR body LIKE ? OR tags LIKE ?)";
    $params = [$like,$like,$like]; $types = 'sss';
}
$sql .= " ORDER BY created_at DESC, id DESC LIMIT 200";

$stmt = $mysqli->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$r = $stmt->get_result();
while($row=$r->fetch_assoc()) { $rows[] = $row; }

// ----- Get most recent note for quickfill -----
$recent_note = null;
if (!empty($rows)) {
    $recent_note = $rows[0];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Deploy Notes — Auto-Mode Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --primary: #5a2bd9;
    --primary-hover: #4a1fc4;
    --secondary: #6b7280;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --bg: #f8fafc;
    --card: #ffffff;
    --border: #e5e7eb;
    --text: #111827;
    --text-muted: #6b7280;
  }
  
  * { box-sizing: border-box; }
  body {
    font-family: Inter, system-ui, Arial, sans-serif;
    background: var(--bg);
    margin: 0;
    color: var(--text);
    line-height: 1.6;
  }
  
  header {
    background: var(--primary);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }
  
  header h1 { margin: 0; font-size: 1.25rem; font-weight: 700; }
  
  .env-badge {
    background: rgba(255,255,255,0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
  }
  
  .container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
  }
  
  .grid {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 20px;
    margin-top: 20px;
  }
  
  @media (max-width: 1200px) {
    .grid { grid-template-columns: 1fr; }
  }
  
  .card {
    background: var(--card);
    border-radius: 12px;
    border: 1px solid var(--border);
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
  }
  
  .card h2, .card h3 {
    margin: 0 0 16px 0;
    color: var(--text);
    font-weight: 700;
  }
  
  .form-group {
    margin-bottom: 16px;
  }
  
  .form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: var(--text);
  }
  
  input, select, textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font: inherit;
    background: white;
    transition: border-color 0.2s;
  }
  
  input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(90, 43, 217, 0.1);
  }
  
  textarea { 
    min-height: 100px;
    resize: vertical;
  }
  
  .row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }
  
  .row-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
  }
  
  @media (max-width: 768px) {
    .row, .row-3 { grid-template-columns: 1fr; }
  }
  
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    font-size: 0.875rem;
  }
  
  .btn-primary {
    background: var(--primary);
    color: white;
  }
  
  .btn-primary:hover { background: var(--primary-hover); }
  
  .btn-secondary {
    background: var(--secondary);
    color: white;
  }
  
  .btn-success { background: var(--success); color: white; }
  .btn-warning { background: var(--warning); color: white; }
  .btn-outline {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
  }
  
  .btn-sm { padding: 6px 12px; font-size: 0.75rem; }
  .btn-xs { padding: 4px 8px; font-size: 0.75rem; }
  
  .flash {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-weight: 600;
  }
  
  .flash-success {
    background: #dcfce7;
    border: 1px solid #16a34a;
    color: #166534;
  }
  
  .flash-error {
    background: #fee2e2;
    border: 1px solid #dc2626;
    color: #991b1b;
  }
  
  .table {
    width: 100%;
    border-collapse: collapse;
  }
  
  .table th, .table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
  }
  
  .table th {
    font-weight: 700;
    color: var(--text);
    background: #f9fafb;
  }
  
  .pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
  }
  
  .pill-feature { background: #dbeafe; color: #1e40af; }
  .pill-hotfix { background: #fee2e2; color: #dc2626; }
  .pill-migration { background: #fef3c7; color: #d97706; }
  .pill-maintenance { background: #f3f4f6; color: #374151; }
  
  .pill-low { background: #dcfce7; color: #16a34a; }
  .pill-medium { background: #fef3c7; color: #d97706; }
  .pill-high { background: #fee2e2; color: #dc2626; }
  .pill-critical { background: #fecaca; color: #991b1b; }
  
  .pill-planned { background: #f3f4f6; color: #374151; }
  .pill-in-progress { background: #dbeafe; color: #1e40af; }
  .pill-deployed { background: #dcfce7; color: #16a34a; }
  .pill-rolled-back { background: #fee2e2; color: #dc2626; }
  
  .actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  
  .sticky-actions {
    position: sticky;
    bottom: 20px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    box-shadow: 0 -4px 12px rgba(0,0,0,0.1);
    margin-top: 20px;
  }
  
  .search-bar {
    display: flex;
    gap: 12px;
    align-items: center;
  }
  
  .search-bar input {
    flex: 1;
    max-width: 300px;
  }
  
  .health-panel {
    background: #f0f9ff;
    border: 1px solid #0ea5e9;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
  }
  
  .health-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
  }
  
  .health-status {
    width: 12px;
    height: 12px;
    border-radius: 50%;
  }
  
  .health-ok { background: var(--success); }
  .health-fail { background: var(--danger); }
  .health-unknown { background: var(--text-muted); }
  
  .file-suggestions {
    border: 1px solid var(--border);
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
    padding: 8px;
    background: #fafafa;
  }
  
  .file-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px;
    border-radius: 4px;
  }
  
  .file-item:hover {
    background: #e5e7eb;
  }
  
  .tags-input {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    min-height: 44px;
  }
  
  .tag {
    background: var(--primary);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  
  .tag-remove {
    cursor: pointer;
    font-weight: bold;
  }
  
  .tag-input {
    border: none;
    outline: none;
    flex: 1;
    min-width: 100px;
    font: inherit;
  }
  
  .modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
  }
  
  .modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 12px;
    padding: 20px;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
  }
  
  .shortcuts-hint {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 4px;
  }
  
  .table-warning {
    background: #fef3c7;
    border: 1px solid #d97706;
    color: #92400e;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
  }
</style>
</head>
<body>
<header>
  <h1>Deployment Notes Auto-Mode</h1>
  <div class="env-badge"><?= h(APP_ENV ?? 'unknown') ?></div>
</header>

<div class="container">
  <?php if($flash): ?>
    <div class="flash <?= strpos($flash, 'error') !== false ? 'flash-error' : 'flash-success' ?>">
      <?= h($flash) ?>
    </div>
  <?php endif; ?>
  
  <?php if (!$table_exists): ?>
    <div class="table-warning">
      <strong>Database Setup Required</strong><br>
      The deploy_notes table doesn't exist. Create it to start using the Auto-Mode features.
      <div style="margin-top: 12px;">
        <button class="btn btn-primary" onclick="createTable()">Create Table</button>
      </div>
    </div>
  <?php elseif (!$has_new_fields): ?>
    <div class="table-warning">
      <strong>Table Migration Available</strong><br>
      Your table exists but doesn't have the new Auto-Mode features. The system will work with existing fields.
      <div style="margin-top: 12px;">
        <a href="?migrate=1" class="btn btn-warning btn-sm">Migrate to New Structure</a>
      </div>
    </div>
  <?php endif; ?>
  
  <!-- Health Pre-flight Panel -->
  <div class="health-panel">
    <h3 style="margin-top: 0;">Pre-flight Checks</h3>
    <div class="health-item">
      <div class="health-status health-unknown" id="backup-status"></div>
      <label><input type="checkbox" id="backup-done"> DB Backup Completed</label>
    </div>
    <div class="health-item">
      <div class="health-status health-unknown" id="health-status"></div>
      <span>Health Check: <span id="health-text">Checking...</span></span>
    </div>
    <div class="health-item">
      <div class="health-status health-unknown" id="cache-status"></div>
      <label><input type="checkbox" id="cache-cleared"> Cache Cleared</label>
    </div>
  </div>
  
  <div class="grid">
    <!-- Main Form -->
    <div class="card">
      <h2><?= $edit ? 'Edit Note #' . (int)$edit['id'] : 'Create New Note' ?></h2>
      
      <form method="post" id="deployForm">
        <input type="hidden" name="csrf" value="<?= h(get_csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        
        <div class="row-3">
          <div class="form-group">
            <label>Environment</label>
            <select name="env" id="env">
              <option value="local">Local</option>
              <option value="staging">Staging</option>
              <option value="prod">Production</option>
            </select>
          </div>
          <div class="form-group">
            <label>Note Type</label>
            <select name="note_type" id="noteType">
              <option value="feature">Feature</option>
              <option value="hotfix">Hotfix</option>
              <option value="migration">Migration</option>
              <option value="maintenance">Maintenance</option>
            </select>
          </div>
          <div class="form-group">
            <label>Impact</label>
            <select name="impact" id="impact">
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
        </div>
        
        <div class="form-group">
          <label>Title</label>
          <input type="text" name="title" id="title" placeholder="Brief description of the change" value="<?= h($edit['title'] ?? '') ?>">
          <div class="shortcuts-hint">Auto-detected from SQL/Body content</div>
        </div>
        
        <div class="form-group">
          <label>Status</label>
          <select name="status" id="status">
            <option value="planned">Planned</option>
            <option value="in_progress">In Progress</option>
            <option value="deployed">Deployed</option>
            <option value="rolled_back">Rolled Back</option>
          </select>
        </div>
        
        <div class="row">
          <div class="form-group">
            <label>SQL Up</label>
            <div style="display: flex; gap: 8px;">
              <textarea name="sql_up" id="sqlUp" placeholder="SQL commands to apply"><?= h($edit['sql_up'] ?? '') ?></textarea>
              <button type="button" class="btn btn-outline btn-sm" onclick="copyToClipboard('sqlUp')">Copy</button>
            </div>
          </div>
          <div class="form-group">
            <label>SQL Down</label>
            <div style="display: flex; gap: 8px;">
              <textarea name="sql_down" id="sqlDown" placeholder="SQL commands to rollback"><?= h($edit['sql_down'] ?? '') ?></textarea>
              <button type="button" class="btn btn-outline btn-sm" onclick="copyToClipboard('sqlDown')">Copy</button>
            </div>
          </div>
        </div>
        
        <div class="form-group">
          <label>Body / Notes</label>
          <textarea name="body" id="body" placeholder="Detailed notes, issues, testing procedures..."><?= h($edit['body'] ?? '') ?></textarea>
        </div>
        
        <div class="row">
          <div class="form-group">
            <label>Files</label>
            <div style="display: flex; gap: 8px; margin-bottom: 8px;">
              <button type="button" class="btn btn-outline btn-sm" onclick="scanFiles()">Suggest Files (last 4h)</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="pasteFromClipboard()">Paste from Clipboard</button>
            </div>
            <textarea name="files_json" id="filesJson" placeholder="File paths, one per line or JSON array"><?= h($edit['files_json'] ?? '') ?></textarea>
            <div id="fileSuggestions" class="file-suggestions" style="display: none;"></div>
          </div>
          <div class="form-group">
            <label>Links</label>
            <input type="text" name="links_json" id="linksJson" placeholder="GitHub, Jira, PR links" value="<?= h($edit['links_json'] ?? '') ?>">
            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
              Accepts comma/newline separated URLs
            </div>
          </div>
        </div>
        
        <div class="form-group">
          <label>Tags</label>
          <div class="tags-input" id="tagsContainer">
            <input type="text" class="tag-input" id="tagInput" placeholder="Add tags...">
          </div>
          <input type="hidden" name="tags" id="tagsHidden" value="<?= h($edit['tags'] ?? '') ?>">
        </div>
        
        <div class="form-group">
          <label>
            <input type="checkbox" id="rememberDefaults" checked> Remember my defaults
          </label>
        </div>
        
        <div class="sticky-actions">
          <button type="button" class="btn btn-outline" onclick="clearForm()">Clear</button>
          <button type="submit" class="btn btn-primary" onclick="setAction('save')">Save (Ctrl+S)</button>
          <button type="button" class="btn btn-success" onclick="saveAndMarkDeployed()">Save & Mark Deployed (Ctrl+Enter)</button>
        </div>
      </form>
    </div>
    
    <!-- Recent Notes -->
    <div class="card">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3>Recent Notes</h3>
        <div class="actions">
          <button class="btn btn-outline btn-sm" onclick="quickFillFromLast()">From last note</button>
          <a href="?export=json" class="btn btn-outline btn-sm">Export JSON</a>
          <a href="?export=csv" class="btn btn-outline btn-sm">Export CSV</a>
        </div>
      </div>
      
      <div class="search-bar" style="margin-bottom: 16px;">
        <input type="text" id="searchInput" placeholder="Search notes..." value="<?= h($search) ?>">
        <button class="btn btn-outline btn-sm" onclick="searchNotes()">Search</button>
      </div>
      
      <div style="overflow-x: auto;">
        <table class="table">
          <thead>
            <tr>
              <th>When</th>
              <th>Type/Impact</th>
              <th>Title</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="notesTable">
            <?php foreach($rows as $r): ?>
            <tr>
              <td>
                <div style="font-size: 0.875rem;">
                  <?= h(date('M j, H:i', strtotime($r['created_at']))) ?>
                </div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">
                  <?= h(strtoupper($r['env'])) ?>
                </div>
              </td>
              <td>
                <div class="pill pill-<?= h($r['note_type']) ?>"><?= h($r['note_type']) ?></div>
                <div class="pill pill-<?= h($r['impact']) ?>" style="margin-top: 2px;"><?= h($r['impact']) ?></div>
              </td>
              <td>
                <div style="font-weight: 600; max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                  <?= h($r['title']) ?>
                </div>
                <?php if ($r['tags']): ?>
                <div style="font-size: 0.75rem; color: var(--text-muted);">
                  <?= h($r['tags']) ?>
                </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="pill pill-<?= h($r['status']) ?>"><?= h($r['status']) ?></div>
              </td>
              <td>
                <div class="actions">
                  <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-outline btn-xs">Edit</a>
                  <a href="?duplicate=<?= (int)$r['id'] ?>" class="btn btn-outline btn-xs">Duplicate</a>
                  <a href="?view=<?= (int)$r['id'] ?>" class="btn btn-outline btn-xs">View</a>
                </div>
              </td>
            </tr>
            <?php endforeach; if(empty($rows)): ?>
            <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No notes yet</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- File Suggestions Modal -->
<div id="fileModal" class="modal">
  <div class="modal-content">
    <h3>Recent Files (last 4 hours)</h3>
    <div id="fileList"></div>
    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 16px;">
      <button class="btn btn-outline" onclick="closeFileModal()">Cancel</button>
      <button class="btn btn-primary" onclick="addSelectedFiles()">Add Selected</button>
    </div>
  </div>
</div>

<script>
// ----- Auto-Mode JavaScript Implementation -----

// Global state
let autoMode = {
    currentNoteId: <?= (int)($edit['id'] ?? 0) ?>,
    selectedFiles: [],
    lastHealthCheck: null,
    defaults: {
        env: 'prod',
        noteType: 'feature',
        impact: 'low',
        status: 'planned'
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeForm();
    setupKeyboardShortcuts();
    checkHealth();
    restoreDefaults();
    applyUrlPrefill();
    detectIntent();
    setupEventListeners();
});

// ----- Form Initialization -----
function initializeForm() {
    // Set current environment as default
    const envSelect = document.getElementById('env');
    const currentEnv = '<?= h(APP_ENV ?? 'unknown') ?>';
    if (currentEnv !== 'unknown') {
        envSelect.value = currentEnv;
    }
    
    // Set edit values if editing
    <?php if ($edit): ?>
    document.getElementById('env').value = '<?= h($edit['env'] ?? 'prod') ?>';
    document.getElementById('noteType').value = '<?= h($edit['note_type'] ?? 'feature') ?>';
    document.getElementById('impact').value = '<?= h($edit['impact'] ?? 'low') ?>';
    document.getElementById('status').value = '<?= h($edit['status'] ?? 'planned') ?>';
    initializeTags('<?= h($edit['tags'] ?? '') ?>');
    <?php else: ?>
    initializeTags('');
    <?php endif; ?>
    
    // Pre-flight from URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('prefill') === 'preflight_ok') {
        document.getElementById('backup-done').checked = true;
        document.getElementById('cache-cleared').checked = true;
        updateHealthStatus();
    }
}

// ----- Keyboard Shortcuts -----
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            document.querySelector('form').dispatchEvent(new Event('submit', {cancelable: true}));
        }
        
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            saveAndMarkDeployed();
        }
    });
}

// ----- Smart Defaults & Prefill -----
function restoreDefaults() {
    const saved = localStorage.getItem('deployDefaults');
    if (saved) {
        try {
            autoMode.defaults = JSON.parse(saved);
            // Apply defaults but don't override existing values
            if (!document.getElementById('env').value) {
                document.getElementById('env').value = autoMode.defaults.env;
            }
            if (!document.getElementById('noteType').value) {
                document.getElementById('noteType').value = autoMode.defaults.noteType;
            }
            if (!document.getElementById('impact').value) {
                document.getElementById('impact').value = autoMode.defaults.impact;
            }
            if (!document.getElementById('status').value) {
                document.getElementById('status').value = autoMode.defaults.status;
            }
        } catch (e) {
            console.warn('Failed to restore defaults:', e);
        }
    }
}

function applyUrlPrefill() {
    const params = new URLSearchParams(window.location.search);
    
    // Environment from URL
    const env = params.get('env');
    if (env && ['local', 'staging', 'prod'].includes(env)) {
        document.getElementById('env').value = env;
    }
    
    // Type, impact, status from URL
    const type = params.get('type');
    if (type && ['feature', 'hotfix', 'migration', 'maintenance'].includes(type)) {
        document.getElementById('noteType').value = type;
    }
    
    const impact = params.get('impact');
    if (impact && ['low', 'medium', 'high', 'critical'].includes(impact)) {
        document.getElementById('impact').value = impact;
    }
    
    const status = params.get('status');
    if (status && ['planned', 'in_progress', 'deployed', 'rolled_back'].includes(status)) {
        document.getElementById('status').value = status;
    }
    
    // Title from URL
    const title = params.get('title');
    if (title) {
        document.getElementById('title').value = decodeURIComponent(title);
    }
    
    // Tags from URL
    const tags = params.get('tags');
    if (tags) {
        initializeTags(decodeURIComponent(tags));
    }
}

// ----- Intent Detection -----
function detectIntent() {
    const titleInput = document.getElementById('title');
    const sqlUpTextarea = document.getElementById('sqlUp');
    const bodyTextarea = document.getElementById('body');
    
    let title = titleInput.value.trim();
    let sqlContent = sqlUpTextarea.value;
    let bodyContent = bodyTextarea.value;
    
    // Detect SQL intent
    if (sqlContent && !title) {
        const sqlUpper = sqlContent.toUpperCase();
        if (sqlUpper.includes('ALTER ') || sqlUpper.includes('CREATE ') || sqlUpper.includes('DROP ')) {
            document.getElementById('noteType').value = 'migration';
            document.getElementById('impact').value = 'high';
        }
    }
    
    // Detect title patterns
    if (title) {
        const titleLower = title.toLowerCase();
        if (titleLower.includes('hotfix') || titleLower.includes('fix') || titleLower.includes('urgent')) {
            document.getElementById('noteType').value = 'hotfix';
            document.getElementById('impact').value = 'high';
        }
        
        if (titleLower.includes('rollback') || titleLower.includes('revert')) {
            document.getElementById('status').value = 'rolled_back';
        }
    }
    
    // Generate title from body/SQL if empty
    if (!title) {
        const firstLine = (bodyContent || sqlContent).split('\n').find(line => line.trim());
        if (firstLine) {
            titleInput.value = firstLine.trim().substring(0, 80);
        }
    }
}

// ----- Recent Files Suggest -----
function scanFiles() {
    fetch('?action=scan_files&hours=4', {
        method: 'GET',
        headers: {
            'X-CSRF-Token': '<?= h(get_csrf_token()) ?>'
        }
    })
    .then(response => response.json())
    .then(data => {
        showFileSuggestions(data.files || []);
    })
    .catch(error => {
        console.error('File scan failed:', error);
        alert('Failed to scan files. Please try again.');
    });
}

function showFileSuggestions(files) {
    const modal = document.getElementById('fileModal');
    const list = document.getElementById('fileList');
    
    list.innerHTML = '';
    autoMode.selectedFiles = [];
    
    if (files.length === 0) {
        list.innerHTML = '<p>No recent files found.</p>';
    } else {
        files.forEach(file => {
            const item = document.createElement('div');
            item.className = 'file-item';
            item.innerHTML = `
                <input type="checkbox" onchange="toggleFileSelection('${file.path}', this.checked)">
                <span>${file.path}</span>
                <small style="color: var(--text-muted);">(${new Date(file.mtime_iso).toLocaleString()})</small>
            `;
            list.appendChild(item);
        });
    }
    
    modal.style.display = 'block';
}

function toggleFileSelection(path, checked) {
    if (checked) {
        autoMode.selectedFiles.push(path);
    } else {
        autoMode.selectedFiles = autoMode.selectedFiles.filter(f => f !== path);
    }
}

function addSelectedFiles() {
    if (autoMode.selectedFiles.length === 0) return;
    
    const filesTextarea = document.getElementById('filesJson');
    const existingFiles = filesTextarea.value.trim();
    const allFiles = existingFiles ? existingFiles.split('\n').filter(f => f.trim()) : [];
    
    autoMode.selectedFiles.forEach(file => {
        if (!allFiles.includes(file)) {
            allFiles.push(file);
        }
    });
    
    filesTextarea.value = allFiles.join('\n');
    closeFileModal();
}

function closeFileModal() {
    document.getElementById('fileModal').style.display = 'none';
}

// ----- Clipboard Integration -----
function pasteFromClipboard() {
    if (!navigator.clipboard) {
        alert('Clipboard API not available. Please paste manually.');
        return;
    }
    
    navigator.clipboard.readText().then(text => {
        if (!text) return;
        
        // Detect file paths in clipboard
        const lines = text.split('\n').filter(line => line.trim());
        const filePaths = lines.filter(line => {
            const ext = line.trim().split('.').pop().toLowerCase();
            return ['php', 'css', 'js'].includes(ext);
        });
        
        if (filePaths.length > 0) {
            // Add to files
            const filesTextarea = document.getElementById('filesJson');
            const existingFiles = filesTextarea.value.trim();
            const allFiles = existingFiles ? existingFiles.split('\n').filter(f => f.trim()) : [];
            
            filePaths.forEach(path => {
                if (!allFiles.includes(path.trim())) {
                    allFiles.push(path.trim());
                }
            });
            
            filesTextarea.value = allFiles.join('\n');
        } else {
            // Check for links
            const urlRegex = /https?:\/\/[^\s]+/g;
            const links = text.match(urlRegex) || [];
            
            if (links.length > 0) {
                const linksInput = document.getElementById('linksJson');
                const existingLinks = linksInput.value.trim();
                const allLinks = existingLinks ? existingLinks.split(/[\n,]+/).filter(l => l.trim()) : [];
                
                links.forEach(link => {
                    if (!allLinks.includes(link.trim())) {
                        allLinks.push(link.trim());
                    }
                });
                
                linksInput.value = allLinks.join(', ');
            }
        }
    }).catch(err => {
        console.error('Clipboard read failed:', err);
        alert('Failed to read clipboard. Please paste manually.');
    });
}

// ----- Health Checks -----
function checkHealth() {
    const healthToken = '<?= h($GLOBALS['HEALTH_TOKEN'] ?? '') ?>';
    const healthUrl = healthToken ? `/api/health.php?token=${encodeURIComponent(healthToken)}` : '/api/health.php';
    
    fetch(healthUrl)
        .then(response => {
            const statusEl = document.getElementById('health-status');
            const textEl = document.getElementById('health-text');
            
            if (response.ok) {
                statusEl.className = 'health-status health-ok';
                textEl.textContent = 'OK';
                autoMode.lastHealthCheck = 'ok';
            } else {
                statusEl.className = 'health-status health-fail';
                textEl.textContent = 'Failed';
                autoMode.lastHealthCheck = 'fail';
            }
        })
        .catch(error => {
            const statusEl = document.getElementById('health-status');
            const textEl = document.getElementById('health-text');
            
            statusEl.className = 'health-status health-unknown';
            textEl.textContent = 'Unknown';
            autoMode.lastHealthCheck = 'unknown';
        });
}

function updateHealthStatus() {
    const backupDone = document.getElementById('backup-done').checked;
    const healthStatus = autoMode.lastHealthCheck;
    const cacheCleared = document.getElementById('cache-cleared').checked;
    
    // Update visual indicators (you can enhance this with actual status updates)
    console.log('Health status updated:', { backupDone, healthStatus, cacheCleared });
}

// ----- Tags Input -----
function initializeTags(tagsString) {
    const container = document.getElementById('tagsContainer');
    const hiddenInput = document.getElementById('tagsHidden');
    const input = document.getElementById('tagInput');
    
    // Clear existing tags
    container.querySelectorAll('.tag').forEach(tag => tag.remove());
    
    const tags = tagsString ? tagsString.split(',').map(t => t.trim()).filter(t => t) : [];
    
    tags.forEach(tag => addTag(tag));
    
    // Add input handling
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',' || e.key === ' ') {
            e.preventDefault();
            const value = input.value.trim();
            if (value) {
                addTag(value);
                input.value = '';
            }
        } else if (e.key === 'Backspace' && !input.value && container.querySelector('.tag')) {
            // Remove last tag
            container.querySelector('.tag:last-child .tag-remove').click();
        }
    });
    
    input.addEventListener('blur', function() {
        const value = input.value.trim();
        if (value) {
            addTag(value);
            input.value = '';
        }
    });
}

function addTag(tag) {
    const container = document.getElementById('tagsContainer');
    const hiddenInput = document.getElementById('tagsHidden');
    const input = document.getElementById('tagInput');
    
    // Remove existing tag if duplicate
    const existingTag = container.querySelector(`[data-tag="${tag}"]`);
    if (existingTag) return;
    
    const tagElement = document.createElement('span');
    tagElement.className = 'tag';
    tagElement.setAttribute('data-tag', tag);
    tagElement.innerHTML = `
        ${tag}
        <span class="tag-remove" onclick="removeTag('${tag}')">&times;</span>
    `;
    
    container.insertBefore(tagElement, input);
    
    // Update hidden input
    const currentTags = hiddenInput.value ? hiddenInput.value.split(',').map(t => t.trim()).filter(t => t) : [];
    if (!currentTags.includes(tag)) {
        currentTags.push(tag);
        hiddenInput.value = currentTags.join(',');
    }
}

function removeTag(tag) {
    const container = document.getElementById('tagsContainer');
    const hiddenInput = document.getElementById('tagsHidden');
    
    // Remove visual tag
    const tagElement = container.querySelector(`[data-tag="${tag}"]`);
    if (tagElement) {
        tagElement.remove();
    }
    
    // Update hidden input
    const currentTags = hiddenInput.value ? hiddenInput.value.split(',').map(t => t.trim()).filter(t => t) : [];
    const newTags = currentTags.filter(t => t !== tag);
    hiddenInput.value = newTags.join(',');
}

// ----- Form Actions -----
function saveAndMarkDeployed() {
    document.getElementById('status').value = 'deployed';
    document.querySelector('form').dispatchEvent(new Event('submit', {cancelable: true}));
}

function clearForm() {
    if (confirm('Clear all form data?')) {
        document.getElementById('deployForm').reset();
        initializeTags('');
        autoMode.currentNoteId = 0;
    }
}

function setAction(action) {
    // Add action handling if needed
    console.log('Form action:', action);
}

// ----- Copy to Clipboard -----
function copyToClipboard(elementId) {
    const element = document.getElementById(elementId);
    if (navigator.clipboard) {
        navigator.clipboard.writeText(element.value).then(() => {
            // You could add a toast notification here
            console.log('Copied to clipboard');
        });
    }
}

// ----- Table Management -----
function createTable() {
    if (!confirm('Create the deploy_notes table with Auto-Mode features?')) return;
    
    fetch('?action=create_table', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= h(get_csrf_token()) ?>'
        },
        body: 'action=create_table&csrf=<?= h(get_csrf_token()) ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Table created successfully! Please refresh the page.');
            location.reload();
        } else {
            alert('Failed to create table: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Table creation failed:', error);
        alert('Failed to create table. Please check database permissions.');
    });
}

// ----- Recent Notes Features -----
function quickFillFromLast() {
    <?php if ($recent_note): ?>
    const note = <?= json_encode($recent_note) ?>;
    
    document.getElementById('env').value = note.env;
    document.getElementById('noteType').value = note.note_type;
    document.getElementById('impact').value = note.impact;
    document.getElementById('status').value = 'planned';
    document.getElementById('title').value = note.title;
    document.getElementById('body').value = note.body || '';
    document.getElementById('sqlUp').value = note.sql_up || '';
    document.getElementById('sqlDown').value = note.sql_down || '';
    document.getElementById('filesJson').value = note.files_json || '';
    document.getElementById('linksJson').value = note.links_json || '';
    
    initializeTags(note.tags || '');
    <?php else: ?>
    alert('No recent notes found.');
    <?php endif; ?>
}

function searchNotes() {
    const query = document.getElementById('searchInput').value;
    const url = new URL(window.location);
    url.searchParams.set('q', query);
    window.location.href = url.toString();
}

// ----- Event Listeners -----
function setupEventListeners() {
    // Save defaults on form change
    const form = document.getElementById('deployForm');
    const rememberCheckbox = document.getElementById('rememberDefaults');
    
    form.addEventListener('change', function() {
        if (rememberCheckbox.checked) {
            const defaults = {
                env: document.getElementById('env').value,
                noteType: document.getElementById('noteType').value,
                impact: document.getElementById('impact').value,
                status: document.getElementById('status').value
            };
            localStorage.setItem('deployDefaults', JSON.stringify(defaults));
        }
    });
    
    // Intent detection on input
    document.getElementById('title').addEventListener('input', detectIntent);
    document.getElementById('sqlUp').addEventListener('input', detectIntent);
    document.getElementById('body').addEventListener('input', detectIntent);
    
    // Health checkbox listeners
    document.getElementById('backup-done').addEventListener('change', updateHealthStatus);
    document.getElementById('cache-cleared').addEventListener('change', updateHealthStatus);
    
    // Links normalization
    document.getElementById('linksJson').addEventListener('blur', function() {
        const value = this.value.trim();
        if (value) {
            const links = value.split(/[\n,]+/).map(l => l.trim()).filter(l => l);
            this.value = JSON.stringify(links).replace(/[\[\]"]/g, '').replace(/,/g, ', ');
        }
    });
    
    // Files normalization
    document.getElementById('filesJson').addEventListener('blur', function() {
        const value = this.value.trim();
        if (value) {
            const files = value.split('\n').map(f => f.trim()).filter(f => f);
            this.value = files.join('\n');
        }
    });
}

// ----- Form Submission Handling -----
document.getElementById('deployForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Normalize JSON fields before submission
    const filesJson = document.getElementById('filesJson');
    const linksJson = document.getElementById('linksJson');
    
    // Try to parse and validate JSON
    try {
        if (filesJson.value) {
            const files = JSON.parse(filesJson.value);
            if (Array.isArray(files)) {
                filesJson.value = JSON.stringify(files);
            }
        }
        
        if (linksJson.value) {
            const links = JSON.parse(linksJson.value);
            if (Array.isArray(links)) {
                linksJson.value = JSON.stringify(links);
            }
        }
    } catch (error) {
        // If JSON parsing fails, keep the text format
        console.log('JSON validation failed, keeping text format');
    }
    
    // Submit the form normally
    this.submit();
});

</script>
</body>
</html>