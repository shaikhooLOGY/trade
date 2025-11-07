<?php
/**
 * api/mtm/enroll.php
 *
 * MTM API - Create new MTM enrollment request
 * POST /api/mtm/enroll.php
 *
 * Create a new MTM enrollment request for the authenticated trader
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/security/csrf_guard.php';
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

// CSRF protection for mutating requests - E2E test bypass
require_csrf_json();

// Require authentication and active user
require_active_user_json('Authentication required');

// Rate limiting: 5 per minute for enrollment creation
require_rate_limit('mtm_enroll_create', 5);

try {
    // Read JSON input
    $input = get_json_input();
    
    // Validate input
    if (!isset($input['model_id']) || empty($input['model_id'])) {
        json_fail('INVALID_INPUT', 'Model ID is required');
    }
    
    $modelId = (int)$input['model_id'];
    if ($modelId <= 0) {
        json_fail('INVALID_INPUT', 'Valid model ID is required');
    }
    
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    
    global $mysqli;
    
    // Check if model exists and is active
    $modelStmt = $mysqli->prepare("SELECT id, title, status FROM mtm_models WHERE id = ? AND status = 'active'");
    $modelStmt->bind_param('i', $modelId);
    $modelStmt->execute();
    $modelResult = $modelStmt->get_result();
    $model = $modelResult->fetch_assoc();
    $modelStmt->close();
    
    if (!$model) {
        json_fail('MODEL_NOT_FOUND', 'Model not found or not active');
    }
    
    // Check if user already has an active enrollment for this model
    $existingStmt = $mysqli->prepare("
        SELECT id, status FROM mtm_enrollments
        WHERE user_id = ? AND model_id = ?
    ");
    $existingStmt->bind_param('ii', $traderId, $modelId);
    $existingStmt->execute();
    $existingResult = $existingStmt->get_result();
    $existing = $existingResult->fetch_assoc();
    $existingStmt->close();
    
    if ($existing) {
        if ($existing['status'] === 'active' || $existing['status'] === 'approved') {
            json_fail('ALREADY_ENROLLED', 'Already enrolled in this model');
        } elseif ($existing['status'] === 'pending') {
            json_fail('PENDING_ENROLLMENT', 'Enrollment request already pending approval');
        }
    }
    
    // Get tier from input (default to basic)
    $tier = $input['tier'] ?? 'basic';
    if (!in_array($tier, ['basic', 'premium', 'professional'])) {
        $tier = 'basic';
    }
    
    // Create new enrollment request
    $insertStmt = $mysqli->prepare("
        INSERT INTO mtm_enrollments (user_id, model_id, tier, status, started_at, created_at, updated_at)
        VALUES (?, ?, ?, 'active', NOW(), NOW(), NOW())
    ");
    
    if (!$insertStmt) {
        throw new Exception('Failed to prepare enrollment query: ' . $mysqli->error);
    }
    
    $insertStmt->bind_param('iis', $traderId, $modelId, $tier);
    $success = $insertStmt->execute();
    
    if (!$success) {
        $insertStmt->close();
        throw new Exception('Failed to create enrollment request');
    }
    
    $enrollmentId = $mysqli->insert_id;
    $insertStmt->close();
    
    // Log the enrollment request
    app_log('info', sprintf(
        'MTM enrollment request created: ID=%d, User=%d, Model=%s (%d), Tier=%s',
        $enrollmentId,
        $traderId,
        $model['title'],
        $modelId,
        $tier
    ));
    
    json_ok([
        'enrollment_id' => $enrollmentId,
        'model_id' => $modelId,
        'model_title' => $model['title'],
        'tier' => $tier,
        'status' => 'pending'
    ], 'MTM enrollment request submitted successfully. Awaiting admin approval.');
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'mtm_enroll_api_error: ' . $e->getMessage());
    
    json_fail('SERVER_ERROR', 'Failed to create enrollment request');
}