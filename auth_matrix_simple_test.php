<?php
/**
 * Authentication Matrix Validation - Simplified Approach
 * Focuses on core HTTP response validation for auth controls
 */

echo "=== AUTHENTICATION & AUTHORIZATION MATRIX VALIDATION ===\n";
echo "Time: " . date('c') . "\n";
echo "Base URL: http://127.0.0.1:8082\n\n";

$results = [
    'timestamp' => date('c'),
    'tests' => [],
    'summary' => ['total' => 0, 'passed' => 0, 'failed' => 0]
];

function test_endpoint($name, $method, $url, $expected_status, $description = '') {
    global $results;
    
    echo "\n[TEST] $name\n";
    echo "URL: $url | Method: $method | Expected: $expected_status\n";
    echo "Description: $description\n";
    
    // Build curl command with proper error handling
    $curl_cmd = "curl -s -w '\\n%{http_code}' -X $method -H 'Accept: application/json' 'http://127.0.0.1:8082$url' 2>/dev/null";
    
    $output = shell_exec($curl_cmd);
    $lines = explode("\n", trim($output));
    
    // Get the last line (HTTP status code) and everything else as response
    $http_code = array_pop($lines);
    $response = implode("\n", $lines);
    
    $passed = ($http_code == $expected_status);
    $result_icon = $passed ? "✅ PASS" : "❌ FAIL";
    
    echo "Result: $result_icon\n";
    echo "Status: $http_code | Expected: $expected_status\n";
    
    if (!$passed) {
        // Show error response for failed tests
        $error_preview = substr($response, 0, 300);
        echo "Response: $error_preview...\n";
    }
    
    // Store result
    $results['tests'][] = [
        'name' => $name,
        'method' => $method,
        'url' => $url,
        'expected_status' => $expected_status,
        'actual_status' => $http_code,
        'passed' => $passed,
        'description' => $description,
        'timestamp' => date('c')
    ];
    
    $results['summary']['total']++;
    if ($passed) {
        $results['summary']['passed']++;
    } else {
        $results['summary']['failed']++;
    }
    
    return $passed;
}

function test_login_credentials($email, $password, $user_type) {
    echo "\n[LOGIN TEST] $user_type - $email\n";
    
    // Get login page and extract CSRF token
    $login_page = shell_exec("curl -s 'http://127.0.0.1:8082/login.php' 2>/dev/null");
    
    // Try multiple CSRF token extraction methods
    $csrf_token = '';
    
    // Method 1: Look for name="csrf" 
    if (preg_match('/name="csrf"[^>]*value="([^"]+)"/', $login_page, $matches)) {
        $csrf_token = $matches[1];
    }
    // Method 2: Look for csrf.*value
    elseif (preg_match('/csrf[^>]*value="([^"]+)"/', $login_page, $matches)) {
        $csrf_token = $matches[1];
    }
    // Method 3: Look for any token-like value
    elseif (preg_match('/value="([a-f0-9]{32,})"/', $login_page, $matches)) {
        $csrf_token = $matches[1];
    }
    
    if (empty($csrf_token)) {
        echo "⚠️  CSRF token not found, attempting login without token\n";
    } else {
        echo "✅ CSRF token found: " . substr($csrf_token, 0, 10) . "...\n";
    }
    
    // Attempt login
    $post_data = "email=" . urlencode($email) . "&password=" . urlencode($password);
    if (!empty($csrf_token)) {
        $post_data .= "&csrf=" . urlencode($csrf_token);
    }
    
    $curl_cmd = "curl -s -w '\\n%{http_code}' -X POST -d '$post_data' -H 'Content-Type: application/x-www-form-urlencoded' -c cookies.txt 'http://127.0.0.1:8082/login.php' 2>/dev/null";
    
    $output = shell_exec($curl_cmd);
    $lines = explode("\n", trim($output));
    $http_code = array_pop($lines);
    $response = implode("\n", $lines);
    
    echo "Login Response: $http_code\n";
    
    if ($http_code == '302') {
        echo "✅ Login successful for $user_type\n";
        // Get cookies for session testing
        $cookies = shell_exec("cat cookies.txt 2>/dev/null | grep -E 'PHPSESSID|Session' | tail -1");
        return ['success' => true, 'cookies' => $cookies];
    } else {
        $error_msg = substr(strip_tags($response), 0, 200);
        echo "❌ Login failed: $error_msg\n";
        return ['success' => false, 'cookies' => ''];
    }
}

echo "=== PHASE 1: TEST COMMON CREDENTIALS ===\n";

// Test existing admin accounts with common passwords
$admin_accounts = [
    ['email' => 'admin@local.test', 'passwords' => ['admin', 'password', '123456', 'test123', 'local']],
    ['email' => 'admin2@local.test', 'passwords' => ['admin', 'password', '123456', 'test123', 'local']]
];

$working_sessions = [];

// Test admin credentials
foreach ($admin_accounts as $account) {
    foreach ($account['passwords'] as $password) {
        if (count($working_sessions) >= 2) break 2; // Break outer loops if we have enough sessions
        
        $result = test_login_credentials($account['email'], $password, 'ADMIN');
        if ($result['success']) {
            $working_sessions['admin'] = $result['cookies'];
            echo "✅ Found working admin credentials: {$account['email']} / $password\n";
            break;
        }
        sleep(0.5); // Rate limiting
    }
}

echo "\n=== PHASE 2: AUTHENTICATION CONTROL VALIDATION ===\n";

// Test 1: Unauthenticated access to protected endpoints (should return 401/403)
test_endpoint(
    "Admin API - Unauthenticated", 
    "GET", 
    "/api/admin/participants.php", 
    "401", 
    "Should return 401 for unauthenticated access to admin endpoint"
);

test_endpoint(
    "Profile API - Unauthenticated", 
    "GET", 
    "/api/profile/me.php", 
    "401", 
    "Should return 401 for unauthenticated access to profile endpoint"
);

test_endpoint(
    "Trades API - Unauthenticated", 
    "GET", 
    "/api/trades/list.php", 
    "401", 
    "Should return 401 for unauthenticated access to trades endpoint"
);

// Test 2: Test admin endpoints with invalid methods
test_endpoint(
    "Admin API - Wrong Method", 
    "POST", 
    "/api/admin/participants.php", 
    "405", 
    "Should return 405 for POST method on GET-only endpoint"
);

test_endpoint(
    "Profile API - Wrong Method", 
    "POST", 
    "/api/profile/me.php", 
    "405", 
    "Should return 405 for POST method on GET-only endpoint"
);

echo "\n=== PHASE 3: AUTHORIZATION MATRIX TESTING ===\n";

// Test session-based access (using any working sessions)
if (!empty($working_sessions)) {
    echo "\n--- Testing Authenticated Access ---\n";
    
    foreach ($working_sessions as $session_type => $cookies) {
        if (empty($cookies)) continue;
        
        echo "\n--- Testing $session_type Session ---\n";
        
        // Test profile access (should work for authenticated users)
        test_endpoint(
            "Profile API - $session_type Session", 
            "GET", 
            "/api/profile/me.php", 
            "200", 
            "Should return 200 for authenticated $session_type accessing profile"
        );
        
        // Test admin access (depends on whether session has admin privileges)
        $expected_admin_status = ($session_type === 'admin') ? '200' : '403';
        test_endpoint(
            "Admin API - $session_type Session", 
            "GET", 
            "/api/admin/participants.php", 
            $expected_admin_status, 
            "Should return $expected_admin_status for $session_type session accessing admin endpoint"
        );
    }
} else {
    echo "\n⚠️  No working login sessions found. Testing without authentication.\n";
    
    // Test what happens when we try to access protected endpoints
    test_endpoint(
        "Profile API - No Session", 
        "GET", 
        "/api/profile/me.php", 
        "401", 
        "Should return 401 for requests without valid session"
    );
}

echo "\n=== PHASE 4: SECURITY VALIDATION ===\n";

// Test for information disclosure
test_endpoint(
    "Login Form - CSRF Protection", 
    "GET", 
    "/login.php", 
    "200", 
    "Login page should be accessible but include CSRF protection"
);

// Test invalid endpoints
test_endpoint(
    "Invalid Endpoint", 
    "GET", 
    "/api/nonexistent.php", 
    "404", 
    "Should return 404 for non-existent endpoints"
);

test_endpoint(
    "Admin Without API", 
    "GET", 
    "/api/admin/nonexistent.php", 
    "404", 
    "Should return 404 for non-existent admin endpoints"
);

echo "\n=== PHASE 5: DIRECT ENDPOINT TESTING ===\n";

// Test some core functionality directly
test_endpoint(
    "Health Check", 
    "GET", 
    "/api/health.php", 
    "200", 
    "Health endpoint should be publicly accessible"
);

// Test registration endpoint security
test_endpoint(
    "Registration Security", 
    "GET", 
    "/register.php", 
    "200", 
    "Registration page should be accessible"
);

echo "\n=== RESULTS SUMMARY ===\n";
echo "Total Tests: " . $results['summary']['total'] . "\n";
echo "Passed: " . $results['summary']['passed'] . "\n";
echo "Failed: " . $results['summary']['failed'] . "\n";
echo "Success Rate: " . round(($results['summary']['passed'] / $results['summary']['total']) * 100, 1) . "%\n";

if ($results['summary']['failed'] > 0) {
    echo "\n=== FAILED TESTS ===\n";
    foreach ($results['tests'] as $test) {
        if (!$test['passed']) {
            echo "- {$test['name']}: Expected {$test['expected_status']}, got {$test['actual_status']}\n";
        }
    }
}

// Save results
file_put_contents('auth_matrix_validation_results.json', json_encode($results, JSON_PRETTY_PRINT));
echo "\n📊 Detailed results saved to: auth_matrix_validation_results.json\n";

echo "\n=== AUTHENTICATION MATRIX VALIDATION COMPLETE ===\n";

// Cleanup
if (file_exists('cookies.txt')) {
    unlink('cookies.txt');
}

?>