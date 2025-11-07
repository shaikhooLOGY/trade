<?php
/**
 * Admin Enrollment API Bootstrap
 * 
 * Standardized bootstrap for enrollment admin endpoints
 */

// Ensure this is accessed through proper API routing
if (!defined('API_ACCESS')) {
    http_response_code(404);
    exit('Not Found');
}

// Include unified bootstrap
require_once __DIR__ . '/../../../includes/bootstrap.php';

// Verify admin authentication
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required',
        'code' => 'FORBIDDEN'
    ]);
    exit;
}

// Set JSON response headers
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}