<?php
/**
 * api/mtm/enroll.php
 *
 * MTM API - Enroll user in MTM model with authoritative audit trail
 * POST /api/mtm/enroll.php
 */

require_once __DIR__ . '/../../_bootstrap.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';
require_once __DIR__ . '/../../includes/logger/audit_log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

try {
    // Require authentication and active user
    require_active_user_json('Authentication required');
    
    // Check CSRF for mutating operations
    csrf_api_middleware();
    
    // Rate limiting: 30 per minute
    require_rate_limit('api:mtm:enroll', 30);
    
    // Read JSON input
    $input = get_json_input();
    
    // Validate required fields
    validate_required_fields($input, ['model_id', 'tier']);
    
    $modelId = (int)($input['model_id'] ?? 0);
    $tier = (string)($input['tier'] ?? '');
    
    if ($modelId < 1) {
        json_fail('VALIDATION_ERROR', 'model_id must be a positive integer');
    }
    
    if (!in_array($tier, ['basic', 'intermediate', 'advanced'], true)) {
        json_fail('VALIDATION_ERROR', 'tier must be basic|intermediate|advanced');
    }
    
    global $mysqli;
    
    // Verify model exists
    $stmt = $mysqli->prepare("SELECT id FROM mtm_models WHERE id=? AND is_active=1 LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare model query');
    }
    
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows === 0) {
        $stmt->close();
        json_fail('MODEL_NOT_FOUND', 'Selected model not available');
    }
    $stmt->close();
    
    // Call enrollment service
    $result = mtm_enroll((int)$_SESSION['user_id'], $modelId, $tier);
    
    if ($result['success']) {
        // Log successful enrollment using authoritative audit function
        audit_enroll(
            (int)$_SESSION['user_id'],
            'enroll',
            $result['enrollment_id'],
            sprintf('User enrolled in MTM model ID %d at %s tier', $modelId, $tier)
        );
        
        // Success response
        json_ok([
            'enrollment_id' => $result['enrollment_id'],
            'unlocked_task_id' => $result['unlocked_task_id']
        ], 'Enrollment successful', [], 200);
    } else {
        // Log failed enrollment attempt
        audit_enroll(
            (int)$_SESSION['user_id'],
            'enroll_failed',
            null,
            sprintf('Failed to enroll user in MTM model ID %d at %s tier - Error: %s',
                $modelId,
                $tier,
                $result['error'] ?? 'Unknown error'
            )
        );
        
        // Handle specific error cases
        if ($result['error'] === 'ALREADY_ENROLLED') {
            json_fail('ALREADY_ENROLLED', 'Trader is already enrolled in this model');
        } else {
            json_fail('SERVER_ERROR', 'An error occurred during enrollment');
        }
    }
    
} catch (Exception $e) {
    // Log system error
    audit_admin_action(
        $_SESSION['user_id'] ?? null,
        'system_error',
        'enrollment',
        null,
        'MTM enroll error: ' . $e->getMessage()
    );
    
    app_log('error', 'MTM enroll error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to process enrollment');
}