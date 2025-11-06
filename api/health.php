<?php
/**
 * api/health.php
 *
 * Minimal Health Check API - Environment-based health monitoring
 * GET /api/health.php
 *
 * NOTE: This endpoint returns 200 only when APP_ENV=local; otherwise 404 JSON.
 */

require_once __DIR__ . '/_bootstrap.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('METHOD_NOT_ALLOWED', 'Only GET method is allowed');
}

// Check environment using getenv()
$appEnv = getenv('APP_ENV');

if ($appEnv !== 'local') {
    json_fail('FEATURE_OFF', 'Health endpoint available only in local environment');
}

// Return health status for local environment
http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'app_env' => $appEnv,
    'time' => date('c')
]);