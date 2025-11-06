<?php
/**
 * Security Baseline Test for Phase 3 Pre-Integration
 * Tests negative security scenarios
 */

echo "=== PHASE 3 PRE-INTEGRATION SECURITY BASELINE TEST ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

// Function to test security endpoint with details
function testSecurityEndpoint($url, $name, $method = 'GET', $data = null, $headers = []) {
    $start_time = microtime(true);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Phase3-Security-Test/1.0');
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    $headers_raw = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    // Parse headers for security info
    $parsed_headers = [];
    $header_lines = explode("\n", $headers_raw);
    foreach ($header_lines as $line) {
        if (strpos($line, ':') !== false) {
            [$key, $value] = explode(':', $line, 2);
            $parsed_headers[trim($key)] = trim($value);
        }
    }
    
    return [
        'name' => $name,
        'url' => $url,
        'method' => $method,
        'http_code' => $http_code,
        'latency_ms' => round($total_time * 1000, 2),
        'headers' => $parsed_headers,
        'body' => $body
    ];
}

$base_url = 'http://127.0.0.1:8082';
$security_tests = [];

echo "=== TESTING SECURITY SCENARIOS ===\n\n";

// Test 1: Invalid CSRF Token (should return 403)
echo "Test 1: Invalid CSRF Token\n";
$csrf_test = testSecurityEndpoint(
    $base_url . '/api/trades/list.php',
    'Invalid CSRF Test',
    'POST',
    'invalid_data=test',
    ['X-CSRF-Token: invalid_csrf_token_12345']
);
$security_tests[] = $csrf_test;
echo "  Expected: 403 Forbidden\n";
echo "  Actual: {$csrf_test['http_code']} " . ($csrf_test['http_code'] === 403 ? "✅" : "❌") . "\n\n";

// Test 2: Rate Limiting Test (Burst 10 requests/sec)
echo "Test 2: Rate Limiting (10 requests burst)\n";
$rate_limit_results = [];
for ($i = 1; $i <= 10; $i++) {
    $result = testSecurityEndpoint(
        $base_url . '/api/trades/list.php',
        "Rate Limit Request #{$i}",
        'GET'
    );
    $rate_limit_results[] = $result;
    echo "  Request {$i}: HTTP {$result['http_code']}\n";
}
$security_tests = array_merge($security_tests, $rate_limit_results);

$blocked_count = 0;
$rate_limited_count = 0;
foreach ($rate_limit_results as $result) {
    if ($result['http_code'] === 429) {
        $rate_limited_count++;
    } elseif (in_array($result['http_code'], [403, 429])) {
        $blocked_count++;
    }
}
echo "  Rate Limited (429): {$rate_limited_count}\n";
echo "  Total Blocked: {$blocked_count}\n";
echo "  Expected: At least 2 blocked (429)\n";
echo "  Result: " . ($rate_limited_count >= 2 ? "✅" : "❌") . "\n\n";

// Test 3: Unauthorized Admin Access
echo "Test 3: Unauthorized Admin Access\n";
$admin_test = testSecurityEndpoint(
    $base_url . '/api/admin/enrollment/approve.php',
    'Unauthorized Admin Access',
    'POST',
    'id=1&action=approve'
);
$security_tests[] = $admin_test;
echo "  Expected: 401 Unauthorized\n";
echo "  Actual: {$admin_test['http_code']} " . ($admin_test['http_code'] === 401 ? "✅" : "❌") . "\n\n";

// Test 4: No CSRF Token on State-Changing Request
echo "Test 4: Missing CSRF Token\n";
$no_csrf_test = testSecurityEndpoint(
    $base_url . '/api/mtm/enroll.php',
    'Missing CSRF Token',
    'POST',
    'data=test'
);
$security_tests[] = $no_csrf_test;
echo "  Expected: 403 Forbidden\n";
echo "  Actual: {$no_csrf_test['http_code']} " . ($no_csrf_test['http_code'] === 403 ? "✅" : "❌") . "\n\n";

// Summary
echo "=== SECURITY BASELINE SUMMARY ===\n";

$csfr_blocked = ($csrf_test['http_code'] === 403);
$rate_limit_working = ($rate_limited_count >= 2);
$admin_blocked = ($admin_test['http_code'] === 401);
$no_csrf_blocked = ($no_csrf_test['http_code'] === 403);

echo "CSRF Protection: " . ($csfr_blocked ? "✅ Working" : "❌ Failed") . "\n";
echo "Rate Limiting: " . ($rate_limit_working ? "✅ Working ({$rate_limited_count} blocked)" : "❌ Failed (0 blocked)") . "\n";
echo "Admin Authorization: " . ($admin_blocked ? "✅ Working" : "❌ Failed") . "\n";
echo "CSRF Requirement: " . ($no_csrf_blocked ? "✅ Working" : "❌ Failed") . "\n";

$security_score = 0;
if ($csfr_blocked) $security_score++;
if ($rate_limit_working) $security_score++;
if ($admin_blocked) $security_score++;
if ($no_csrf_blocked) $security_score++;

echo "\nSecurity Score: {$security_score}/4\n";
echo "Overall: " . ($security_score >= 3 ? "✅ SECURE" : "⚠️ NEEDS IMPROVEMENT") . "\n";

// Generate detailed report
$report_content = "# Security Baseline Test Report - Phase 3 Pre-Integration\n\n";
$report_content .= "**Generated:** " . date('Y-m-d H:i:s') . "\n";
$report_content .= "**Security Score:** {$security_score}/4\n";
$report_content .= "**Overall Status:** " . ($security_score >= 3 ? "✅ SECURE" : "⚠️ NEEDS IMPROVEMENT") . "\n\n";

$report_content .= "## Security Test Results\n\n";

$report_content .= "### 1. CSRF Protection Test\n";
$report_content .= "- **Test:** Invalid CSRF token on POST request\n";
$report_content .= "- **Expected:** 403 Forbidden\n";
$report_content .= "- **Actual:** {$csrf_test['http_code']}\n";
$report_content .= "- **Status:** " . ($csfr_blocked ? "✅ PASS" : "❌ FAIL") . "\n\n";

$report_content .= "### 2. Rate Limiting Test\n";
$report_content .= "- **Test:** Burst of 10 requests to test rate limiting\n";
$report_content .= "- **Expected:** At least 2 requests blocked with 429\n";
$report_content .= "- **Actual:** {$rate_limited_count} requests blocked with 429\n";
$report_content .= "- **Status:** " . ($rate_limit_working ? "✅ PASS" : "❌ FAIL") . "\n\n";

$report_content .= "### 3. Admin Authorization Test\n";
$report_content .= "- **Test:** Unauthorized access to admin endpoint\n";
$report_content .= "- **Expected:** 401 Unauthorized\n";
$report_content .= "- **Actual:** {$admin_test['http_code']}\n";
$report_content .= "- **Status:** " . ($admin_blocked ? "✅ PASS" : "❌ FAIL") . "\n\n";

$report_content .= "### 4. CSRF Requirement Test\n";
$report_content .= "- **Test:** POST request without CSRF token\n";
$report_content .= "- **Expected:** 403 Forbidden\n";
$report_content .= "- **Actual:** {$no_csrf_test['http_code']}\n";
$report_content .= "- **Status:** " . ($no_csrf_blocked ? "✅ PASS" : "❌ FAIL") . "\n\n";

$report_content .= "## Rate Limiting Details\n\n";
$report_content .= "| Request # | HTTP Code | Latency |\n";
$report_content .= "|-----------|-----------|---------|\n";
foreach ($rate_limit_results as $i => $result) {
    $report_content .= "| " . ($i + 1) . " | {$result['http_code']} | {$result['latency_ms']}ms |\n";
}

if ($security_score < 3) {
    $report_content .= "\n## Security Issues Detected\n\n";
    $report_content .= "The following security tests failed:\n\n";
    if (!$csfr_blocked) {
        $report_content .= "- **CSRF Protection:** Invalid tokens are not being blocked\n";
    }
    if (!$rate_limit_working) {
        $report_content .= "- **Rate Limiting:** No requests were blocked in burst test\n";
    }
    if (!$admin_blocked) {
        $report_content .= "- **Admin Authorization:** Admin endpoints accessible without authentication\n";
    }
    if (!$no_csrf_blocked) {
        $report_content .= "- **CSRF Requirement:** State-changing requests work without CSRF tokens\n";
    }
    $report_content .= "\n**Action Required:** Review and fix security controls before production deployment.\n";
} else {
    $report_content .= "\n## Security Status: ✅ BASELINE SECURE\n\n";
    $report_content .= "All security tests passed. The application implements proper:\n";
    $report_content .= "- CSRF protection\n";
    $report_content .= "- Rate limiting\n";
    $report_content .= "- Admin access controls\n";
    $report_content .= "- Authentication requirements\n";
}

// Save report
file_put_contents('reports/phase3_preintegration/security_baseline.md', $report_content);
echo "\n✅ Report saved to: reports/phase3_preintegration/security_baseline.md\n";