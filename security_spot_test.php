<?php
/**
 * Phase-3 Litmus Security Spot Test
 * Rate limiting and CSRF protection verification
 */

header('Content-Type: application/json');

$baseUrl = 'http://127.0.0.1:8082';
$response = [
    'timestamp' => date('c'),
    'test' => 'security_spot_check',
    'tests' => [
        'rate_limiting' => [
            'description' => '10 rapid POSTs to /login.php should return some 429s',
            'expected' => 'At least some requests with 429 + X-RateLimit-* headers'
        ],
        'csrf_protection' => [
            'description' => 'CSRF violations should return 403, not 401/200',
            'expected' => '403 status codes for CSRF violations'
        ]
    ],
    'results' => [],
    'summary' => [
        'rate_limit_status' => 'unknown',
        'csrf_status' => 'unknown',
        'overall_status' => 'unknown'
    ]
];

function sendPostRequest($url, $data = null, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data ?: []));
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    curl_close($ch);
    
    return [
        'status_code' => $httpCode,
        'headers' => $headers,
        'body' => $body
    ];
}

try {
    // Test 1: Rate Limiting - Send 10 rapid POSTs to /login.php
    $loginAttempts = [];
    for ($i = 1; $i <= 10; $i++) {
        $result = sendPostRequest($baseUrl . '/login.php', [
            'username' => 'test' . $i,
            'password' => 'wrong'
        ]);
        $loginAttempts[] = [
            'attempt' => $i,
            'status_code' => $result['status_code'],
            'has_ratelimit_headers' => (strpos($result['headers'], 'X-RateLimit') !== false),
            'has_retry_after' => (strpos($result['headers'], 'Retry-After') !== false),
            'is_429' => ($result['status_code'] === 429)
        ];
    }
    
    $response['results']['rate_limiting'] = [
        'endpoint' => '/login.php (10 rapid POSTs)',
        'attempts' => $loginAttempts,
        'rate_limited_count' => count(array_filter($loginAttempts, function($a) { return $a['is_429']; })),
        'retry_after_count' => count(array_filter($loginAttempts, function($a) { return $a['has_retry_after']; })),
        'x_ratelimit_count' => count(array_filter($loginAttempts, function($a) { return $a['has_ratelimit_headers']; })),
        'status' => (count(array_filter($loginAttempts, function($a) { return $a['is_429']; })) > 0) ? 'PASS' : 'FAIL'
    ];
    
    // Test 2: CSRF Protection - Send POST without CSRF token
    $csrfTests = [
        ['endpoint' => '/api/trades/create.php', 'data' => ['symbol' => 'TEST', 'quantity' => 1]],
        ['endpoint' => '/api/mtm/enroll.php', 'data' => ['model_id' => 1]]
    ];
    
    $csrfResults = [];
    foreach ($csrfTests as $test) {
        $result = sendPostRequest($baseUrl . $test['endpoint'], $test['data']);
        $csrfResults[] = [
            'endpoint' => $test['endpoint'],
            'status_code' => $result['status_code'],
            'is_403' => ($result['status_code'] === 403),
            'is_401' => ($result['status_code'] === 401),
            'is_200' => ($result['status_code'] === 200),
            'contains_csrf_error' => (strpos(strtolower($result['body']), 'csrf') !== false)
        ];
    }
    
    $response['results']['csrf_protection'] = [
        'tests' => $csrfResults,
        'all_return_403' => (count(array_filter($csrfResults, function($r) { return $r['is_403']; })) === count($csrfResults)),
        'any_return_401_or_200' => (count(array_filter($csrfResults, function($r) { return $r['is_401'] || $r['is_200']; })) > 0),
        'status' => (count(array_filter($csrfResults, function($r) { return $r['is_403']; })) === count($csrfResults)) ? 'PASS' : 'FAIL'
    ];
    
    // Calculate summary
    $response['summary']['rate_limit_status'] = $response['results']['rate_limiting']['status'];
    $response['summary']['csrf_status'] = $response['results']['csrf_protection']['status'];
    $response['summary']['overall_status'] = (
        $response['summary']['rate_limit_status'] === 'PASS' && 
        $response['summary']['csrf_status'] === 'PASS'
    ) ? 'PASS' : 'FAIL';
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    $response['summary']['overall_status'] = 'ERROR';
}

echo json_encode($response, JSON_PRETTY_PRINT);