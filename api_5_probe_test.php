<?php
/**
 * Phase-3 Litmus API 5-Probe Test
 * Tests all 5 required API endpoints for JSON envelope and status codes
 */

header('Content-Type: application/json');

$baseUrl = 'http://127.0.0.1:8082';
$response = [
    'timestamp' => date('c'),
    'test' => 'api_5_probe',
    'expected_endpoints' => [
        'GET /api/health.php' => 'success:true JSON',
        'GET /api/profile/me.php (unauth)' => '401 JSON',
        'POST /api/trades/create.php (no CSRF)' => '403 JSON',
        'POST /api/mtm/enroll.php (no CSRF)' => '403 JSON',
        'GET /api/admin/e2e_status.php (unauth)' => '401 JSON'
    ],
    'results' => [],
    'summary' => [
        'total_probed' => 0,
        'expected_responses' => 0,
        'unexpected_responses' => 0,
        'status' => 'unknown'
    ]
];

function probeEndpoint($method, $url, $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
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
        'body' => $body,
        'is_json' => (strpos($headers, 'Content-Type: application/json') !== false)
    ];
}

try {
    // Probe 1: GET /api/health.php → {success:true}
    $result1 = probeEndpoint('GET', $baseUrl . '/api/health.php');
    $response['results']['health_endpoint'] = [
        'endpoint' => 'GET /api/health.php',
        'expected' => 'success:true JSON',
        'actual_status' => $result1['status_code'],
        'is_json' => $result1['is_json'],
        'response_contains_success' => (strpos($result1['body'], '"success":true') !== false),
        'status' => ($result1['status_code'] === 200 && $result1['is_json']) ? 'PASS' : 'FAIL',
        'raw_body' => $result1['body']
    ];
    $response['summary']['total_probed']++;
    
    // Probe 2: GET /api/profile/me.php (unauth) → 401 JSON
    $result2 = probeEndpoint('GET', $baseUrl . '/api/profile/me.php');
    $response['results']['profile_me_unauth'] = [
        'endpoint' => 'GET /api/profile/me.php (unauth)',
        'expected' => '401 JSON',
        'actual_status' => $result2['status_code'],
        'is_json' => $result2['is_json'],
        'is_401' => ($result2['status_code'] === 401),
        'status' => ($result2['status_code'] === 401 && $result2['is_json']) ? 'PASS' : 'FAIL',
        'raw_body' => $result2['body']
    ];
    $response['summary']['total_probed']++;
    
    // Probe 3: POST /api/trades/create.php (without CSRF) → 403 JSON
    $result3 = probeEndpoint('POST', $baseUrl . '/api/trades/create.php', ['test' => 'data']);
    $response['results']['trades_create_no_csrf'] = [
        'endpoint' => 'POST /api/trades/create.php (no CSRF)',
        'expected' => '403 JSON',
        'actual_status' => $result3['status_code'],
        'is_json' => $result3['is_json'],
        'is_403' => ($result3['status_code'] === 403),
        'status' => ($result3['status_code'] === 403 && $result3['is_json']) ? 'PASS' : 'FAIL',
        'raw_body' => $result3['body']
    ];
    $response['summary']['total_probed']++;
    
    // Probe 4: POST /api/mtm/enroll.php (without CSRF) → 403 JSON
    $result4 = probeEndpoint('POST', $baseUrl . '/api/mtm/enroll.php', ['test' => 'data']);
    $response['results']['mtm_enroll_no_csrf'] = [
        'endpoint' => 'POST /api/mtm/enroll.php (no CSRF)',
        'expected' => '403 JSON',
        'actual_status' => $result4['status_code'],
        'is_json' => $result4['is_json'],
        'is_403' => ($result4['status_code'] === 403),
        'status' => ($result4['status_code'] === 403 && $result4['is_json']) ? 'PASS' : 'FAIL',
        'raw_body' => $result4['body']
    ];
    $response['summary']['total_probed']++;
    
    // Probe 5: GET /api/admin/e2e_status.php (unauth) → 401 JSON
    $result5 = probeEndpoint('GET', $baseUrl . '/api/admin/e2e_status.php');
    $response['results']['admin_e2e_status_unauth'] = [
        'endpoint' => 'GET /api/admin/e2e_status.php (unauth)',
        'expected' => '401 JSON',
        'actual_status' => $result5['status_code'],
        'is_json' => $result5['is_json'],
        'is_401' => ($result5['status_code'] === 401),
        'status' => ($result5['status_code'] === 401 && $result5['is_json']) ? 'PASS' : 'FAIL',
        'raw_body' => $result5['body']
    ];
    $response['summary']['total_probed']++;
    
    // Calculate summary
    $passed = 0;
    foreach ($response['results'] as $result) {
        if ($result['status'] === 'PASS') $passed++;
    }
    
    $response['summary']['expected_responses'] = $passed;
    $response['summary']['unexpected_responses'] = $response['summary']['total_probed'] - $passed;
    $response['summary']['status'] = ($passed === $response['summary']['total_probed']) ? 'PASS' : 'FAIL';
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    $response['summary']['status'] = 'ERROR';
}

echo json_encode($response, JSON_PRETTY_PRINT);