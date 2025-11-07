<?php
/**
 * api/mtm/enroll.php
 *
 * MTM API - Create new MTM enrollment request with idempotency
 * POST /api/mtm/enroll.php
 *
 * Create a new MTM enrollment request for the authenticated trader
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/security/csrf_guard.php';
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
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
        json_error('VALIDATION_ERROR', 'Model ID is required');
    }
    
    $modelId = (int)$input['model_id'];
    if ($modelId <= 0) {
        json_error('VALIDATION_ERROR', 'Valid model ID is required');
    }
    
    // Get trader ID from session
    $traderId = (int)$_SESSION['user_id'];
    
    global $mysqli;
    
    // Start transaction for enrollment creation
    $mysqli->begin_transaction();
    
    try {
        // Check if model exists and is active
        $modelStmt = $mysqli->prepare("SELECT id, title, status FROM mtm_models WHERE id = ? AND status = 'active'");
        $modelStmt->bind_param('i', $modelId);
        $modelStmt->execute();
        $modelResult = $modelStmt->get_result();
        $model = $modelResult->fetch_assoc();
        $modelStmt->close();
        
        if (!$model) {
            $mysqli->rollback();
            json_error('NOT_FOUND', 'Model not found or not active');
        }
        
        // Check if user already has an enrollment for this model (status in {pending,approved,rejected})
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
            $status = $existing['status'];
            if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
                $mysqli->rollback();
                json_error('ALREADY_EXISTS', 'User already has enrollment status: ' . $status);
            }
        }
        
        // Handle idempotency
        $idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
        $idempotentResponse = null;
        
        if ($idempotencyKey) {
            $keyHash = hash('sha256', $idempotencyKey . json_encode($input, 0));
            
            // Check if this idempotency key was already used
            $idempotencyStmt = $mysqli->prepare("
                SELECT response_data FROM idempotency_keys
                WHERE key_hash = ? AND endpoint_path = ?
            ");
            $path = '/api/mtm/enroll.php';
            $idempotencyStmt->bind_param('ss', $keyHash, $path);
            $idempotencyStmt->execute();
            $idempotencyResult = $idempotencyStmt->get_result();
            $existingIdempotency = $idempotencyResult->fetch_assoc();
            $idempotencyStmt->close();
            
            if ($existingIdempotency) {
                // Return the previous response
                $mysqli->commit();
                $previousResponse = json_decode($existingIdempotency['response_data'], true);
                $previousResponse['meta']['idempotent'] = true;
                $previousResponse['meta']['idempotency_key'] = $idempotencyKey;
                
                header('Content-Type: application/json');
                echo json_encode($previousResponse, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                exit;
            }
        }
        
        // Get tier from input (default to basic)
        $tier = $input['tier'] ?? 'basic';
        if (!in_array($tier, ['basic', 'premium', 'professional'])) {
            $tier = 'basic';
        }
        
        // Create new enrollment request with status = 'pending'
        $insertStmt = $mysqli->prepare("
            INSERT INTO mtm_enrollments (user_id, model_id, tier, status, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', NOW(), NOW())
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
        
        // Store idempotency key if provided
        if ($idempotencyKey) {
            $responseData = json_encode([
                'success' => true,
                'data' => ['enrollment_id' => $enrollmentId],
                'message' => 'MTM enrollment request submitted successfully. Awaiting admin approval.',
                'timestamp' => date('c'),
                'meta' => ['idempotent' => false, 'idempotency_key' => $idempotencyKey]
            ]);
            
            $idempotencyInsert = $mysqli->prepare("
                INSERT INTO idempotency_keys (key_hash, endpoint_path, request_method, user_id, response_data, created_at_ts)
                VALUES (?, ?, 'POST', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE response_data = VALUES(response_data)
            ");
            $idempotencyInsert->bind_param('ssiss', $keyHash, $path, $traderId, $responseData);
            $idempotencyInsert->execute();
            $idempotencyInsert->close();
        }
        
        // Log the enrollment request
        app_log('info', sprintf(
            'MTM enrollment request created: ID=%d, User=%d, Model=%s (%d), Tier=%s',
            $enrollmentId,
            $traderId,
            $model['title'],
            $modelId,
            $tier
        ));
        
        // Commit transaction
        $mysqli->commit();
        
        // Return success response in unified format
        json_success([
            'enrollment_id' => $enrollmentId
        ], 'MTM enrollment request submitted successfully. Awaiting admin approval.', [
            'idempotent' => false,
            'idempotency_key' => $idempotencyKey
        ]);
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'mtm_enroll_api_error: ' . $e->getMessage());
    
    json_error('SERVER_ERROR', 'Failed to create enrollment request');
}