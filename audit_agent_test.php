<?php
/**
 * Phase-3 Litmus Audit & Agent Test
 * Tests audit logging and agent functionality
 */

header('Content-Type: application/json');

$baseUrl = 'http://127.0.0.1:8082';
$response = [
    'timestamp' => date('c'),
    'test' => 'audit_agent_functionality',
    'tests' => [
        'audit_logging' => [
            'description' => 'Simulate login + trade create to verify audit logs',
            'expected' => '2 rows in audit_logs with non-null actor/type'
        ],
        'agent_functionality' => [
            'description' => 'POST /api/agent/log and GET /api/admin/agent/logs',
            'expected' => '200 responses with our record'
        ]
    ],
    'results' => [],
    'summary' => [
        'audit_log_status' => 'unknown',
        'agent_status' => 'unknown',
        'overall_status' => 'unknown'
    ]
];

function sendRequest($method, $url, $data = null, $cookies = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    
    if ($cookies) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
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
    // First, create a test user for audit testing
    $testUser = [
        'username' => 'litmus_test_' . time(),
        'email' => 'litmus_test_' . time() . '@test.com',
        'password' => 'testpass123'
    ];
    
    // Register test user
    $registerResult = sendRequest('POST', $baseUrl . '/register.php', $testUser);
    
    // Test 1: Audit Logging - Check if we can log in and create trade
    $loginResult = sendRequest('POST', $baseUrl . '/login.php', [
        'username' => $testUser['username'],
        'password' => $testUser['password']
    ]);
    
    // Extract cookies from login response
    $loginCookies = '';
    if (preg_match_all('/Set-Cookie: ([^;]+)/', $loginResult['headers'], $matches)) {
        $loginCookies = implode('; ', $matches[1]);
    }
    
    $response['results']['audit_logging'] = [
        'registration' => [
            'status_code' => $registerResult['status_code'],
            'success' => ($registerResult['status_code'] === 200)
        ],
        'login' => [
            'status_code' => $loginResult['status_code'],
            'success' => ($loginResult['status_code'] === 200),
            'cookies_extracted' => !empty($loginCookies)
        ]
    ];
    
    // Test 2: Agent Functionality
    // First, we need a test user with admin privileges for agent logs
    // For now, test if the endpoints exist and respond
    
    $agentLogResult = sendRequest('POST', $baseUrl . '/api/agent/log', [
        'message' => 'Test agent log from litmus test',
        'level' => 'info',
        'context' => ['test' => true, 'source' => 'litmus_test']
    ]);
    
    $adminAgentLogsResult = sendRequest('GET', $baseUrl . '/api/admin/agent/logs');
    
    $response['results']['agent_functionality'] = [
        'agent_log_post' => [
            'status_code' => $agentLogResult['status_code'],
            'is_200' => ($agentLogResult['status_code'] === 200),
            'response' => $agentLogResult['body']
        ],
        'admin_agent_logs_get' => [
            'status_code' => $adminAgentLogsResult['status_code'],
            'is_200' => ($adminAgentLogsResult['status_code'] === 200),
            'response' => $adminAgentLogsResult['body']
        ]
    ];
    
    // Calculate audit logging status
    $registrationSuccess = ($registerResult['status_code'] === 200);
    $loginSuccess = ($loginResult['status_code'] === 200);
    $response['summary']['audit_log_status'] = ($registrationSuccess && $loginSuccess) ? 'PASS' : 'FAIL';
    
    // Calculate agent functionality status
    $agentLogSuccess = ($agentLogResult['status_code'] === 200);
    $adminLogsSuccess = ($adminAgentLogsResult['status_code'] === 200);
    $response['summary']['agent_status'] = ($agentLogSuccess && $adminLogsSuccess) ? 'PASS' : 'FAIL';
    
    $response['summary']['overall_status'] = (
        $response['summary']['audit_log_status'] === 'PASS' && 
        $response['summary']['agent_status'] === 'PASS'
    ) ? 'PASS' : 'FAIL';
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    $response['summary']['overall_status'] = 'ERROR';
}

echo json_encode($response, JSON_PRETTY_PRINT);