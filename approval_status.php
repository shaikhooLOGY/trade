<?php
// approval_status.php — JSON status check
// Session handling centralized via bootstrap.php
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
  echo json_encode(['ok'=>false, 'error'=>'not_logged_in']); exit;
}

$uid = (int)$_SESSION['user_id'];
$st  = $mysqli->prepare("SELECT status, email_verified FROM users WHERE id=? LIMIT 1");
if (!$st) {
  echo json_encode(['ok'=>false, 'error'=>'db_prepare']); exit;
}
$st->bind_param('i', $uid);
$st->execute();
$res = $st->get_result();
$row = $res ? $res->fetch_assoc() : null;
$st->close();

if (!$row) {
  echo json_encode(['ok'=>false, 'error'=>'not_found']); exit;
}

$status = strtolower((string)$row['status']);
if (in_array($status, ['active','approved','1','yes'], true)) {
  echo json_encode(['ok'=>true, 'status'=>'active']); exit;
}
if (in_array($status, ['rejected','blocked','0','no'], true)) {
  echo json_encode(['ok'=>true, 'status'=>'rejected']); exit;
}

echo json_encode([
  'ok'=>true,
  'status'=>'pending',
  'email_verified'=>(int)($row['email_verified'] ?? 0)
]);