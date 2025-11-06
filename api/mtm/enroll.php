<?php
/**
 * api/mtm/enroll.php
 *
 * Standardized MTM API - Enroll user in MTM model
 * POST /api/mtm/enroll.php
 */

require_once __DIR__ . '/../_bootstrap.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('HTTP/1.1 200 OK');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Idempotency-Key, X-CSRF-Token');
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Apply rate limiting
api_rate_limit('mtm_enroll', 30);

// Require authentication
require_login_json();

// Check CSRF token
validate_csrf_api();

// Handle idempotency
$idempotencyKey = validate_idempotency_key();
if ($idempotencyKey) {
    // Idempotency will be handled by process_idempotency_request in the service
}

try {
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_error('Invalid JSON input', 400);
    }
    
    // Validate required fields
    if (empty($input['model_id']) || empty($input['tier'])) {
        json_error('Missing required fields: model_id, tier', 400);
    }
    
    $modelId = (int)($input['model_id'] ?? 0);
    $tier = (string)($input['tier'] ?? '');
    
    if ($modelId < 1) {
        json_error('model_id must be a positive integer', 400);
    }
    
    if (!in_array($tier, ['basic', 'intermediate', 'advanced'], true)) {
        json_error('tier must be basic, intermediate, or advanced', 400);
    }
    
    global $mysqli;
    
    // Verify model exists
    $stmt = $mysqli->prepare("SELECT id FROM mtm_models WHERE id=? AND is_active=1 LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare model query');
    }
    
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        json_error('Model not found or not active', 404);
    }
    $stmt->close();
    
    // Call enrollment service (idempotency will be implemented in Phase D)
    $userId = (int)$_SESSION['user_id'];
    $result = mtm_enroll($userId, $modelId, $tier);
    
    if ($result['success']) {
        // Log successful enrollment
        if (function_exists('audit_admin_action')) {
            audit_admin_action($userId, 'enroll', 'mtm_model', $modelId,
                sprintf('User enrolled in MTM model %d at %s tier', $modelId, $tier));
        }
        
        json_success([
            'enrollment_id' => $result['enrollment_id'],
            'unlocked_task_id' => $result['unlocked_task_id']
        ], 'Enrollment successful', [
            'endpoint' => 'mtm_enroll',
            'model_id' => $modelId,
            'tier' => $tier
        ]);
    } else {
        // Log failed enrollment
        if (function_exists('audit_admin_action')) {
            audit_admin_action($userId, 'enroll_failed', 'mtm_model', $modelId,
                sprintf('Failed to enroll in MTM model %d at %s tier: %s',
                    $modelId, $tier, $result['error'] ?? 'Unknown error'));
        }
        
        // Handle specific error cases
        if ($result['error'] === 'ALREADY_ENROLLED') {
            json_error('User is already enrolled in this model', 409);
        } else {
            json_error('Enrollment failed: ' . ($result['error'] ?? 'Unknown error'), 500);
        }
    }
    
} catch (Exception $e) {
    json_error('Failed to process enrollment: ' . $e->getMessage(), 500);
}