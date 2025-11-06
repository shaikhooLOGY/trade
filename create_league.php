<?php
require 'config.php';
require_once __DIR__ . '/includes/security/csrf.php';

if (!is_logged_in()) { header('Location: login.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection - validate before any DB operations
    if (!validate_csrf($_POST['csrf'] ?? '')) {
        $err = 'Security verification failed. Please try again.';
    } else {
        $title = $_POST['title']; $desc = $_POST['description'];
        $start = $_POST['start_date']; $end = $_POST['end_date']; $fee = $_POST['entry_fee'];
        $stmt = $mysqli->prepare("INSERT INTO leagues (title,description,start_date,end_date,entry_fee,created_by) VALUES (?,?,?,?,?,?)");
        $uid = $_SESSION['user_id'];
        $stmt->bind_param('ssssdi',$title,$desc,$start,$end,$fee,$uid);
        if ($stmt->execute()) {
            header('Location: dashboard.php?created=1'); exit;
        } else $err = "DB error: ".$mysqli->error;
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Create League</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="header"><div><a href="dashboard.php">Dashboard</a></div></div>
<div class="container">
  <h2>Create League</h2>
  <?php if($err) echo "<div style='color:red'>$err</div>"; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(get_csrf_token()) ?>">
    <div class="form-row"><label>Title</label><input name="title" required></div>
    <div class="form-row"><label>Description</label><textarea name="description"></textarea></div>
    <div class="form-row"><label>Start Date</label><input name="start_date" type="date" required></div>
    <div class="form-row"><label>End Date</label><input name="end_date" type="date" required></div>
    <div class="form-row"><label>Entry Fee (optional)</label><input name="entry_fee" type="text" value="0"></div>
    <button class="btn" type="submit">Create</button>
  </form>
</div>
</body></html>