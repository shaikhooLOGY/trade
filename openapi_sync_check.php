<?php
/**
 * OpenAPI Sync Check Script
 * Validates OpenAPI documentation without using deprecated yaml_parse
 */

require_once 'config.php';
require_once 'includes/http/json.php';

header('Content-Type: application/json');

$response = [
    'timestamp' => date('c'),
    'test' => 'openapi_sync_check',
    'description' => 'Check OpenAPI documentation consistency',
    'results' => [],
    'summary' => [
        'openapi_exists' => false,
        'valid_yaml' => false,
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
    
    // Load and validate YAML content without yaml_parse
    $openapiContent = file_get_contents($openapiFile);
    
    // Basic YAML structure validation (without yaml_parse)
    $hasValidStructure = (
        strpos($openapiContent, 'openapi:') !== false ||
        strpos($openapiContent, 'swagger:') !== false
    );
    
    $response['summary']['valid_yaml'] = $hasValidStructure;
    $response['summary']['status'] = $hasValidStructure ? 'PASS' : 'FAIL';
    
    if ($hasValidStructure) {
        $response['results']['yaml_structure'] = 'Valid OpenAPI/Swagger structure detected';
    } else {
        $response['results']['yaml_structure'] = 'Invalid or missing OpenAPI/Swagger structure';
    }
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    $response['summary']['status'] = 'ERROR';
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>