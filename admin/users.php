<?php
// admin/users.php — Netflix-ish dark UI, role badges, scoped actions (refined)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['admin_csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// who am I
$me = (int)($_SESSION['user_id'] ?? 0);
$meRole = 'user';
if ($st = $mysqli->prepare("SELECT role, is_admin FROM users WHERE id=?")) {
  $st->bind_param('i',$me); $st->execute();
  if ($r = $st->get_result()->fetch_assoc()) {
    $meRole = $r['role'] ?: (($r['is_admin']??0)?'admin':'user');
  }
  $st->close();
}

/* Get filter from URL parameter */
$filter = $_GET['filter'] ?? 'all';

// Filter users based on status for new registration workflow
$registration_workflow = [];
$profile_pending = [];
$admin_review = [];
$pending = [];
$all_users = [];

/* New Registration Workflow - Users in the new registration process */
$qr_workflow = $mysqli->query("
  SELECT id, name, email, status, email_verified, created_at, role, is_admin
  FROM users
  WHERE status IN ('pending', 'profile_pending', 'admin_review')
  ORDER BY created_at DESC
  LIMIT 200
");
if ($qr_workflow) {
  while ($x = $qr_workflow->fetch_assoc()) {
    $registration_workflow[] = $x;
    // Categorize by status
    if ($x['status'] === 'pending') {
      $pending[] = $x;
    } elseif ($x['status'] === 'profile_pending') {
      $profile_pending[] = $x;
    } elseif ($x['status'] === 'admin_review') {
      $admin_review[] = $x;
    }
  }
  $qr_workflow->free();
}

/* Legacy pending bucket: needs_update + unverified + rejected (not active/approved) */
$qr_legacy = $mysqli->query("
  SELECT id, name, email, status, email_verified, created_at, role, is_admin
  FROM users
  WHERE status IN ('needs_update','unverified','rejected') AND email_verified = 0
  ORDER BY created_at DESC
  LIMIT 200
");
if ($qr_legacy) {
  while ($x = $qr_legacy->fetch_assoc()) $pending[] = $x;
  $qr_legacy->free();
}

/* ALL USERS (everyone) — for overview + restrictive actions */
$users = [];
$qr2 = $mysqli->query("
  SELECT u.id, COALESCE(u.name, u.email) as name, u.email, u.is_admin, u.role, u.status, u.email_verified, u.created_at,
         u.promoted_by, pb.name AS promoter_name
  FROM users u
  LEFT JOIN users pb ON pb.id = u.promoted_by
  ORDER BY u.created_at DESC
  LIMIT 800
");
if ($qr2) { while ($x = $qr2->fetch_assoc()) $users[] = $x; $qr2->free(); }

include __DIR__ . '/../header.php';
?>
<style>
/* ---- Subtle dark theme (clearer contrast) ---- */
body{ background:#111827; color:#e5e7eb; }
.container{ max-width:1100px; margin:20px auto; padding:0 16px; }
.card{ background:#1f2937; border:1px solid #273244; border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,.25); }
h1,h2{ color:#f3f4f6; }

.section-bar{
  margin:18px 0 10px;
  background:linear-gradient(90deg,#9333ea,#2563eb);
  padding:12px 16px; border-radius:12px; color:#fff; font-weight:800; letter-spacing:.2px;
  display:flex; align-items:center; gap:10px; box-shadow:0 8px 22px rgba(0,0,0,.25);
}
.section-bar .emoji{ font-size:20px; }

.table{ width:100%; border-collapse:collapse; }
.table th,.table td{ padding:12px 12px; border-bottom:1px solid #2a3446; }
.table th{ text-transform:uppercase; font-size:12px; letter-spacing:.5px; color:#c7d2fe; background:#0f172a; position:sticky; top:0; z-index:1; }
.table tr:hover{ background:#111c2b; }

/* Links clearer */
a{ color:#93c5fd; text-decoration:none; font-weight:700; }
a:hover{ color:#bfdbfe; text-decoration:underline; }

/* Pills */
.badge{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:800; letter-spacing:.2px; }
.st-green{ background:#064e3b; color:#34d399; border:1px solid #065f46; }       /* active/approved */
.st-yellow{ background:#3b2f03; color:#fde047; border:1px solid #a16207; }      /* unverified */
.st-orange{ background:#3a1f06; color:#fbbf24; border:1px solid #b45309; }      /* needs_update */
.st-purple{ background:#21103a; color:#c4b5fd; border:1px solid #7c3aed; }      /* pending */
.st-red{ background:#3b0d0d; color:#fca5a5; border:1px solid #ef4444; }         /* rejected */

/* Email tick/cross */
.chk{ font-size:16px; }
.chk-yes{ color:#34d399; }
.chk-no{ color:#ef4444; }

/* Role badges next to name */
.role{ margin-left:8px; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:900 }
.role-admin{ background:#312e81; color:#c7d2fe; border:1px solid #4338ca; }
.role-super{ background:#7c2d12; color:#fed7aa; border:1px solid #ea580c; }

/* Buttons */
.btn{ padding:8px 12px; border-radius:10px; border:1px solid #334155; background:#0b1220; color:#e5e7eb; cursor:pointer; }
.btn:hover{ background:#0f172a; }
.btn-ghost{ background:#0b1220; }
.btn-danger{ background:#7f1d1d; border-color:#ef4444; color:#fff; }
.btn-danger:hover{ background:#991b1b; }
.btn-secondary{ background:#1e293b; border-color:#334155; }

.flash{ background:#064e3b; color:#a7f3d0; padding:10px 12px; border-radius:10px; border:1px solid #065f46; margin:10px 0; }
.muted{ color:#9ca3af; font-size:12px; }
.actions form{ display:inline; margin-right:6px; }
</style>

<main class="container" style="padding-top:12px">
  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="flash"><?= h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
  <?php endif; ?>

  <!-- Filter Tabs -->
  <div class="section-bar">
    <span class="emoji">🎯</span>
    <span>User Registration Workflow</span>
    <div style="margin-left:auto; display:flex; gap:10px;">
      <a href="?filter=all" class="btn <?= $filter==='all' ? 'btn-primary' : 'btn-secondary' ?>" style="padding:6px 12px; font-size:12px;">All Users</a>
      <a href="?filter=new_registrations" class="btn <?= $filter==='new_registrations' ? 'btn-primary' : 'btn-secondary' ?>" style="padding:6px 12px; font-size:12px;">New Registrations</a>
      <a href="?filter=profile_pending" class="btn <?= $filter==='profile_pending' ? 'btn-primary' : 'btn-secondary' ?>" style="padding:6px 12px; font-size:12px;">Profile Pending</a>
      <a href="?filter=admin_review" class="btn <?= $filter==='admin_review' ? 'btn-primary' : 'btn-secondary' ?>" style="padding:6px 12px; font-size:12px;">Admin Review</a>
    </div>
  </div>

  <!-- New Registration Workflow Section -->
  <?php if ($filter === 'new_registrations' || $filter === 'all'): ?>
  <div class="section-bar" style="background:linear-gradient(90deg,#3b82f6,#1d4ed8); margin-top:18px 0 10px;">
    <span class="emoji">🎯</span><span>New Registration Workflow (Pending, Profile Pending, Admin Review)</span>
    <?php if (count($registration_workflow) > 0): ?>
      <span style="margin-left:auto; background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:20px; font-size:12px;">
        <?= count($registration_workflow) ?> users
      </span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (($filter === 'new_registrations' || $filter === 'all') && !empty($registration_workflow)): ?>
  <div class="card">
    <table class="table" id="registrationTable">
      <thead>
        <tr>
          <th>#</th><th>Name</th><th>Email</th><th>Status</th>
          <th>Email Verified</th><th>Profile Complete</th><th>Joined</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($registration_workflow as $p):
          $s = strtolower((string)$p['status']);
          $badge = 'st-purple';
          $label = $s;
          if ($s==='active'||$s==='approved'){ $badge='st-green'; }
          elseif ($s==='unverified'){ $badge='st-yellow'; }
          elseif ($s==='needs_update'){ $badge='st-orange'; }
          elseif ($s==='rejected'){ $badge='st-red'; }
          elseif ($s==='profile_pending'){ $badge='st-yellow'; }
          elseif ($s==='admin_review'){ $badge='st-purple'; }
          $role = strtolower((string)($p['role'] ?: (($p['is_admin']??0)?'admin':'user')));
          
          // Check if profile is complete (has user_profiles record)
          $profile_complete = false;
          try {
            $stmt = $mysqli->prepare("SELECT id FROM user_profiles WHERE user_id=? LIMIT 1");
            $stmt->bind_param('i', $p['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $profile_complete = $result->num_rows > 0;
            $stmt->close();
          } catch (Exception $e) {
            // ignore if table doesn't exist
          }
        ?>
          <tr>
            <td><?= (int)$p['id'] ?></td>
            <td>
              <a href="user_profile.php?id=<?= (int)$p['id'] ?>"><?= h($p['name'] ?: '—') ?></a>
              <?php if ($role==='superadmin'): ?><span class="role role-super">Superadmin</span>
              <?php elseif ($role==='admin'): ?><span class="role role-admin">Admin</span><?php endif; ?>
            </td>
            <td><?= h($p['email']) ?></td>
            <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
            <td class="chk"><?= ((int)$p['email_verified'] ? '<span class="chk-yes">✔</span>' : '<span class="chk-no">✖</span>') ?></td>
            <td class="chk"><?= $profile_complete ? '<span class="chk-yes">✔</span>' : '<span class="chk-no">✖</span>' ?></td>
            <td class="muted"><?= h($p['created_at']) ?></td>
            <td class="actions">
              <!-- Actions for new registration workflow -->
              <?php if ($p['status'] === 'admin_review'): ?>
                <a href="user_profile.php?id=<?= (int)$p['id'] ?>&action=review" class="btn btn-secondary" style="padding:6px 10px; font-size:11px;">
                  Review Profile
                </a>
              <?php else: ?>
                <a href="user_profile.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary" style="padding:6px 10px; font-size:11px;">
                  View Details
                </a>
              <?php endif; ?>
              
              <form method="post" action="user_action.php" style="display:inline; margin-left:5px;"
                    onsubmit="var r=prompt('Reason (optional):','Please complete your registration.'); if(r===null)return false; this.reason.value=r;">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="action" value="send_back">
                <input type="hidden" name="reason" value="">
                <button class="btn btn-secondary" type="submit" style="padding:6px 10px; font-size:11px;">Send Back</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($filter === 'new_registrations' || $filter === 'all'): ?>
  <div class="card">
    <p style="padding:14px 16px" class="muted">No users in new registration workflow.</p>
  </div>
  <?php endif; ?>

  <!-- Legacy Pending Section -->
  <div class="section-bar" style="margin-top:22px;"><span class="emoji">⏳</span><span>Legacy Pending / Needs Update / Unverified</span></div>
  <div class="card">
    <?php if (empty($pending)): ?>
      <p style="padding:14px 16px" class="muted">No legacy pending records.</p>
    <?php else: ?>
      <table class="table" id="pendingTable">
        <thead>
          <tr><th>#</th><th>Name</th><th>Email</th><th>Status</th><th>Email Verified</th><th>Joined</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach($pending as $p):
            $s = strtolower((string)$p['status']);
            $badge = 'st-purple';
            $label = $s;
            if ($s==='active'||$s==='approved'){ $badge='st-green'; }
            elseif ($s==='unverified'){ $badge='st-yellow'; }
            elseif ($s==='needs_update'){ $badge='st-orange'; }
            elseif ($s==='rejected'){ $badge='st-red'; }
            $role = strtolower((string)($p['role'] ?: (($p['is_admin']??0)?'admin':'user')));
          ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td>
                <a href="user_profile.php?id=<?= (int)$p['id'] ?>"><?= h($p['name'] ?: '—') ?></a>
                <?php if ($role==='superadmin'): ?><span class="role role-super">Superadmin</span>
                <?php elseif ($role==='admin'): ?><span class="role role-admin">Admin</span><?php endif; ?>
              </td>
              <td><?= h($p['email']) ?></td>
              <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
              <td class="chk"><?= ((int)$p['email_verified'] ? '<span class="chk-yes">✔</span>' : '<span class="chk-no">✖</span>') ?></td>
              <td class="muted"><?= h($p['created_at']) ?></td>
              <td class="actions">
                <!-- Only legacy pending bucket can show Send Back / Reject -->
                <form method="post" action="user_action.php"
                      onsubmit="var r=prompt('Reason (optional):','Please update required fields.'); if(r===null)return false; this.reason.value=r;">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="action" value="send_back">
                  <input type="hidden" name="reason" value="">
                  <button class="btn btn-secondary" type="submit">Send Back</button>
                </form>
                <form method="post" action="user_action.php"
                      onsubmit="var r=prompt('Rejection reason (optional):','Insufficient / invalid details.'); if(r===null)return false; this.reason.value=r;">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="reason" value="">
                  <button class="btn btn-danger" type="submit">Reject</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="section-bar" style="margin-top:22px;"><span class="emoji">📚</span><span>All Users</span></div>
  <div class="card">
    <table class="table" id="usersTable">
      <thead>
        <tr><th>#</th><th>Name</th><th>Email</th><th>Status</th><th>Email Verified</th><th>Joined</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach($users as $u):
          $role = strtolower((string)($u['role'] ?: (($u['is_admin']??0)?'admin':'user')));
          $s = strtolower((string)$u['status']);
          $badge='st-purple'; $label=$s;
          if ($s==='active'||$s==='approved'){ $badge='st-green'; }
          elseif ($s==='unverified'){ $badge='st-yellow'; }
          elseif ($s==='needs_update'){ $badge='st-orange'; }
          elseif ($s==='rejected'){ $badge='st-red'; }
        ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td>
              <a href="user_profile.php?id=<?= (int)$u['id'] ?>"><?= h($u['name'] ?: '—') ?></a>
              <?php if ($role==='superadmin'): ?><span class="role role-super">Superadmin</span>
              <?php elseif ($role==='admin'): ?><span class="role role-admin">Admin</span><?php endif; ?>
            </td>
            <td><?= h($u['email']) ?></td>
            <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
            <td class="chk"><?= ((int)$u['email_verified'] ? '<span class="chk-yes">✔</span>' : '<span class="chk-no">✖</span>') ?></td>
            <td class="muted"><?= h($u['created_at']) ?></td>
            <td class="actions">
              <?php
              // Actions: for "All Users" show only Deactivate + Delete (as requested).
              // (No Send Back / Reject here.)
              ?>
              <?php if ($role !== 'superadmin'): ?>
                <form method="post" action="user_action.php"
                      onsubmit="return confirm('Deactivate this account? They will lose access until re-approved.');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="action" value="deactivate">
                  <button class="btn" type="submit">Deactivate</button>
                </form>
                <form method="post" action="user_action.php"
                      onsubmit="return confirm('Permanently delete this user? This cannot be undone.');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-ghost" type="submit">Delete</button>
                </form>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>