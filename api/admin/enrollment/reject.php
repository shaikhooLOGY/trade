<?php
/**
 * api/admin/enrollment/reject.php
 *
 * Admin API - Reject MTM enrollment with authoritative audit trail
 * POST /api/admin/enrollment/reject.php
 */

require_once __DIR__ . '/../../_bootstrap.php';
require_once __DIR__ . '/../../includes/logger/audit_log.php';

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
    json_fail('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

try {
    // Require admin authentication
    $adminUser = require_admin_json('Admin access required');
    $adminId = (int)$adminUser['id'];
    
    // Check CSRF for mutating operations
    csrf_api_middleware();
    
    // Get JSON input
    $input = get_json_input();
    
    // Validate required fields
    validate_required_fields($input, ['enrollment_id']);
    
    $enrollmentId = (int)$input['enrollment_id'];
    
    if ($enrollmentId <= 0) {
        json_fail('VALIDATION_ERROR', 'Invalid enrollment ID provided');
    }
    
    // Validate reason if provided
    $rejectionReason = isset($input['reason']) ? trim($input['reason']) : null;
    if ($rejectionReason && strlen($rejectionReason) > 1000) {
        json_fail('VALIDATION_ERROR', 'Rejection reason must not exceed 1000 characters');
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
                m.name as model_name,
                u.name as user_name,
                u.email as user_email,
                e.status,
                e.created_at,
                e.rejected_at,
                e.approved_at
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
            json_not_found('Enrollment not found');
        }
        
        // Check if already rejected
        if ($enrollment['status'] === 'rejected') {
            $mysqli->rollback();
            json_fail('ALREADY_EXISTS', 'Enrollment is already rejected');
        }
        
        // Check if already approved
        if ($enrollment['status'] === 'approved') {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Cannot reject an already approved enrollment');
        }
        
        // Validate rejection reason (required for rejecting pending enrollments)
        if ($enrollment['status'] === 'pending' && empty($rejectionReason)) {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Rejection reason is required for pending enrollments');
        }
        
        // Get admin notes from input
        $adminNotes = isset($input['admin_notes']) ? trim($input['admin_notes']) : null;
        if ($adminNotes && strlen($adminNotes) > 1000) {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Admin notes must not exceed 1000 characters');
        }
        
        // Reject the enrollment
        $rejectStmt = $mysqli->prepare("
            UPDATE mtm_enrollments
            SET
                status = 'rejected',
                rejected_at = NOW(),
                rejected_by = ?,
                rejection_reason = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        if (!$rejectStmt) {
            throw new Exception('Failed to prepare reject query');
        }
        
        $rejectStmt->bind_param('issi', $adminId, $rejectionReason, $adminNotes, $enrollmentId);
        $success = $rejectStmt->execute();
        $affectedRows = $rejectStmt->affected_rows;
        $rejectStmt->close();
        
        if (!$success || $affectedRows === 0) {
            $mysqli->rollback();
            throw new Exception('Failed to reject enrollment');
        }
        
        // Log the enrollment rejection using authoritative audit function
        audit_approve(
            $adminId,
            'reject',
            'enrollment',
            $enrollmentId,
            sprintf('Admin rejected enrollment for user %s (%s) in model %s. Reason: %s',
                $enrollment['user_name'],
                $enrollment['user_email'],
                $enrollment['model_name'],
                $rejectionReason ?: 'No reason provided'
            )
        );
        
        // Commit the transaction
        $mysqli->commit();
        
        // Return success response
        $response = [
            'enrollment_id' => $enrollmentId,
            'status' => 'rejected',
            'rejected_by' => $adminId,
            'rejected_at' => date('c'),
            'rejection_reason' => $rejectionReason,
            'user' => [
                'id' => (int)$enrollment['user_id'],
                'name' => $enrollment['user_name'],
                'email' => $enrollment['user_email']
            ],
            'model' => [
                'id' => (int)$enrollment['model_id'],
                'name' => $enrollment['model_name']
            ],
            'admin_notes' => $adminNotes,
            'previous_status' => $enrollment['status'],
            'created_at' => $enrollment['created_at']
        ];
        
        json_ok($response, 'Enrollment rejected successfully');
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log admin error using authoritative audit function
    audit_admin_action(
        $adminId ?? null,
        'system_error',
        'enrollment_rejection',
        $enrollmentId ?? null,
        'Admin enrollment reject error: ' . $e->getMessage()
    );
    
    app_log('error', 'Admin enrollment reject error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to reject enrollment');
}