<?php
require 'config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }
$user = current_user();

if (!isset($_GET['league'])) { header('Location: dashboard.php'); exit; }
$league_id = (int)$_GET['league'];

// find participant id (ensure user joined)
$stmt = $mysqli->prepare("SELECT id FROM participants WHERE user_id = ? AND league_id = ?");
$stmt->bind_param('ii', $user['id'], $league_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows==0) { die('You must join the league first.'); }
$participant = $res->fetch_assoc();
$participant_id = $participant['id'];

$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload failed';
    } else {
        $uploaddir = __DIR__ . '/uploads/';
        if (!is_dir($uploaddir)) mkdir($uploaddir, 0755, true);
        $fname = time().'_'.basename($_FILES['proof']['name']);
        $target = $uploaddir . $fname;
        // validate file type (images/pdf)
        $allowed = ['image/png','image/jpeg','image/jpg','application/pdf','video/mp4'];
        if (!in_array($_FILES['proof']['type'], $allowed)) {
            $err = 'Invalid file type. Use png/jpg/pdf/mp4';
        } else {
            if (move_uploaded_file($_FILES['proof']['tmp_name'], $target)) {
                $path_db = 'uploads/'.$fname;
                $stmt2 = $mysqli->prepare("INSERT INTO submissions (participant_id, file_path, notes) VALUES (?,?,?)");
                $notes = $_POST['notes'] ?? '';
                $stmt2->bind_param('iss',$participant_id,$path_db,$notes);
                if ($stmt2->execute()) {
                    header('Location: dashboard.php?submitted=1'); exit;
                } else $err = 'DB error: '.$mysqli->error;
            } else $err = 'Could not move uploaded file.';
        }
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Upload Proof</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="header"><div><a href="dashboard.php">Dashboard</a></div></div>
<div class="container">
  <h2>Upload Proof for League #<?=htmlspecialchars($league_id)?></h2>
  <?php if($err) echo "<div style='color:red'>$err</div>"; ?>
  <form method="post" enctype="multipart/form-data">
    <div class="form-row"><label>Choose file (png/jpg/pdf/mp4)</label><input type="file" name="proof" required></div>
    <div class="form-row"><label>Notes (optional)</label><textarea name="notes"></textarea></div>
    <button class="btn" type="submit">Upload</button>
  </form>
</div>
</body></html>