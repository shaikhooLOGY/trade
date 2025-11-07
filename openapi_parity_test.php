<?php
/**
 * Phase-3 Litmus OpenAPI Parity Check
 * Compare docs/openapi.yaml vs probed endpoints/status codes
 */

require_once 'config.php';
require_once 'includes/http/json.php';

header('Content-Type: application/json');

$response = [
    'timestamp' => date('c'),
    'test' => 'openapi_parity_check',
    'description' => 'Compare docs/openapi.yaml vs probed endpoints/status codes',
    'results' => [],
    'summary' => [
        'openapi_exists' => false,
        'endpoints_defined' => 0,
        'endpoints_tested' => 0,
        'parity_score' => 0,
        'status' => 'unknown'
    ]
];

try {
    // Check if OpenAPI file exists
    $openapiFile = 'docs/openapi.yaml';
    if (!file_exists($openapiFile)) {
        $response['results']['error'] = 'OpenAPI documentation not found at ' . $openapiFile;
        $response['summary']['status'] = 'FAIL';
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    $response['summary']['openapi_exists'] = true;
    
    // Load and parse OpenAPI spec (simplified YAML parsing)
    $openapiContent = file_get_contents($openapiFile);
    
    // Extract endpoint definitions from OpenAPI spec
    $endpoints = [];
    $lines = explode("\n", $openapiContent);
    $currentPath = '';
    $currentMethod = '';
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Extract path (e.g., "/api/health.php")
        if (preg_match('/^\/?api\/[^\s]+:', $line)) {
            $currentPath = trim($line, ':');
            $currentPath = '/' . ltrim($currentPath, '/');
        }
        
        // Extract HTTP methods
        if (preg_match('/^(get|post|put|delete|patch):/i', $line)) {
            $currentMethod = strtoupper(preg_replace('/:.*$/', '', $line));
        }
        
        // Extract status codes (responses section)
        if (preg_match('/^\s*(\d{3}):/', $line, $matches)) {
            $statusCode = (int)$matches[1];
            if (!empty($currentPath) && !empty($currentMethod)) {
                $key = $currentMethod . ' ' . $currentPath;
                if (!isset($endpoints[$key])) {
                    $endpoints[$key] = [];
                }
                if (!isset($endpoints[$key]['status_codes'])) {
                    $endpoints[$key]['status_codes'] = [];
                }
                $endpoints[$key]['status_codes'][] = $statusCode;
            }
        }
    }
    
    // Define the expected endpoints from our probes
    $expectedEndpoints = [
        'GET /api/health.php' => [200],
        'GET /api/profile/me.php' => [401],
        'POST /api/trades/create.php' => [401, 403], // Could be either due to auth/CSRF order
        'POST /api/mtm/enroll.php' => [401, 403],   // Could be either due to auth/CSRF order
        'GET /api/admin/e2e_status.php' => [401]
    ];
    
    $response['summary']['endpoints_defined'] = count($endpoints);
    $response['summary']['endpoints_tested'] = count($expectedEndpoints);
    
    // Compare each expected endpoint
    $parityMatches = 0;
    $totalExpected = count($expectedEndpoints);
    
    foreach ($expectedEndpoints as $expectedKey => $expectedCodes) {
        $expectedMethod = explode(' ', $expectedKey)[0];
        $expectedPath = explode(' ', $expectedKey)[1];
        $key = $expectedMethod . ' ' . $expectedPath;
        
        $result = [
            'endpoint' => $expectedKey,
            'expected_codes' => $expectedCodes,
            'defined_in_openapi' => isset($endpoints[$key]),
            'defined_codes' => $endpoints[$key]['status_codes'] ?? [],
            'parity_match' => false,
            'status' => 'unknown'
        ];
        
        if ($result['defined_in_openapi']) {
            $definedCodes = $endpoints[$key]['status_codes'];
            
            // Check if at least one expected code is documented
            $hasMatchingCode = false;
            foreach ($expectedCodes as $code) {
                if (in_array($code, $definedCodes)) {
                    $hasMatchingCode = true;
                    break;
                }
            }
            
            $result['parity_match'] = $hasMatchingCode;
            $result['status'] = $hasMatchingCode ? 'PASS' : 'PARTIAL';
        } else {
            $result['status'] = 'MISSING';
        }
        
        if ($result['parity_match']) {
            $parityMatches++;
        }
        
        $response['results'][$expectedKey] = $result;
    }
    
    // Calculate parity score
    $response['summary']['parity_score'] = ($totalExpected > 0) ? round(($parityMatches / $totalExpected) * 100, 1) : 0;
    $response['summary']['status'] = ($parityMatches === $totalExpected) ? 'PASS' : 'FAIL';
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    $response['summary']['status'] = 'ERROR';
}

echo json_encode($response, JSON_PRETTY_PRINT);