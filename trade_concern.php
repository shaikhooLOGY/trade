<?php
// /public_html/trade_concern.php
require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$uid = (int)$_SESSION['user_id'];
$is_admin = !empty($_SESSION['is_admin']) ? (int)$_SESSION['is_admin'] : 0;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- schema helpers ----------
function col_exists(mysqli $db, string $table, string $col): bool {
  $sql = "SELECT COUNT(*) c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $st = $db->prepare($sql); $st->bind_param('ss',$table,$col); $st->execute();
  $r = $st->get_result()->fetch_assoc(); $st->close();
  return !empty($r) && (int)$r['c'] > 0;
}
function index_exists(mysqli $db, string $table, string $index): bool {
  $sql = "SELECT COUNT(*) c FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?";
  $st = $db->prepare($sql); $st->bind_param('ss',$table,$index); $st->execute();
  $r = $st->get_result()->fetch_assoc(); $st->close();
  return !empty($r) && (int)$r['c'] > 0;
}
function ensure_table(mysqli $db) {
  // base table
  $db->query("CREATE TABLE IF NOT EXISTS trade_concerns (
      id INT AUTO_INCREMENT PRIMARY KEY,
      trade_id INT NOT NULL,
      user_id INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // add missing columns
  $want = [
    ['reason',     "VARCHAR(200) NOT NULL AFTER user_id"],
    ['details',    "TEXT NOT NULL AFTER reason"],
    ['status',     "ENUM('open','resolved','reopened') NOT NULL DEFAULT 'open' AFTER details"],
    ['handled_by', "INT NULL AFTER status"],
    ['created_at', "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER handled_by"],
    ['updated_at', "TIMESTAMP NULL DEFAULT NULL AFTER created_at"],
  ];
  foreach ($want as [$c,$def]) {
    if (!col_exists($db,'trade_concerns',$c)) {
      $db->query("ALTER TABLE trade_concerns ADD COLUMN $def");
    }
  }

  // add indexes only if missing (STRICT-safe)
  if (!index_exists($db,'trade_concerns','idx_tc_trade')) {
    $db->query("CREATE INDEX idx_tc_trade ON trade_concerns(trade_id)");
  }
  if (!index_exists($db,'trade_concerns','idx_tc_user')) {
    $db->query("CREATE INDEX idx_tc_user ON trade_concerns(user_id)");
  }
}
ensure_table($mysqli);

// ---------- CSRF (using unified system) ----------
$csrf = get_csrf_token();

// ---------- load trade ----------
$trade_id = isset($_REQUEST['trade_id']) ? (int)$_REQUEST['trade_id'] : 0;
if ($trade_id <= 0) { echo "Invalid trade."; exit; }

$has_locked = col_exists($mysqli,'trades','locked');
$sql = "SELECT id, user_id".($has_locked?", locked":"")." FROM trades WHERE id=? LIMIT 1";
$st = $mysqli->prepare($sql); $st->bind_param('i',$trade_id); $st->execute();
$tr = $st->get_result()->fetch_assoc(); $st->close();
if (!$tr) { echo "Trade not found."; exit; }
if ($tr['user_id'] != $uid && !$is_admin) { header('HTTP/1.1 403 Forbidden'); echo "Access denied."; exit; }

$err = '';

// ---------- submit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__submit_tc'])) {
  if (!validate_csrf($_POST['csrf'] ?? '')) {
    $err = "Invalid request (CSRF).";
  } else {
    $reason  = trim($_POST['reason'] ?? '');
    $details = trim($_POST['details'] ?? '');
    if ($reason === '' || $details === '') {
      $err = "Please fill reason and details.";
    } else {
      $ins = $mysqli->prepare("INSERT INTO trade_concerns (trade_id,user_id,reason,details,status) VALUES (?,?,?,?, 'open')");
      $ins->bind_param('iiss', $trade_id, $uid, $reason, $details);
      $ins->execute();
      $ins->close();

      $_SESSION['flash_ok'] = "Concern raised successfully. Admin will review.";
      header('Location: /dashboard.php'); exit;
    }
  }
}

include __DIR__ . '/header.php';
?>
<style>
.card{background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(15,20,40,.06);padding:16px}
.field{margin:10px 0}
label{display:block;font-weight:700;margin-bottom:6px}
input[type=text], select, textarea{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:15px}
textarea{min-height:120px}
.btn{background:#4f46e5;color:#fff;padding:10px 14px;border:0;border-radius:8px;font-weight:700;cursor:pointer}
.muted{color:#6b7280}
.alert{padding:10px;border-radius:8px;margin-bottom:10px}
.alert-err{background:#fff1f2;border:1px solid #fecaca;color:#991b1b}
</style>

<main class="container" style="padding:20px 18px;">
  <h2 style="margin:0 0 10px">Raise Concern — Trade #<?= (int)$trade_id ?></h2>
  <p class="muted" style="margin:0 0 12px">Explain what went wrong. Admin will review and (if valid) unlock the trade.</p>

  <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>

  <form method="post" class="card" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(get_csrf_token()) ?>">
    <input type="hidden" name="trade_id" value="<?= (int)$trade_id ?>">
    <input type="hidden" name="__submit_tc" value="1">

    <div class="field">
      <label for="reason">Reason</label>
      <select name="reason" id="reason" required>
        <option value="">-- Select reason --</option>
        <option>Mistyped exit/values</option>
        <option>Exited by mistake</option>
        <option>Broker/platform issue</option>
        <option>Other</option>
      </select>
    </div>

    <div class="field">
      <label for="details">Details</label>
      <textarea name="details" id="details" placeholder="Describe the issue, expected correction, supporting info (order id / screenshot link)..." required></textarea>
    </div>

    <div style="display:flex;gap:10px;align-items:center">
      <button class="btn" type="submit">Submit concern</button>
      <a class="muted" href="/dashboard.php">Cancel</a>
    </div>
  </form>
</main>

<?php include __DIR__ . '/footer.php'; ?>