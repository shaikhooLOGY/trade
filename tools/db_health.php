<?php
/**
 * Database Health Check Endpoint
 * Protected non-secret health probe for future triage
 * 
 * Only accessible in development/local environments
 */

require_once __DIR__ . '/../includes/env.php';

// Production protection: deny access
if (APP_ENV === 'prod' || APP_ENV === 'production') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

try {
    // Load database configuration
    $config = db_config();
    
    // Create temporary connection for health check
    $test_connection = @new mysqli(
        $config['host'], 
        $config['user'], 
        $config['pass'], 
        $config['name']
    );
    
    if ($test_connection->connect_errno) {
        throw new Exception('Connection failed: ' . $test_connection->connect_error);
    }
    
    // Test ping
    $ping_result = $test_connection->ping();
    
    // Close test connection
    $test_connection->close();
    
    // Return health status with masked host
    $response = [
        'ok' => true,
        'host' => '***' . substr($config['host'], 0, 2) . '***',
        'db' => $config['name'],
        'ping' => $ping_result,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database health check failed',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>