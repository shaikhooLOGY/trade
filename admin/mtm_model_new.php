<?php
// admin/mtm_models_new.php — create MTM model (with banner upload)
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Access denied'); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$csrf = get_csrf_token();

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD']==='POST' && validate_csrf($_POST['csrf'] ?? '')) {
  $title   = trim($_POST['title'] ?? '');
  $desc    = trim($_POST['description'] ?? '');
  $diff    = in_array(($_POST['difficulty'] ?? 'easy'), ['easy','moderate','hard'], true) ? $_POST['difficulty'] : 'easy';
  $status  = in_array(($_POST['status'] ?? 'draft'), ['draft','active','paused','archived'], true) ? $_POST['status'] : 'draft';
  $order   = (int)($_POST['display_order'] ?? 0);
  $days    = max(0,(int)($_POST['estimated_days'] ?? 0));
  $color   = trim($_POST['banner_color'] ?? '#7c3aed');

  // handle banner upload (optional)
  $banner_path = null;
  if (!empty($_FILES['banner_image']['name']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['banner_image']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
      $dir = __DIR__ . '/../uploads/mtm';
      if (!is_dir($dir)) mkdir($dir, 0775, true);
      $fname = 'mtm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $dir . '/' . $fname;
      if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $dest)) {
        // web path
        $banner_path = '/uploads/mtm/' . $fname;
      }
    }
  }

  if ($st = $mysqli->prepare("INSERT INTO mtm_models (title, description, difficulty, status, display_order, estimated_days, banner_color, banner_image, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st->bind_param('ssssisssi', $title,$desc,$diff,$status,$order,$days,$color,$banner_path,$uid);
    $ok = $st->execute();
    $st->close();
    $_SESSION['flash'] = $ok ? "✅ Model created." : "❌ Failed: ".$mysqli->error;
  }
  header('Location: /admin/mtm_models.php'); exit;
}

include __DIR__ . '/../header.php';
?>
<style>
  .wrap{max-width:920px;margin:22px auto;padding:0 16px;color:#eaeaea}
  .glass{background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.35);border-radius:14px;padding:16px;backdrop-filter:blur(6px)}
  label{display:block;margin:10px 0 6px}
  input[type=text],textarea,select{width:100%;background:#0f1220;color:#fff;border:1px solid #2a2f45;border-radius:10px;padding:10px}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .btn{display:inline-block;background:#5a2bd9;padding:10px 14px;border-radius:10px;color:#fff;text-decoration:none;font-weight:800;border:0}
  .btn.ghost{background:#24263a}
</style>
<div class="wrap">
  <div class="glass" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">
    <div style="font-weight:900">🧠 New MTM Model</div>
    <div>
      <a class="btn ghost" href="/admin/mtm_models.php">← Back to MTM Models</a>
    </div>
  </div>

  <?php if($flash): ?>
    <div class="glass" style="border-color:#16a34a;color:#bbf7d0"><?= h($flash) ?></div>
  <?php endif; ?>

  <form class="glass" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Title</label>
    <input type="text" name="title" required>

    <label>Description</label>
    <textarea name="description" rows="4" placeholder="Short pitch and structure..."></textarea>

    <div class="row">
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
          <option value="paused">Paused</option>
          <option value="archived">Archived</option>
        </select>
      </div>
    </div>

    <div class="row">
      <div>
        <label>Display order</label>
        <input type="text" name="display_order" value="0">
      </div>
      <div>
        <label>Estimated days</label>
        <input type="text" name="estimated_days" value="0">
      </div>
    </div>

    <div class="row">
      <div>
        <label>Banner color</label>
        <input type="text" name="banner_color" value="#7c3aed">
      </div>
      <div>
        <label>Banner image (optional)</label>
        <input type="file" name="banner_image" accept=".jpg,.jpeg,.png,.gif,.webp">
      </div>
    </div>

    <div style="margin-top:14px">
      <button class="btn" type="submit">+ Create Model</button>
    </div>
  </form>
</div>
<?php include __DIR__ . '/../footer.php'; ?>