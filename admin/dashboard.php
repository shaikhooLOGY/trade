<?php
require 'config.php';

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Agar admin galti se yaha aaye to unko admin panel bhejo
if ($_SESSION['role'] === 'admin') {
    header("Location: admin/admin_dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Dashboard — Shaikhoology</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: green; }
        .logout { margin-top: 20px; }
    </style>
</head>
<body>
    <h1>Welcome <?= htmlspecialchars($_SESSION['username']); ?> 🎉</h1>

    <h2>Your Submissions</h2>
    <?php
    $uid = $_SESSION['user_id'];
    $res = $mysqli->query("SELECT id, file_path, notes, submitted_at FROM submissions WHERE user_id = $uid");
    if ($res->num_rows > 0) {
        echo "<ul>";
        while ($row = $res->fetch_assoc()) {
            echo "<li>" . htmlspecialchars($row['file_path']) . " — " . htmlspecialchars($row['notes']) . " (" . $row['submitted_at'] . ")</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No submissions yet.</p>";
    }
    ?>

    <div class="logout">
        <a href="logout.php">Logout</a>
    </div>
</body>
</html>