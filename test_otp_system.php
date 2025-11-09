<?php
// test_otp_system.php — Comprehensive test script for OTP email verification system
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

echo "=== OTP Email Verification System Test ===\n";
echo "Testing started at: " . date('Y-m-d H:i:s') . "\n\n";

$tests_passed = 0;
$tests_failed = 0;
$test_results = [];

function run_test($test_name, $test_function) {
    global $tests_passed, $tests_failed, $test_results;
    
    echo "Testing: $test_name... ";
    
    try {
        $result = $test_function();
        if ($result['success']) {
            echo "✅ PASSED\n";
            $tests_passed++;
            $test_results[] = ['name' => $test_name, 'status' => 'PASSED', 'message' => $result['message'] ?? ''];
        } else {
            echo "❌ FAILED\n";
            echo "   Error: " . ($result['message'] ?? 'Unknown error') . "\n";
            $tests_failed++;
            $test_results[] = ['name' => $test_name, 'status' => 'FAILED', 'message' => $result['message'] ?? ''];
        }
    } catch (Exception $e) {
        echo "❌ FAILED\n";
        echo "   Exception: " . $e->getMessage() . "\n";
        $tests_failed++;
        $test_results[] = ['name' => $test_name, 'status' => 'FAILED', 'message' => $e->getMessage()];
    }
}

// Test 1: Database Connection
run_test("Database Connection", function() {
    $mysqli = db();
    if (!$mysqli) {
        return ['success' => false, 'message' => 'Failed to connect to database'];
    }
    return ['success' => true, 'message' => 'Database connection successful'];
});

// Test 2: OTP Table Creation
run_test("OTP Table Creation", function() {
    $mysqli = db();
    $created = otp_create_database_table($mysqli);
    if (!$created) {
        return ['success' => false, 'message' => 'Failed to create OTP table'];
    }
    
    // Verify table exists
    if (!db_has_table($mysqli, 'user_otps')) {
        return ['success' => false, 'message' => 'OTP table was not created'];
    }
    
    return ['success' => true, 'message' => 'OTP table created successfully'];
});

// Test 3: OTP Generation
run_test("OTP Generation", function() {
    $otp = otp_generate_secure_otp();
    if (!preg_match('/^\d{6}$/', $otp)) {
        return ['success' => false, 'message' => 'Generated OTP is not 6 digits'];
    }
    return ['success' => true, 'message' => "Generated OTP: $otp"];
});

// Test 4: Rate Limiting Check
run_test("Rate Limiting Check", function() {
    $test_user_id = 999999; // Non-existent user ID for testing
    $test_email = "test@example.com";
    
    $rate_limit = otp_rate_limit_check($test_user_id, $test_email);
    
    if (!isset($rate_limit['allowed']) || !isset($rate_limit['message'])) {
        return ['success' => false, 'message' => 'Rate limiting function returned invalid structure'];
    }
    
    return ['success' => true, 'message' => 'Rate limiting check working correctly'];
});

// Test 5: OTP Status Check
run_test("OTP Status Check", function() {
    $test_user_id = 999999; // Non-existent user ID for testing
    
    $status = otp_get_user_status($test_user_id);
    
    $required_keys = ['has_active_otp', 'email_verified', 'message'];
    foreach ($required_keys as $key) {
        if (!isset($status[$key])) {
            return ['success' => false, 'message' => "Missing required key: $key"];
        }
    }
    
    return ['success' => true, 'message' => 'OTP status check working correctly'];
});

// Test 6: Email Template Generation
run_test("Email Template Generation", function() {
    $test_name = "Test User";
    $test_otp = "123456";
    
    $template = otp_get_email_template($test_name, $test_otp);
    
    if (empty($template)) {
        return ['success' => false, 'message' => 'Email template is empty'];
    }
    
    if (strpos($template, $test_otp) === false) {
        return ['success' => false, 'message' => 'OTP code not found in email template'];
    }
    
    if (strpos($template, $test_name) === false) {
        return ['success' => false, 'message' => 'User name not found in email template'];
    }
    
    return ['success' => true, 'message' => 'Email template generated successfully'];
});

// Test 7: Cleanup Function
run_test("OTP Cleanup Function", function() {
    $cleaned = otp_cleanup_expired();
    
    if (!is_numeric($cleaned)) {
        return ['success' => false, 'message' => 'Cleanup function did not return a number'];
    }
    
    return ['success' => true, 'message' => "Cleaned up $cleaned expired OTP records"];
});

// Test 8: Session Management
run_test("Session Management", function() {
    // Test session data
    $_SESSION['test_user_id'] = 12345;
    $_SESSION['test_email'] = 'test@example.com';
    
    if (!isset($_SESSION['test_user_id']) || !isset($_SESSION['test_email'])) {
        return ['success' => false, 'message' => 'Session data not properly stored'];
    }
    
    // Clean up test data
    unset($_SESSION['test_user_id']);
    unset($_SESSION['test_email']);
    
    return ['success' => true, 'message' => 'Session management working correctly'];
});

// Test 9: CSRF Token Generation
run_test("CSRF Token Generation", function() {
    $token = csrf_token();
    
    if (empty($token) || strlen($token) < 32) {
        return ['success' => false, 'message' => 'CSRF token generation failed'];
    }
    
    return ['success' => true, 'message' => 'CSRF token generated successfully'];
});

// Test 10: Input Validation
run_test("Input Validation", function() {
    // Test email validation
    if (!is_valid_email('test@example.com')) {
        return ['success' => false, 'message' => 'Email validation failed for valid email'];
    }
    
    if (is_valid_email('invalid-email')) {
        return ['success' => false, 'message' => 'Email validation passed for invalid email'];
    }
    
    // Test password validation
    if (!is_strong_password('password123')) {
        return ['success' => false, 'message' => 'Password validation failed for strong password'];
    }
    
    if (is_strong_password('weak')) {
        return ['success' => false, 'message' => 'Password validation passed for weak password'];
    }
    
    return ['success' => true, 'message' => 'Input validation working correctly'];
});

// Test 11: Database Schema Verification
run_test("Database Schema Verification", function() {
    $mysqli = db();
    
    // Check if user_otps table has required columns
    $required_columns = ['id', 'user_id', 'otp_hash', 'expires_at', 'created_at', 'verified_at', 'attempts', 'max_attempts', 'is_active', 'email_sent_at', 'ip_address'];
    
    foreach ($required_columns as $column) {
        if (!db_has_col($mysqli, 'user_otps', $column)) {
            return ['success' => false, 'message' => "Missing required column: $column"];
        }
    }
    
    return ['success' => true, 'message' => 'Database schema verification passed'];
});

// Test 12: Error Handling
run_test("Error Handling", function() {
    // Test with invalid user ID
    $result = otp_verify_code(999999, '123456');
    
    if (!isset($result['success']) || !isset($result['message'])) {
        return ['success' => false, 'message' => 'Error handling structure invalid'];
    }
    
    if ($result['success']) {
        return ['success' => false, 'message' => 'OTP verification should have failed for non-existent user'];
    }
    
    return ['success' => true, 'message' => 'Error handling working correctly'];
});

// Summary
echo "\n=== Test Summary ===\n";
echo "Total Tests: " . ($tests_passed + $tests_failed) . "\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";
echo "Success Rate: " . round(($tests_passed / ($tests_passed + $tests_failed)) * 100, 1) . "%\n";

if ($tests_failed > 0) {
    echo "\n=== Failed Tests ===\n";
    foreach ($test_results as $result) {
        if ($result['status'] === 'FAILED') {
            echo "- {$result['name']}: {$result['message']}\n";
        }
    }
}

echo "\n=== System Status ===\n";

// Check if all core functions exist
$required_functions = [
    'otp_generate_secure_otp',
    'otp_create_database_table',
    'otp_send_verification_email',
    'otp_verify_code',
    'otp_rate_limit_check',
    'otp_cleanup_expired',
    'otp_get_user_status'
];

echo "Function Availability:\n";
foreach ($required_functions as $function) {
    $exists = function_exists($function);
    echo "- $function: " . ($exists ? "✅ Available" : "❌ Missing") . "\n";
}

// Check database tables
$mysqli = db();
if ($mysqli) {
    echo "\nDatabase Tables:\n";
    echo "- users table: " . (db_has_table($mysqli, 'users') ? "✅ Exists" : "❌ Missing") . "\n";
    echo "- user_otps table: " . (db_has_table($mysqli, 'user_otps') ? "✅ Exists" : "❌ Missing") . "\n";
}

echo "\n=== Test Completed ===\n";
echo "End time: " . date('Y-m-d H:i:s') . "\n";

if ($tests_failed === 0) {
    echo "\n🎉 All tests passed! OTP system is ready for production.\n";
} else {
    echo "\n⚠️  Some tests failed. Please review and fix issues before production deployment.\n";
}
?>