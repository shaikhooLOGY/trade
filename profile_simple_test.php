<?php
// Simple Profile Test - Minimal version to isolate issues
echo "Starting simple profile test...<br>";

// Basic includes without complex logic
try {
    echo "1. Including env...<br>";
    require_once __DIR__ . '/includes/env.php';
    echo "✅ Env loaded<br>";
} catch (Exception $e) {
    echo "❌ Env error: " . $e->getMessage() . "<br>";
    exit;
}

try {
    echo "2. Including config...<br>";
    require_once __DIR__ . '/config.php';
    echo "✅ Config loaded<br>";
} catch (Exception $e) {
    echo "❌ Config error: " . $e->getMessage() . "<br>";
    exit;
}

try {
    echo "3. Starting session...<br>";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo "✅ Session started<br>";
} catch (Exception $e) {
    echo "❌ Session error: " . $e->getMessage() . "<br>";
    exit;
}

echo "4. Checking if logged in...<br>";
if (empty($_SESSION['user_id'])) {
    echo "❌ Not logged in - redirecting to login<br>";
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];
echo "✅ User ID: $uid<br>";

try {
    echo "5. Loading profile fields...<br>";
    $profile_fields = require __DIR__ . '/profile_fields.php';
    echo "✅ Profile fields loaded: " . count($profile_fields) . " fields<br>";
} catch (Exception $e) {
    echo "❌ Profile fields error: " . $e->getMessage() . "<br>";
    exit;
}

try {
    echo "6. Testing database query...<br>";
    $stmt = $mysqli->prepare("SELECT id, email FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($user) {
        echo "✅ User data loaded: " . $user['email'] . "<br>";
    } else {
        echo "❌ No user data found<br>";
    }
} catch (Exception $e) {
    echo "❌ Database query error: " . $e->getMessage() . "<br>";
    exit;
}

echo "7. Testing header include...<br>";
try {
    ob_start();
    include __DIR__ . '/header.php';
    $header_output = ob_get_clean();
    echo "✅ Header loaded successfully (" . strlen($header_output) . " chars)<br>";
} catch (Exception $e) {
    echo "❌ Header error: " . $e->getMessage() . "<br>";
    echo "Attempting without header...<br>";
}

echo "8. Basic HTML test...<br>";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Simple Profile Test</title>
</head>
<body>
    <h1>✅ Profile Page Test - Basic Version</h1>
    <p>This is a simplified version of the profile page.</p>
    <p>User ID: <?php echo htmlspecialchars($uid); ?></p>
    <p>Email: <?php echo htmlspecialchars($user['email']); ?></p>
    <p>Profile Fields Count: <?php echo count($profile_fields); ?></p>
    
    <h2>Profile Fields:</h2>
    <ul>
    <?php foreach ($profile_fields as $field => $config): ?>
        <li><?php echo htmlspecialchars($field); ?> - <?php echo htmlspecialchars($config['label']); ?></li>
    <?php endforeach; ?>
    </ul>
    
    <p><a href="profile.php">→ Try Full Profile Page</a></p>
    <p><a href="dashboard.php">→ Back to Dashboard</a></p>
</body>
</html>