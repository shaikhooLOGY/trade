<?php
require 'auth_check.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: manage_scores.php'); exit; }
$id = intval($_POST['submission_id'] ?? 0);
$status = $_POST['status'] ?? 'pending';
$score = intval($_POST['score'] ?? 0);
$notes = trim($_POST['admin_notes'] ?? '');

if (!$id) { header('Location: manage_scores.php?err=1'); exit; }

$stmt = $mysqli->prepare("UPDATE submissions SET status=?, score=?, admin_notes=? WHERE id=?");
$stmt->bind_param('si si', $status, $score, $notes, $id); // oops, fix types - use 'sisi'
?>
<?php
// Corrected
$stmt = $mysqli->prepare("UPDATE submissions SET status=?, score=?, admin_notes=? WHERE id=?");
$stmt->bind_param('sisi', $status, $score, $notes, $id);
if ($stmt->execute()) header('Location: manage_scores.php?msg=ok');
else header('Location: manage_scores.php?err=db');