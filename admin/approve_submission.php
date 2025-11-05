<?php
require 'auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php'); exit;
}

$submission_id = intval($_POST['submission_id'] ?? 0);
$action = $_POST['action'] ?? '';
$score = intval($_POST['score'] ?? 0);
$admin_notes = trim($_POST['admin_notes'] ?? '');

if (!$submission_id) {
    header('Location: dashboard.php?err=invalid'); exit;
}

// Load submission and participant
$stmt = $mysqli->prepare("SELECT s.*, p.user_id FROM submissions s JOIN participants p ON s.participant_id = p.id WHERE s.id = ?");
$stmt->bind_param('i', $submission_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    header('Location: dashboard.php?err=notfound'); exit;
}
$row = $res->fetch_assoc();
$user_id = $row['user_id'];

if ($action === 'approve') {
    // update submission status + score
    $stmt2 = $mysqli->prepare("UPDATE submissions SET status='approved', score=?, admin_notes=? WHERE id=?");
    $stmt2->bind_param('isi', $score, $admin_notes, $submission_id);
    $ok = $stmt2->execute();

    // Optionally: you may keep a per-participant total or compute on leaderboard. We'll compute on the fly.
    if ($ok) {
        header('Location: dashboard.php?msg=approved'); exit;
    } else {
        header('Location: dashboard.php?err=db'); exit;
    }
} elseif ($action === 'reject') {
    $stmt2 = $mysqli->prepare("UPDATE submissions SET status='rejected', score=0, admin_notes=? WHERE id=?");
    $stmt2->bind_param('si', $admin_notes, $submission_id);
    if ($stmt2->execute()) {
        header('Location: dashboard.php?msg=rejected'); exit;
    } else {
        header('Location: dashboard.php?err=db'); exit;
    }
} else {
    header('Location: dashboard.php'); exit;
}