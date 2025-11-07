<?php
/**
 * api/admin/enrollment/drop.php
 *
 * Admin API - Drop MTM enrollment participant
 * POST /api/admin/enrollment/drop.php
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/security/csrf_guard.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

try {
    // Require admin authentication
    require_admin_json('Admin access required');
    $adminId = (int)$_SESSION['user_id'];
    
    // CSRF protection for mutating requests
    require_csrf_json();
    
    // Rate limiting: 10 per minute
    require_rate_limit('api:admin:drop', 10);
    
    // Get JSON input
    $input = get_json_input();
    
    // Validate required fields
    validate_required_fields($input, ['enrollment_id']);
    
    $enrollmentId = (int)$input['enrollment_id'];
    
    if ($enrollmentId <= 0) {
        json_error('VALIDATION_ERROR', 'Invalid enrollment ID provided');
    }
    
    global $mysqli;
    
    // Start transaction for data consistency
    $mysqli->begin_transaction();
    
    try {
        // Get enrollment details
        $stmt = $mysqli->prepare("
            SELECT
                e.id,
                e.user_id,
                e.model_id,
                e.status,
                m.title as model_title,
                u.name as user_name,
                u.email as user_email
            FROM mtm_enrollments e
            INNER JOIN mtm_models m ON m.id = e.model_id
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.id = ?
            FOR UPDATE
        ");
        
        if (!$stmt) {
            throw new Exception('Failed to prepare enrollment query');
        }
        
        $stmt->bind_param('i', $enrollmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $enrollment = $result->fetch_assoc();
        $stmt->close();
        
        if (!$enrollment) {
            $mysqli->rollback();
            json_error('NOT_FOUND', 'Enrollment not found');
        }
        
        // Check if enrollment can be dropped
        if ($enrollment['status'] !== 'approved') {
            $mysqli->rollback();
            json_error('VALIDATION_ERROR', 'Only approved enrollments can be dropped');
        }
        
        // Drop the enrollment - set status = 'dropped'
        $dropStmt = $mysqli->prepare("
            UPDATE mtm_enrollments
            SET 
                status = 'dropped',
                updated_at = NOW()
            WHERE id = ?
        ");
        
        if (!$dropStmt) {
            throw new Exception('Failed to prepare drop query');
        }
        
        $dropStmt->bind_param('i', $enrollmentId);
        $success = $dropStmt->execute();
        $affectedRows = $dropStmt->affected_rows;
        $dropStmt->close();
        
        if (!$success || $affectedRows === 0) {
            $mysqli->rollback();
            throw new Exception('Failed to drop enrollment');
        }
        
        // Commit the transaction
        $mysqli->commit();
        
        // Return success response in unified JSON envelope format
        json_success([
            'enrollment_id' => $enrollmentId,
            'status' => 'dropped'
        ], 'Participant dropped from MTM successfully');
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log admin error
    app_log('error', 'Admin enrollment drop error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Failed to drop enrollment');
}