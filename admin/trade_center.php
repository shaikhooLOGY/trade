// 🔁 API Integration: replaced direct DB with admin APIs
<?php
// admin/trade_center.php — v5.0 (API Integrated) — 24h unlock expiry stamp
require_once __DIR__ . '/../includes/bootstrap.php';
if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Admins only'); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function t($s){ return trim((string)$s); }
function flash($m=null){ if($m!==null){ $_SESSION['flash']=$m; } else { $m=$_SESSION['flash']??''; unset($_SESSION['flash']); return $m; } }

// 🔁 API Integration: helper function to call admin APIs
function callAdminApi($endpoint, $filters = []) {
    $url = $endpoint . '?' . http_build_query(array_filter($filters, function($v) { return $v !== '' && $v !== null; }));
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? '',
                'X-Requested-With: XMLHttpRequest'
            ],
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['error' => 'Failed to connect to API'];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON response'];
    }
    
    return $data;
}

$VERSION='v5.0';
if (!empty($_GET['flush']) && function_exists('opcache_reset')) @opcache_reset();

// 🔁 API Integration: POST actions now handled by /api/admin/trades/manage.php
/* -------------------- POST actions -------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && validate_csrf($_POST['csrf'] ?? '')) {
    $act   = $_POST['action'] ?? '';
    $cid   = (int)($_POST['id'] ?? 0);
    $tid   = (int)($_POST['trade_id'] ?? 0);
    $reason= t($_POST['reason'] ?? '');
    $redir = $_POST['redir'] ?? 'trade_center.php';

    // Prepare data for API call
    $postData = [
        'action' => $act,
        'id' => $cid,
        'trade_id' => $tid,
        'reason' => $reason,
        'csrf' => $_POST['csrf'] ?? ''
    ];

    // Make API call
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? '',
                'X-Requested-With: XMLHttpRequest'
            ],
            'content' => http_build_query($postData),
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents('/api/admin/trades/manage.php', false, $context);
    $result = json_decode($response, true);
    
    if ($result && isset($result['success']) && $result['success']) {
        flash($result['message'] ?? 'Action completed successfully.');
    } else {
        flash('Action failed: ' . ($result['message'] ?? 'Unknown error'));
    }
    
    header("Location: {$redir}"); exit;
}

// 🔁 API Integration: All data now loaded from /api/admin/trades/manage.php
/* -------------------- Tabs & data -------------------- */
$tab = strtolower($_GET['tab'] ?? 'concerns');
if (!in_array($tab,['concerns','user_trades','deleted'],true)) $tab='concerns';
$flash = flash();

// Load users for dropdown (this can be cached or from a separate endpoint)
$users = [];
try {
    $usersApiData = callAdminApi('/api/admin/users/search.php', ['limit' => 1000, 'page' => 1]);
    if (isset($usersApiData['users'])) {
        foreach ($usersApiData['users'] as $user) {
            $users[] = [
                'id' => $user['id'],
                'label' => $user['name'] ?: $user['email']
            ];
        }
    }
} catch (Exception $e) {
    // Fallback to basic user list if API fails
    try {
        $ru = $mysqli->query("SELECT id, COALESCE(name,email) label FROM users ORDER BY name IS NULL, name, email");
        if ($ru) while($x=$ru->fetch_assoc()) $users[]=$x;
    } catch (Exception $fallback) {
        $users = []; // Empty if both fail
    }
}

// Get filter parameters for API
$filters = [
    'tab' => $tab,
    'limit' => min(100, max(1, (int)($_GET['limit'] ?? 20))),
    'offset' => max(0, (int)($_GET['offset'] ?? 0)),
    'status' => t($_GET['status'] ?? ''),
    'user_id' => t($_GET['user_id'] ?? '')
];

// Load data from API
$apiData = callAdminApi('/api/admin/trades/manage.php', $filters);
$apiError = null;

if (isset($apiData['error'])) {
    $apiError = 'Failed to load data: ' . $apiData['error'];
    $data = ['rows' => [], 'meta' => ['total' => 0, 'limit' => $filters['limit'], 'offset' => $filters['offset'], 'count' => 0]];
} else {
    $data = $apiData['data'] ?? ['rows' => [], 'meta' => ['total' => 0, 'limit' => $filters['limit'], 'offset' => $filters['offset'], 'count' => 0]];
}

// Get data based on tab
if ($tab === 'concerns') {
    $concerns = $data['rows'];
    $status = strtolower($_GET['status'] ?? 'pending');
} elseif ($tab === 'user_trades') {
    $trades = $data['rows'];
    $uid = (int)($_GET['user_id'] ?? 0);
    $state = strtolower($_GET['status'] ?? 'all');
} elseif ($tab === 'deleted') {
    $deleted = $data['rows'];
}

// 🔁 API Integration: All trade data loaded from API endpoint above

/* -------------------- UI -------------------- */
$title="Admin • Trade Center {$VERSION}";
include __DIR__ . '/../header.php';
?>
<div style="max-width:1200px;margin:22px auto;padding:0 16px">
  <?php if($flash): ?>
    <div style="background:#ecfdf5;border:1px solid #10b98133;padding:10px;margin-bottom:12px;border-radius:10px;color:#065f46;font-weight:700"><?=h($flash)?></div>
  <?php endif; ?>
  
  <?php if(isset($apiError)): ?>
    <div style="background:#fef2f2;border:1px solid #ef444433;padding:10px;margin-bottom:12px;border-radius:10px;color:#b91c1c;font-weight:700"><?=h($apiError)?></div>
  <?php endif; ?>

  <div style="display:flex;gap:8px;margin-bottom:14px">
    <?php
      $tabs=['concerns'=>'Trade Concerns','user_trades'=>'User-wise Trades','deleted'=>'Deleted Trades'];
    foreach($tabs as $k=>$v){
      $active = ($tab===$k) ? 'background:#5a2bd9;color:#fff;font-weight:800;border-color:transparent' : '';
      echo '<a href="?tab='.$k.'" style="padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;text-decoration:none;'.$active.'">'.$v.'</a>';
    }?>
    <div style="margin-left:auto;opacity:.6;font-size:12px">Build: <?=$VERSION?> · <a href="?tab=<?=$tab?>&flush=1" style="color:#5a2bd9">flush</a></div>
  </div>

  <?php if($tab==='concerns'):
    $v = strtolower($_GET['status'] ?? 'pending');
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $currentPage = ($offset / $limit) + 1;
    $totalItems = $data['meta']['total'] ?? 0;
    $totalPages = ceil($totalItems / $limit);
    ?>
    <div style="display:flex;gap:8px;margin-bottom:12px">
      <?php foreach(['pending','approved','rejected','all'] as $s): ?>
        <a href="?tab=concerns&status=<?=$s?>&limit=<?=$limit?>" style="padding:8px 12px;border:1px solid #e5e7eb;border-radius:999px;text-decoration:none;<?=($v==$s?'background:#5a2bd9;color:#fff;font-weight:800;border-color:transparent':'')?>"><?=ucfirst($s)?></a>
      <?php endforeach;?>
    </div>
    
    <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
      <label style="font-size:12px;color:#64748b">Per page:</label>
      <select name="limit" onchange="window.location.href='?tab=concerns&status=<?=$v?>&limit='+this.value" style="padding:4px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px">
        <?php foreach([20,50,100] as $limitOption): ?>
          <option value="<?=$limitOption?>" <?=$limit==$limitOption?'selected':''?>><?=$limitOption?></option>
        <?php endforeach; ?>
      </select>
    </div>
    
    <?php if($totalPages > 1): ?>
      <!-- 🔁 API Integration: Pagination controls for concerns -->
      <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
        <div style="color:#64748b;font-size:14px">
          Showing <?=($offset + 1)?> to <?=min($offset + $limit, $totalItems)?> of <?=$totalItems?> results
        </div>
        <div style="display:flex;gap:4px">
          <?php
          $queryParams = array_filter([
              'tab' => 'concerns',
              'status' => $v,
              'limit' => $limit
          ], function($v) { return $v !== '' && $v !== null; });
          
          // Previous button
          if ($currentPage > 1) {
              $prevParams = $queryParams;
              $prevParams['offset'] = ($currentPage - 2) * $limit;
              echo '<a href="?' . http_build_query($prevParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">‹ Previous</a>';
          }
          
          // Page numbers
          for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
              $pageParams = $queryParams;
              $pageParams['offset'] = ($i - 1) * $limit;
              $isActive = ($i === $currentPage);
              $style = $isActive
                  ? 'background:#5a2bd9;color:#fff;border-color:transparent'
                  : 'background:#fff;color:#5a2bd9;border-color:#e2e8f0';
              echo '<a href="?' . http_build_query($pageParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;' . $style . '">' . $i . '</a>';
          }
          
          // Next button
          if ($currentPage < $totalPages) {
              $nextParams = $queryParams;
              $nextParams['offset'] = $currentPage * $limit;
              echo '<a href="?' . http_build_query($nextParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">Next ›</a>';
          }
          ?>
        </div>
      </div>
    <?php endif; ?>

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
          $decision = strtolower(t($c['unlock_status'] ?? 'none'));
          $isOpen   = (strtoupper(t($c['outcome']))==='OPEN');
          if     ($decision==='approved') { $status='APPROVED'; $col='#10b981'; }
          elseif ($decision==='rejected') { $status='REJECTED'; $col='#ef4444'; }
          elseif ($decision==='pending')  { $status='PENDING';  $col='#f59e0b'; }
          else                            { $status = $isOpen ? 'OPEN' : '—'; $col='#64748b'; }
        ?>
          <tr style="border-top:1px solid #eef2f7">
            <td style="padding:10px"><?= (int)$c['id']?></td>
            <td style="padding:10px"><?=h($c['name'] ?: $c['email'])?></td>
            <td style="padding:10px">
              <a href="/trade_view.php?id=<?=$c['trade_id']?>" style="text-decoration:none;color:#5a2bd9;font-weight:700"><?=h($c['symbol'])?></a><br>
              <small style="color:#64748b">Entry: <?=h($c['entry_date'])?> • Exit: <?=h($c['exit_price'])?></small>
            </td>
            <td style="padding:10px"><?=nl2br(h($c['reason']))?></td>
            <td style="padding:10px"><?=h($c['created_at'])?></td>
            <td style="padding:10px;font-weight:800;color:<?=$col?>"><?=$status?></td>
            <td style="padding:10px;white-space:nowrap">
              <?php if ($isOpen): ?>
                <span style="opacity:.6">Open trade — no action.</span>
              <?php elseif ($decision==='approved' || $decision==='rejected'): ?>
                <span style="background:#ecfdf5;color:#065f46;padding:6px 10px;border-radius:6px;font-weight:700">✅ Resolved</span>
              <?php else: ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                  <input type="hidden" name="redir" value="trade_center.php?tab=concerns&status=<?=$v?>">
                  <button name="action" value="approve_concern" style="background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Approve</button>
                </form>
                <form method="post" style="display:inline;margin-left:6px">
                  <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                  <input type="hidden" name="redir" value="trade_center.php?tab=concerns&status=<?=$v?>">
                  <button name="action" value="reject_concern" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Reject</button>
                </form>
                <form method="post" style="display:inline;margin-left:6px">
                  <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                  <input type="hidden" name="redir" value="trade_center.php?tab=concerns&status=<?=$v?>">
                  <button name="action" value="resolve_concern" style="background:#f1f5f9;color:#111;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Resolve</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if($tab==='user_trades'):
    $uid = (int)($_GET['user_id'] ?? 0);
    $state = strtolower($_GET['status'] ?? 'all');
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $currentPage = ($offset / $limit) + 1;
    $totalItems = $data['meta']['total'] ?? 0;
    $totalPages = ceil($totalItems / $limit);
    ?>
    <form method="get" style="margin-bottom:12px;display:flex;gap:10px;flex-wrap:wrap">
      <input type="hidden" name="tab" value="user_trades">
      <select name="user_id" style="padding:8px;border:1px solid #e5e7eb;border-radius:8px">
        <option value="0" <?=$uid===0?'selected':''?>>All users (for special filters)</option>
        <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>" <?=$uid==$u['id']?'selected':''?>><?=h($u['label'])?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" style="padding:8px;border:1px solid #e5e7eb;border-radius:8px">
        <?php foreach(['all','open','closed','unlocked','locked','deleted','required_unlock'] as $o): ?>
          <option value="<?=$o?>" <?=$state===$o?'selected':''?>><?=ucwords(str_replace('_',' ',$o))?></option>
        <?php endforeach; ?>
      </select>
      <select name="limit" style="padding:8px;border:1px solid #e5e7eb;border-radius:8px">
        <?php foreach([20,50,100] as $limitOption): ?>
          <option value="<?=$limitOption?>" <?=$limit==$limitOption?'selected':''?>><?=$limitOption?> per page</option>
        <?php endforeach; ?>
      </select>
      <button type="submit" style="background:#5a2bd9;color:#fff;border:0;border-radius:8px;padding:8px 12px;font-weight:700;cursor:pointer">Filter</button>
    </form>
    
    <?php if($totalPages > 1): ?>
      <!-- 🔁 API Integration: Pagination controls -->
      <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
        <div style="color:#64748b;font-size:14px">
          Showing <?=($offset + 1)?> to <?=min($offset + $limit, $totalItems)?> of <?=$totalItems?> results
        </div>
        <div style="display:flex;gap:4px">
          <?php
          $queryParams = array_filter([
              'tab' => 'user_trades',
              'user_id' => $uid,
              'status' => $state,
              'limit' => $limit
          ], function($v) { return $v !== '' && $v !== null; });
          
          // Previous button
          if ($currentPage > 1) {
              $prevParams = $queryParams;
              $prevParams['offset'] = ($currentPage - 2) * $limit;
              echo '<a href="?' . http_build_query($prevParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">‹ Previous</a>';
          }
          
          // Page numbers
          for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
              $pageParams = $queryParams;
              $pageParams['offset'] = ($i - 1) * $limit;
              $isActive = ($i === $currentPage);
              $style = $isActive
                  ? 'background:#5a2bd9;color:#fff;border-color:transparent'
                  : 'background:#fff;color:#5a2bd9;border-color:#e2e8f0';
              echo '<a href="?' . http_build_query($pageParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;' . $style . '">' . $i . '</a>';
          }
          
          // Next button
          if ($currentPage < $totalPages) {
              $nextParams = $queryParams;
              $nextParams['offset'] = $currentPage * $limit;
              echo '<a href="?' . http_build_query($nextParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">Next ›</a>';
          }
          ?>
        </div>
      </div>
    <?php endif; ?>

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
          <tr><td colspan="9" style="padding:12px;color:#666">No trades.</td></tr>
        <?php else: foreach($trades as $t):
          $closed  = (strtoupper(t($t['outcome']))!=='OPEN' && $t['outcome']!=='');
          $deleted = !empty($t['deleted_at']);
          $unlock  = strtolower(t($t['unlock_status'] ?? 'none'));
          if ($deleted) { $badge='🗑 Deleted '.($t['deleted_by_admin']?'by Admin':'by User'); }
          else if ($unlock==='approved' && $closed) { $badge='🟣 Unlocked'; }
          else if ($unlock==='rejected' && $closed) { $badge='🔒 Locked'; }
          else if ($closed) { $badge='⚫ Closed'; }
          else { $badge='🟢 Open'; }

          $unlockText = !$closed ? '—' : ($unlock==='approved'?'Unlocked':($unlock==='pending'?'Pending':($unlock==='rejected'?'Locked':'—')));
          $plDisp = $closed ? number_format((float)$t['pl_percent'],1) : '0.0';
          $plColor = ((float)$t['pl_percent']>=0 ? 'color:#065f46' : 'color:#b91c1c');
        ?>
          <tr style="border-top:1px solid #eef2f7">
            <td style="padding:10px"><?=$t['id']?></td>
            <td style="padding:10px"><a href="/trade_view.php?id=<?=$t['id']?>" style="color:#5a2bd9;font-weight:700;text-decoration:none"><?=h($t['symbol'])?></a></td>
            <td style="padding:10px"><?=h($t['name'] ?: $t['email'])?></td>
            <td style="padding:10px"><?=h($t['entry_date'])?></td>
            <td style="padding:10px"><?=h($t['outcome'])?></td>
            <td style="padding:10px;<?=$plColor?>;font-weight:700"><?=$plDisp?></td>
            <td style="padding:10px;font-weight:800"><?=h($unlockText)?></td>
            <td style="padding:10px"><?=h($badge)?></td>
            <td style="padding:10px;white-space:nowrap">
              <?php if($deleted): ?>
                <?php if ($state==='deleted'): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                    <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                    <input type="hidden" name="redir" value="trade_center.php?tab=user_trades&user_id=<?=$uid?>&status=<?=$state?>">
                    <button name="action" value="restore" style="background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Restore</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <?php if ($closed): ?>
                  <?php if ($unlock!=='approved'): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                      <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                      <input type="hidden" name="redir" value="trade_center.php?tab=user_trades&user_id=<?=$uid?>&status=<?=$state?>">
                      <button name="action" value="force_unlock" style="background:#5a2bd9;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Unlock</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" style="display:inline;margin-left:6px">
                    <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                    <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                    <input type="hidden" name="redir" value="trade_center.php?tab=user_trades&user_id=<?=$uid?>&status=<?=$state?>">
                    <button name="action" value="force_lock" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Lock</button>
                  </form>
                <?php endif; ?>

                <form method="post" style="display:inline;margin-left:6px">
                  <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                  <input type="hidden" name="trade_id" value="<?=$t['id']?>">
                  <input type="hidden" name="redir" value="trade_center.php?tab=user_trades&user_id=<?=$uid?>&status=<?=$state?>">
                  <input type="text" name="reason" placeholder="Reason" style="padding:6px;border:1px solid #e5e7eb;border-radius:8px;max-width:160px">
                  <button name="action" value="soft_delete" style="background:#f59e0b;color:#111;border:0;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer;margin-left:6px">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if($tab==='deleted'):
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $currentPage = ($offset / $limit) + 1;
    $totalItems = $data['meta']['total'] ?? 0;
    $totalPages = ceil($totalItems / $limit);
    ?>
    
    <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
      <label style="font-size:12px;color:#64748b">Per page:</label>
      <select name="limit" onchange="window.location.href='?tab=deleted&limit='+this.value" style="padding:4px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px">
        <?php foreach([20,50,100] as $limitOption): ?>
          <option value="<?=$limitOption?>" <?=$limit==$limitOption?'selected':''?>><?=$limitOption?></option>
        <?php endforeach; ?>
      </select>
    </div>
    
    <?php if($totalPages > 1): ?>
      <!-- 🔁 API Integration: Pagination controls for deleted trades -->
      <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
        <div style="color:#64748b;font-size:14px">
          Showing <?=($offset + 1)?> to <?=min($offset + $limit, $totalItems)?> of <?=$totalItems?> results
        </div>
        <div style="display:flex;gap:4px">
          <?php
          $queryParams = array_filter([
              'tab' => 'deleted',
              'limit' => $limit
          ], function($v) { return $v !== '' && $v !== null; });
          
          // Previous button
          if ($currentPage > 1) {
              $prevParams = $queryParams;
              $prevParams['offset'] = ($currentPage - 2) * $limit;
              echo '<a href="?' . http_build_query($prevParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">‹ Previous</a>';
          }
          
          // Page numbers
          for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
              $pageParams = $queryParams;
              $pageParams['offset'] = ($i - 1) * $limit;
              $isActive = ($i === $currentPage);
              $style = $isActive
                  ? 'background:#5a2bd9;color:#fff;border-color:transparent'
                  : 'background:#fff;color:#5a2bd9;border-color:#e2e8f0';
              echo '<a href="?' . http_build_query($pageParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;' . $style . '">' . $i . '</a>';
          }
          
          // Next button
          if ($currentPage < $totalPages) {
              $nextParams = $queryParams;
              $nextParams['offset'] = $currentPage * $limit;
              echo '<a href="?' . http_build_query($nextParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">Next ›</a>';
          }
          ?>
        </div>
      </div>
    <?php endif; ?>
    
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
            <td style="padding:10px"><?=$d['id']?></td>
            <td style="padding:10px"><?=h($d['user_name'])?></td>
            <td style="padding:10px"><a href="/trade_view.php?id=<?=$d['id']?>" style="text-decoration:none;color:#5a2bd9;font-weight:700"><?=h($d['symbol'])?></a></td>
            <td style="padding:10px"><?=h($d['entry_date'])?></td>
            <td style="padding:10px"><?=h($d['deleted_at'])?></td>
            <td style="padding:10px"><?= $d['deleted_by_admin']?'Admin':'User'?></td>
            <td style="padding:10px"><?=h($d['deleted_reason'] ?? '')?></td>
            <td style="padding:10px">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?=h(get_csrf_token())?>">
                <input type="hidden" name="trade_id" value="<?=$d['id']?>">
                <input type="hidden" name="redir" value="trade_center.php?tab=deleted">
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