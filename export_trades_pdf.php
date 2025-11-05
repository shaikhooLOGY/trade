<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

// Same filters as dashboard
$tab   = ($_GET['tab'] ?? 'active') === 'deleted' ? 'deleted' : 'active';
$state = strtolower($_GET['state'] ?? 'all');

$where = "user_id={$user_id}";
$where .= ($tab === 'active') ? " AND deleted_at IS NULL" : " AND deleted_at IS NOT NULL";
if ($tab === 'active') {
  if ($state === 'open')            $where .= " AND UPPER(COALESCE(outcome,'OPEN'))='OPEN'";
  if ($state === 'closed')          $where .= " AND UPPER(COALESCE(outcome,''))<>'OPEN'";
  if ($state === 'unlocked')        $where .= " AND LOWER(COALESCE(unlock_status,'none'))='approved' AND UPPER(COALESCE(outcome,''))<>'OPEN'";
  if ($state === 'locked')          $where .= " AND LOWER(COALESCE(unlock_status,'none')) IN ('none','rejected') AND UPPER(COALESCE(outcome,''))<>'OPEN'";
  if ($state === 'required_unlock') $where .= " AND LOWER(COALESCE(unlock_status,'none')) NOT IN ('approved','rejected') AND UPPER(COALESCE(outcome,''))<>'OPEN'";
}

$sql = "
  SELECT id, entry_date, symbol, entry_price, exit_price, close_date,
         COALESCE(outcome,'') outcome,
         CASE WHEN UPPER(COALESCE(outcome,''))='OPEN' THEN 'Open' ELSE 'Closed' END AS status,
         COALESCE(pl_percent,0) pl_percent,
         COALESCE(unlock_status,'none') unlock_status,
         COALESCE(allocation_amount,0) allocation_amount
  FROM trades
  WHERE {$where}
  ORDER BY id DESC
";
$res = $mysqli->query($sql);
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Shaikhoology — Trading Journal (PDF)</title>
<style>
  @page { margin: 20mm; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; color:#111; }
  .brand {
    display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;
    border-bottom:2px solid #5a2bd9; padding-bottom:8px;
  }
  .brand h1 { margin:0; font-size:20px; }
  .brand .meta { font-size:12px; color:#555; text-align:right; }
  table { width:100%; border-collapse:collapse; font-size:12px; }
  th, td { border:1px solid #ddd; padding:6px 8px; }
  th { background:#f4f3ff; text-align:left; }
  .green { color:#057a55; font-weight:700; }
  .red { color:#b91c1c; font-weight:700; }
  .footer { margin-top:12px; font-size:11px; color:#555; text-align:center; }
  @media print {
    .noprint { display:none; }
  }
</style>
</head>
<body>
  <div class="brand">
    <h1>Shaikhoology — Trading Psychology</h1>
    <div class="meta">
      Trading Journal Report<br>
      Generated: <?=date('Y-m-d H:i:s')?> · Filter: <?=htmlspecialchars($tab)?><?= $state!=='all' ? (' / '.htmlspecialchars($state)) : '' ?>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th><th>Date</th><th>Symbol</th><th>Entry</th><th>Exit</th><th>Exit Date</th>
        <th>Outcome</th><th>Status</th><th>P/L %</th><th>Allocation</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10">No trades.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>#<?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['entry_date']) ?></td>
          <td><?= htmlspecialchars($r['symbol']) ?></td>
          <td><?= htmlspecialchars($r['entry_price']) ?></td>
          <td><?= htmlspecialchars($r['exit_price']) ?></td>
          <td><?= htmlspecialchars($r['close_date']) ?></td>
          <td><?= htmlspecialchars($r['outcome']) ?></td>
          <td><?= htmlspecialchars($r['status']) ?></td>
          <?php $pl = (float)$r['pl_percent']; ?>
          <td class="<?= $pl>=0 ? 'green':'red' ?>"><?= number_format($pl,2) ?></td>
          <td><?= number_format((float)$r['allocation_amount'],2) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="footer">
    © Shaikhoology — Trading Psychology
  </div>

  <div class="noprint" style="margin-top:10px;text-align:right">
    <button onclick="window.print()" style="padding:8px 12px;border:0;border-radius:8px;background:#5a2bd9;color:#fff">Print / Save as PDF</button>
  </div>
</body>
</html>