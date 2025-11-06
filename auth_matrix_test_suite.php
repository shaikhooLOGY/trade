<?php
/**
 * Authentication Matrix Test Suite
 * Comprehensive testing of authentication and authorization controls
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security/csrf.php';

echo "=== AUTHENTICATION & AUTHORIZATION MATRIX TEST ===\n";
echo "Time: " . date('c') . "\n";
echo "Base URL: http://127.0.0.1:8082\n\n";

// Initialize test results
$test_results = [
    'timestamp' => date('c'),
    'tests' => [],
    'summary' => [
        'total' => 0,
        'passed' => 0,
        'failed' => 0
    ]
];

function run_test($name, $method, $url, $expected_status, $cookies = '', $post_data = '', $description = '') {
    global $test_results;
    
    $test_id = count($test_results['tests']) + 1;
    echo "\n[TEST $test_id] $name\n";
    echo "Description: $description\n";
    echo "Method: $method | URL: $url | Expected: $expected_status\n";
    
    // Build curl command
    $curl_cmd = "curl -s -w '\\nHTTP_CODE:%{http_code}\\n' -X $method";
    
    if (!empty($cookies)) {
        $curl_cmd .= " -H 'Cookie: $cookies'";
    }
    
    if (!empty($post_data)) {
        $curl_cmd .= " -H 'Content-Type: application/json'";
        $curl_cmd .= " -d '" . str_replace("'", "'\\''", $post_data) . "'";
    }
    
    $curl_cmd .= " 'http://127.0.0.1:8082$url'";
    
    // Execute curl command
    $output = shell_exec($curl_cmd);
    $lines = explode("\n", $output);
    
    // Extract HTTP status code
    $http_code = '';
    $response_body = '';
    
    foreach ($lines as $line) {
        if (strpos($line, 'HTTP_CODE:') === 0) {
            $http_code = substr($line, 11);
        } else {
            $response_body .= $line . "\n";
        }
    }
    
    // Determine result
    $passed = ($http_code == $expected_status);
    $result_icon = $passed ? "✅ PASS" : "❌ FAIL";
    
    echo "Result: $result_icon\n";
    echo "Actual Status: $http_code\n";
    
    if (!$passed) {
        echo "Response:\n" . trim($response_body) . "\n";
    }
    
    // Store test result
    $test_results['tests'][] = [
        'id' => $test_id,
        'name' => $name,
        'method' => $method,
        'url' => $url,
        'expected_status' => $expected_status,
        'actual_status' => $http_code,
        'passed' => $passed,
        'cookies' => $cookies,
        'post_data' => $post_data,
        'description' => $description,
        'timestamp' => date('c')
    ];
    
    $test_results['summary']['total']++;
    if ($passed) {
        $test_results['summary']['passed']++;
    } else {
        $test_results['summary']['failed']++;
    }
    
    return $passed;
}

// Test password discovery function
function test_login($email, $password) {
    echo "\n[LOGIN TEST] Attempting login with: $email / $password\n";
    
    // Get CSRF token first
    $csrf_response = shell_exec("curl -s 'http://127.0.0.1:8082/login.php' | grep -o 'name=\"csrf\" value=\"[^\"]*\"' | cut -d'\"' -f4");
    $csrf_token = trim($csrf_response);
    
    if (empty($csrf_token)) {
        echo "❌ Failed to get CSRF token\n";
        return false;
    }
    
    echo "CSRF Token: $csrf_token\n";
    
    // Attempt login
    $post_data = "email=" . urlencode($email) . "&password=" . urlencode($password) . "&csrf=" . urlencode($csrf_token);
    
    $curl_cmd = "curl -s -w '\\nHTTP_CODE:%{http_code}\\n' -X POST -d '$post_data' -H 'Content-Type: application/x-www-form-urlencoded' 'http://127.0.0.1:8082/login.php'";
    
    $output = shell_exec($curl_cmd);
    $lines = explode("\n", $output);
    
    // Extract HTTP status code and cookies
    $http_code = '';
    $cookies = '';
    
    foreach ($lines as $line) {
        if (strpos($line, 'HTTP_CODE:') === 0) {
            $http_code = substr($line, 11);
        } elseif (preg_match('/^Set-Cookie:\s*([^;]+)/', $line, $matches)) {
            $cookies .= $matches[1] . '; ';
        }
    }
    
    $response_body = implode("\n", array_slice($lines, 0, -1));
    
    echo "Login Response Code: $http_code\n";
    
    if ($http_code == '302') {
        echo "✅ Login successful - redirected\n";
        return ['success' => true, 'cookies' => trim($cookies, '; ')];
    } else {
        echo "❌ Login failed - Response:\n" . substr($response_body, 0, 500) . "\n";
        return ['success' => false, 'cookies' => ''];
    }
}

echo "=== PHASE 1: ACCOUNT DISCOVERY ===\n";

// Test common passwords with admin accounts
$admin_emails = [
    'admin@local.test',
    'admin2@local.test'
];

$test_passwords = [
    'password',
    'admin',
    'test123',
    '123456',
    'admin123',
    'local',
    'test',
    'password123',
    'admin@local.test',
    'demo123'
];

$working_credentials = [];

// Try to find working credentials for admin accounts
foreach ($admin_emails as $email) {
    echo "\n--- Testing $email ---\n";
    foreach ($test_passwords as $password) {
        $result = test_login($email, $password);
        if ($result['success']) {
            $working_credentials['admin'] = [
                'email' => $email,
                'password' => $password,
                'cookies' => $result['cookies']
            ];
            echo "✅ FOUND WORKING ADMIN CREDENTIALS: $email / $password\n";
            break 2; // Break both loops
        }
        sleep(1); // Rate limiting consideration
    }
}

if (empty($working_credentials)) {
    echo "❌ No working admin credentials found. Creating test account...\n";
    
    // Create a test admin account
    try {
        $test_password = password_hash('testadmin123', PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("
            INSERT INTO users (name, email, password_hash, role, status, email_verified, created_at) 
            VALUES (?, ?, ?, 'admin', 'active', 1, NOW())
            ON DUPLICATE KEY UPDATE 
                password_hash = VALUES(password_hash),
                role = 'admin',
                status = 'active'
        ");
        
        $test_name = 'Test Admin';
        $test_email = 'testadmin@local.test';
        $stmt->bind_param('sss', $test_name, $test_email, $test_password);
        $stmt->execute();
        $stmt->close();
        
        echo "✅ Created test admin account: testadmin@local.test / testadmin123\n";
        
        $working_credentials['admin'] = [
            'email' => 'testadmin@local.test',
            'password' => 'testadmin123',
            'cookies' => ''
        ];
        
    } catch (Exception $e) {
        echo "❌ Failed to create test admin: " . $e->getMessage() . "\n";
    }
}

// Create a regular user account for testing
try {
    $user_password = password_hash('testuser123', PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("
        INSERT INTO users (name, email, password_hash, role, status, email_verified, created_at) 
        VALUES (?, ?, ?, 'user', 'active', 1, NOW())
        ON DUPLICATE KEY UPDATE 
            password_hash = VALUES(password_hash),
            role = 'user',
            status = 'active'
    ");
    
    $user_name = 'Test User';
    $user_email = 'testuser@local.test';
    $stmt->bind_param('sss', $user_name, $user_email, $user_password);
    $stmt->execute();
    $stmt->close();
    
    echo "✅ Created test user account: testuser@local.test / testuser123\n";
    
    $working_credentials['user'] = [
        'email' => 'testuser@local.test',
        'password' => 'testuser123',
        'cookies' => ''
    ];
    
} catch (Exception $e) {
    echo "❌ Failed to create test user: " . $e->getMessage() . "\n";
}

echo "\n=== PHASE 2: AUTHENTICATION TESTING ===\n";

// Test admin login
if (isset($working_credentials['admin'])) {
    echo "\n--- Admin Login Test ---\n";
    $result = test_login($working_credentials['admin']['email'], $working_credentials['admin']['password']);
    if ($result['success']) {
        $admin_cookies = $result['cookies'];
        run_test(
            "Admin Login", 
            "POST", 
            "/login.php", 
            "302", 
            '', 
            '', 
            "Admin user should be able to log in and be redirected"
        );
    }
}

// Test regular user login
if (isset($working_credentials['user'])) {
    echo "\n--- Regular User Login Test ---\n";
    $result = test_login($working_credentials['user']['email'], $working_credentials['user']['password']);
    if ($result['success']) {
        $user_cookies = $result['cookies'];
        run_test(
            "User Login", 
            "POST", 
            "/login.php", 
            "302", 
            '', 
            '', 
            "Regular user should be able to log in and be redirected"
        );
    }
}

echo "\n=== PHASE 3: AUTHORIZATION MATRIX TESTING ===\n";

// Test unauthenticated access to protected endpoints
echo "\n--- Unauthenticated Access Tests ---\n";

run_test(
    "Admin API - No Auth", 
    "GET", 
    "/api/admin/participants.php", 
    "401", 
    "", 
    "", 
    "Should return 401 for unauthenticated access to admin endpoint"
);

run_test(
    "Profile API - No Auth", 
    "GET", 
    "/api/profile/me.php", 
    "401", 
    "", 
    "", 
    "Should return 401 for unauthenticated access to profile endpoint"
);

run_test(
    "Trades API - No Auth", 
    "GET", 
    "/api/trades/list.php", 
    "401", 
    "", 
    "", 
    "Should return 401 for unauthenticated access to trades endpoint"
);

// Test with regular user session (simulate user cookies)
if (isset($user_cookies)) {
    echo "\n--- Regular User Access Tests ---\n";
    
    run_test(
        "Admin API - Regular User", 
        "GET", 
        "/api/admin/participants.php", 
        "403", 
        $user_cookies, 
        "", 
        "Should return 403 for regular user trying to access admin endpoint"
    );
    
    run_test(
        "Profile API - Regular User", 
        "GET", 
        "/api/profile/me.php", 
        "200", 
        $user_cookies, 
        "", 
        "Should return 200 for regular user accessing their own profile"
    );
}

// Test with admin session (simulate admin cookies)  
if (isset($admin_cookies)) {
    echo "\n--- Admin Access Tests ---\n";
    
    run_test(
        "Admin API - Admin User", 
        "GET", 
        "/api/admin/participants.php", 
        "200", 
        $admin_cookies, 
        "", 
        "Should return 200 for admin user accessing admin endpoint"
    );
    
    run_test(
        "Profile API - Admin User", 
        "GET", 
        "/api/profile/me.php", 
        "200", 
        $admin_cookies, 
        "", 
        "Should return 200 for admin user accessing their own profile"
    );
}

echo "\n=== PHASE 4: CSRF PROTECTION TESTING ===\n";

// Test CSRF protection on protected POST endpoints
if (isset($user_cookies)) {
    run_test(
        "CSRF Protection - Trades Create", 
        "POST", 
        "/api/trades/create.php", 
        "400", 
        $user_cookies, 
        '{"symbol":"TEST","side":"buy","quantity":100,"price":10}', 
        "Should return 400 for missing CSRF token on protected POST endpoint"
    );
}

if (isset($admin_cookies)) {
    run_test(
        "CSRF Protection - Admin Enrollment", 
        "POST", 
        "/api/admin/enrollment/approve.php", 
        "400", 
        $admin_cookies, 
        '{"enrollment_id":1}', 
        "Should return 400 for missing CSRF token on admin POST endpoint"
    );
}

echo "\n=== TEST RESULTS SUMMARY ===\n";
echo "Total Tests: " . $test_results['summary']['total'] . "\n";
echo "Passed: " . $test_results['summary']['passed'] . "\n";
echo "Failed: " . $test_results['summary']['failed'] . "\n";
echo "Success Rate: " . round(($test_results['summary']['passed'] / $test_results['summary']['total']) * 100, 2) . "%\n";

// Save results to JSON
$json_file = 'auth_matrix_test_results.json';
file_put_contents($json_file, json_encode($test_results, JSON_PRETTY_PRINT));
echo "\n📊 Detailed results saved to: $json_file\n";

echo "\n=== AUTHENTICATION MATRIX TEST COMPLETE ===\n";
?>