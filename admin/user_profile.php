<?php
// admin/user_profile.php — Review screen with Ask-to-Update, vertical timeline & scoped actions
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403'); exit('Access denied'); }

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$uid = (int)($_GET['id'] ?? 0);
if (!$uid) { header('Location: users.php'); exit; }

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['admin_csrf'];

/* Load user */
$sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
$st = $mysqli->prepare($sql);
$st->bind_param('i', $uid);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();

/* Load user profile data if available */
$profile_data = null;
try {
    $sql_profile = "SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1";
    $st_profile = $mysqli->prepare($sql_profile);
    $st_profile->bind_param('i', $uid);
    $st_profile->execute();
    $profile_data = $st_profile->get_result()->fetch_assoc();
    $st_profile->close();
} catch (Exception $e) {
    // user_profiles table might not exist
    app_log("Error loading profile data: " . $e->getMessage());
}

if (!$user) { $_SESSION['flash'] = 'User not found.'; header('Location: users.php'); exit; }

/* current admin role for guard logic */
$me = (int)($_SESSION['user_id'] ?? 0);
$meRole = 'user';
if ($st = $mysqli->prepare("SELECT role,is_admin FROM users WHERE id=?")) {
  $st->bind_param('i',$me); $st->execute();
  if ($r = $st->get_result()->fetch_assoc()) $meRole = $r['role'] ?: (($r['is_admin']??0)?'admin':'user');
  $st->close();
}

/* Profile fields config */
$profile_fields = include __DIR__ . '/../profile_fields.php';

/* Handle actions */
$actionTaken = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $act = $_POST['action'] ?? '';
    if ($act === 'approve') {
        // Enhanced approval logic for new registration workflow
        $current_status = strtolower($user['status'] ?? 'pending');
        
        if (in_array($current_status, ['active','approved'], true)) {
            $actionTaken = 'User already approved';
        } elseif ($current_status === 'admin_review') {
            // Approve user from admin_review status
            $st = $mysqli->prepare("UPDATE users SET status='approved' WHERE id=?");
            $st->bind_param('i', $uid);
            $st->execute(); $st->close();
            
            // Update profile status if user_profiles table exists
            if ($profile_data) {
                $st2 = $mysqli->prepare("UPDATE user_profiles SET admin_review_status='approved', admin_review_date=NOW(), admin_reviewed_by=? WHERE user_id=?");
                $st2->bind_param('ii', $_SESSION['user_id'], $uid);
                $st2->execute(); $st2->close();
            }
            
            // Send approval email notification
            send_approval_email($user['email'], $user['name'] ?: 'Member', true);
            
            $actionTaken = 'User approved and email sent';
            $user['status'] = 'approved';
        } elseif (in_array($current_status, ['pending', 'profile_pending'], true)) {
            // Direct approval for early stages
            $st = $mysqli->prepare("UPDATE users SET status='active' WHERE id=?");
            $st->bind_param('i', $uid);
            $st->execute(); $st->close();
            
            $actionTaken = 'User approved';
            $user['status'] = 'active';
        } else {
            $actionTaken = 'Cannot approve user with status: ' . $current_status;
        }
    } elseif ($act === 'reject') {
        // New reject action for admin_review
        $reason = trim((string)($_POST['reason'] ?? ''));
        $st = $mysqli->prepare("UPDATE users SET status='rejected', rejection_reason=? WHERE id=?");
        $st->bind_param('si', $reason, $uid);
        $st->execute(); $st->close();
        
        // Update profile status if user_profiles table exists
        if ($profile_data) {
            $st2 = $mysqli->prepare("UPDATE user_profiles SET admin_review_status='rejected', admin_review_date=NOW(), admin_reviewed_by=?, admin_review_comments=? WHERE user_id=?");
            $st2->bind_param('isi', $_SESSION['user_id'], $reason, $uid);
            $st2->execute(); $st2->close();
        }
        
        // Send rejection email notification
        send_approval_email($user['email'], $user['name'] ?: 'Member', false, $reason);
        
        $actionTaken = 'User rejected and email sent';
        $user['status'] = 'rejected';
    } elseif ($act === 'send_back') {
        // Ask user to update (needs_update) + optional per-field remarks
        $reason = trim((string)($_POST['reason'] ?? ''));
        $status_json   = $_POST['field_status_json']   ?? '';
        $comments_json = $_POST['field_comments_json'] ?? '';

        if ($status_json || $comments_json || $reason !== '') {
            // Explicit variants for clean binding
            if ($status_json && $comments_json && $reason !== '') {
                $st = $mysqli->prepare("UPDATE users SET status='needs_update', profile_field_status=?, profile_comments=?, rejection_reason=? WHERE id=?");
                $st->bind_param('sssi', $status_json, $comments_json, $reason, $uid);
            } elseif ($status_json && $comments_json) {
                $st = $mysqli->prepare("UPDATE users SET status='needs_update', profile_field_status=?, profile_comments=? WHERE id=?");
                $st->bind_param('ssi', $status_json, $comments_json, $uid);
            } elseif ($status_json && $reason !== '') {
                $st = $mysqli->prepare("UPDATE users SET status='needs_update', profile_field_status=?, rejection_reason=? WHERE id=?");
                $st->bind_param('ssi', $status_json, $reason, $uid);
            } elseif ($comments_json && $reason !== '') {
                $st = $mysqli->prepare("UPDATE users SET status='needs_update', profile_comments=?, rejection_reason=? WHERE id=?");
                $st->bind_param('ssi', $comments_json, $reason, $uid);
            } elseif ($status_json) {
                $st = $mysqli->prepare("UPDATE users SET status='needs_update', profile_field_status=? WHERE id=?");
                $st->bind_param('si', $status_json, $uid);
            } elseif ($comments_json) {
                $st = $mysqli->prepare("UPDATE users SET status='needs_update', profile_comments=? WHERE id=?");
                $st->bind_param('si', $comments_json, $uid);
            } else { // only reason
                $st = $mysqli->prepare("UPDATE users SET status='needs_update', rejection_reason=? WHERE id=?");
                $st->bind_param('si', $reason, $uid);
            }
        } else {
            $st = $mysqli->prepare("UPDATE users SET status='needs_update' WHERE id=?");
            $st->bind_param('i', $uid);
        }
        $st->execute(); $st->close();
        $actionTaken = 'Asked user to update profile correctly';
        $user['status'] = 'needs_update';
    } elseif ($act === 'promote' && $meRole === 'superadmin') {
        if (($user['role'] ?: (($user['is_admin']??0)?'admin':'user')) === 'user') {
            $st = $mysqli->prepare("UPDATE users SET is_admin=1, role='admin' WHERE id=?");
            $st->bind_param('i', $uid);
            $st->execute(); $st->close();
            $actionTaken = 'Promoted to Admin';
            $user['is_admin']=1; $user['role']='admin';
        }
    } elseif ($act === 'demote' && $meRole === 'superadmin') {
        if (($user['role'] ?: (($user['is_admin']??0)?'admin':'user')) === 'admin') {
            $st = $mysqli->prepare("UPDATE users SET is_admin=0, role='user' WHERE id=?");
            $st->bind_param('i', $uid);
            $st->execute(); $st->close();
            $actionTaken = 'Demoted to User';
            $user['is_admin']=0; $user['role']='user';
        }
    } elseif ($act === 'deactivate') {
        $st = $mysqli->prepare("UPDATE users SET status='rejected' WHERE id=?");
        $st->bind_param('i', $uid);
        $st->execute(); $st->close();
        $actionTaken = 'Deactivated';
        $user['status']='rejected';
    } elseif ($act === 'delete') {
        $targetRole = $user['role'] ?: (($user['is_admin']??0)?'admin':'user');
        if ($targetRole === 'superadmin') {
            $actionTaken = 'Not allowed (superadmin)';
        } elseif ($targetRole === 'admin' && $meRole !== 'superadmin') {
            $actionTaken = 'Only superadmin can delete an admin';
        } else {
            $st = $mysqli->prepare("DELETE FROM users WHERE id=?");
            $st->bind_param('i',$uid);
            $st->execute(); $st->close();
            $_SESSION['flash'] = 'User deleted.';
            header('Location: users.php'); exit;
        }
    }
}

/* Decode per-field statuses/comments for UI */
$field_status   = !empty($user['profile_field_status']) ? (json_decode($user['profile_field_status'], true) ?: []) : [];
$field_comments = !empty($user['profile_comments']) ? (json_decode($user['profile_comments'], true) ?: []) : [];

/* Helpers for UI */
$role = strtolower($user['role'] ?: (($user['is_admin']??0)?'admin':'user'));
$roleBadge = ($role==='superadmin' ? 'Superadmin' : ($role==='admin' ? 'Admin' : 'User'));
$status = strtolower($user['status'] ?? 'pending');
$canApprove = in_array($status, ['pending','needs_update','unverified'], true);
$showAskToUpdate = !in_array($role, ['admin','superadmin'], true); // hide for admin/superadmin profiles

include __DIR__ . '/../header.php';
?>
<style>
  body{background:#0b0b0f;color:#e5e7eb}
  .container{max-width:1100px;margin:18px auto;padding:0 16px}
  .panel{background:#111317;border:1px solid #23262d;border-radius:14px;padding:14px;margin-bottom:16px}
  .top-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
  .who{font-size:22px;font-weight:800}
  .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:800;font-size:12px;margin-left:8px}
  .b-active{background:#052e2b;color:#34d399}
  .b-pending{background:#312e1f;color:#fde68a}
  .b-update{background:#3a2611;color:#fbbf24}
  .b-unverified{background:#312e1f;color:#fde68a}
  .b-rejected{background:#3a1212;color:#fca5a5}
  .role-admin{background:#1e3a8a;color:#93c5fd}
  .role-super{background:#7c2d12;color:#fcd34d}
  .actions .btn{border:0;border-radius:10px;padding:9px 12px;font-weight:800;cursor:pointer;margin:2px}
  .btn-approve{background:#2563eb;color:#fff}
  .btn-approve.orange{background:#b45309}
  .btn-approve.yellow{background:#a16207}
  .btn-promote{background:#0f766e;color:#c7f9cc}
  .btn-demote{background:#7c2d12;color:#ffd7a1}
  .btn-deact{background:#6b7280;color:#fff}
  .btn-delete{background:#b91c1c;color:#fff}
  .btn-ask{background:#374151;color:#e5e7eb}
  .btn-back{background:#1f2937;color:#cbd5e1;border:1px solid #374151;border-radius:10px;padding:8px 12px;font-weight:800;text-decoration:none}
  .note{margin-left:8px;color:#93c5fd}
  .grid{display:grid;grid-template-columns: 1fr 360px; gap:16px}
  .card{background:#0f1218;border:1px solid #23262d;border-radius:14px;padding:0;overflow:hidden}
  .card-head{display:flex;align-items:center;justify-content:space-between;background:#151923;padding:12px 14px;border-bottom:1px solid #23262d;font-weight:800}
  .table{width:100%;border-collapse:collapse}
  .table th,.table td{padding:12px;border-bottom:1px solid #1f232b}
  .table th{background:#141821;color:#cbd5e1;text-transform:uppercase;font-size:11px}
  .okwrap{display:flex;align-items:center;gap:8px}
  .oktoggle{position:relative;width:58px;height:28px;background:#1f2937;border-radius:999px;border:2px solid #374151;cursor:pointer;transition:.15s}
  .oktoggle.on{background:#10b981;border-color:#34d399}
  .oktoggle .dot{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:999px;background:#9ca3af;transition:.15s}
  .oktoggle.on .dot{left:33px;background:#ecfdf5}
  .oklabel{font-weight:800;font-size:12px}
  .cmt{width:100%;max-width:100%}
  .cmt input{width:100%;padding:9px 10px;border-radius:10px;border:1px solid #2b3038;background:#111317;color:#e5e7eb}
  .sendRow{padding:12px 14px;border-top:1px solid #23262d;display:flex;justify-content:flex-end}
  /* Vertical timeline */
  .timeline{position:relative;padding:18px 14px 14px 32px}
  .timeline:before{content:"";position:absolute;left:16px;top:14px;bottom:14px;width:2px;background:#273449;border-radius:2px}
  .tstep{position:relative;margin:12px 0 12px 0;padding-left:8px}
  .tstep .dot{position:absolute;left:-19px;top:2px;width:10px;height:10px;border-radius:50%;background:#475569;border:2px solid #1f2937}
  .tstep.done .dot{background:#22c55e;border-color:#064e3b}
  .tstep.current .dot{background:#60a5fa;border-color:#1e3a8a}
  .ttext{line-height:1.35}
  .flash{margin:10px 0;padding:10px;border-radius:10px;background:#0b3a1e;color:#bbf7d0;border:1px solid #166534}
</style>

<div class="container">
  <?php if ($actionTaken): ?>
    <div class="flash">Action taken: <strong><?= h($actionTaken) ?></strong>.</div>
  <?php endif; ?>

  <div class="panel">
    <div class="top-row">
      <div>
        <div class="who">
          <?= h($user['name'] ?: '—') ?>
          <?php
            $sBadge = '<span class="badge b-pending">pending</span>';
            if ($status==='active' || $status==='approved') $sBadge = '<span class="badge b-active">active</span>';
            elseif ($status==='needs_update') $sBadge = '<span class="badge b-update">needs_update</span>';
            elseif ($status==='profile_pending') $sBadge = '<span class="badge b-unverified">profile_pending</span>';
            elseif ($status==='admin_review') $sBadge = '<span class="badge b-pending">admin_review</span>';
            elseif ($status==='unverified')   $sBadge = '<span class="badge b-unverified">unverified</span>';
            elseif ($status==='rejected')      $sBadge = '<span class="badge b-rejected">rejected</span>';
            echo $sBadge;
          ?>
          <?php
            if ($role==='superadmin') echo ' <span class="badge role-super">Superadmin</span>';
            elseif ($role==='admin')  echo ' <span class="badge role-admin">Admin</span>';
          ?>
        </div>
        <div style="opacity:.85"><?= h($user['email'] ?? '') ?></div>
        <div style="margin-top:8px; font-size:12px; color:#93c5fd;">
          <?php if ($profile_data): ?>
            <strong>Profile:</strong> Complete
            <?php if (!empty($profile_data['completeness_score'])): ?>
              (<?= (int)$profile_data['completeness_score'] ?>% complete)
            <?php endif; ?>
          <?php else: ?>
            <strong>Profile:</strong> Incomplete
          <?php endif; ?>
        </div>
        <?php
        // Note: promoted_by and promoter_name columns may not exist in database
        // These features are disabled until columns are added to database
        if (false): ?>
          <div class="note">Promoted by: <strong><?= h($user['promoter_name'] ?? '') ?></strong> <?= h($user['promoted_at'] ?? '') ?></div>
        <?php endif; ?>
      </div>

      <div class="actions">
        <a class="btn-back" href="users.php">← Back to User Management</a>

        <?php if ($status === 'admin_review'): ?>
          <!-- New Registration Workflow Actions -->
          <form method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to approve this user?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-approve" type="submit">✅ Approve & Send Email</button>
          </form>
          
          <form method="post" style="display:inline" onsubmit="var r=prompt('Reason for rejection (required):',''); if(r===null || r.trim()==='') return false; this.reason.value=r; return confirm('Reject this user?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="reason" value="">
            <button class="btn btn-delete" type="submit">❌ Reject & Send Email</button>
          </form>
        <?php elseif ($canApprove): ?>
          <!-- Legacy approval for other statuses -->
          <?php
            $ac = 'btn-approve';
            if ($status==='needs_update') $ac .= ' orange';
            if ($status==='unverified') $ac .= ' yellow';
          ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn <?= $ac ?>" type="submit">Approve</button>
          </form>
        <?php endif; ?>

        <?php if ($meRole === 'superadmin' && $role==='user'): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="promote">
            <button class="btn btn-promote" type="submit">Promote</button>
          </form>
        <?php endif; ?>

        <?php if ($meRole === 'superadmin' && $role==='admin'): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="demote">
            <button class="btn btn-demote" type="submit">Demote</button>
          </form>
        <?php endif; ?>

        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="deactivate">
          <button class="btn btn-deact" type="submit">Deactivate</button>
        </form>

        <?php if ($role !== 'superadmin' && !($role==='admin' && $meRole!=='superadmin')): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this user?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn btn-delete" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <div class="card-head">
        <div>Field Review &amp; Feedback</div>
        <?php if ($showAskToUpdate): ?>
          <form method="post" id="askForm" style="margin:0">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="send_back">
            <input type="hidden" name="reason" id="askReason" value="">
            <input type="hidden" name="field_status_json" id="fieldStatusJson" value="">
            <input type="hidden" name="field_comments_json" id="fieldCommentsJson" value="">
            <button class="btn btn-ask" type="button" onclick="submitAsk()">Ask user to update profile correctly</button>
          </form>
        <?php endif; ?>
      </div>

      <table class="table">
        <thead>
          <tr>
            <th>Field</th>
            <th>User Data</th>
            <th>OK?</th>
            <th>Comment (optional)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($profile_fields as $field => $cfg):
              $ok    = ($field_status[$field] ?? '') === 'ok';
              $cmt   = $field_comments[$field] ?? '';
              $value = $user[$field] ?? '—';
          ?>
            <tr data-field="<?= h($field) ?>">
              <td><?= h($cfg['label']) ?></td>
              <td><?= h($value) ?></td>
              <td>
                <div class="okwrap">
                  <div class="oktoggle <?= $ok?'on':'' ?>" onclick="toggleOK(this)"><div class="dot"></div></div>
                  <div class="oklabel"><?= $ok?'OK':'—' ?></div>
                </div>
              </td>
              <td class="cmt">
                <input type="text" placeholder="Comment for user" value="<?= h($cmt) ?>">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="sendRow">
        <?php if ($showAskToUpdate): ?>
          <button class="btn btn-ask" type="button" onclick="submitAsk()">Ask user to update profile correctly</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head">Timeline</div>
      <div class="timeline">
        <?php
          // Progress: joined -> verified -> reviewed -> approved/active
          $j = !empty($user['created_at']);
          $v = ((int)$user['email_verified']===1);
          $r = !empty($user['last_reviewed_at']);
          $a = ($status==='active'||$status==='approved');
        ?>
        <div class="tstep <?= $j?'done':'' ?>">
          <div class="dot"></div>
          <div class="ttext">Joined: <strong><?= h($user['created_at'] ?? '—') ?></strong></div>
        </div>
        <div class="tstep <?= $v?'done':'current' ?>">
          <div class="dot"></div>
          <div class="ttext">Email Verified: <strong><?= $v?'Yes':'No' ?></strong></div>
        </div>
        <div class="tstep <?= $r?'done':'' ?>">
          <div class="dot"></div>
          <div class="ttext">
            Last Reviewed: <strong><?= h($user['last_reviewed_at'] ?? '—') ?></strong>
            <?php
            // Note: last_reviewed_by and reviewer_name columns may not exist in database
            // These features are disabled until columns are added to database
            if (false && !empty($user['reviewer_name'])): ?>
              · by <strong><?= h($user['reviewer_name']) ?></strong>
            <?php endif; ?>
          </div>
        </div>
        <div class="tstep <?= $a?'done':'' ?>">
          <div class="dot"></div>
          <div class="ttext">Account Status: <strong><?= h($status) ?></strong> <?= !empty($user['updated_at']) ? '· '.h($user['updated_at']) : '' ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function toggleOK(el){
  el.classList.toggle('on');
  const label = el.parentElement.querySelector('.oklabel');
  label.textContent = el.classList.contains('on') ? 'OK' : '—';
}
function collectFieldJSON(){
  const rows = document.querySelectorAll('tr[data-field]');
  const status = {}, comments = {};
  rows.forEach(r=>{
    const field = r.getAttribute('data-field');
    const ok = r.querySelector('.oktoggle').classList.contains('on');
    const c  = r.querySelector('.cmt input').value.trim();
    if (ok) status[field] = 'ok';
    if (c !== '') comments[field] = c;
  });
  return {status: JSON.stringify(status), comments: JSON.stringify(comments)};
}
function submitAsk(){
  <?php if (!$showAskToUpdate): ?>return;<?php endif; ?>
  const why = prompt('Short note for the user (optional):','Please update the highlighted fields and resubmit.');
  if (why === null) return;
  const d = collectFieldJSON();
  document.getElementById('askReason').value = why || '';
  document.getElementById('fieldStatusJson').value = d.status;
  document.getElementById('fieldCommentsJson').value = d.comments;
  document.getElementById('askForm').submit();
}
</script>

<?php include __DIR__ . '/../footer.php'; ?>