<?php
// admin/mtm_levels.php — MTM Level Manager v1.0
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Access denied'); }

// helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $db, $name){
  $q = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->bind_param('s',$name); $q->execute(); $q->bind_result($c); $q->fetch(); $q->close();
  return ((int)$c) > 0;
}

$has_models = table_exists($mysqli,'mtm_models');
$has_levels = table_exists($mysqli,'mtm_levels');

$message = '';

// load models for dropdown (if table exists)
$models = [];
if ($has_models) {
  if ($res = $mysqli->query("SELECT id, title, slug, status FROM mtm_models ORDER BY id DESC")) {
    while($r = $res->fetch_assoc()) $models[] = $r;
    $res->free();
  }
}

// current model (from query or first)
$active_model_id = 0;
if (!empty($_GET['model_id'])) $active_model_id = (int)$_GET['model_id'];
if ($active_model_id === 0 && !empty($models)) $active_model_id = (int)$models[0]['id'];

// handle create new level
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form']) && $_POST['form']==='create') {
  $model_id   = (int)($_POST['model_id'] ?? 0);
  $title      = trim($_POST['title'] ?? '');
  $sequence   = (int)($_POST['sequence'] ?? 1);
  $difficulty = trim($_POST['difficulty'] ?? 'easy');
  $status     = trim($_POST['status'] ?? 'draft');
  $descr      = trim($_POST['description'] ?? '');

  if (!$has_levels || !$has_models) {
    $message = "❌ Tables missing. Please run SQL migrations first.";
  } elseif ($model_id <= 0) {
    $message = "⚠️ Select a model first.";
  } elseif ($title === '') {
    $message = "⚠️ Level title is required.";
  } else {
    $st = $mysqli->prepare("INSERT INTO mtm_levels (model_id, title, sequence, difficulty, status, description, created_at, updated_at)
                            VALUES (?,?,?,?,?,?,NOW(),NOW())");
    $st->bind_param('isisss', $model_id, $title, $sequence, $difficulty, $status, $descr);
    if ($st->execute()) {
      $message = "✅ Level “".h($title)."” added!";
      // keep context on same model
      $active_model_id = $model_id;
    } else {
      $message = "❌ DB Error: ".$mysqli->error;
    }
    $st->close();
  }
}

// read levels for active model
$levels = [];
if ($has_levels && $active_model_id > 0) {
  $st = $mysqli->prepare("SELECT id, title, sequence, difficulty, status, description, created_at, updated_at
                          FROM mtm_levels WHERE model_id=? ORDER BY sequence ASC, id ASC");
  $st->bind_param('i',$active_model_id);
  $st->execute();
  $res = $st->get_result();
  while($row = $res->fetch_assoc()) $levels[] = $row;
  $st->close();
}

$title = "MTM Levels — Shaikhoology";
include __DIR__ . '/../header.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= h($title) ?></title>
<style>
  body{
    font-family: Inter, Roboto, Arial, sans-serif;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color:#fff; margin:0;
  }
  .wrap{max-width:1100px;margin:20px auto;padding:0 20px}
  .topbar{ text-align:center;padding:30px 20px;background:rgba(0,0,0,0.3);margin-bottom:30px}
  .topbar h1{ margin:0; font-size:28px;
    background:linear-gradient(90deg,#ff6b6b,#4ecdc4,#45b7d1);
    -webkit-background-clip:text; background-clip:text;
    -webkit-text-fill-color:transparent; color:transparent;
  }
  .muted{color:#adb5bd;font-size:13px}
  .card{
    background:rgba(255,255,255,0.08);
    -webkit-backdrop-filter:blur(10px);
    backdrop-filter:blur(10px);
    border:1px solid rgba(255,255,255,0.1);
    border-radius:16px; padding:18px; margin-bottom:20px;
  }
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
  label{font-weight:700;display:block;margin:8px 0 4px}
  input,select,textarea{
    width:100%; padding:9px 10px; border-radius:8px;
    border:1px solid rgba(255,255,255,0.15);
    background:rgba(255,255,255,0.05); color:#fff; font-size:14px;
  }
  textarea{min-height:90px;resize:vertical}
  .btn{
    display:inline-block; background:linear-gradient(90deg,#ff6b6b,#ee5a52); color:#fff;
    border:none; padding:10px 16px; border-radius:10px; font-weight:800; cursor:pointer;
    text-decoration:none; transition:transform .2s;
  }
  .btn:hover{ transform:scale(1.05); box-shadow:0 10px 20px rgba(238,90,82,0.35); }
  .success{background:#16a34a33;border:1px solid #16a34a55;padding:10px;border-radius:10px;color:#86efac;font-weight:800;margin-bottom:10px}
  .error{background:#dc262633;border:1px solid #dc262655;padding:10px;border-radius:10px;color:#fca5a5;font-weight:800;margin-bottom:10px}
  table{width:100%;border-collapse:collapse;font-size:14px;margin-top:10px}
  th,td{padding:10px;border-top:1px solid rgba(255,255,255,0.1);text-align:left;vertical-align:top}
  thead th{background:rgba(255,255,255,0.08);font-weight:800}
  .row-head{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
  .pill{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);font-weight:700}
  .right{margin-left:auto}
</style>
</head>
<body>
  <div class="topbar">
    <h1>🧱 MTM — Level Manager</h1>
    <div class="muted">Create & organize levels (Beginner / Intermediate / Advanced) for each MTM model.</div>
  </div>

  <div class="wrap">
    <?php if($message): ?>
      <div class="<?= (strpos($message,'✅')!==false)?'success':'error' ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Model switcher -->
    <div class="card">
      <div class="row-head">
        <strong>Select Model</strong>
        <div class="right"><a class="btn" href="mtm_models.php">↩ MTM Models</a></div>
      </div>
      <?php if(!$has_models): ?>
        <div class="muted">⚠️ Table <code>mtm_models</code> missing. Run migrations first.</div>
      <?php elseif(empty($models)): ?>
        <div class="muted">No models yet. Create one in MTM Models.</div>
      <?php else: ?>
        <form method="get" style="max-width:360px">
          <label>Model</label>
          <select name="model_id" onchange="this.form.submit()">
            <?php foreach($models as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= ((int)$m['id']===$active_model_id?'selected':'') ?>>
                <?= h($m['title']) ?> (<?= h($m['status']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>

    <!-- Add level -->
    <div class="card">
      <h2 style="margin:0 0 10px;font-size:20px">➕ Add New Level</h2>
      <?php if(!$has_levels): ?>
        <div class="muted">⚠️ Table <code>mtm_levels</code> missing. Run migrations first.</div>
      <?php elseif($active_model_id<=0): ?>
        <div class="muted">Select a model to add levels.</div>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="form" value="create">
          <input type="hidden" name="model_id" value="<?= (int)$active_model_id ?>">
          <div class="grid">
            <div>
              <label>Level Title*</label>
              <input type="text" name="title" required placeholder="e.g. Basic">
            </div>
            <div>
              <label>Show Order (sequence)</label>
              <input type="number" name="sequence" value="1" min="1">
            </div>
            <div>
              <label>Difficulty</label>
              <select name="difficulty">
                <option value="easy">Easy</option>
                <option value="moderate">Moderate</option>
                <option value="hard">Hard</option>
              </select>
            </div>
            <div>
              <label>Status</label>
              <select name="status">
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="archived">Archived</option>
              </select>
            </div>
          </div>
          <label style="margin-top:10px">Short Description (optional)</label>
          <textarea name="description" placeholder="What’s covered in this level?"></textarea>
          <div style="margin-top:12px">
            <button class="btn" type="submit">Save Level</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- Existing levels -->
    <div class="card">
      <h2 style="margin:0 0 10px;font-size:20px">📋 Levels for this Model</h2>
      <?php if(!$has_levels || $active_model_id<=0): ?>
        <div class="muted">No data to show.</div>
      <?php elseif(empty($levels)): ?>
        <div class="muted">No levels added yet.</div>
      <?php else: ?>
        <div style="overflow:auto">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Title</th>
                <th>Sequence</th>
                <th>Difficulty</th>
                <th>Status</th>
                <th>Description</th>
                <th>Created</th>
                <th>Updated</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($levels as $lv): ?>
              <tr>
                <td><?= (int)$lv['id'] ?></td>
                <td><span class="pill"><?= h($lv['title']) ?></span></td>
                <td><?= (int)$lv['sequence'] ?></td>
                <td><?= ucfirst(h($lv['difficulty'])) ?></td>
                <td><?= ucfirst(h($lv['status'])) ?></td>
                <td><?= nl2br(h($lv['description'])) ?></td>
                <td class="muted"><?= h($lv['created_at']) ?></td>
                <td class="muted"><?= h($lv['updated_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div style="text-align:center;margin:18px 0">
      <a class="btn" href="admin_dashboard.php">← Back to Admin Dashboard</a>
    </div>
  </div>

<?php include __DIR__ . '/../footer.php'; ?>
</body>
</html>