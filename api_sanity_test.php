<?php
/**
 * API Sanity Test for Phase 3 Pre-Integration
 * Tests 5 representative endpoints for proper JSON responses
 */

echo "=== PHASE 3 PRE-INTEGRATION API SANITY TEST ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

// Function to test an endpoint
function testEndpoint($url, $name, $method = 'GET', $data = null, $headers = []) {
    $start_time = microtime(true);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Phase3-PreIntegration-Test/1.0');
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
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
    
    // Parse headers
    $parsed_headers = [];
    $header_lines = explode("\n", $headers_raw);
    foreach ($header_lines as $line) {
        if (strpos($line, ':') !== false) {
            [$key, $value] = explode(':', $line, 2);
            $parsed_headers[trim($key)] = trim($value);
        }
    }
    
    // Check for CSRF cookie
    $has_csrf = false;
    if (isset($parsed_headers['Set-Cookie']) && strpos($parsed_headers['Set-Cookie'], 'csrf') !== false) {
        $has_csrf = true;
    }
    
    // Check for rate limit headers
    $rate_limit_info = [];
    foreach (['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Retry-After'] as $header) {
        if (isset($parsed_headers[$header])) {
            $rate_limit_info[$header] = $parsed_headers[$header];
        }
    }
    
    // Try to parse JSON
    $json_valid = false;
    $json_data = null;
    if ($body) {
        $json_data = json_decode($body, true);
        $json_valid = (json_last_error() === JSON_ERROR_NONE);
    }
    
    // Check for correct envelope structure
    $envelope_valid = false;
    $envelope_keys = ['success', 'data', 'message', 'error', 'meta'];
    if ($json_valid && is_array($json_data)) {
        $has_all_keys = true;
        foreach ($envelope_keys as $key) {
            if (!array_key_exists($key, $json_data)) {
                $has_all_keys = false;
                break;
            }
        }
        $envelope_valid = $has_all_keys;
    }
    
    return [
        'name' => $name,
        'url' => $url,
        'method' => $method,
        'http_code' => $http_code,
        'latency_ms' => round($total_time * 1000, 2),
        'response_size' => strlen($body),
        'json_valid' => $json_valid,
        'envelope_valid' => $envelope_valid,
        'has_csrf' => $has_csrf,
        'rate_limit_info' => $rate_limit_info,
        'headers' => $parsed_headers,
        'body' => $body,
        'json_data' => $json_data
    ];
}

// Test endpoints
$endpoints = [
    ['/api/health.php', 'Health Check'],
    ['/api/trades/list.php', 'Trades List'],
    ['/api/mtm/enroll.php', 'MTM Enroll'],
    ['/api/admin/enrollment/approve.php', 'Admin Enrollment Approve'],
    ['/api/agent/log', 'Agent Log']
];

$base_url = 'http://127.0.0.1:8082';
$results = [];

echo "=== TESTING ENDPOINTS ===\n\n";

foreach ($endpoints as [$endpoint, $name]) {
    $url = $base_url . $endpoint;
    echo "Testing: {$name} ({$url})\n";
    
    $result = testEndpoint($url, $name);
    $results[] = $result;
    
    // Display results
    echo "  HTTP Code: {$result['http_code']}\n";
    echo "  Latency: {$result['latency_ms']}ms\n";
    echo "  JSON Valid: " . ($result['json_valid'] ? '✅' : '❌') . "\n";
    echo "  Envelope Valid: " . ($result['envelope_valid'] ? '✅' : '❌') . "\n";
    echo "  CSRF Cookie: " . ($result['has_csrf'] ? '✅' : '❌') . "\n";
    
    if (!empty($result['rate_limit_info'])) {
        echo "  Rate Limit Headers: " . json_encode($result['rate_limit_info']) . "\n";
    }
    
    if (!$result['json_valid']) {
        echo "  Response Preview: " . substr($result['body'], 0, 200) . "...\n";
    }
    
    echo "\n";
}

// Summary
echo "=== SUMMARY ===\n";
$passed = 0;
$total = count($results);

foreach ($results as $result) {
    $endpoint_passed = ($result['http_code'] === 200 && $result['json_valid'] && $result['envelope_valid']);
    if ($endpoint_passed) {
        $passed++;
    }
    echo ($endpoint_passed ? "✅" : "❌") . " {$result['name']}: HTTP {$result['http_code']}, " . 
         ($result['json_valid'] ? 'Valid JSON' : 'Invalid JSON') . ", " . 
         ($result['envelope_valid'] ? 'Valid Envelope' : 'Invalid Envelope') . "\n";
}

echo "\nPassed: {$passed}/{$total}\n";
echo "Success Rate: " . round(($passed / $total) * 100, 1) . "%\n";

// Generate report
$report_content = "# API Sanity Test Report - Phase 3 Pre-Integration\n\n";
$report_content .= "**Generated:** " . date('Y-m-d H:i:s') . "\n";
$report_content .= "**Base URL:** {$base_url}\n";
$report_content .= "**Status:** " . ($passed === $total ? "✅ ALL PASSED" : "⚠️ ISSUES DETECTED") . "\n\n";

$report_content .= "## Endpoint Test Results\n\n";
$report_content .= "| Endpoint | HTTP Code | Latency | JSON Valid | Envelope Valid | CSRF | Rate Limit Headers |\n";
$report_content .= "|----------|-----------|---------|------------|----------------|------|--------------------|\n";

foreach ($results as $result) {
    $status_icon = ($result['http_code'] === 200 && $result['json_valid'] && $result['envelope_valid']) ? '✅' : '❌';
    $rate_limit = !empty($result['rate_limit_info']) ? json_encode($result['rate_limit_info']) : '-';
    $report_content .= "| {$result['name']} | {$result['http_code']} | {$result['latency_ms']}ms | " . 
                      ($result['json_valid'] ? '✅' : '❌') . " | " . ($result['envelope_valid'] ? '✅' : '❌') . " | " . 
                      ($result['has_csrf'] ? '✅' : '❌') . " | {$rate_limit} |\n";
}

$report_content .= "\n## Summary\n\n";
$report_content .= "- **Total Endpoints Tested:** {$total}\n";
$report_content .= "- **Passed:** {$passed}\n";
$report_content .= "- **Success Rate:** " . round(($passed / $total) * 100, 1) . "%\n\n";

if ($passed !== $total) {
    $report_content .= "## Issues Detected\n\n";
    foreach ($results as $result) {
        if (!($result['http_code'] === 200 && $result['json_valid'] && $result['envelope_valid'])) {
            $report_content .= "### {$result['name']}\n";
            $report_content .= "- HTTP Code: {$result['http_code']}\n";
            $report_content .= "- JSON Valid: " . ($result['json_valid'] ? 'Yes' : 'No') . "\n";
            $report_content .= "- Envelope Valid: " . ($result['envelope_valid'] ? 'Yes' : 'No') . "\n";
            if (!$result['json_valid']) {
                $report_content .= "- Response: " . substr($result['body'], 0, 500) . "\n";
            }
            $report_content .= "\n";
        }
    }
} else {
    $report_content .= "## API Status: ✅ ALL ENDPOINTS FUNCTIONAL\n\n";
    $report_content .= "All tested endpoints returned proper JSON responses with correct envelope structure.\n";
}

// Save report
file_put_contents('reports/phase3_preintegration/api_sanity.md', $report_content);
echo "\n✅ Report saved to: reports/phase3_preintegration/api_sanity.md\n";