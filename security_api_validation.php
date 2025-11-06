<?php
/**
 * Phase 3 - Security & API Validation
 * Comprehensive endpoint and security testing
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Phase 3 - Security & API Validation</h1>";
echo "<p>Generated: " . date('c') . "</p>";

$validation_results = [
    'endpoint_tests' => [],
    'security_tests' => [],
    'csrf_tests' => [],
    'rate_limit_tests' => [],
    'audit_tests' => [],
    'errors' => []
];

// Base URL for local testing
$base_url = 'http://localhost';

// Test API endpoint
function test_endpoint($endpoint, $method = 'GET', $data = [], $headers = []) {
    global $base_url, $validation_results;
    
    $url = $base_url . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
    }
    
    if (!empty($headers)) {
        $header_array = [];
        foreach ($headers as $key => $value) {
            $header_array[] = "$key: $value";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header_array);
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    curl_close($ch);
    
    $headers_raw = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    $headers = [];
    foreach (explode("\n", $headers_raw) as $header_line) {
        if (strpos($header_line, ':') !== false) {
            list($key, $value) = explode(':', $header_line, 2);
            $headers[trim($key)] = trim($value);
        }
    }
    
    $json_valid = false;
    $json_data = null;
    
    if (!empty($body)) {
        $json_data = json_decode($body, true);
        $json_valid = (json_last_error() === JSON_ERROR_NONE);
    }
    
    return [
        'http_code' => $http_code,
        'body' => $body,
        'headers' => $headers,
        'json_valid' => $json_valid,
        'json_data' => $json_data
    ];
}

// Test 1: Core API Endpoints
echo "<h2>Core API Endpoint Tests</h2>";

$core_endpoints = [
    ['url' => '/api/health.php', 'method' => 'GET', 'expected_code' => 200],
    ['url' => '/api/trades/list.php', 'method' => 'GET', 'expected_code' => 200],
    ['url' => '/api/mtm/enroll.php', 'method' => 'GET', 'expected_code' => 200],
    ['url' => '/api/admin/enrollment/approve.php', 'method' => 'GET', 'expected_code' => 200],
    ['url' => '/api/admin/audit_log.php', 'method' => 'GET', 'expected_code' => 200]
];

foreach ($core_endpoints as $test) {
    echo "<h3>Testing: {$test['url']}</h3>";
    
    $result = test_endpoint($test['url'], $test['method']);
    
    $passed = (
        $result['http_code'] === $test['expected_code'] &&
        $result['json_valid'] === true &&
        isset($result['json_data']['success'])
    );
    
    $validation_results['endpoint_tests'][] = [
        'url' => $test['url'],
        'method' => $test['method'],
        'expected_code' => $test['expected_code'],
        'actual_code' => $result['http_code'],
        'json_valid' => $result['json_valid'],
        'passed' => $passed
    ];
    
    $status = $passed ? '✅ PASS' : '❌ FAIL';
    $code_info = "Expected: {$test['expected_code']}, Got: {$result['http_code']}";
    $json_info = $result['json_valid'] ? 'Valid JSON' : 'Invalid JSON';
    
    echo "<div style='margin: 5px 0; padding: 5px; border: 1px solid " . ($passed ? 'green' : 'red') . "'>";
    echo "$status - {$test['url']} - $code_info - $json_info";
    if (!$result['json_valid'] && !empty($result['body'])) {
        echo "<br><small>Response: " . htmlspecialchars(substr($result['body'], 0, 200)) . "...</small>";
    }
    echo "</div>";
}

// Test 2: CSRF Protection
echo "<h2>CSRF Protection Tests</h2>";

// Test without CSRF token
$result = test_endpoint('/api/mtm/enroll.php', 'POST', ['test' => 'data']);
$csrf_test = ($result['http_code'] === 403 || $result['http_code'] === 400);
$validation_results['csrf_tests']['no_token'] = $csrf_test;

echo "<h3>Test 1: POST without CSRF token</h3>";
echo "<div style='margin: 5px 0; padding: 5px; border: 1px solid " . ($csrf_test ? 'green' : 'red') . "'>";
echo ($csrf_test ? '✅ PASS' : '❌ FAIL') . " - CSRF protection active (HTTP " . $result['http_code'] . ")";
echo "</div>";

// Test 3: Rate Limiting Headers
echo "<h2>Rate Limiting Validation</h2>";

// Make multiple requests to test rate limiting
$rate_limit_tests = [];
for ($i = 0; $i < 3; $i++) {
    $result = test_endpoint('/api/mtm/enroll.php', 'GET');
    $rate_limit_tests[] = [
        'request' => $i + 1,
        'http_code' => $result['http_code'],
        'rate_limit_limit' => $result['headers']['X-RateLimit-Limit'] ?? null,
        'rate_limit_remaining' => $result['headers']['X-RateLimit-Remaining'] ?? null,
        'has_headers' => isset($result['headers']['X-RateLimit-Limit'])
    ];
}

$validation_results['rate_limit_tests'] = $rate_limit_tests;

echo "<h3>Rate Limit Headers Check</h3>";
foreach ($rate_limit_tests as $test) {
    $has_headers = $test['has_headers'] ? '✅ Headers present' : '❌ Headers missing';
    echo "<div style='margin: 3px 0;'>Request {$test['request']}: HTTP {$test['http_code']} - $has_headers";
    if ($test['has_headers']) {
        echo " (Limit: {$test['rate_limit_limit']}, Remaining: {$test['rate_limit_remaining']})";
    }
    echo "</div>";
}

// Test 4: Audit Trail Functionality
echo "<h2>Audit Trail Tests</h2>";

require_once __DIR__ . '/api/_bootstrap.php';

$audit_tests = [];

// Test audit logging functions
try {
    $event_id = log_audit_event(1, 'test_action', 'test_entity', 1, 'Test audit event');
    $audit_tests['log_audit_event'] = ($event_id !== false);
} catch (Exception $e) {
    $audit_tests['log_audit_event'] = false;
    echo "<p style='color: red;'>❌ log_audit_event failed: " . $e->getMessage() . "</p>";
}

// Test audit retrieval
try {
    $events = get_audit_events(['action' => 'test_action'], 5);
    $audit_tests['get_audit_events'] = (isset($events['events']) && is_array($events['events']));
} catch (Exception $e) {
    $audit_tests['get_audit_events'] = false;
    echo "<p style='color: red;'>❌ get_audit_events failed: " . $e->getMessage() . "</p>";
}

$validation_results['audit_tests'] = $audit_tests;

foreach ($audit_tests as $test => $passed) {
    $status = $passed ? '✅ PASS' : '❌ FAIL';
    echo "<div style='margin: 3px 0; padding: 3px; border: 1px solid " . ($passed ? 'green' : 'red') . "'>";
    echo "$status - $test";
    echo "</div>";
}

// Test 5: Bootstrap Validation
echo "<h2>Bootstrap Validation</h2>";

$bootstrap_test = require __DIR__ . '/bootstrap_validation_test.php';
$validation_results['bootstrap_validation'] = $bootstrap_test;

echo "<h3>Bootstrap Components</h3>";
foreach ($bootstrap_test['results'] as $result) {
    $status = $result['passed'] ? '✅' : '❌';
    echo "<div style='margin: 2px 0;'>$status {$result['name']}</div>";
}

// Summary
echo "<h2>Security & API Validation Summary</h2>";

$passed_endpoints = array_filter($validation_results['endpoint_tests'], function($test) {
    return $test['passed'];
});
$endpoint_count = count($validation_results['endpoint_tests']);
$endpoint_passed = count($passed_endpoints);

$csrf_count = count($validation_results['csrf_tests']);
$csrf_passed = count(array_filter($validation_results['csrf_tests']));

$audit_count = count($validation_results['audit_tests']);
$audit_passed = count(array_filter($validation_results['audit_tests']));

$bootstrap_passed = $bootstrap_test['passed'];
$bootstrap_failed = $bootstrap_test['failed'];

echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "<h3>Test Results</h3>";
echo "<p><strong>Core API Endpoints:</strong> $endpoint_passed/$endpoint_count passed</p>";
echo "<p><strong>CSRF Protection:</strong> $csrf_passed/$csrf_count passed</p>";
echo "<p><strong>Audit Trail:</strong> $audit_passed/$audit_count passed</p>";
echo "<p><strong>Bootstrap Validation:</strong> $bootstrap_passed passed, $bootstrap_failed failed</p>";

$total_passed = $endpoint_passed + $csrf_passed + $audit_passed + $bootstrap_passed;
$total_tests = $endpoint_count + $csrf_count + $audit_count + ($bootstrap_passed + $bootstrap_failed);

if ($total_passed >= $total_tests * 0.8) {
    echo "<p style='color: green; font-weight: bold;'>🎉 Phase C Security Validation: PASSED</p>";
} else {
    echo "<p style='color: orange; font-weight: bold;'>⚠️ Phase C Security Validation: NEEDS ATTENTION</p>";
}
echo "</div>";

return $validation_results;
?>