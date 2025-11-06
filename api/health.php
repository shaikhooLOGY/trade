<?php
/**
 * api/health.php
 *
 * Standardized Health Check API
 * GET /api/health.php
 *
 * Returns unified JSON envelope with environment and system information
 */

require_once __DIR__ . '/_bootstrap.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('HTTP/1.1 200 OK');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Idempotency-Key, X-CSRF-Token');
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

// Apply rate limiting for health check (high limit since it's lightweight)
api_rate_limit('health', 300);

try {
    // Get environment information
    $env_info = get_env_info();
    
    // Return standardized health response
    json_success([
        'env' => $env_info['app_env'],
        'version' => '1.0.0',
        'db_status' => $env_info['db_connection'],
        'php_version' => $env_info['php_version'],
        'system_info' => [
            'uptime' => null, // Could implement uptime tracking
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]
    ], 'ok', [
        'endpoint' => 'health',
        'ts' => $env_info['timestamp']
    ]);
    
} catch (Exception $e) {
    json_error('Health check failed: ' . $e->getMessage(), 500);
}