<?php
// admin/admin_unlocks.php — Manage trade unlock approvals (no expiry version)
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['is_admin'])) {
  header('HTTP/1.1 403 Forbidden');
  exit('Admins only.');
}

// tiny helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function flash_set($m){ $_SESSION['flash'] = (string)$m; }
function flash_pop(){ $m = $_SESSION['flash'] ?? ''; if ($m!=='') unset($_SESSION['flash']); return $m; }

// (optional) ensure column exists (runs fast; safe)
// Comment this block if not needed.
$colExists = false;
if ($res = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'unlock_status'")) {
  $colExists = (bool)$res->num_rows; $res->close();
}
if (!$colExists) {
  $mysqli->query("ALTER TABLE trades ADD COLUMN unlock_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'");
}

// handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!validate_csrf($_POST['csrf'] ?? '')) {
    flash_set('Security token invalid. Try again.');
    header('Location: admin_unlocks.php'); exit;
  }
  $tradeId = (int)($_POST['trade_id'] ?? 0);
  $action  = strtolower((string)($_POST['action'] ?? ''));
  if ($tradeId <= 0) {
    flash_set('Bad trade id.');
    header('Location: admin_unlocks.php'); exit;
  }

  if ($action === 'approve') {
    $st = $mysqli->prepare("UPDATE trades SET unlock_status='approved' WHERE id=?");
  } elseif ($action === 'reject') {
    $st = $mysqli->prepare("UPDATE trades SET unlock_status='rejected' WHERE id=?");
  } elseif ($action === 'reset') {
    $st = $mysqli->prepare("UPDATE trades SET unlock_status='none' WHERE id=?");
  } else {
    flash_set('Unknown action.');
    header('Location: admin_unlocks.php'); exit;
  }

  $ok = false;
  if ($st) {
    $st->bind_param('i',$tradeId);
    $st->execute();
    $ok = ($st->affected_rows >= 0); // even 0 is okay (no change)
    $st->close();
  }
  flash_set($ok ? 'Saved.' : 'Update failed.');
  header('Location: admin_unlocks.php'); exit;
}

// filter
$allowed = ['pending','approved','rejected','all'];
$view = strtolower($_GET['status'] ?? 'pending');
if (!in_array($view, $allowed, true)) $view = 'pending';

$where = "unlock_status IN ('pending','approved','rejected')";
$order = "FIELD(unlock_status,'pending','approved','rejected'), t.id DESC";
$params = [];
if ($view !== 'all') {
  $where = "unlock_status = ?";
  $order = "t.id DESC";
  $params = [$view];
}

// fetch list
$sql = "
SELECT
  t.id, t.user_id,
  COALESCE(t.symbol, '') AS symbol,
  COALESCE(t.entry_date, DATE(t.created_at)) AS entry_date,
  COALESCE(t.outcome,'') AS outcome,
  COALESCE(t.pl_percent,0) AS pl_percent,
  COALESCE(t.unlock_status,'none') AS unlock_status,
  u.name AS user_name, u.email AS user_email
FROM trades t
LEFT JOIN users u ON u.id = t.user_id
WHERE $where
ORDER BY $order
LIMIT 500
";
$rows = [];
if ($view === 'all') {
  $res = $mysqli->query($sql);
} else {
  $st = $mysqli->prepare($sql);
  $st->bind_param('s', $params[0]);
  $st->execute();
  $res = $st->get_result();
}
if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; if ($view!=='all') $st->close(); }

$title = 'Manage Unlocks';
$hideNav = false;
include __DIR__ . '/../header.php';
$flash = flash_pop();
?>
<div class="container" style="max-width:1100px;margin:22px auto;padding:0 16px">
  <h2 style="margin:6px 0 14px">🔓 Unlock Requests</h2>

  <?php if ($flash): ?>
    <div style="background:#ecfdf5;border:1px solid #10b98133;color:#065f46;padding:10px;border-radius:8px;margin-bottom:10px;font-weight:600">
      <?= h($flash) ?>
    </div>
  <?php endif; ?>

  <div style="display:flex;gap:8px;margin-bottom:12px">
    <a href="?status=pending"  class="pill" style="text-decoration:none;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;<?= $view==='pending'?'background:#5a2bd9;color:#fff;border-color:transparent;font-weight:800':'' ?>">Pending</a>
    <a href="?status=approved" class="pill" style="text-decoration:none;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;<?= $view==='approved'?'background:#5a2bd9;color:#fff;border-color:transparent;font-weight:800':'' ?>">Approved</a>
    <a href="?status=rejected" class="pill" style="text-decoration:none;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;<?= $view==='rejected'?'background:#5a2bd9;color:#fff;border-color:transparent;font-weight:800':'' ?>">Rejected</a>
    <a href="?status=all"      class="pill" style="text-decoration:none;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;<?= $view==='all'?'background:#5a2bd9;color:#fff;border-color:transparent;font-weight:800':'' ?>">All</a>
  </div>

  <div style="overflow:auto;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead>
        <tr style="background:#f8fafc;text-align:left">
          <th style="padding:10px">ID</th>
          <th style="padding:10px">User</th>
          <th style="padding:10px">Symbol</th>
          <th style="padding:10px">Entry</th>
          <th style="padding:10px">Outcome</th>
          <th style="padding:10px">P/L%</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" style="padding:12px;color:#666">Nothing here.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr style="border-top:1px solid #eef2f7">
            <td style="padding:10px"><?= (int)$r['id'] ?></td>
            <td style="padding:10px">
              <?= h($r['user_name'] ?: $r['user_email']) ?>
              <div style="font-size:12px;color:#6b7280"><?= h($r['user_email']) ?></div>
            </td>
            <td style="padding:10px"><?= h($r['symbol']) ?></td>
            <td style="padding:10px"><?= h($r['entry_date']) ?></td>
            <td style="padding:10px"><?= h($r['outcome']) ?></td>
            <td style="padding:10px;<?= (float)$r['pl_percent']>=0?'color:#065f46':'color:#b91c1c' ?>;font-weight:700">
              <?= number_format((float)$r['pl_percent'],1) ?>
            </td>
            <td style="padding:10px;font-weight:800">
              <?= strtoupper($r['unlock_status']) ?>
            </td>
            <td style="padding:10px;white-space:nowrap">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(get_csrf_token()) ?>">
                <input type="hidden" name="trade_id" value="<?= (int)$r['id'] ?>">
                <button name="action" value="approve" style="background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Approve</button>
              </form>
              <form method="post" style="display:inline;margin-left:6px">
                <input type="hidden" name="csrf" value="<?= h(get_csrf_token()) ?>">
                <input type="hidden" name="trade_id" value="<?= (int)$r['id'] ?>">
                <button name="action" value="reject" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Reject</button>
              </form>
              <form method="post" style="display:inline;margin-left:6px">
                <input type="hidden" name="csrf" value="<?= h(get_csrf_token()) ?>">
                <input type="hidden" name="trade_id" value="<?= (int)$r['id'] ?>">
                <button name="action" value="reset" style="background:#f1f5f9;color:#111;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Reset</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../footer.php'; ?>