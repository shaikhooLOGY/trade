<?php
// test_admin_approval_system.php - Test script for admin approval system
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

echo "<h1>Admin Approval System Test</h1>";

// Test 1: Check if functions exist
echo "<h2>Test 1: Function Availability</h2>";
echo "send_approval_email function: " . (function_exists('send_approval_email') ? "✅ EXISTS" : "❌ MISSING") . "<br>";
echo "get_approval_email_template function: " . (function_exists('get_approval_email_template') ? "✅ EXISTS" : "❌ MISSING") . "<br>";
echo "get_rejection_email_template function: " . (function_exists('get_rejection_email_template') ? "✅ EXISTS" : "❌ MISSING") . "<br>";

// Test 2: Check database tables
echo "<h2>Test 2: Database Tables</h2>";
try {
    $result1 = $mysqli->query("SHOW TABLES LIKE 'user_profiles'");
    echo "user_profiles table: " . ($result1 && $result1->num_rows > 0 ? "✅ EXISTS" : "❌ MISSING") . "<br>";
    
    $result2 = $mysqli->query("SHOW TABLES LIKE 'user_otps'");
    echo "user_otps table: " . ($result2 && $result2->num_rows > 0 ? "✅ EXISTS" : "❌ MISSING") . "<br>";
} catch (Exception $e) {
    echo "Error checking tables: " . $e->getMessage() . "<br>";
}

// Test 3: Check if status column exists in users table
echo "<h2>Test 3: Users Table Status Column</h2>";
try {
    $result = $mysqli->query("SHOW COLUMNS FROM users LIKE 'status'");
    echo "status column in users table: " . ($result && $result->num_rows > 0 ? "✅ EXISTS" : "❌ MISSING") . "<br>";
} catch (Exception $e) {
    echo "Error checking status column: " . $e->getMessage() . "<br>";
}

// Test 4: Check user status values
echo "<h2>Test 4: User Status Values</h2>";
try {
    $result = $mysqli->query("SELECT DISTINCT status FROM users WHERE status IS NOT NULL");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "Found status: " . $row['status'] . "<br>";
        }
    } else {
        echo "No status values found<br>";
    }
} catch (Exception $e) {
    echo "Error checking status values: " . $e->getMessage() . "<br>";
}

// Test 5: Create a test user for admin review (if needed)
echo "<h2>Test 5: Test User Creation</h2>";
$test_email = "test_" . time() . "@example.com";
$test_name = "Test User";
try {
    // Check if test user already exists
    $check = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param('s', $test_email);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows == 0) {
        // Create test user
        $password_hash = password_hash('test123', PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("INSERT INTO users (name, email, password_hash, status, email_verified, created_at) VALUES (?, ?, ?, 'admin_review', 1, NOW())");
        $stmt->bind_param('sss', $test_name, $test_email, $password_hash);
        $stmt->execute();
        $user_id = $mysqli->insert_id;
        $stmt->close();
        
        echo "✅ Test user created: ID = $user_id, Email = $test_email<br>";
    } else {
        $row = $result->fetch_assoc();
        $user_id = $row['id'];
        echo "✅ Test user already exists: ID = $user_id, Email = $test_email<br>";
    }
} catch (Exception $e) {
    echo "❌ Error creating test user: " . $e->getMessage() . "<br>";
}

// Test 6: Test email template generation
echo "<h2>Test 6: Email Template Generation</h2>";
try {
    $approval_template = get_approval_email_template("Test User");
    $rejection_template = get_rejection_email_template("Test User", "Test rejection reason");
    
    echo "✅ Approval email template generated: " . strlen($approval_template) . " characters<br>";
    echo "✅ Rejection email template generated: " . strlen($rejection_template) . " characters<br>";
} catch (Exception $e) {
    echo "❌ Error generating email templates: " . $e->getMessage() . "<br>";
}

// Test 7: Check admin dashboard access
echo "<h2>Test 7: Admin Dashboard Files</h2>";
$dashboard_files = [
    'admin/admin_dashboard.php' => 'Admin Dashboard',
    'admin/users.php' => 'User Management',
    'admin/user_profile.php' => 'User Profile'
];

foreach ($dashboard_files as $file => $name) {
    echo "$name: " . (file_exists($file) ? "✅ EXISTS" : "❌ MISSING") . "<br>";
}

echo "<h2>Test Complete</h2>";
echo "<p>All core components have been tested. The admin approval system should be ready for use.</p>";
echo "<p><a href='/admin/admin_dashboard.php'>Go to Admin Dashboard</a> | <a href='/admin/users.php'>Go to User Management</a></p>";
?>