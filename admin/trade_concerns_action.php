<?php
// admin/trade_concerns_action.php
require_once __DIR__ . '/../includes/bootstrap.php';

// must be admin
if (empty($_SESSION['is_admin'])) {
  header('HTTP/1.1 403 Forbidden');
  exit('Access denied');
}

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (!validate_csrf($csrf)) {
  exit('Bad CSRF');
}

// inputs
$id       = (int)($_POST['id'] ?? 0);        // concern id
$trade_id = (int)($_POST['trade_id'] ?? 0);  // trade id
$action   = $_POST['action'] ?? '';

if ($id <= 0 || $trade_id <= 0 || ($action !== 'unlock' && $action !== 'resolve')) {
  exit('Bad request');
}

// helper: does a column exist?
function col_exists(mysqli $db, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  if (!$st = $db->prepare($sql)) return false;
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $r = $st->get_result();
  $ok = $r && $r->num_rows > 0;
  $st->close();
  return $ok;
}

$hasResolvedAt = col_exists($mysqli, 'trade_concerns', 'resolved_at');

// actions
if ($action === 'unlock') {
  // 1) unlock the trade
  if ($st = $mysqli->prepare("UPDATE trades SET is_locked = 0 WHERE id = ?")) {
    $st->bind_param('i', $trade_id);
    $st->execute();
    $st->close();
  }
  // 2) mark concern resolved
  if ($hasResolvedAt) {
    $sql = "UPDATE trade_concerns SET status='resolved', resolved_at=NOW() WHERE id=?";
  } else {
    $sql = "UPDATE trade_concerns SET status='resolved' WHERE id=?";
  }
  if ($st = $mysqli->prepare($sql)) {
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
  }
} else { // resolve only
  if ($hasResolvedAt) {
    $sql = "UPDATE trade_concerns SET status='resolved', resolved_at=NOW() WHERE id=?";
  } else {
    $sql = "UPDATE trade_concerns SET status='resolved' WHERE id=?";
  }
  if ($st = $mysqli->prepare($sql)) {
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
  }
}

header('Location: /admin/trade_concerns.php');
exit;