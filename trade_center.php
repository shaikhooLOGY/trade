<?php
// admin/trade_center.php — Trade Center v4.1 (filters fixed + diag + opcache flush)
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Admins only.'); }

/* ---------- quick utilities ---------- */
$VERSION = 'v4.1';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(){ return get_csrf_token(); }
function csrf_verify($t){ return validate_csrf((string)$t); }
function flash_set($m){ $_SESSION['flash']=(string)$m; }
function flash_pop(){ $m=$_SESSION['flash']??''; if($m!=='') unset($_SESSION['flash']); return $m; }
$admin_id = (int)($_SESSION['user_id'] ?? 0);

/* ---------- manual opcache flush (Hostinger caches PHP) ---------- */
if (!empty($_GET['flush']) && function_exists('opcache_reset')) { @opcache_reset(); }

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf'] ?? '')) {
  $act = $_POST['action'] ?? '';
  $cid = (int)($_POST['id'] ?? 0);        // concern id
  $tid = (int)($_POST['trade_id'] ?? 0);  // trade id
  $reason = trim($_POST['reason'] ?? '');

  switch ($act) {
    case 'approve_concern':
      if ($cid>0) {
        $mysqli->query("UPDATE trade_concerns SET resolved='yes', resolved_at=NOW(), resolved_by={$admin_id} WHERE id={$cid}");
        $mysqli->query("UPDATE trades t JOIN trade_concerns c ON t.id=c.trade_id SET t.unlock_status='approved' WHERE c.id={$cid}");
        flash_set('Concern approved → trade unlocked.');
      }
      break;

    case 'reject_concern':
      if ($cid>0) {
        $mysqli->query("UPDATE trade_concerns SET resolved='yes', resolved_at=NOW(), resolved_by={$admin_id} WHERE id={$cid}");
        $mysqli->query("UPDATE trades t JOIN trade_concerns c ON t.id=c.trade_id SET t.unlock_status='rejected' WHERE c.id={$cid}");
        flash_set('Concern rejected → trade locked.');
      }
      break;

    case 'resolve_concern':
      if ($cid>0) {
        $mysqli->query("UPDATE trade_concerns SET resolved='yes', resolved_at=NOW(), resolved_by={$admin_id} WHERE id={$cid}");
        flash_set('Concern marked resolved.');
      }
      break;

    case 'force_unlock':
      if ($tid>0) { $mysqli->query("UPDATE trades SET unlock_status='approved' WHERE id={$tid}"); flash_set('Trade unlocked.'); }
      break;

    case 'force_lock':
      if ($tid>0) { $mysqli->query("UPDATE trades SET unlock_status='rejected' WHERE id={$tid}"); flash_set('Trade locked.'); }
      break;

    case 'soft_delete':
      if ($tid>0) {
        $stmt=$mysqli->prepare("UPDATE trades SET deleted_at=NOW(), deleted_by=?, deleted_by_admin=1, deleted_reason=? WHERE id=?");
        $stmt->bind_param('isi',$admin_id,$reason,$tid); $stmt->execute(); $stmt->close();
        flash_set('Trade soft-deleted.');
      }
      break;

    case 'restore':
      if ($tid>0) {
        $mysqli->query("UPDATE trades SET deleted_at=NULL, deleted_by=NULL, deleted_by_admin=0, deleted_reason=NULL WHERE id={$tid}");
        flash_set('Trade restored.');
      }
      break;
  }

  $tab = urlencode($_POST['tab'] ?? 'concerns');
  $qs  = $_POST['qs'] ?? '';
  header("Location: trade_center.php?tab={$tab}{$qs}");
  exit;
}

/* ---------- tabs ---------- */
$tab = strtolower($_GET['tab'] ?? 'concerns');
if (!in_array($tab,['concerns','user_trades','deleted'],true)) $tab='concerns';
$flash = flash_pop();

/* =========================================================
   CONCERNS TAB – definitive filters
   Approved   => trades.unlock_status = 'approved'
   Rejected   => trades.unlock_status = 'rejected'
   Pending    => NOT resolved AND unlock_status NOT in (approved,rejected)
   All        => no extra filter
   ========================================================= */
if ($tab==='concerns') {
  $view = strtolower($_GET['status'] ?? 'pending'); // pending|approved|rejected|all
  if (!in_array($view,['pending','approved','rejected','all'],true)) $view='pending';

  if ($view==='approved') {
    $where = "LOWER(t.unlock_status)='approved'";
  } elseif ($view==='rejected') {
    $where = "LOWER(t.unlock_status)='rejected'";
  } elseif ($view==='pending') {
    $where = "(COALESCE(LOWER(c.resolved),'') NOT IN ('yes','resolved','1','done') OR c.resolved IS NULL)
              AND COALESCE(LOWER(t.unlock_status),'none') NOT IN ('approved','rejected')";
  } else {
    $where = "1";
  }

  $sql = "
    SELECT c.id, c.trade_id, c.user_id,
           COALESCE(c.reason,'') AS reason,
           c.created_at,
           COALESCE(c.resolved,'') AS resolved,
           u.name, u.email,
           t.symbol, t.entry_date, t.exit_price,
           COALESCE(t.outcome,'') AS outcome,
           COALESCE(t.unlock_status,'none') AS unlock_status
    FROM trade_concerns c
    LEFT JOIN users u ON u.id=c.user_id
    LEFT JOIN trades t ON t.id=c.trade_id
    WHERE {$where}
    ORDER BY c.created_at DESC";
  $res = $mysqli->query($sql);
  $concerns = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/* =========================================================
   USER-WISE TRADES TAB
   ========================================================= */
if ($tab==='user_trades') {
  $users=[]; $resU=$mysqli->query("SELECT id, COALESCE(name,email) AS label FROM users ORDER BY name IS NULL, name, email LIMIT 1000");
  if ($resU) while($x=$resU->fetch_assoc()) $users[]=$x;

  $sel=(int)($_GET['user_id'] ?? 0);
  $status=strtolower($_GET['status'] ?? 'all');
  if (!in_array($status,['all','open','closed','unlocked','locked','deleted'],true)) $status='all';

  $where = $sel ? "t.user_id=$sel" : "1=0";
  if ($status==='open')      $where.=" AND COALESCE(t.outcome,'OPEN')='OPEN' AND t.deleted_at IS NULL";
  if ($status==='closed')    $where.=" AND COALESCE(t.outcome,'')<>'OPEN' AND t.deleted_at IS NULL";
  if ($status==='unlocked')  $where.=" AND LOWER(t.unlock_status)='approved' AND t.deleted_at IS NULL";
  if ($status==='locked')    $where.=" AND LOWER(COALESCE(t.unlock_status,'none')) IN ('none','rejected') AND t.deleted_at IS NULL";
  if ($status==='deleted')   $where.=" AND t.deleted_at IS NOT NULL";

  $sqlU="
    SELECT t.id,t.user_id,COALESCE(t.symbol,'') AS symbol,t.entry_date,t.exit_price,
           COALESCE(t.outcome,'') AS outcome, COALESCE(t.pl_percent,0) AS pl_percent,
           COALESCE(t.unlock_status,'none') AS unlock_status,
           t.deleted_at, t.deleted_by_admin,
           u.name, u.email
    FROM trades t
    LEFT JOIN users u ON u.id=t.user_id
    WHERE $where
    ORDER BY t.id DESC
    LIMIT 500";
  $res=$mysqli->query($sqlU);
  $trades=$res?$res->fetch_all(MYSQLI_ASSOC):[];
}

/* =========================================================
   DELETED TAB
   ========================================================= */
if ($tab==='deleted') {
  $sqlD="
    SELECT t.id, t.user_id, COALESCE(u.name,u.email) AS user_name,
           COALESCE(t.symbol,'') AS symbol, t.entry_date, t.deleted_at,
           t.deleted_by_admin, t.deleted_reason
    FROM trades t
    LEFT JOIN users u ON u.id=t.user_id
    WHERE t.deleted_at IS NOT NULL
    ORDER BY t.deleted_at DESC";
  $res=$mysqli->query($sqlD);
  $deleted=$res?$res->fetch_all(MYSQLI_ASSOC):[];
}

/* =========================================================
   RENDER
   ========================================================= */
$title="Admin • Trade Center {$VERSION}";
include __DIR__ . '/../header.php';
?>
<div style="max-width:1200px;margin:22px auto;padding:0 16px">
  <h2>🔧 Trade Center</h2>
  <?php if($flash):?>
    <div style="background:#ecfdf5;border:1px solid #10b98133;padding:10px;margin:10px 0;border-radius:8px;font-weight:600;color:#065f46">
      <?=h($flash)?>
    </div>
  <?php endif;?>

  <div style="display:flex;gap:8px;margin-bottom:14px">
    <?php foreach(['concerns'=>'Trade Concerns','user_trades'=>'User-wise Trades','deleted'=>'Deleted Trades'] as $k=>$v): ?>
      <a href="?tab=<?=$k?>" style="padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;text-decoration:none;<?=($tab==$k?'background:#5a2bd9;color:#fff;font-weight:800;border-color:transparent':'')?>"><?=$v?></a>
    <?php endforeach; ?>
    <div style="margin-left:auto;opacity:.6;font-size:12px">Build: <?=$VERSION?> · <a href="?tab=<?=$tab?>&flush=1" style="color:#5a2bd9">flush</a> · <a href="?tab=<?=$tab?>&debug=1" style="color:#5a2bd9">debug</a></div>
  </div>

  <?php if($tab==='concerns'):
    $view=strtolower($_GET['status']??'pending'); ?>
    <div style="display:flex;gap:8px;margin-bottom:12px">
      <?php foreach(['pending','approved','rejected','all'] as $s): ?>
        <a href="?tab=concerns&status=<?=$s?>" style="padding:8px 12px;border:1px solid #e5e7eb;border-radius:999px;text-decoration:none;<?=($view==$s?'background:#5a2bd9;color:#fff;font-weight:800;border-color:transparent':'')?>"><?=ucfirst($s)?></a>
      <?php endforeach;?>
    </div>

    <div style="overflow:auto;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead><tr style="background:#f8fafc">
          <th style="padding:10px;text-align:left">#</th>
          <th style="padding:10px;text-align:left">User</th>
          <th style="padding:10px;text-align:left">Trade</th>
          <th style="padding:10px;text-align:left">Reason</th>
          <th style="padding:10px;text-align:left">Raised</th>
          <th style="padding:10px;text-align:left">Status</th>
          <th style="padding:10px;text-align:left">Actions</th>
        </tr></thead>
        <tbody>
          <?php if(empty($concerns)): ?>
            <tr><td colspan="7" style="padding:12px;color:#666">No matching concerns.</td></tr>
          <?php else: foreach($concerns as $c):
            $resolvedFlag = strtolower(trim($c['resolved'] ?? ''));
            $isResolved = in_array($resolvedFlag, ['yes','resolved','1','done'], true);
            $decision = strtolower($c['unlock_status'] ?? 'none');   // approved|rejected|none
            $tradeOpen = (strtoupper($c['outcome'] ?? '') === 'OPEN');

            if     ($decision==='approved') { $statusLabel='APPROVED'; $statusColor='#10b981'; }
            elseif ($decision==='rejected') { $statusLabel='REJECTED'; $statusColor='#ef4444'; }
            elseif ($isResolved)            { $statusLabel='RESOLVED'; $statusColor='#10b981'; }
            elseif ($tradeOpen)             { $statusLabel='OPEN';     $statusColor='#64748b'; }
            else                            { $statusLabel='PENDING';  $statusColor='#f59e0b'; }
          ?>
          <tr style="border-top:1px solid #eef2f7">
            <td style="padding:10px"><?= (int)$c['id']?></td>
            <td style="padding:10px"><?= h($c['name']?:$c['email'])?></td>
            <td style="padding:10px">
              <a href="/trade_view.php?id=<?=$c['trade_id']?>" style="text-decoration:none;color:#5a2bd9;font-weight:700"><?= h($c['symbol'])?></a><br>
              <small style="color:#64748b">Entry: <?= h($c['entry_date'])?> • Exit: <?= h($c['exit_price'])?></small>
            </td>
            <td style="padding:10px"><?= nl2br(h($c['reason']))?></td>
            <td style="padding:10px"><?= h($c['created_at'])?></td>
            <td style="padding:10px;font-weight:800;color:<?=$statusColor?>"><?= $statusLabel ?></td>
            <td style="padding:10px;white-space:nowrap">
              <?php if ($decision==='approved' || $decision==='rejected' || $isResolved): ?>
                <span style="background:#ecfdf5;color:#065f46;padding:6px 10px;border-radius:6px;font-weight:700">✅ Resolved</span>
              <?php elseif ($tradeOpen): ?>
                <span style="color:#666">Open trade — no action.</span>
              <?php else: ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                  <input type="hidden" name="tab" value="concerns">
                  <input type="hidden" name="qs" value="<?= h('&status='.$view) ?>">
                  <button name="action" value="approve_concern" style="background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Approve</button>
                </form>
                <form method="post" style="display:inline;margin-left:6px">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                  <input type="hidden" name="tab" value="concerns">
                  <input type="hidden" name="qs" value="<?= h('&status='.$view) ?>">
                  <button name="action" value="reject_concern" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Reject</button>
                </form>
                <form method="post" style="display:inline;margin-left:6px">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                  <input type="hidden" name="tab" value="concerns">
                  <input type="hidden" name="qs" value="<?= h('&status='.$view) ?>">
                  <button name="action" value="resolve_concern" style="background:#f1f5f9;color:#111;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Resolve</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($_GET['debug'])): ?>
      <div style="margin-top:14px;padding:10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px">
        <div style="font-weight:800;margin-bottom:6px">Debug (first 5 concerns)</div>
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead><tr><th style="text-align:left">id</th><th>resolved</th><th>unlock_status</th><th>outcome</th></tr></thead>
          <tbody>
          <?php foreach(array_slice($concerns,0,5) as $d): ?>
            <tr style="border-top:1px solid #eee"><td><?= (int)$d['id']?></td><td><?= h($d['resolved'])?></td><td><?= h($d['unlock_status'])?></td><td><?= h($d['outcome'])?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <?php if($tab==='user_trades'):
    $sel = (int)($_GET['user_id'] ?? 0);
    $status = strtolower($_GET['status'] ?? 'all');
    $users = $users ?? [];
  ?>
    <form method="get" style="margin-bottom:12px;display:flex;gap:10px;flex-wrap:wrap">
      <input type="hidden" name="tab" value="user_trades">
      <select name="user_id" style="padding:8px;border:1px solid #e5e7eb;border-radius:8px">
        <option value="0">Select user…</option>
        <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>" <?=$sel==$u['id']?'selected':''?>><?= h($u['label'])?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" style="padding:8px;border:1px solid #e5e7eb;border-radius:8px">
        <?php foreach(['all','open','closed','unlocked','locked','deleted'] as $o): ?>
          <option value="<?=$o?>" <?=$status===$o?'selected':''?>><?= ucfirst($o)?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" style="background:#5a2bd9;color:#fff;border:0;border-radius:8px;padding:8px 12px;font-weight:700;cursor:pointer">Filter</button>
    </form>

    <div style="overflow:auto;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead><tr style="background:#f8fafc">
          <th style="padding:10px;text-align:left;">ID</th>
          <th style="padding:10px;text-align:left;">Symbol</th>
          <th style="padding:10px;text-align:left;">User</th>
          <th style="padding:10px;text-align:left;">Entry</th>
          <th style="padding:10px;text-align:left;">Outcome</th>
          <th style="padding:10px;text-align:left;">P/L%</th>
          <th style="padding:10px;text-align:left;">Unlock</th>
          <th style="padding:10px;text-align:left;">State</th>
          <th style="padding:10px;text-align:left;">Actions</th>
        </tr></thead>
        <tbody>
          <?php if(empty($trades)): ?>
            <tr><td colspan="9" style="padding:12px;color:#666">Pick a user and filter.</td></tr>
          <?php else: foreach($trades as $t):
            $closed = (strtoupper($t['outcome'])!=='OPEN');
            $deleted = !empty($t['deleted_at']);
            $badge = $deleted
              ? '🗑 Deleted'.($t['deleted_by_admin']?' (admin)':'')
              : ( $t['unlock_status']==='approved' ? '🟣 Unlocked'
                : ( $t['unlock_status']==='rejected' ? '🔒 Locked'
                  : ($closed ? '⚫ Closed' : '🟢 Open')));
          ?>
          <tr style="border-top:1px solid #eef2f7">
            <td style="padding:10px"><?= (int)$t['id']?></td>
            <td style="padding:10px"><a href="/trade_view.php?id=<?=$t['id']?>" style="color:#5a2bd9;font-weight:700;text-decoration:none"><?= h($t['symbol'])?></a></td>
            <td style="padding:10px"><?= h($t['name'] ?: $t['email'])?></td>
            <td style="padding:10px"><?= h($t['entry_date'])?></td>
            <td style="padding:10px"><?= h($t['outcome'])?></td>
            <td style="padding:10px;<?= ((float)$t['pl_percent']>=0?'color:#065f46':'color:#b91c1c')?>;font-weight:700"><?= number_format((float)$t['pl_percent'],1)?></td>
            <td style="padding:10px;font-weight:800"><?= strtoupper($t['unlock_status'])?></td>
            <td style="padding:10px"><?= h($badge)?></td>
            <td style="padding:10px;white-space:nowrap">
              <?php if($closed && !$deleted): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                  <input type="hidden" name="tab" value="user_trades">
                  <input type="hidden" name="qs" value="<?= h('&user_id='.$sel.'&status='.$status)?>">
                  <button name="action" value="force_unlock" style="background:#5a2bd9;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Unlock</button>
                </form>
                <form method="post" style="display:inline;margin-left:6px">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                  <input type="hidden" name="tab" value="user_trades">
                  <input type="hidden" name="qs" value="<?= h('&user_id='.$sel.'&status='.$status)?>">
                  <button name="action" value="force_lock" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Lock</button>
                </form>
                <form method="post" style="display:inline;margin-left:6px">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                  <input type="hidden" name="tab" value="user_trades">
                  <input type="hidden" name="qs" value="<?= h('&user_id='.$sel.'&status='.$status)?>">
                  <input type="text" name="reason" placeholder="Reason" style="padding:6px;border:1px solid #e5e7eb;border-radius:8px;max-width:160px">
                  <button name="action" value="soft_delete" style="background:#f59e0b;color:#111;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer;margin-left:6px">Delete</button>
                </form>
              <?php elseif($deleted): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                  <input type="hidden" name="tab" value="user_trades">
                  <input type="hidden" name="qs" value="<?= h('&user_id='.$sel.'&status='.$status)?>">
                  <button name="action" value="restore" style="background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Restore</button>
                </form>
              <?php else: ?>
                <span style="color:#666">No actions for open trades.</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if($tab==='deleted'): ?>
    <div style="overflow:auto;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead><tr style="background:#f8fafc">
          <th style="padding:10px;text-align:left;">ID</th>
          <th style="padding:10px;text-align:left;">User</th>
          <th style="padding:10px;text-align:left;">Symbol</th>
          <th style="padding:10px;text-align:left;">Entry</th>
          <th style="padding:10px;text-align:left;">Deleted At</th>
          <th style="padding:10px;text-align:left;">By</th>
          <th style="padding:10px;text-align:left;">Reason</th>
          <th style="padding:10px;text-align:left;">Action</th>
        </tr></thead>
        <tbody>
          <?php if(empty($deleted)): ?>
            <tr><td colspan="8" style="padding:12px;color:#666">No deleted trades.</td></tr>
          <?php else: foreach($deleted as $d): ?>
            <tr style="border-top:1px solid #eef2f7">
              <td style="padding:10px"><?= (int)$d['id']?></td>
              <td style="padding:10px"><?= h($d['user_name'])?></td>
              <td style="padding:10px"><a href="/trade_view.php?id=<?=$d['id']?>" style="text-decoration:none;color:#5a2bd9;font-weight:700"><?= h($d['symbol'])?></a></td>
              <td style="padding:10px"><?= h($d['entry_date'])?></td>
              <td style="padding:10px"><?= h($d['deleted_at'])?></td>
              <td style="padding:10px"><?= $d['deleted_by_admin']?'Admin':'User'?></td>
              <td style="padding:10px"><?= h($d['deleted_reason'] ?? '')?></td>
              <td style="padding:10px">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token())?>">
                  <input type="hidden" name="trade_id" value="<?=$d['id']?>">
                  <input type="hidden" name="tab" value="deleted">
                  <button name="action" value="restore" style="background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Restore</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div style="opacity:.5;font-size:12px;margin-top:10px;text-align:right">Trade Center <?=$VERSION?></div>
</div>
<?php include __DIR__ . '/../footer.php'; ?>