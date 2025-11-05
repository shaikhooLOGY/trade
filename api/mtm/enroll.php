<?php
/**
 * api/mtm/enroll.php
 *
 * MTM API - Enroll user in MTM model
 * POST /api/mtm/enroll.php
 */

require_once __DIR__ . '/../../_bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

try {
    // Require authentication and active user
    require_active_user_json('Authentication required');
    
    // Check CSRF for mutating operations
    csrf_api_middleware();
    
    // Rate limiting
    if (!rate_limit_api_middleware('mtm_enroll', 5)) {
        exit; // Rate limit response already sent
    }
    
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
        // Success response
        json_ok([
            'enrollment_id' => $result['enrollment_id'],
            'unlocked_task_id' => $result['unlocked_task_id']
        ], 'Enrollment successful');
    } else {
        // Handle specific error cases
        if ($result['error'] === 'ALREADY_ENROLLED') {
            json_fail('ALREADY_ENROLLED', 'Trader is already enrolled in this model');
        } else {
            json_fail('SERVER_ERROR', 'An error occurred during enrollment');
        }
    }
    
} catch (Exception $e) {
    app_log('error', 'MTM enroll error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to process enrollment');
}