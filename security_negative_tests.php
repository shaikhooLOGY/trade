<?php
/**
 * Phase 3 Security & Negative Tests
 * Tests CSRF, rate limiting, unauthorized access, and replay attacks
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment and config
require_once 'includes/env.php';
require_once 'config.php';

$base_url = 'http://localhost:8082';
$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'base_url' => $base_url,
    'tests' => [],
    'summary' => [
        'total' => 0,
        'passed' => 0,
        'failed' => 0
    ]
];

echo "=== PHASE 3 SECURITY & NEGATIVE TESTS ===\n";
echo "Timestamp: " . $results['timestamp'] . "\n";
echo "Base URL: {$base_url}\n\n";

function testEndpoint($url, $options = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
    
    if (isset($options['method'])) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $options['method']);
    }
    
    if (isset($options['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
    }
    
    if (isset($options['post_data'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['post_data']);
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['response' => $response, 'http_code' => $http_code];
}

// Test 1: CSRF Invalid Token
echo "1. CSRF TOKEN VALIDATION\n";
echo str_repeat("-", 50) . "\n";

$test_name = "CSRF Invalid Token Test";
$results['summary']['total']++;

$test_url = $base_url . '/api/trades/list.php';
$result = testEndpoint($test_url, [
    'headers' => [
        'X-CSRF-Token: invalid_token_12345',
        'User-Agent: Phase3-Security/1.0'
    ]
]);

$csrf_passed = ($result['http_code'] === 403);
if ($csrf_passed) {
    $results['summary']['passed']++;
    echo "✓ CSRF Test: PASS (403 for invalid token)\n";
} else {
    $results['summary']['failed']++;
    echo "✗ CSRF Test: FAIL (HTTP {$result['http_code']}, expected 403)\n";
}

$results['tests'][] = [
    'name' => $test_name,
    'url' => $test_url,
    'http_code' => $result['http_code'],
    'expected' => 403,
    'passed' => $csrf_passed
];

// Test 2: Rate Limiting Burst (10 requests/second)
echo "\n2. RATE LIMITING BURST TEST\n";
echo str_repeat("-", 50) . "\n";

$test_name = "Rate Limiting Burst Test";
$results['summary']['total']++;

$test_url = $base_url . '/api/health.php';
$blocked_count = 0;
$total_requests = 10;

for ($i = 0; $i < $total_requests; $i++) {
    $result = testEndpoint($test_url);
    if ($result['http_code'] === 429) {
        $blocked_count++;
    }
    // Small delay to simulate burst
    usleep(100000); // 0.1 second
}

$rate_limit_passed = ($blocked_count >= 2);
if ($rate_limit_passed) {
    $results['summary']['passed']++;
    echo "✓ Rate Limit Test: PASS ({$blocked_count}/{$total_requests} blocked)\n";
} else {
    $results['summary']['failed']++;
    echo "✗ Rate Limit Test: FAIL (only {$blocked_count}/{$total_requests} blocked, expected ≥2)\n";
}

$results['tests'][] = [
    'name' => $test_name,
    'url' => $test_url,
    'requests' => $total_requests,
    'blocked' => $blocked_count,
    'expected_blocked' => 2,
    'passed' => $rate_limit_passed
];

// Test 3: Unauthorized Admin Access
echo "\n3. UNAUTHORIZED ADMIN ACCESS\n";
echo str_repeat("-", 50) . "\n";

$test_name = "Unauthorized Admin Test";
$results['summary']['total']++;

$test_url = $base_url . '/api/admin/enrollment/approve.php';
$result = testEndpoint($test_url);

$admin_passed = ($result['http_code'] === 401);
if ($admin_passed) {
    $results['summary']['passed']++;
    echo "✓ Admin Auth Test: PASS (401 for unauthorized access)\n";
} else {
    $results['summary']['failed']++;
    echo "✗ Admin Auth Test: FAIL (HTTP {$result['http_code']}, expected 401)\n";
}

$results['tests'][] = [
    'name' => $test_name,
    'url' => $test_url,
    'http_code' => $result['http_code'],
    'expected' => 401,
    'passed' => $admin_passed
];

// Test 4: Idempotency Key Replay Attack
echo "\n4. IDEMPOTENCY KEY REPLAY ATTACK\n";
echo str_repeat("-", 50) . "\n";

$test_name = "Idempotency Key Replay Test";
$results['summary']['total']++;

$test_url = $base_url . '/api/mtm/enroll.php';
$idempotency_key = 'test-key-' . time();

// First request
$result1 = testEndpoint($test_url, [
    'method' => 'POST',
    'post_data' => json_encode(['test' => 'data']),
    'headers' => [
        'Idempotency-Key: ' . $idempotency_key,
        'Content-Type: application/json'
    ]
]);

// Second request with same key (replay)
$result2 = testEndpoint($test_url, [
    'method' => 'POST', 
    'post_data' => json_encode(['test' => 'data_different']),
    'headers' => [
        'Idempotency-Key: ' . $idempotency_key,
        'Content-Type: application/json'
    ]
]);

$idempotency_passed = ($result2['http_code'] === 409);
if ($idempotency_passed) {
    $results['summary']['passed']++;
    echo "✓ Idempotency Test: PASS (409 for replay attempt)\n";
} else {
    $results['summary']['failed']++;
    echo "✗ Idempotency Test: FAIL (HTTP {$result2['http_code']}, expected 409)\n";
    echo "  First request: {$result1['http_code']}\n";
    echo "  Replay request: {$result2['http_code']}\n";
}

$results['tests'][] = [
    'name' => $test_name,
    'url' => $test_url,
    'key' => $idempotency_key,
    'first_request_code' => $result1['http_code'],
    'replay_request_code' => $result2['http_code'],
    'expected' => 409,
    'passed' => $idempotency_passed
];

// Test 5: Audit Trail Verification
echo "\n5. AUDIT TRAIL INTEGRATION\n";
echo str_repeat("-", 50) . "\n";

$test_name = "Audit Trail Logging";
$results['summary']['total']++;

// Check if audit system captures security events
try {
    $pdo = new PDO("mysql:host=" . $dbHost . ";port=" . $dbPort . ";dbname=" . $dbName, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Check if audit_events table exists and has recent entries
    $audit_check = $pdo->query("SELECT COUNT(*) as count FROM audit_events WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $audit_count = $audit_check->fetch()['count'];
    
    $audit_passed = ($audit_count > 0);
    if ($audit_passed) {
        $results['summary']['passed']++;
        echo "✓ Audit Trail Test: PASS ({$audit_count} events in last hour)\n";
    } else {
        $results['summary']['failed']++;
        echo "✗ Audit Trail Test: FAIL (no events in last hour)\n";
    }
    
} catch (Exception $e) {
    $results['summary']['failed']++;
    echo "✗ Audit Trail Test: ERROR - " . $e->getMessage() . "\n";
    $audit_passed = false;
}

$results['tests'][] = [
    'name' => $test_name,
    'events_last_hour' => $audit_count ?? 0,
    'passed' => $audit_passed
];

// Summary
echo "\n6. SECURITY TESTS SUMMARY\n";
echo str_repeat("-", 50) . "\n";
echo "Total Tests: {$results['summary']['total']}\n";
echo "Passed: {$results['summary']['passed']}\n";
echo "Failed: {$results['summary']['failed']}\n";

$security_score = ($results['summary']['passed'] / $results['summary']['total']) * 100;
echo "Security Score: " . round($security_score, 1) . "%\n";

if ($security_score >= 80) {
    echo "Status: STRONG (GREEN)\n";
} elseif ($security_score >= 60) {
    echo "Status: MODERATE (YELLOW)\n";
} else {
    echo "Status: WEAK (RED)\n";
}

// Save results
file_put_contents('reports/phase3_verification/security_raw_data.json', json_encode($results, JSON_PRETTY_PRINT));
echo "\n=== SECURITY TESTING COMPLETE ===\n";
echo "Results saved to: reports/phase3_verification/security_raw_data.json\n";

?>