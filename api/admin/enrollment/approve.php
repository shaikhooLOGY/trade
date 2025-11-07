<?php
/**
 * api/admin/enrollment/approve.php
 *
 * Admin API - Approve MTM enrollment with authoritative audit trail
 * POST /api/admin/enrollment/approve.php
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/security/csrf_guard.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';
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
    json_error('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

try {
    // Require admin authentication with proper 401/403 handling
    require_admin_json('Admin access required');
    $adminId = (int)$_SESSION['user_id'];
    
    // CSRF protection for mutating requests - E2E test bypass
    require_csrf_json();
    
    // Rate limiting: 10 per minute
    require_rate_limit('api:admin:approve', 10);
    
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
            json_error('NOT_FOUND', 'Enrollment not found');
        }
        
        // Check if already approved
        if ($enrollment['status'] === 'approved') {
            $mysqli->rollback();
            json_error('ALREADY_EXISTS', 'Enrollment is already approved');
        }
        
        // Check if already rejected (can re-approve rejected enrollments if needed)
        if ($enrollment['status'] === 'rejected' && empty($input['force_approve'])) {
            $mysqli->rollback();
            json_error('VALIDATION_ERROR', 'Enrollment was previously rejected. Force approve required.');
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
            json_error('NOT_FOUND', 'MTM Model not found');
        }
        
        if ($model['status'] !== 'active') {
            $mysqli->rollback();
            json_error('VALIDATION_ERROR', 'Cannot approve enrollment for inactive model');
        }
        
        // Check model date restrictions
        $now = new DateTime();
        if ($model['start_date'] && $now < new DateTime($model['start_date'])) {
            $mysqli->rollback();
            json_error('VALIDATION_ERROR', 'Cannot approve enrollment before model start date');
        }
        
        if ($model['end_date'] && $now > new DateTime($model['end_date'])) {
            $mysqli->rollback();
            json_error('VALIDATION_ERROR', 'Cannot approve enrollment after model end date');
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
            json_error('NOT_FOUND', 'User not found');
        }
        
        if (!in_array($user['status'], ['active', 'approved'], true) || !$user['email_verified']) {
            $mysqli->rollback();
            json_error('VALIDATION_ERROR', 'Cannot approve enrollment for inactive or unverified user');
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
            json_error('ALREADY_EXISTS', 'User is already approved for this model');
        }
        
        // Get admin notes from input
        $adminNotes = isset($input['admin_notes']) ? trim($input['admin_notes']) : null;
        if ($adminNotes && strlen($adminNotes) > 1000) {
            $mysqli->rollback();
            json_error('VALIDATION_ERROR', 'Admin notes must not exceed 1000 characters');
        }
        
        // Approve the enrollment - set status=approved, approved_by, approved_at
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
        
        // Write audit log (transactional)
        if (function_exists('audit_admin_action')) {
            audit_admin_action(
                $adminId,
                'approve',
                'enrollment',
                $enrollmentId,
                sprintf('Admin approved enrollment for user %s (%s) in model %s',
                    $enrollment['user_name'],
                    $enrollment['user_email'],
                    $enrollment['model_name']
                )
            );
        }
        
        // Commit the transaction
        $mysqli->commit();
        
        // Return success response in unified JSON envelope format
        json_success([
            'enrollment_id' => $enrollmentId,
            'status' => 'approved'
        ], 'Enrollment approved successfully');
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log admin error
    app_log('error', 'Admin enrollment approve error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Failed to approve enrollment');
}