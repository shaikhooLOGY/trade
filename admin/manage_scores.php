<?php
require 'auth_check.php';
require 'header.php';
?>
<h2>All Submissions (Manage)</h2>
<div class="card">
  <?php
  $q = "SELECT s.id, s.file_path, s.notes, s.submitted_at, s.status, s.score, s.admin_notes, u.username, l.title AS league_title
        FROM submissions s
        JOIN participants p ON s.participant_id = p.id
        JOIN users u ON p.user_id = u.id
        JOIN leagues l ON p.league_id = l.id
        ORDER BY s.submitted_at DESC
       ";
  $res = $mysqli->query($q);
  if ($res->num_rows == 0) echo "<p class='small'>No submissions.</p>";
  else {
      while ($r = $res->fetch_assoc()) {
          echo "<div style='padding:8px;border-bottom:1px solid #f5f8fc;'>";
          echo "<strong>ID:</strong> ".$r['id']." | <strong>User:</strong> ".htmlspecialchars($r['username'])." | <strong>League:</strong> ".htmlspecialchars($r['league_title'])."<br>";
          echo "<div class='small'>Status: ".htmlspecialchars($r['status'])." | Score: ".intval($r['score'])." | Submitted: ".$r['submitted_at']."</div>";
          echo "<div style='margin-top:6px'>File: <a target='_blank' href='../".htmlspecialchars($r['file_path'])."'>View</a></div>";
          echo "<div class='small' style='margin-top:6px'>Notes: ".nl2br(htmlspecialchars($r['notes']))."</div>";
          echo "<form method='post' action='edit_submission.php' style='margin-top:8px'>
                  <input type='hidden' name='submission_id' value='".intval($r['id'])."'>
                  <label>Status</label>
                  <select name='status'><option value='pending'>pending</option><option value='approved'>approved</option><option value='rejected'>rejected</option></select>
                  <label>Score</label>
                  <input type='number' name='score' value='".intval($r['score'])."' style='width:120px'>
                  <label>Admin Notes</label>
                  <input name='admin_notes' value=\"".htmlspecialchars($r['admin_notes'])."\">
                  <button class='btn' type='submit'>Save</button>
                </form>";
          echo "</div>";
      }
  }
  ?>
</div>
<?php require 'footer.php'; ?>