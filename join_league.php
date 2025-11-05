<?php
require 'config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }

if (!isset($_GET['id'])) { header('Location: dashboard.php'); exit; }
$league_id = (int)$_GET['id'];
$uid = $_SESSION['user_id'];

$stmt = $mysqli->prepare("INSERT INTO participants (user_id, league_id) VALUES (?, ?)");
$stmt->bind_param('ii',$uid,$league_id);
if ($stmt->execute()) {
    header('Location: dashboard.php?joined=1'); exit;
} else {
    header('Location: dashboard.php?err='.urlencode($mysqli->error)); exit;
}