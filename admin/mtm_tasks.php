<?php
// admin/mtm_tasks.php — MTM Tasks Manager (list + add + edit + delete + advanced JSON rules)
require_once __DIR__ . '/../includes/bootstrap.php';
if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Access denied'); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function t($s){ return trim((string)$s); }

$csrf = get_csrf_token();

// --- Input: model id ---
$model_id = (int)($_GET['model_id'] ?? 0);
if ($model_id <= 0) { $_SESSION['flash'] = "Model not specified."; header('Location: mtm_models.php'); exit; }

// Ensure model exists
$MODEL = null;
if ($st = $mysqli->prepare("SELECT id, title, difficulty, status FROM mtm_models WHERE id=? LIMIT 1")) {
  $st->bind_param('i',$model_id);
  $st->execute();
  $MODEL = $st->get_result()->fetch_assoc();
  $st->close();
}
if (!$MODEL) { $_SESSION['flash'] = "Model not found."; header('Location: mtm_models.php'); exit; }

// --- helpers: decode JSON safely ---
function decode_json_assoc($s, &$err=null){
  $err = null;
  if ($s === '' || $s === null) return [];
  $data = json_decode($s, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    $err = json_last_error_msg();
    return null;
  }
  return is_array($data) ? $data : [];
}

// --- Flash message ---
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// --- Actions: add / edit / delete ---
$mode = $_GET['mode'] ?? '';            // 'edit' for editing existing task
$edit_id = (int)($_GET['id'] ?? 0);     // current editing row

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf($_POST['csrf'] ?? '')) {
  $action = $_POST['action'] ?? '';

  // Common fields
  $title       = t($_POST['title'] ?? '');
  $level       = t($_POST['level'] ?? 'easy'); // easy|moderate|hard
  $sort_order  = (int)($_POST['sort_order'] ?? 0);

  // Standard rules
  $r_mode              = t($_POST['r_mode'] ?? 'both'); // both|paper|real
  $r_min_trades        = (int)($_POST['r_min_trades'] ?? 0);
  $r_time_window_days  = (int)($_POST['r_time_window_days'] ?? 0);
  $r_require_sl        = isset($_POST['r_require_sl']) ? 1 : 0;
  $r_max_risk_pct      = ($_POST['r_max_risk_pct'] === '' ? null : (float)$_POST['r_max_risk_pct']);
  $r_max_position_pct  = ($_POST['r_max_position_pct'] === '' ? null : (float)$_POST['r_max_position_pct']);
  $r_min_rr            = ($_POST['r_min_rr'] === '' ? null : (float)$_POST['r_min_rr']);
  $r_require_link      = isset($_POST['r_require_analysis_link']) ? 1 : 0;
  $r_weekly_min        = (int)($_POST['r_weekly_min_trades'] ?? 0);
  $r_weeks             = (int)($_POST['r_weeks'] ?? 0);

  // Baseline rules array (only include if set)
  $rules = [
    'mode'                  => $r_mode,
    'min_trades'            => $r_min_trades,
    'time_window_days'      => $r_time_window_days,
    'require_sl'            => $r_require_sl,
    'require_analysis_link' => $r_require_link
  ];
  if ($r_max_risk_pct !== null)     $rules['max_risk_pct']     = $r_max_risk_pct;
  if ($r_max_position_pct !== null) $rules['max_position_pct'] = $r_max_position_pct;
  if ($r_min_rr !== null)           $rules['min_rr']           = $r_min_rr;
  if ($r_weekly_min > 0)            $rules['weekly_min_trades']= $r_weekly_min;
  if ($r_weeks > 0)                 $rules['weeks']            = $r_weeks;

  // Advanced JSON merge (optional)
  $adv_err = null;
  $adv_raw = trim((string)($_POST['advanced_json'] ?? ''));
  if ($adv_raw !== '') {
    $adv = decode_json_assoc($adv_raw, $adv_err);
    if ($adv === null) {
      $_SESSION['flash'] = "Advanced JSON invalid: " . $adv_err;
      header("Location: mtm_tasks.php?model_id={$model_id}" . ($action==='update' ? "&mode=edit&id=".(int)($_POST['task_id'] ?? 0) : '') );
      exit;
    }
    // Merge: advanced overrides baseline if same keys appear.
    $rules = array_replace_recursive($rules, $adv);
  }

  // Serialize
  $rule_json = json_encode($rules, JSON_UNESCAPED_SLASHES);

  if ($action === 'create') {
    if ($title === '') {
      $_SESSION['flash'] = "Title is required.";
    } else {
      if ($st=$mysqli->prepare("INSERT INTO mtm_tasks (model_id, title, level, rule_json, sort_order, created_at) VALUES (?,?,?,?,?,NOW())")) {
        $st->bind_param('isssi', $model_id, $title, $level, $rule_json, $sort_order);
        $ok = $st->execute();
        $st->close();
        $_SESSION['flash'] = $ok ? "✅ Task created." : "❌ Could not create task: ".$mysqli->error;
      }
    }
    header("Location: mtm_model_view.php?id={$model_id}&tab=tasks"); exit;
  }

  if ($action === 'update') {
    $task_id = (int)($_POST['task_id'] ?? 0);
    if ($task_id <= 0) { $_SESSION['flash'] = "Invalid task id."; header("Location: mtm_tasks.php?model_id={$model_id}"); exit; }
    if ($st=$mysqli->prepare("UPDATE mtm_tasks SET title=?, level=?, rule_json=?, sort_order=? WHERE id=? AND model_id=?")) {
      $st->bind_param('sssiii', $title, $level, $rule_json, $sort_order, $task_id, $model_id);
      $ok = $st->execute();
      $st->close();
      $_SESSION['flash'] = $ok ? "✅ Task updated." : "❌ Could not update task: ".$mysqli->error;
    }
    header("Location: mtm_model_view.php?id={$model_id}&tab=tasks"); exit;
  }

  if ($action === 'delete') {
    $task_id = (int)($_POST['task_id'] ?? 0);
    if ($task_id > 0) {
      if ($st=$mysqli->prepare("DELETE FROM mtm_tasks WHERE id=? AND model_id=?")) {
        $st->bind_param('ii', $task_id, $model_id);
        $ok = $st->execute();
        $st->close();
        $_SESSION['flash'] = $ok ? "🗑️ Task deleted." : "❌ Could not delete task: ".$mysqli->error;
      }
    }
    header("Location: mtm_model_view.php?id={$model_id}&tab=tasks"); exit;
  }
}

// --- If editing, load the row
$EDIT = null;
if ($mode === 'edit' && $edit_id > 0) {
  if ($st=$mysqli->prepare("SELECT * FROM mtm_tasks WHERE id=? AND model_id=? LIMIT 1")) {
    $st->bind_param('ii',$edit_id,$model_id);
    $st->execute();
    $EDIT = $st->get_result()->fetch_assoc();
    $st->close();
  }
}

// --- Fetch all tasks for model
$TASKS = [];
$q = $mysqli->prepare("SELECT id, title, level, rule_json, sort_order, created_at FROM mtm_tasks WHERE model_id=? ORDER BY sort_order ASC, id ASC");
$q->bind_param('i',$model_id);
$q->execute();
$res = $q->get_result();
while ($row = $res->fetch_assoc()) { $TASKS[] = $row; }
$q->close();

// --- Prepare default/edit state fields
$F_title      = $EDIT ? $EDIT['title'] : '';
$F_level      = $EDIT ? (string)$EDIT['level'] : 'easy';
$F_sort_order = $EDIT ? (int)$EDIT['sort_order'] : (count($TASKS)+1);

// Extract standard rule fields from rule_json (for editing UX)
$F_rules = [];
$F_adv_json = '';
if ($EDIT) {
  $err=null;
  $decoded = decode_json_assoc((string)$EDIT['rule_json'], $err);
  if (is_array($decoded)) {
    $F_rules = $decoded;
    // Pre-fill Advanced JSON with full rule_json (admin can tweak)
    $F_adv_json = json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
  }
}
// Prefill helper
function getr($arr,$key,$def=null){ return isset($arr[$key]) ? $arr[$key] : $def; }

include __DIR__ . '/../header.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MTM Tasks — <?= h($MODEL['title']) ?></title>
<style>
  body{background:#ffffff;color:#1f2937;font-family:Inter,system-ui,Arial,sans-serif}
  .wrap{max-width:1100px;margin:18px auto;padding:0 16px}
  .bar{display:flex;align-items:center;gap:12px;margin-bottom:14px}
  .badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#e0e7ff;color:#3730a3;font-weight:700;font-size:12px}
  .muted{color:#6b7280}
  .card{background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 10px 28px rgba(15,23,42,.08);padding:16px;margin-bottom:16px}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:960px){ .grid{grid-template-columns:1fr} }
  label{display:block;font-size:12px;color:#4b5563;margin-bottom:6px}
  input[type=text], input[type=number], select, textarea{
    width:100%;padding:10px 12px;border-radius:10px;border:1px solid #d1d5db;background:#ffffff;color:#1f2937;font-size:14px
  }
  textarea{min-height:110px;font-family:ui-monospace, Menlo, Consolas, monospace}
  .row{display:flex;gap:10px;flex-wrap:wrap}
  .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid #4f46e5;background:#4f46e5;color:#fff;font-weight:800;text-decoration:none;cursor:pointer}
  .btn.secondary{background:#f3f4f6;border-color:#d1d5db;color:#374151}
  .btn.danger{background:#b91c1c;border-color:#b91c1c}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
  th{background:#f9fafb;color:#4b5563}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#e0e7ff;color:#3730a3;font-size:12px;font-weight:700}
  .ok{background:#ecfdf5;color:#047857;border-left:4px solid #10b981;padding:10px;border-radius:10px;margin-bottom:10px}
  .err{background:#fef2f2;color:#b91c1c;border-left:4px solid #ef4444;padding:10px;border-radius:10px;margin-bottom:10px}
  .tiny{font-size:12px;color:#6b7280}
  .inline-code{font-family:ui-monospace, Menlo, Consolas, monospace;background:#f3f4f6;border:1px solid #e2e8f0;padding:2px 6px;border-radius:6px;color:#1f2937}
</style>
</head>
<body>
<div class="wrap">

  <div class="bar">
    <a class="btn secondary" href="mtm_model_view.php?id=<?= (int)$MODEL['id'] ?>">← Back to <?= h($MODEL['title']) ?></a>
    <div class="badge">Model #<?= (int)$MODEL['id'] ?> · <?= h($MODEL['title']) ?></div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="ok"><?= h($flash) ?></div>
  <?php endif; ?>

  <!-- Add / Edit Form -->
  <div class="card">
    <h2 style="margin:0 0 10px">
      <?php if ($EDIT): ?>
        ✏️ Edit Task: <?= h($EDIT['title']) ?>
      <?php else: ?>
        ➕ Create New Task
      <?php endif; ?>
    </h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="<?= $EDIT ? 'update' : 'create' ?>">
      <?php if ($EDIT): ?>
        <input type="hidden" name="task_id" value="<?= (int)$EDIT['id'] ?>">
      <?php endif; ?>

      <div class="grid">
        <div>
          <label>Title</label>
          <input type="text" name="title" value="<?= h($F_title) ?>" required>
        </div>
        <div>
          <label>Level</label>
          <select name="level">
            <?php
              $opts = ['easy'=>'Easy','moderate'=>'Moderate','hard'=>'Hard'];
              foreach($opts as $k=>$v){
                $sel = ($F_level===$k)?'selected':'';
                echo "<option value='".h($k)."' $sel>".h($v)."</option>";
              }
            ?>
          </select>
        </div>
      </div>

      <div class="grid" style="margin-top:12px">
        <div>
          <label>Sort Order</label>
          <input type="number" name="sort_order" value="<?= (int)$F_sort_order ?>">
        </div>
      </div>

      <hr style="border-color:#e5e7eb; margin:14px 0">

      <h3 style="margin:0 0 8px">Standard Rules</h3>
      <div class="grid">
        <div>
          <label>Mode</label>
          <?php $rv=getr($F_rules,'mode','both'); ?>
          <select name="r_mode">
            <option value="both"   <?= $rv==='both'?'selected':'' ?>>Both</option>
            <option value="paper"  <?= $rv==='paper'?'selected':'' ?>>Paper</option>
            <option value="real"   <?= $rv==='real'?'selected':'' ?>>Real</option>
          </select>
        </div>
        <div>
          <label>Min Trades</label>
          <input type="number" name="r_min_trades" value="<?= (int)getr($F_rules,'min_trades',0) ?>" min="0">
        </div>
        <div>
          <label>Time Window (days)</label>
          <input type="number" name="r_time_window_days" value="<?= (int)getr($F_rules,'time_window_days',0) ?>" min="0">
        </div>
        <div>
          <label>Require Stop Loss?</label>
          <?php $rq=(int)getr($F_rules,'require_sl',0); ?>
          <select name="r_require_sl">
            <option value="" <?= $rq? '': 'selected' ?>>No</option>
            <option value="1" <?= $rq? 'selected':'' ?>>Yes</option>
          </select>
        </div>
        <div>
          <label>Max Risk % (Entry→SL)</label>
          <input type="number" step="0.01" name="r_max_risk_pct" value="<?= h(getr($F_rules,'max_risk_pct','')) ?>">
        </div>
        <div>
          <label>Max Position %</label>
          <input type="number" step="0.01" name="r_max_position_pct" value="<?= h(getr($F_rules,'max_position_pct','')) ?>">
        </div>
        <div>
          <label>Min R:R</label>
          <input type="number" step="0.01" name="r_min_rr" value="<?= h(getr($F_rules,'min_rr','')) ?>">
        </div>
        <div>
          <label>Require Analysis Link?</label>
          <?php $rl=(int)getr($F_rules,'require_analysis_link',0); ?>
          <select name="r_require_analysis_link">
            <option value="" <?= $rl? '': 'selected' ?>>No</option>
            <option value="1" <?= $rl? 'selected':'' ?>>Yes</option>
          </select>
        </div>
        <div>
          <label>Weekly Min Trades</label>
          <input type="number" name="r_weekly_min_trades" value="<?= (int)getr($F_rules,'weekly_min_trades',0) ?>" min="0">
        </div>
        <div>
          <label>Weeks (Consistency)</label>
          <input type="number" name="r_weeks" value="<?= (int)getr($F_rules,'weeks',0) ?>" min="0">
        </div>
      </div>

      <div style="margin-top:12px">
        <label>Advanced JSON (optional) — add/override rules (stored in <span class="inline-code">rule_json</span>)</label>
        <textarea name="advanced_json" placeholder='{
  "allowed_outcomes": ["TARGET HIT","BE"],
  "min_win_rate_pct": 60,
  "max_open_days": 5,
  "require_chart_tag": "breakout"
}'><?= h($F_adv_json) ?></textarea>
        <div class="tiny" style="margin-top:6px">
          Tip: Unknown keys are kept safely and can be evaluated by your verifier. This box overrides standard fields if same keys appear.
        </div>
      </div>

      <div class="row" style="margin-top:14px">
        <button class="btn" type="submit"><?= $EDIT ? '💾 Save Changes' : '➕ Create Task' ?></button>
        <a class="btn secondary" href="mtm_model_view.php?id=<?= (int)$model_id ?>&tab=tasks">Cancel</a>
      </div>
    </form>
  </div>

  <!-- Tasks List -->
  <div class="card">
    <h2 style="margin:0 0 10px">Tasks (<?= count($TASKS) ?>)</h2>
    <?php if (empty($TASKS)): ?>
      <div class="muted">No tasks yet. Add your first task above.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Title & Level</th>
            <th>Rules (summary)</th>
            <th>Sort</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($TASKS as $i=>$t):
          $R = [];
          $err=null;
          $decoded = decode_json_assoc((string)$t['rule_json'], $err);
          if (is_array($decoded)) $R = $decoded;

          $summary = [];
          if (!empty($R['mode']))                 $summary[] = "mode: ".h($R['mode']);
          if (isset($R['min_trades']))            $summary[] = "min_trades: ".(int)$R['min_trades'];
          if (isset($R['time_window_days']) && $R['time_window_days']>0) $summary[] = "window: ".(int)$R['time_window_days']."d";
          if (!empty($R['require_sl']))           $summary[] = "require_sl: yes";
          if (isset($R['max_risk_pct']))          $summary[] = "max_risk%: ".h($R['max_risk_pct']);
          if (isset($R['max_position_pct']))      $summary[] = "max_pos%: ".h($R['max_position_pct']);
          if (isset($R['min_rr']))                $summary[] = "min_rr: ".h($R['min_rr']);
          if (!empty($R['require_analysis_link']))$summary[] = "analysis_link: yes";
          if (!empty($R['weekly_min_trades']))    $summary[] = "weekly_min: ".(int)$R['weekly_min_trades'];
          if (!empty($R['weeks']))                $summary[] = "weeks: ".(int)$R['weeks'];

          // Show a couple of advanced keys if present
          $adv_keys = ['min_win_rate_pct','allowed_outcomes','max_open_days','require_chart_tag','min_points_total','market','min_capital','min_gap_between_trades_h','forbid_avg_down'];
          foreach($adv_keys as $k){
            if (isset($R[$k])) {
              $val = is_array($R[$k]) ? implode(',', $R[$k]) : (string)$R[$k];
              $summary[] = h($k).": ".h($val);
            }
          }
        ?>
          <tr>
            <td><?= (int)$t['id'] ?></td>
            <td>
              <div style="font-weight:800"><?= h($t['title']) ?></div>
              <div class="tiny">Level: <span class="pill"><?= h(ucfirst($t['level'])) ?></span></div>
            </td>
            <td class="tiny"><?= implode(' · ', $summary) ?: '—' ?></td>
            <td><?= (int)$t['sort_order'] ?></td>
            <td style="white-space:nowrap">
              <a class="btn" href="mtm_tasks.php?model_id=<?= (int)$model_id ?>&mode=edit&id=<?= (int)$t['id'] ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this task?')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                <button class="btn danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px">How to add new rules (no DB change)</h3>
    <div class="tiny">
      Put any extra constraints in <span class="inline-code">Advanced JSON</span>. They’re stored in
      <span class="inline-code">mtm_tasks.rule_json</span> and your verifier can enforce them.
      <br><br>
      <strong>Useful extra keys you may add:</strong>
      <ul>
        <li><span class="inline-code">min_win_rate_pct</span> — e.g. <span class="inline-code">60</span></li>
        <li><span class="inline-code">allowed_outcomes</span> — e.g. <span class="inline-code">["TARGET HIT","BE"]</span></li>
        <li><span class="inline-code">max_open_days</span> — e.g. <span class="inline-code">5</span> (close within 5 days)</li>
        <li><span class="inline-code">require_chart_tag</span> — e.g. <span class="inline-code">"breakout"</span> (must appear in notes/link)</li>
        <li><span class="inline-code">min_points_total</span> — e.g. <span class="inline-code">50</span></li>
        <li><span class="inline-code">market</span> — e.g. <span class="inline-code">"NSE"</span></li>
        <li><span class="inline-code">min_capital</span> — e.g. <span class="inline-code">50000</span></li>
        <li><span class="inline-code">min_gap_between_trades_h</span> — e.g. <span class="inline-code">12</span> hours (cooloff)</li>
        <li><span class="inline-code">forbid_avg_down</span> — <span class="inline-code">1</span></li>
      </ul>
      If a key in Advanced JSON matches a standard field (like <span class="inline-code">min_trades</span>), the JSON value overrides the form field.
    </div>
  </div>

</div>
<?php include __DIR__ . '/../footer.php'; ?>
</body>
</html>
