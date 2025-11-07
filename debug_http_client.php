<?php
// Quick debug test for the HTTP client
require_once __DIR__ . '/reports/e2e/e2e_full_test_suite.php';

$client = new E2EHttpClient('http://127.0.0.1:8082');
$response = $client->get('api/health.php');

echo "Status: " . $response['status'] . "\n";
echo "Headers: " . print_r($response['headers'], true) . "\n";
echo "Body: " . $response['body'] . "\n";
echo "JSON: " . print_r($response['json'], true) . "\n";
echo "JSON Last Error: " . json_last_error_msg() . "\n";
?>