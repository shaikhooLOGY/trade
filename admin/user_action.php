<?php
// admin/user_action.php — centralized admin user actions
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['is_admin'])) {
  header('HTTP/1.1 403 Forbidden');
  exit('Access denied');
}

function back($msg) {
  $_SESSION['flash'] = $msg;
  header('Location: users.php');
  exit;
}

$id   = (int)($_POST['id'] ?? 0);
$act  = trim($_POST['action'] ?? '');
$csrf = $_POST['csrf'] ?? '';

if ($id <= 0 || $csrf === '' || !validate_csrf($csrf)) {
  back('❌ Invalid request.');
}

// Who is acting
$me = (int)($_SESSION['user_id'] ?? 0);

switch ($act) {
  case 'approve': {
    $sql = "UPDATE users SET status='active', profile_status='approved',
                             last_reviewed_by=?, last_reviewed_at=NOW()
            WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('ii', $me, $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '✅ User approved.' : '❌ DB error approving user.');
    }
    back('❌ Prepare failed.');
    break;
  }

  case 'send_back': {
    $reason = trim($_POST['reason'] ?? '');
    $sql = "UPDATE users SET status='needs_update', profile_status='needs_update',
                             rejection_reason=?, last_reviewed_by=?, last_reviewed_at=NOW()
            WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('sii', $reason, $me, $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '↩️ User sent back.' : '❌ DB error sending back.');
    }
    back('❌ Prepare failed.');
    break;
  }

  case 'send_back_detail': {
    $pfFile = __DIR__ . '/../profile_fields.php';
    $profile_fields = file_exists($pfFile) ? include $pfFile : [];

    $statusMap = [];
    $commentMap = [];

    foreach ($profile_fields as $field => $cfg) {
      $ok = !empty($_POST['ok_'.$field]);
      $comment = trim((string)($_POST['comment_'.$field] ?? ''));
      if ($ok) {
        $statusMap[$field] = 'ok';
      } elseif ($comment !== '') {
        $statusMap[$field] = 'needs_update';
        $commentMap[$field] = $comment;
      }
    }

    $status_json   = !empty($statusMap)   ? json_encode($statusMap)   : null;
    $comments_json = !empty($commentMap) ? json_encode($commentMap) : null;

    $sql = "UPDATE users SET profile_status='needs_update', status='needs_update',
                             profile_field_status=?, profile_comments=?,
                             last_reviewed_by=?, last_reviewed_at=NOW()
            WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('ssii', $status_json, $comments_json, $me, $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '↩️ Sent back with detailed feedback.' : '❌ DB error sending back.');
    }
    back('❌ Prepare failed.');
    break;
  }

  case 'reject': {
    $reason = trim($_POST['reason'] ?? '');
    $sql = "UPDATE users SET status='rejected', profile_status='rejected',
                             rejection_reason=?, last_reviewed_by=?, last_reviewed_at=NOW()
            WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('sii', $reason, $me, $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '❌ User rejected.' : '❌ DB error rejecting user.');
    }
    back('❌ Prepare failed.');
    break;
  }

  case 'activate': {
    $sql = "UPDATE users SET status='pending', profile_status='needs_update',
                             last_reviewed_by=?, last_reviewed_at=NOW()
            WHERE id=? AND status='rejected'";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('ii', $me, $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '🔄 User re-activated (pending update).' : '❌ DB error activating user.');
    }
    back('❌ Prepare failed.');
    break;
  }

  case 'promote': {
    $sql = "UPDATE users SET is_admin=1, role='admin', promoted_by=?, promoted_at=NOW() WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('ii', $me, $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '⬆️ User promoted to admin.' : '❌ DB error promoting.');
    }
    back('❌ Prepare failed.');
    break;
  }

  case 'demote': {
    $sql = "UPDATE users SET is_admin=0, role='user' WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('i', $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '⬇️ Admin demoted to user.' : '❌ DB error demoting.');
    }
    back('❌ Prepare failed.');
    break;
  }

  case 'delete': {
    $sql = "DELETE FROM users WHERE id=?";
    if ($st = $mysqli->prepare($sql)) {
      $st->bind_param('i', $id);
      $ok = $st->execute();
      $st->close();
      back($ok ? '🗑 User deleted.' : '❌ DB error deleting user.');
    }
    back('❌ Prepare failed.');
    break;
  }

  default:
    back('❌ Unknown action.');
}