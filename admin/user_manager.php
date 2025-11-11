<?php
// admin/user_manager.php — Simple user management: Pending Approval + All Users
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['admin_csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Pending Approval - All non-active/approved users
$pending_approval = [];
$qr_pending = $mysqli->query("
  SELECT id, name, email, status, email_verified, created_at, role, is_admin
  FROM users
  WHERE status NOT IN ('active', 'approved')
  ORDER BY created_at DESC
  LIMIT 500
");
if ($qr_pending) {
  while ($x = $qr_pending->fetch_assoc()) $pending_approval[] = $x;
  $qr_pending->free();
}

// All Users
$users = [];
$qr_all = $mysqli->query("
  SELECT id, COALESCE(name, email) as name, email, is_admin, role, status, email_verified, created_at
  FROM users
  ORDER BY created_at DESC
  LIMIT 800
");
if ($qr_all) {
  while ($x = $qr_all->fetch_assoc()) $users[] = $x;
  $qr_all->free();
}

include __DIR__ . '/../header.php';
?>
<style>
body{ background:#111827; color:#e5e7eb; }
.container{ max-width:1100px; margin:20px auto; padding:0 16px; }
.card{ background:#1f2937; border:1px solid #273244; border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,.25); overflow-x:auto; }
h1,h2{ color:#f3f4f6; }

.section-bar{
  margin:18px 0 10px;
  background:linear-gradient(90deg,#9333ea,#2563eb);
  padding:12px 16px; border-radius:12px; color:#fff; font-weight:800; letter-spacing:.2px;
  display:flex; align-items:center; gap:10px; box-shadow:0 8px 22px rgba(0,0,0,.25);
  flex-wrap:wrap;
}
.section-bar .emoji{ font-size:20px; }

.table{ width:100%; border-collapse:collapse; min-width:800px; }
.table th,.table td{ padding:12px 12px; border-bottom:1px solid #2a3446; }
.table th{ text-transform:uppercase; font-size:12px; letter-spacing:.5px; color:#c7d2fe; background:#0f172a; position:sticky; top:0; z-index:1; }
.table tr:hover{ background:#111c2b; }

a{ color:#93c5fd; text-decoration:none; font-weight:700; }
a:hover{ color:#bfdbfe; text-decoration:underline; }

.badge{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:800; letter-spacing:.2px; }
.st-green{ background:#064e3b; color:#34d399; border:1px solid #065f46; }
.st-yellow{ background:#3b2f03; color:#fde047; border:1px solid #a16207; }
.st-orange{ background:#3a1f06; color:#fbbf24; border:1px solid #b45309; }
.st-purple{ background:#21103a; color:#c4b5fd; border:1px solid #7c3aed; }
.st-red{ background:#3b0d0d; color:#fca5a5; border:1px solid #ef4444; }

.chk{ font-size:16px; }
.chk-yes{ color:#34d399; }
.chk-no{ color:#ef4444; }

.role{ margin-left:8px; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:900 }
.role-admin{ background:#312e81; color:#c7d2fe; border:1px solid #4338ca; }
.role-super{ background:#7c2d12; color:#fed7aa; border:1px solid #ea580c; }

.btn{ padding:8px 12px; border-radius:10px; border:1px solid #334155; background:#0b1220; color:#e5e7eb; cursor:pointer; }
.btn:hover{ background:#0f172a; }
.btn-ghost{ background:#0b1220; }
.btn-danger{ background:#7f1d1d; border-color:#ef4444; color:#fff; }
.btn-danger:hover{ background:#991b1b; }
.btn-secondary{ background:#1e293b; border-color:#334155; }

.flash{ background:#064e3b; color:#a7f3d0; padding:10px 12px; border-radius:10px; border:1px solid #065f46; margin:10px 0; }
.muted{ color:#9ca3af; font-size:12px; }
.actions form{ display:inline; margin-right:6px; }

@media (max-width: 768px) {
  .container{ padding:0 8px; }
  .section-bar{ font-size:14px; padding:10px 12px; flex-direction:column; align-items:flex-start; }
  .section-bar > div{ width:100%; margin-top:10px; }
  .table{ font-size:12px; min-width:600px; }
  .table th,.table td{ padding:8px 6px; }
  .badge{ font-size:10px; padding:3px 8px; }
  .btn{ padding:6px 10px; font-size:11px; }
  .card{ margin-bottom:16px; }
}
</style>

<main class="container" style="padding-top:12px">
  <div style="margin-bottom:16px">
    <a href="admin_dashboard.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px">
      ← Back to Admin Dashboard
    </a>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="flash"><?= h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
  <?php endif; ?>

  <!-- Compact Stats -->
  <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">
    <div style="background:#1f2937;border:1px solid #273244;border-radius:8px;padding:12px 16px;flex:1;min-width:150px">
      <div style="font-size:24px;font-weight:800;color:#fbbf24"><?= count($pending_approval) ?></div>
      <div style="color:#9ca3af;font-size:12px">Pending Approval</div>
    </div>
    <div style="background:#1f2937;border:1px solid #273244;border-radius:8px;padding:12px 16px;flex:1;min-width:150px">
      <div style="font-size:24px;font-weight:800;color:#34d399"><?= count($users) ?></div>
      <div style="color:#9ca3af;font-size:12px">Total Users</div>
    </div>
  </div>

  <!-- Pending Approval Section (Always shown first) -->
  <div class="section-bar" style="background:linear-gradient(90deg,#f59e0b,#d97706);">
    <span class="emoji">⏳</span><span>Pending Approval</span>
    <?php if (count($pending_approval) > 0): ?>
      <span style="margin-left:auto; background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:20px; font-size:12px;">
        <?= count($pending_approval) ?> users
      </span>
    <?php endif; ?>
  </div>

  <?php if (!empty($pending_approval)): ?>
  <div class="card">
    <table class="table">
      <thead>
        <tr><th>#</th><th>Name</th><th>Email</th><th>Status</th><th>Email Verified</th><th>Joined</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach($pending_approval as $p):
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
              <a href="user_profile.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary" style="padding:6px 10px; font-size:11px;">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card">
    <p style="padding:14px 16px" class="muted">No users pending approval.</p>
  </div>
  <?php endif; ?>

  <!-- All Users Section (Always shown below) -->
  <div class="section-bar" style="margin-top:22px;"><span class="emoji">📚</span><span>All Users</span></div>
  <div class="card">
    <table class="table">
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
              <?php if ($role !== 'superadmin'): ?>
                <form method="post" action="user_action.php"
                      onsubmit="return confirm('Deactivate this account?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="action" value="deactivate">
                  <button class="btn" type="submit">Deactivate</button>
                </form>
                <form method="post" action="user_action.php"
                      onsubmit="return confirm('Permanently delete this user?');">
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