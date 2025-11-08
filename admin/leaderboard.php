<?php
require 'auth_check.php';
require 'header.php';
?>
<h2>Leaderboard (all leagues combined)</h2>
<div class="card">
  <?php
  $q = "SELECT u.id AS user_id, u.username, SUM(s.score) AS total_score
        FROM users u
        JOIN participants p ON p.user_id = u.id
        JOIN submissions s ON s.participant_id = p.id AND s.status='approved'
        GROUP BY u.id
        ORDER BY total_score DESC
        LIMIT 100";
  $res = $mysqli->query($q);
  if ($res->num_rows == 0) echo "<p class='small'>No scores yet.</p>";
  else {
      echo "<table style='width:100%;border-collapse:collapse;'><tr><th>#</th><th>User</th><th>Total Points</th></tr>";
      $rank = 1;
      while ($r = $res->fetch_assoc()) {
          echo "<tr style='border-bottom:1px solid #f0f4f8;'><td style='padding:8px;'>".$rank."</td>
                <td style='padding:8px;'>".htmlspecialchars($r['username'])."</td>
                <td style='padding:8px;'>".intval($r['total_score'])."</td></tr>";
          $rank++;
      }
      echo "</table>";
  }
  ?>
</div>
<?php require 'footer.php'; ?>