<?php
/**
 * RATE LIMIT BURST TESTING SCRIPT
 * 
 * Comprehensive rate limiting validation for Phase 3 QC
 * Tests burst behavior and 429 response handling across target endpoints
 */

// Configuration
define('TEST_CONFIG', [
    'base_url' => 'http://127.0.0.1:8082', // Local development server
    'concurrent_requests' => 12,
    'time_window_seconds' => 10,
    'test_delay_ms' => 50, // Delay between requests
]);

/**
 * Execute burst test for a specific endpoint
 */
function test_endpoint_burst($endpoint, $method = 'GET', $data = [], $expected_limit = null, $description = '') {
    $start_time = microtime(true);
    $results = [];
    $success_count = 0;
    $rate_limited_count = 0;
    $error_count = 0;
    
    echo "\n🧪 Testing: $description ($endpoint)\n";
    echo "Method: $method | Expected Limit: " . ($expected_limit ?: 'Default') . " per minute\n";
    echo "Executing " . TEST_CONFIG['concurrent_requests'] . " requests in " . TEST_CONFIG['time_window_seconds'] . " seconds...\n";
    
    for ($i = 1; $i <= TEST_CONFIG['concurrent_requests']; $i++) {
        $request_start = microtime(true);
        
        // Execute request
        $response = make_http_request($endpoint, $method, $data, $i);
        $request_time = microtime(true) - $request_start;
        
        // Analyze response
        $status_code = $response['status_code'] ?? 0;
        $headers = $response['headers'] ?? [];
        $body = $response['body'] ?? '';
        
        // Parse response
        $is_429 = ($status_code == 429);
        $has_retry_after = isset($headers['retry-after']) || isset($headers['Retry-After']);
        $retry_after_value = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;
        
        $results[] = [
            'request_num' => $i,
            'status_code' => $status_code,
            'response_time' => round($request_time * 1000, 2), // ms
            'is_429' => $is_429,
            'has_retry_after' => $has_retry_after,
            'retry_after_value' => $retry_after_value,
            'rate_limited' => $is_429,
            'timestamp' => date('H:i:s.' . sprintf('%03d', floor(microtime(true) * 1000) % 1000))
        ];
        
        // Count results
        if ($status_code >= 200 && $status_code < 300) {
            $success_count++;
        } elseif ($status_code == 429) {
            $rate_limited_count++;
        } else {
            $error_count++;
        }
        
        // Display individual request result
        $status_symbol = $is_429 ? '🛡️ 429' : ($status_code >= 200 && $status_code < 300 ? '✅ ' . $status_code : '⚠️ ' . $status_code);
        $retry_info = $has_retry_after ? " (Retry-After: {$retry_after_value}s)" : " (No Retry-After)";
        echo "  Request $i: $status_symbol - {$results[$i-1]['response_time']}ms{$retry_info}\n";
        
        // Rate limiting check for burst pattern
        if ($expected_limit && $success_count >= $expected_limit) {
            echo "  📊 Expected rate limit ($expected_limit) reached at request $i\n";
        }
        
        // Delay between requests (except for the last one)
        if ($i < TEST_CONFIG['concurrent_requests']) {
            usleep(TEST_CONFIG['test_delay_ms'] * 1000); // Convert to microseconds
        }
    }
    
    $total_time = microtime(true) - $start_time;
    
    // Calculate statistics
    $rate_limiting_effective = ($rate_limited_count > 0);
    $burst_prevented = ($rate_limited_count >= TEST_CONFIG['concurrent_requests'] * 0.5); // At least 50% blocked
    
    // Summary
    echo "\n📊 RESULTS SUMMARY:\n";
    echo "  Total Time: " . round($total_time, 2) . " seconds\n";
    echo "  Successful (2xx): $success_count\n";
    echo "  Rate Limited (429): $rate_limited_count\n";
    echo "  Errors/Other: $error_count\n";
    echo "  Rate Limiting Effective: " . ($rate_limiting_effective ? "YES ✅" : "NO ❌") . "\n";
    echo "  Burst Prevented: " . ($burst_prevented ? "YES ✅" : "NO ❌") . "\n";
    
    return [
        'endpoint' => $endpoint,
        'description' => $description,
        'method' => $method,
        'expected_limit' => $expected_limit,
        'total_time' => round($total_time, 2),
        'success_count' => $success_count,
        'rate_limited_count' => $rate_limited_count,
        'error_count' => $error_count,
        'rate_limiting_effective' => $rate_limiting_effective,
        'burst_prevented' => $burst_prevented,
        'detailed_results' => $results,
        'compliance_status' => determine_compliance_status($rate_limiting_effective, $burst_prevented, $expected_limit !== null)
    ];
}

/**
 * Make HTTP request using cURL
 */
function make_http_request($endpoint, $method = 'GET', $data = [], $request_num = 1) {
    $url = TEST_CONFIG['base_url'] . $endpoint;
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => "RateLimitTest/1.0 (QC Burst Testing)",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    curl_close($ch);
    
    // Parse headers
    $header_text = substr($response, 0, $header_size);
    $headers = [];
    foreach (explode("\r\n", $header_text) as $header_line) {
        if (strpos($header_line, ':') !== false) {
            list($header_name, $header_value) = explode(':', $header_line, 2);
            $headers[trim(strtolower($header_name))] = trim($header_value);
        }
    }
    
    // Parse body
    $body = substr($response, $header_size);
    
    return [
        'status_code' => $http_code,
        'headers' => $headers,
        'body' => $body
    ];
}

/**
 * Determine compliance status based on test results
 */
function determine_compliance_status($effective, $burst_prevented, $has_custom_limit) {
    if ($effective && $burst_prevented) {
        return 'COMPLIANT';
    } elseif ($effective && !$burst_prevented) {
        return 'PARTIAL_COMPLIANCE';
    } else {
        return 'NON_COMPLIANT';
    }
}

/**
 * Generate test report
 */
function generate_test_report($test_results) {
    $report = [];
    
    foreach ($test_results as $result) {
        $endpoint_info = [
            'endpoint' => $result['endpoint'],
            'description' => $result['description'],
            'method' => $result['method'],
            'expected_limit' => $result['expected_limit'],
            'compliance_status' => $result['compliance_status'],
            'rate_limiting_effective' => $result['rate_limiting_effective'],
            'burst_prevented' => $result['burst_prevented'],
            'statistics' => [
                'total_requests' => TEST_CONFIG['concurrent_requests'],
                'success_count' => $result['success_count'],
                'rate_limited_count' => $result['rate_limited_count'],
                'error_count' => $result['error_count'],
                'total_time_seconds' => $result['total_time'],
                'requests_per_second' => round(TEST_CONFIG['concurrent_requests'] / $result['total_time'], 2)
            ],
            'detailed_results' => $result['detailed_results']
        ];
        
        $report[] = $endpoint_info;
    }
    
    return $report;
}

/**
 * Main test execution
 */
function main() {
    echo "🚀 RATE LIMIT BURST TESTING - PHASE 3 QC\n";
    echo "=========================================\n";
    echo "Starting comprehensive rate limiting validation...\n";
    echo "Test Configuration:\n";
    echo "  - Concurrent Requests: " . TEST_CONFIG['concurrent_requests'] . "\n";
    echo "  - Time Window: " . TEST_CONFIG['time_window_seconds'] . " seconds\n";
    echo "  - Delay Between Requests: " . TEST_CONFIG['test_delay_ms'] . "ms\n";
    echo "  - Base URL: " . TEST_CONFIG['base_url'] . "\n\n";
    
    $test_results = [];
    
    // Test 1: Login endpoint (CRITICAL - currently no rate limiting)
    $test_results[] = test_endpoint_burst(
        '/login.php',
        'POST',
        ['email' => 'test@example.com', 'password' => 'test'],
        null,
        'Login Endpoint - Authentication'
    );
    
    // Test 2: Trade creation endpoint
    $test_results[] = test_endpoint_burst(
        '/api/trades/create.php',
        'POST',
        [
            'symbol' => 'AAPL',
            'side' => 'buy',
            'quantity' => 100,
            'price' => 150.00
        ],
        10,
        'Trade Creation API - Mutating Operations'
    );
    
    // Test 3: MTM enrollment endpoint
    $test_results[] = test_endpoint_burst(
        '/api/mtm/enroll.php',
        'POST',
        [
            'model_id' => 1,
            'tier' => 'basic'
        ],
        5,
        'MTM Enrollment API - User Actions'
    );
    
    // Test 4: Admin approval endpoint (CRITICAL - currently no rate limiting)
    $test_results[] = test_endpoint_burst(
        '/api/admin/enrollment/approve.php',
        'POST',
        [
            'enrollment_id' => 999999 // Invalid ID for testing
        ],
        10, // Should use admin rate limit (10/min)
        'Admin Approval API - Admin Operations'
    );
    
    // Generate comprehensive report
    $report = generate_test_report($test_results);
    
    // Display overall summary
    echo "\n\n📋 OVERALL COMPLIANCE SUMMARY\n";
    echo "==============================\n";
    
    $compliant_count = 0;
    $partial_count = 0;
    $non_compliant_count = 0;
    
    foreach ($report as $result) {
        $status_symbol = match($result['compliance_status']) {
            'COMPLIANT' => '✅',
            'PARTIAL_COMPLIANCE' => '⚠️',
            'NON_COMPLIANT' => '❌'
        };
        
        echo sprintf(
            "%s %s (%s) - %s requests in %.1fs\n",
            $status_symbol,
            $result['description'],
            $result['endpoint'],
            $result['statistics']['total_requests'],
            $result['statistics']['total_time_seconds']
        );
        
        if ($result['compliance_status'] === 'COMPLIANT') $compliant_count++;
        elseif ($result['compliance_status'] === 'PARTIAL_COMPLIANCE') $partial_count++;
        else $non_compliant_count++;
    }
    
    echo "\n📊 COMPLIANCE BREAKDOWN:\n";
    echo "  Fully Compliant: $compliant_count\n";
    echo "  Partial Compliance: $partial_count\n";
    echo "  Non-Compliant: $non_compliant_count\n";
    echo "  Total Endpoints: " . count($report) . "\n";
    
    return $report;
}

// Execute tests if run directly
if (php_sapi_name() === 'cli') {
    $results = main();
    
    // Save results to JSON for further analysis
    file_put_contents('rate_limit_burst_test_results.json', json_encode($results, JSON_PRETTY_PRINT));
    echo "\n💾 Detailed results saved to: rate_limit_burst_test_results.json\n";
}