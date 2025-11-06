<?php
/**
 * api/mtm/enrollments.php
 *
 * MTM API - Get user MTM enrollments
 * GET /api/mtm/enrollments.php
 *
 * Returns all enrollments for the authenticated trader
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('METHOD_NOT_ALLOWED', 'Only GET method is allowed');
}

try {
    // Require authentication and active user
    require_active_user_json('Authentication required');
    
    // Rate limiting
    if (!rate_limit_api_middleware('mtm_enrollments', 10)) {
        exit; // Rate limit response already sent
    }
    
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    
    // Get user enrollments
    $enrollments = get_user_enrollments($traderId);
    
    // Format response
    $items = array_map(function($enrollment) {
        return [
            'id' => (int)$enrollment['id'],
            'model_id' => (int)$enrollment['model_id'],
            'model_code' => $enrollment['model_code'],
            'model_name' => $enrollment['model_name'],
            'tier' => $enrollment['tier'],
            'status' => $enrollment['status'],
            'started_at' => $enrollment['started_at']
        ];
    }, $enrollments);
    
    json_ok(['items' => $items], 'Enrollments retrieved successfully');
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'mtm_enrollments_api_error: ' . $e->getMessage());
    
    json_fail('SERVER_ERROR', 'Failed to retrieve enrollments');
}