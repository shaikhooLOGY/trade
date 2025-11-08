<?php
// admin/trade_concerns.php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403'); exit('Access denied'); }

function has_col(mysqli $db, string $t, string $c): bool {
  $sql="SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  if($st=$db->prepare($sql)){ $st->bind_param('ss',$t,$c); $st->execute();
    $ok = ($r=$st->get_result()) && $r->num_rows>0; $st->close(); return $ok; }
  return false;
}
$hasResolvedAt  = has_col($mysqli,'trade_concerns','resolved_at');
$hasStatus      = has_col($mysqli,'trade_concerns','status');
$hasProcessedBy = has_col($mysqli,'trade_concerns','processed_by');
$hasProcessedAt = has_col($mysqli,'trade_concerns','processed_at');

if (empty($_SESSION['admin_csrf'])) {
  try { $_SESSION['admin_csrf']=bin2hex(random_bytes(32)); }
  catch(Exception $e){ $_SESSION['admin_csrf']=bin2hex(openssl_random_pseudo_bytes(32)); }
}
$csrf = $_SESSION['admin_csrf'];

$view = strtolower($_GET['view'] ?? 'pending');
if(!in_array($view,['all','pending','resolved'],true)) $view='pending';

$where="1=1";
if($view==='pending'){
  if($hasResolvedAt) $where.=" AND c.resolved_at IS NULL";
  elseif($hasStatus) $where.=" AND (c.status IS NULL OR c.status<>'resolved')";
}
if($view==='resolved'){
  if($hasResolvedAt) $where.=" AND c.resolved_at IS NOT NULL";
  elseif($hasStatus) $where.=" AND c.status='resolved'";
}

$sql="
SELECT c.id,c.trade_id,c.reason,c.created_at,
       ".($hasStatus?"c.status":"NULL AS status").",
       ".($hasResolvedAt?"c.resolved_at":"NULL AS resolved_at").",
       ".($hasProcessedBy?"c.processed_by":"NULL AS processed_by").",
       ".($hasProcessedAt?"c.processed_at":"NULL AS processed_at").",
       t.symbol,t.entry_date,t.exit_price,t.is_locked,
       u.name AS user_name,u.email AS user_email
FROM trade_concerns c
JOIN trades t ON t.id=c.trade_id
LEFT JOIN users u ON u.id=t.user_id
WHERE $where
ORDER BY c.id DESC";
$res = $mysqli->query($sql);
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin • Trade Concerns</title>
<style>
  :root{--bg:#f6f7fb;--card:#fff;--border:#eef0f6;--muted:#6b7280;--blue:#2563eb;--ok:#16a34a;--warn:#f59e0b;}
  body{font-family:Inter,system-ui,Arial;margin:0;background:var(--bg);color:#111}
  .wrap{max-width:1200px;margin:24px auto;padding:0 16px}
  h1{margin:0 0 12px}
  .tabs a{padding:8px 12px;border:1px solid var(--border);border-radius:999px;text-decoration:none;color:#111;font-weight:700;margin-right:6px;background:#fff}
  .tabs a.active{background:var(--blue);color:#fff;border-color:var(--blue)}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:0 10px 28px rgba(22,28,45,.06);padding:12px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:13.5px;vertical-align:top}
  th{background:#fafafa;text-align:left;font-weight:800}
  .muted{color:var(--muted)}
  .btn{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:#fff;font-weight:700;font-size:12px;text-decoration:none;color:#111}
  .btn.green{background:var(--ok);color:#fff;border-color:var(--ok)}
  .btn.amber{background:var(--warn);color:#111;border-color:var(--warn)}
  .status{padding:4px 8px;border-radius:8px;font-weight:700;font-size:12px;display:inline-block}
  .ok{background:#dcfce7;color:#14532d}.pending{background:#fff7ed;color:#7c2d12}
</style></head><body>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <h1>Admin • Trade Concerns</h1>
    <div class="tabs">
      <a class="<?= $view==='pending'?'active':'' ?>" href="?view=pending">Pending</a>
      <a class="<?= $view==='resolved'?'active':'' ?>" href="?view=resolved">Resolved</a>
      <a class="<?= $view==='all'?'active':'' ?>" href="?view=all">All</a>
      <a href="/dashboard.php">← Dashboard</a>
    </div>
  </div>

  <div class="card" style="overflow:auto">
    <table>
      <thead><tr>
        <th>#</th><th>Trade</th><th>User</th><th>Reason</th><th>Raised</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="7" class="muted">No concerns found.</td></tr>
      <?php else: $i=1; foreach($rows as $r):
        $resolved = ($hasResolvedAt && !empty($r['resolved_at'])) || ($hasStatus && strtolower((string)$r['status'])==='resolved');
      ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><strong><?= e($r['symbol']) ?></strong> &nbsp; <a href="/trade_view.php?id=<?= (int)$r['trade_id'] ?>">View</a>
              <div class="muted">Entry: <?= e($r['entry_date']) ?><?= ($r['exit_price']!==null)?(' • Exit: '.e($r['exit_price'])):'' ?></div></td>
          <td><?= e($r['user_name'] ?: '—') ?><div class="muted"><?= e($r['user_email'] ?: '') ?></div></td>
          <td style="max-width:380px"><?= nl2br(e($r['reason'] ?: '—')) ?></td>
          <td class="muted"><?= e($r['created_at'] ?: '—') ?></td>
          <td><?= $resolved ? '<span class="status ok">Resolved</span>' : '<span class="status pending">Pending</span>' ?></td>
          <td>
            <?php if(!$resolved): ?>
              <form action="/admin/trade_concerns_action.php" method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="trade_id" value="<?= (int)$r['trade_id'] ?>">
                <input type="hidden" name="action" value="unlock">
                <button class="btn green" onclick="return confirm('Unlock trade & resolve?');">🔓 Unlock</button>
              </form>
              <form action="/admin/trade_concerns_action.php" method="post" style="display:inline;margin-left:6px">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="trade_id" value="<?= (int)$r['trade_id'] ?>">
                <input type="hidden" name="action" value="resolve">
                <button class="btn amber" onclick="return confirm('Mark resolved only?');">✅ Resolve</button>
              </form>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body></html>