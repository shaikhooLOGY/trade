<?php
/**
 * api/admin/mtm/model_delete.php
 * 
 * Admin API - Delete MTM Model
 * POST /api/admin/mtm/model_delete.php
 */

require_once __DIR__ . '/../../_bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Idempotency-Key');

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
    require_admin_json('Admin access required for model deletion');
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    
    // Check CSRF for mutating operations
    csrf_api_middleware();
    
    // Handle idempotency
    $idempotencyKey = validate_idempotency_key();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_fail('INVALID_JSON', 'Invalid JSON input');
    }
    
    // Validate required fields
    if (empty($input['id'])) {
        json_fail('VALIDATION_ERROR', 'Missing required field: id');
    }
    
    $modelId = (int)$input['id'];
    
    if ($modelId < 1) {
        json_fail('VALIDATION_ERROR', 'Model ID must be a positive integer');
    }
    
    global $mysqli;
    
    // Check if model exists
    $stmt = $mysqli->prepare("SELECT id, title FROM mtm_models WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare model existence check');
    }
    
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        json_fail('NOT_FOUND', 'Model not found', [], null, 404);
    }
    
    $model = $result->fetch_assoc();
    $stmt->close();
    
    // Check if model has enrollments
    $enrollmentStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM mtm_enrollments WHERE model_id = ?");
    if ($enrollmentStmt) {
        $enrollmentStmt->bind_param('i', $modelId);
        $enrollmentStmt->execute();
        $enrollmentResult = $enrollmentStmt->get_result();
        $enrollmentCount = (int)$enrollmentResult->fetch_assoc()['count'];
        $enrollmentStmt->close();
        
        if ($enrollmentCount > 0) {
            json_fail('HAS_ENROLLMENTS', 
                "Cannot delete model with $enrollmentCount active enrollments. Please resolve all enrollments first."
            );
        }
    }
    
    // Delete model rules first (foreign key constraint)
    $deleteRulesStmt = $mysqli->prepare("DELETE FROM mtm_model_rules WHERE model_id = ?");
    if ($deleteRulesStmt) {
        $deleteRulesStmt->bind_param('i', $modelId);
        $deleteRulesStmt->execute();
        $deleteRulesStmt->close();
    }
    
    // Delete the model
    $deleteModelStmt = $mysqli->prepare("DELETE FROM mtm_models WHERE id = ?");
    if (!$deleteModelStmt) {
        throw new Exception('Failed to prepare model deletion query');
    }
    
    $deleteModelStmt->bind_param('i', $modelId);
    
    if (!$deleteModelStmt->execute()) {
        $error = $deleteModelStmt->error;
        $deleteModelStmt->close();
        throw new Exception('Failed to delete model: ' . $error);
    }
    
    $affectedRows = $deleteModelStmt->affected_rows;
    $deleteModelStmt->close();
    
    if ($affectedRows === 0) {
        json_fail('NOT_FOUND', 'Model not found or already deleted', [], null, 404);
    }
    
    // Log admin action
    if (function_exists('log_admin_action')) {
        log_admin_action('model_delete', sprintf(
            'Admin deleted MTM model: %s (ID: %d)',
            $model['title'],
            $modelId
        ), [
            'admin_id' => $adminId,
            'target_type' => 'mtm_model',
            'target_id' => $modelId,
            'metadata' => [
                'model_id' => $modelId,
                'model_title' => $model['title'],
                'deleted_at' => date('Y-m-d H:i:s')
            ],
            'severity' => 'high'
        ]);
    }
    
    json_ok([
        'model_id' => $modelId,
        'title' => $model['title'],
        'deleted_at' => date('Y-m-d H:i:s')
    ], 'MTM model deleted successfully', [
        'model_id' => $modelId,
        'admin_id' => $adminId
    ], 200, 'admin_mtm_model_delete');
    
} catch (Exception $e) {
    app_log('error', 'Admin MTM model deletion error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to delete MTM model: ' . $e->getMessage());
}
?>