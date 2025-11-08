<?php
// Test script to verify reserved capital calculation fix
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = (int)($_SESSION['user_id'] ?? 1); // Use admin user for testing

echo "<h2>Reserved Capital Test - Dashboard Fix Verification</h2>";

// Check what columns exist in trades table
$cols_res = $mysqli->query("SHOW COLUMNS FROM trades");
$db_cols = [];
if ($cols_res) {
    echo "<h3>Trades Table Columns:</h3><ul>";
    while($c = $cols_res->fetch_assoc()) {
        $db_cols[strtolower($c['Field'])] = true;
        echo "<li>" . $c['Field'] . "</li>";
    }
    echo "</ul>";
}

$has_outcome = !empty($db_cols['outcome']);
$has_closed_at = !empty($db_cols['closed_at']);
$has_close_date = !empty($db_cols['close_date']);
$has_deleted_at = !empty($db_cols['deleted_at']);

echo "<h3>Column Detection:</h3><ul>";
echo "<li>Has outcome column: " . ($has_outcome ? 'YES' : 'NO') . "</li>";
echo "<li>Has closed_at column: " . ($has_closed_at ? 'YES' : 'NO') . "</li>";
echo "<li>Has close_date column: " . ($has_close_date ? 'YES' : 'NO') . "</li>";
echo "<li>Has deleted_at column: " . ($has_deleted_at ? 'YES' : 'NO') . "</li>";
echo "</ul>";

// OLD WAY (using AND) - this would have been the broken logic
echo "<h3>OLD WAY (Using AND - Would be broken):</h3>";
$old_conditions = [];
if ($has_outcome) $old_conditions[] = "UPPER(COALESCE(outcome, 'OPEN')) = 'OPEN'";
if ($has_closed_at) $old_conditions[] = "closed_at IS NULL";
if ($has_close_date) $old_conditions[] = "close_date IS NULL";
if (empty($old_conditions)) $old_conditions[] = "1=0";

$old_where_open = '('. implode(' AND ', $old_conditions) .')';
if ($has_deleted_at) $old_where_open .= " AND (deleted_at IS NULL OR deleted_at = '')";

echo "<p><strong>OLD WHERE clause:</strong> $old_where_open</p>";

// NEW WAY (using OR) - this is our fix
echo "<h3>NEW WAY (Using OR - Our Fix):</h3>";
$new_conditions = [];
if ($has_outcome) $new_conditions[] = "UPPER(COALESCE(outcome, 'OPEN')) = 'OPEN'";
if ($has_closed_at) $new_conditions[] = "closed_at IS NULL";
if ($has_close_date) $new_conditions[] = "close_date IS NULL";
if (empty($new_conditions)) $new_conditions[] = "1=0";

$new_where_open = '('. implode(' OR ', $new_conditions) .')';
if ($has_deleted_at) $new_where_open .= " AND (deleted_at IS NULL OR deleted_at = '')";

echo "<p><strong>NEW WHERE clause:</strong> $new_where_open</p>";

// Test both queries
echo "<h3>Test Results:</h3>";

// Test old way
try {
    if ($has_closed_at || $has_outcome) {
        $sql = "SELECT COUNT(*) as cnt FROM trades WHERE user_id=? AND $old_where_open";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo "<p><strong>OLD WAY - Open trades count:</strong> " . (int)$row['cnt'] . "</p>";
        $stmt->close();
    }
} catch (Exception $e) {
    echo "<p><strong>OLD WAY ERROR:</strong> " . $e->getMessage() . "</p>";
}

// Test new way
try {
    if ($has_closed_at || $has_outcome) {
        $sql = "SELECT COUNT(*) as cnt FROM trades WHERE user_id=? AND $new_where_open";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo "<p><strong>NEW WAY - Open trades count:</strong> " . (int)$row['cnt'] . "</p>";
        $stmt->close();
    }
} catch (Exception $e) {
    echo "<p><strong>NEW WAY ERROR:</strong> " . $e->getMessage() . "</p>";
}

// Test total trades for comparison
try {
    $sql = "SELECT COUNT(*) as cnt FROM trades WHERE user_id=?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo "<p><strong>TOTAL trades for user $user_id:</strong> " . (int)$row['cnt'] . "</p>";
    $stmt->close();
} catch (Exception $e) {
    echo "<p><strong>TOTAL COUNT ERROR:</strong> " . $e->getMessage() . "</p>";
}

// Show some sample trade data
try {
    $sql = "SELECT id, symbol, outcome, closed_at, deleted_at FROM trades WHERE user_id=? LIMIT 5";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h3>Sample Trade Data:</h3><table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Symbol</th><th>Outcome</th><th>Closed At</th><th>Deleted At</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
                echo "<td>" . (int)$row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['symbol']) . "</td>";
                echo "<td>" . htmlspecialchars($row['outcome'] ?? 'NULL') . "</td>";
                echo "<td>" . htmlspecialchars($row['closed_at'] ?? 'NULL') . "</td>";
                echo "<td>N/A</td>";
                echo "</tr>";
    }
    echo "</table>";
    $stmt->close();
} catch (Exception $e) {
    echo "<p><strong>SAMPLE DATA ERROR:</strong> " . $e->getMessage() . "</p>";
}

echo "<p><a href='/dashboard.php'>Test the Dashboard</a></p>";
echo "<p><a href='/db_ping.php'>Back to DB Test</a></p>";
?>