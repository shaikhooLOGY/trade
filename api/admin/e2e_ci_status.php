<?php
/**
 * api/admin/e2e_ci_status.php
 *
 * Admin API - Get E2E CI test status from GitHub Actions or other CI
 * GET /api/admin/e2e_ci_status.php
 */

require_once __DIR__ . '/_bootstrap.php';

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

// Require admin authentication
require_admin_json('Admin access required');

try {
    $ciStatusFile = __DIR__ . '/../../reports/e2e/ci_status.json';
    $localStatusFile = __DIR__ . '/../../reports/e2e/last_status.json';
    
    $response = [
        'provider' => 'local',
        'last_status' => 'unknown',
        'pass_rate' => 0,
        'run_at' => null,
        'artifacts_url' => null,
        'sha' => null
    ];
    
    // Try to read CI status file first
    if (file_exists($ciStatusFile)) {
        $fileContent = file_get_contents($ciStatusFile);
        $fileData = json_decode($fileContent, true);
        if ($fileData && json_last_error() === JSON_ERROR_NONE) {
            $response = array_merge($response, $fileData);
        }
    } else {
        // Fallback to local E2E status
        if (file_exists($localStatusFile)) {
            $fileContent = file_get_contents($localStatusFile);
            $fileData = json_decode($fileContent, true);
            if ($fileData && json_last_error() === JSON_ERROR_NONE) {
                $response = array_merge($response, [
                    'provider' => 'local_fallback',
                    'last_status' => $fileData['success'] ? 'passed' : 'failed',
                    'pass_rate' => $fileData['pass_rate'] ?? 0,
                    'run_at' => $fileData['last_run_at'] ?? null,
                    'sha' => 'local-' . date('Y-m-d')
                ]);
            }
        }
    }
    
    json_ok($response, 'E2E CI status retrieved successfully');
    
} catch (Exception $e) {
    app_log('error', 'E2E CI status error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to retrieve E2E CI status');
}