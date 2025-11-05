<?php
// dashboard.php — v3.7 (UI-small: Outcome col, Exit Date col, trimmed numbers, no Qty, status Open/Closed only)
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function t($s){ return trim((string)$s); }
function csrf_token(){ return get_csrf_token(); }
function csrf_ok($x){ return validate_csrf((string)$x); }

/** Safe column check (so we don't break if a column doesn't exist) */
function has_column($mysqli,$table,$col){
  $c = 0;
  $st=$mysqli->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->bind_param('ss',$table,$col); $st->execute(); $st->bind_result($c); $st->fetch(); $st->close();
  return ((int)$c>0);
}

/** “Closed” = outcome not OPEN/empty OR exit_price > 0 */
function is_closed($r){
  $out = strtoupper(trim($r['outcome'] ?? ''));
  $exit= (float)($r['exit_price'] ?? 0);
  return ($out !== '' && $out !== 'OPEN') || $exit > 0;
}

/** Trim trailing zeros nicely up to 4 decimals */
function fmt4($n){
  if ($n === null || $n === '') return '';
  $n = (float)$n;
  return rtrim(rtrim(sprintf('%.4f', $n), '0'), '.');
}

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

/* ===== CSV Export (no layout changes) ===== */
if (isset($_GET['export']) && (int)$_GET['export']===1){
  header('Content-Type:text/csv');
  header('Content-Disposition:attachment; filename=trades.csv');
  $out=fopen('php://output','w');
  fputcsv($out,['ID','Date','Symbol','Qty','Entry','Exit','Outcome','PL%','Unlock','Deleted']);
  $qtySel = has_column($mysqli,'trades','qty') ? 'qty' : 'NULL';
  $sql = "SELECT id,entry_date,symbol,{$qtySel} AS qty,entry_price,exit_price,
                 COALESCE(outcome,'') outcome,COALESCE(pl_percent,0) pl_percent,
                 COALESCE(unlock_status,'none') unlock_status,IFNULL(deleted_at,'') deleted_at
          FROM trades WHERE user_id=? ORDER BY id DESC";
  $st=$mysqli->prepare($sql); $st->bind_param('i',$user_id); $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){ fputcsv($out,$row); }
  fclose($out); exit;
}

/* ===== POST: cancel/delete ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_ok($_POST['csrf'] ?? '')){
  $act = $_POST['action'] ?? '';
  $tid = (int)($_POST['trade_id'] ?? 0);

  if ($act==='cancel_request'){
    $mysqli->query("DELETE FROM trade_concerns WHERE trade_id={$tid} AND user_id={$user_id} AND (resolved='' OR resolved IS NULL)");
    $mysqli->query("UPDATE trades SET unlock_status='none',unlock_requested_by=NULL WHERE id={$tid} AND user_id={$user_id} AND unlock_status='pending'");
    $_SESSION['flash']='Unlock request cancelled.';
    header('Location:/dashboard.php'); exit;
  }

  if ($act==='soft_delete'){
    $reason = t($_POST['reason'] ?? 'User deleted');
    $st=$mysqli->prepare("UPDATE trades SET deleted_at=NOW(),deleted_by=?,deleted_by_admin=0,deleted_reason=? WHERE id=? AND user_id=?");
    $st->bind_param('issi',$user_id,$reason,$tid,$user_id); $st->execute(); $st->close();
    $_SESSION['flash']='Trade deleted.';
    header('Location:/dashboard.php'); exit;
  }
}

/* ===== Stats ===== */
$tot_cap=$reserved=$avail=$profit_pct=0; $active_count=0;

$u=$mysqli->prepare("SELECT COALESCE(trading_capital,0),COALESCE(funds_available,0) FROM users WHERE id=?");
$u->bind_param('i',$user_id); $u->execute(); $u->bind_result($tc,$fa); $u->fetch(); $u->close();
$tot_cap=(float)($tc?:$fa);

/* Reserved = sum of allocation_amount for OPEN & not deleted */
$r1=$mysqli->prepare("SELECT COALESCE(SUM(allocation_amount),0) FROM trades WHERE user_id=? AND UPPER(COALESCE(outcome,'OPEN'))='OPEN' AND deleted_at IS NULL");
$r1->bind_param('i',$user_id); $r1->execute(); $r1->bind_result($x); $r1->fetch(); $r1->close();
$reserved=(float)$x;
$avail = max(0,$tot_cap-$reserved);

$r2=$mysqli->prepare("SELECT COALESCE(SUM(pl_percent),0) FROM trades WHERE user_id=? AND UPPER(COALESCE(outcome,''))<>'OPEN' AND deleted_at IS NULL");
$r2->bind_param('i',$user_id); $r2->execute(); $r2->bind_result($p); $r2->fetch(); $r2->close();
$profit_pct=(float)$p;

$r3=$mysqli->prepare("SELECT COUNT(*) FROM trades WHERE user_id=? AND UPPER(COALESCE(outcome,'OPEN'))='OPEN' AND deleted_at IS NULL");
$r3->bind_param('i',$user_id); $r3->execute(); $r3->bind_result($c); $r3->fetch(); $r3->close();
$active_count=(int)$c;

/* ===== Listing filters ===== */
$tab = ($_GET['tab']??'active')==='deleted' ? 'deleted' : 'active';
$state = strtolower($_GET['state'] ?? 'all');

$where = "user_id={$user_id}";
$where .= ($tab==='active') ? " AND deleted_at IS NULL" : " AND deleted_at IS NOT NULL";

if ($tab==='active'){
  if ($state==='open')           $where.=" AND UPPER(COALESCE(outcome,'OPEN'))='OPEN'";
  if ($state==='closed')         $where.=" AND (UPPER(COALESCE(outcome,''))<>'OPEN' OR COALESCE(exit_price,0)>0)";
  if ($state==='unlocked')       $where.=" AND LOWER(unlock_status)='approved' AND (UPPER(COALESCE(outcome,''))<>'OPEN' OR COALESCE(exit_price,0)>0)";
  if ($state==='locked')         $where.=" AND LOWER(unlock_status) IN('none','rejected') AND (UPPER(COALESCE(outcome,''))<>'OPEN' OR COALESCE(exit_price,0)>0)";
  if ($state==='required_unlock')$where.=" AND LOWER(unlock_status) NOT IN('approved','rejected') AND (UPPER(COALESCE(outcome,''))<>'OPEN' OR COALESCE(exit_price,0)>0)";
}

/* dynamic select for exit date (close_date/exit_date if present) */
$closeCol = 'NULL';
if (has_column($mysqli,'trades','close_date')) $closeCol='close_date';
elseif (has_column($mysqli,'trades','exit_date')) $closeCol='exit_date';

$sql = "SELECT id,entry_date,symbol,entry_price,exit_price,
               COALESCE(outcome,'') AS outcome,
               COALESCE(pl_percent,0) AS pl_percent,
               COALESCE(unlock_status,'none') AS unlock_status,
               {$closeCol} AS close_date,
               deleted_at
        FROM trades
        WHERE {$where}
        ORDER BY id DESC";
$q = $mysqli->query($sql);
$rows = $q ? $q->fetch_all(MYSQLI_ASSOC) : [];

$title='Dashboard — Shaikhoology';
include __DIR__ . '/header.php';
?>
<div style="max-width:1200px;margin:22px auto;padding:0 16px">
  <?php if($flash): ?>
    <div style="background:#ecfdf5;border:1px solid #10b98133;padding:10px;border-radius:10px;margin-bottom:12px;color:#065f46;font-weight:700"><?=$flash?></div>
  <?php endif;?>

  <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px">
    <div style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <div style="opacity:.6;font-size:12px">Total Capital</div>
      <div style="font-weight:800;font-size:20px">₹ <?=number_format($tot_cap,2)?></div>
    </div>
    <div style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <div style="opacity:.6;font-size:12px">Reserved</div>
      <div style="font-weight:800;font-size:20px">₹ <?=number_format($reserved,2)?></div>
    </div>
    <div style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <div style="opacity:.6;font-size:12px">Available</div>
      <div style="font-weight:800;font-size:20px">₹ <?=number_format($avail,2)?></div>
    </div>
    <div style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <div style="opacity:.6;font-size:12px">Profit (Closed P/L %)</div>
      <div style="font-weight:800;font-size:20px;<?=($profit_pct>=0?'color:#059669':'color:#b91c1c')?>"><?=number_format($profit_pct,2)?>%</div>
    </div>
    <div style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <div style="opacity:.6;font-size:12px">Active Trades</div>
      <div style="font-weight:800;font-size:20px"><?=$active_count?></div>
    </div>
  </div>

  <div style="display:flex;gap:10px;margin:14px 0">
    <a href="?tab=active"  style="padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;<?=($tab==='active'?'background:#5a2bd9;color:#fff;font-weight:800;border-color:transparent':'')?>">Active</a>
    <a href="?tab=deleted" style="padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;<?=($tab==='deleted'?'background:#5a2bd9;color:#fff;font-weight:800;border-color:transparent':'')?>">Deleted</a>
  </div>

  <div style="overflow:auto;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead>
        <tr style="background:#f8fafc">
          <th>#</th>
          <th>Date</th>
          <th>Symbol</th>
          <th>Entry</th>
          <th>Exit</th>
          <th>Exit Date</th>
          <th>P/L%</th>
          <th>Outcome</th>
          <th>Status</th>
          <th>Unlock</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="11" style="padding:12px">No trades.</td></tr>
      <?php else: foreach($rows as $r):
        $closed = is_closed($r);
        $unlock = strtolower($r['unlock_status'] ?? 'none');
        $plc    = ((float)$r['pl_percent']>=0 ? '#065f46' : '#b91c1c');

        $statusLabel = $closed ? 'Closed' : 'Open';
        $exitPretty  = $closed ? fmt4($r['exit_price']) : '—';
        $entryPretty = fmt4($r['entry_price']);
        $exitDate    = $closed ? h($r['close_date'] ?? '') : '';
      ?>
        <tr style="border-top:1px solid #eef2f7">
          <td style="padding:10px">#<?= (int)$r['id']?></td>
          <td style="padding:10px"><?= h($r['entry_date'])?></td>
          <td style="padding:10px"><a href="/trade_view.php?id=<?= (int)$r['id']?>" style="color:#5a2bd9;font-weight:700;text-decoration:none"><?= h($r['symbol'])?></a></td>
          <td style="padding:10px"><?= $entryPretty ?></td>
          <td style="padding:10px"><?= $exitPretty ?></td>
          <td style="padding:10px"><?= $exitDate ?: '—' ?></td>
          <td style="padding:10px;font-weight:700;color:<?= $plc ?>"><?= number_format((float)$r['pl_percent'],2) ?></td>
          <td style="padding:10px"><?= h($r['outcome'] ?: ($closed?'Closed':'')) ?></td>
          <td style="padding:10px"><?= $statusLabel ?></td>
          <td style="padding:10px">
            <?php
              if (!$closed) {
                echo '—';
              } else {
                if ($unlock==='approved')      echo '<span style="background:#ecfdf5;color:#065f46;border-radius:6px;padding:4px 8px">Unlocked</span>';
                elseif ($unlock==='pending')    echo '<span style="background:#fef3c7;color:#92400e;border-radius:6px;padding:4px 8px">Pending</span>';
                elseif ($unlock==='rejected')   echo '<span style="background:#fee2e2;color:#991b1b;border-radius:6px;padding:4px 8px">Locked</span>';
                else                            echo '<span style="opacity:.6">—</span>';
              }
            ?>
          </td>
          <td style="padding:10px">
            <?php if($r['deleted_at']): ?>
              <span style="opacity:.6">Deleted</span>
            <?php elseif(!$closed): ?>
              <!-- OPEN: Edit & Delete -->
              <a href="/trade_edit.php?id=<?= (int)$r['id']?>" style="background:#16a34a;color:#fff;border-radius:8px;padding:6px 10px;text-decoration:none">Edit</a>
              <form method="post" style="display:inline;margin-left:6px">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="trade_id" value="<?= (int)$r['id']?>">
                <button name="action" value="soft_delete" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:6px 10px">Delete</button>
              </form>
            <?php else: ?>
              <!-- CLOSED -->
              <?php if ($unlock==='approved'): ?>
                <a href="/trade_edit.php?id=<?= (int)$r['id']?>" style="background:#16a34a;color:#fff;border-radius:8px;padding:6px 10px;text-decoration:none">Edit</a>
              <?php elseif ($unlock==='pending'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="trade_id" value="<?= (int)$r['id']?>">
                  <button name="action" value="cancel_request" style="background:#e5e7eb;border:0;border-radius:8px;padding:6px 10px">Cancel</button>
                </form>
              <?php else: ?>
                <a href="/trade_unlock_request.php?id=<?= (int)$r['id']?>" style="background:#5a2bd9;color:#fff;border-radius:8px;padding:6px 10px;text-decoration:none">Request Unlock</a>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>