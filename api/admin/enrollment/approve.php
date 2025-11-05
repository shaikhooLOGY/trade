<?php
/**
 * api/admin/enrollment/approve.php
 *
 * Admin API - Approve MTM enrollment
 * POST /api/admin/enrollment/approve.php
 */

require_once __DIR__ . '/../../../_bootstrap.php';

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
                e.created_at
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
        
        // Check if already approved
        if ($enrollment['status'] === 'approved') {
            $mysqli->rollback();
            json_fail('ALREADY_EXISTS', 'Enrollment is already approved');
        }
        
        // Check if already rejected (can re-approve rejected enrollments if needed)
        if ($enrollment['status'] === 'rejected' && empty($input['force_approve'])) {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Enrollment was previously rejected. Force approve required.');
        }
        
        // Validate model is active
        $modelStmt = $mysqli->prepare("
            SELECT status, start_date, end_date 
            FROM mtm_models 
            WHERE id = ?
        ");
        
        if (!$modelStmt) {
            throw new Exception('Failed to prepare model query');
        }
        
        $modelStmt->bind_param('i', $enrollment['model_id']);
        $modelStmt->execute();
        $modelResult = $modelStmt->get_result();
        $model = $modelResult->fetch_assoc();
        $modelStmt->close();
        
        if (!$model) {
            $mysqli->rollback();
            json_not_found('MTM Model not found');
        }
        
        if ($model['status'] !== 'active') {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Cannot approve enrollment for inactive model');
        }
        
        // Check model date restrictions
        $now = new DateTime();
        if ($model['start_date'] && $now < new DateTime($model['start_date'])) {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Cannot approve enrollment before model start date');
        }
        
        if ($model['end_date'] && $now > new DateTime($model['end_date'])) {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Cannot approve enrollment after model end date');
        }
        
        // Check user status
        $userStmt = $mysqli->prepare("
            SELECT status, email_verified 
            FROM users 
            WHERE id = ?
        ");
        
        if (!$userStmt) {
            throw new Exception('Failed to prepare user query');
        }
        
        $userStmt->bind_param('i', $enrollment['user_id']);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $user = $userResult->fetch_assoc();
        $userStmt->close();
        
        if (!$user) {
            $mysqli->rollback();
            json_not_found('User not found');
        }
        
        if (!in_array($user['status'], ['active', 'approved'], true) || !$user['email_verified']) {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Cannot approve enrollment for inactive or unverified user');
        }
        
        // Check for duplicate active enrollments
        $duplicateStmt = $mysqli->prepare("
            SELECT COUNT(*) as count 
            FROM mtm_enrollments 
            WHERE user_id = ? 
            AND model_id = ? 
            AND status = 'approved'
            AND id != ?
        ");
        
        if (!$duplicateStmt) {
            throw new Exception('Failed to prepare duplicate check query');
        }
        
        $duplicateStmt->bind_param('iii', $enrollment['user_id'], $enrollment['model_id'], $enrollmentId);
        $duplicateStmt->execute();
        $duplicateResult = $duplicateStmt->get_result();
        $duplicateCount = (int)$duplicateResult->fetch_assoc()['count'];
        $duplicateStmt->close();
        
        if ($duplicateCount > 0) {
            $mysqli->rollback();
            json_fail('ALREADY_EXISTS', 'User is already approved for this model');
        }
        
        // Get admin notes from input
        $adminNotes = isset($input['admin_notes']) ? trim($input['admin_notes']) : null;
        if ($adminNotes && strlen($adminNotes) > 1000) {
            $mysqli->rollback();
            json_fail('VALIDATION_ERROR', 'Admin notes must not exceed 1000 characters');
        }
        
        // Approve the enrollment
        $approveStmt = $mysqli->prepare("
            UPDATE mtm_enrollments 
            SET 
                status = 'approved',
                approved_at = NOW(),
                approved_by = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        if (!$approveStmt) {
            throw new Exception('Failed to prepare approve query');
        }
        
        $approveStmt->bind_param('isi', $adminId, $adminNotes, $enrollmentId);
        $success = $approveStmt->execute();
        $affectedRows = $approveStmt->affected_rows;
        $approveStmt->close();
        
        if (!$success || $affectedRows === 0) {
            $mysqli->rollback();
            throw new Exception('Failed to approve enrollment');
        }
        
        // Log the enrollment approval
        $auditLog = sprintf(
            'mtm_enrollment_approve|%d|%d|%d|%s|%s',
            $enrollmentId,
            $enrollment['user_id'],
            $adminId,
            $enrollment['model_name'],
            $adminNotes ?: ''
        );
        app_log('info', $auditLog);
        
        // Commit the transaction
        $mysqli->commit();
        
        // Return success response
        $response = [
            'enrollment_id' => $enrollmentId,
            'status' => 'approved',
            'approved_by' => $adminId,
            'approved_at' => date('c'),
            'user' => [
                'id' => (int)$enrollment['user_id'],
                'name' => $enrollment['user_name'],
                'email' => $enrollment['user_email']
            ],
            'model' => [
                'id' => (int)$enrollment['model_id'],
                'name' => $enrollment['model_name']
            ],
            'admin_notes' => $adminNotes
        ];
        
        json_ok($response, 'Enrollment approved successfully');
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    app_log('error', 'Admin enrollment approve error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to approve enrollment');
}