<?php
/**
 * Bootstrap Validation Test Script
 * Tests all bootstrap components for proper initialization
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Bootstrap Validation Test</h1>";
echo "<p>Testing: " . date('c') . "</p>";

$tests_passed = 0;
$tests_failed = 0;
$test_results = [];

function test_result($name, $passed, $message = '') {
    global $tests_passed, $tests_failed, $test_results;
    
    $status = $passed ? '✅ PASS' : '❌ FAIL';
    echo "<div style='margin: 10px 0; padding: 10px; border-left: 4px solid " . ($passed ? 'green' : 'red') . "'>";
    echo "<strong>$status:</strong> $name";
    if ($message) {
        echo "<br><small>$message</small>";
    }
    echo "</div>";
    
    if ($passed) {
        $tests_passed++;
    } else {
        $tests_failed++;
    }
    
    $test_results[] = [
        'name' => $name,
        'passed' => $passed,
        'message' => $message
    ];
}

// Test 1: Bootstrap Include
try {
    require_once __DIR__ . '/api/_bootstrap.php';
    test_result('Bootstrap Include', true, 'Successfully loaded api/_bootstrap.php');
} catch (Exception $e) {
    test_result('Bootstrap Include', false, 'Error: ' . $e->getMessage());
    exit(1);
}

// Test 2: Session Management
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        test_result('Session Management', true, 'Session started successfully');
    } else {
        test_result('Session Management', false, 'Session failed to start');
    }
} catch (Exception $e) {
    test_result('Session Management', false, 'Error: ' . $e->getMessage());
}

// Test 3: CSRF Protection
try {
    if (function_exists('get_csrf_token')) {
        $token = get_csrf_token();
        test_result('CSRF Protection', !empty($token), 'CSRF token generated: ' . substr($token, 0, 10) . '...');
    } else {
        test_result('CSRF Protection', false, 'get_csrf_token() function not found');
    }
} catch (Exception $e) {
    test_result('CSRF Protection', false, 'Error: ' . $e->getMessage());
}

// Test 4: Rate Limiting
try {
    if (function_exists('require_rate_limit')) {
        test_result('Rate Limiting', true, 'require_rate_limit() function available');
    } else {
        test_result('Rate Limiting', false, 'require_rate_limit() function not found');
    }
} catch (Exception $e) {
    test_result('Rate Limiting', false, 'Error: ' . $e->getMessage());
}

// Test 5: Database Connection
try {
    global $mysqli;
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        if ($mysqli->ping()) {
            test_result('Database Connection', true, 'Database connection active');
        } else {
            test_result('Database Connection', false, 'Database ping failed');
        }
    } else {
        test_result('Database Connection', false, 'Database connection not established');
    }
} catch (Exception $e) {
    test_result('Database Connection', false, 'Error: ' . $e->getMessage());
}

// Test 6: Required Functions Available
$required_functions = [
    'json_response',
    'log_audit_event',
    'audit_enroll',
    'audit_approve',
    'audit_trade_create',
    'get_audit_events'
];

foreach ($required_functions as $func) {
    $exists = function_exists($func);
    test_result("Function: $func", $exists, $exists ? 'Function available' : 'Function missing');
}

// Test 7: CSRF Token
try {
    $csrf_token = $_SESSION['csrf_token'] ?? null;
    test_result('CSRF Token', !empty($csrf_token), !empty($csrf_token) ? 'Token: ' . substr($csrf_token, 0, 10) . '...' : 'No token found');
} catch (Exception $e) {
    test_result('CSRF Token', false, 'Error: ' . $e->getMessage());
}

// Summary
echo "<div style='margin-top: 20px; padding: 15px; background: #f0f0f0; border-radius: 5px;'>";
echo "<h2>Test Summary</h2>";
echo "<p><strong>Total Tests:</strong> " . ($tests_passed + $tests_failed) . "</p>";
echo "<p style='color: green;'><strong>Passed:</strong> $tests_passed</p>";
echo "<p style='color: red;'><strong>Failed:</strong> $tests_failed</p>";

if ($tests_failed === 0) {
    echo "<p style='color: green; font-weight: bold;'>🎉 ALL TESTS PASSED! Bootstrap is fully functional.</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>❌ Some tests failed. Bootstrap needs attention.</p>";
}
echo "</div>";

return [
    'total_tests' => $tests_passed + $tests_failed,
    'passed' => $tests_passed,
    'failed' => $tests_failed,
    'results' => $test_results
];
?>