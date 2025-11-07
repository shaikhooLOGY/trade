<?php
/**
 * api/admin/mtm/model_update.php
 * 
 * Admin API - Update MTM Model
 * POST /api/admin/mtm/model_update.php
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
    $adminUser = require_admin_json('Admin access required for model updates');
    $adminId = (int)$adminUser['id'];
    
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
    if (empty($input['id']) || empty($input['title']) || empty($input['tier'])) {
        json_fail('VALIDATION_ERROR', 'Missing required fields: id, title, tier');
    }
    
    $modelId = (int)$input['id'];
    $title = trim($input['title']);
    $tier = strtolower(trim($input['tier']));
    $difficulty = strtolower(trim($input['difficulty'] ?? 'beginner'));
    $description = trim($input['description'] ?? '');
    $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;
    
    // Validate fields
    if ($modelId < 1) {
        json_fail('VALIDATION_ERROR', 'Model ID must be a positive integer');
    }
    
    if (strlen($title) < 3 || strlen($title) > 255) {
        json_fail('VALIDATION_ERROR', 'Title must be between 3 and 255 characters');
    }
    
    $validTiers = ['basic', 'intermediate', 'advanced'];
    if (!in_array($tier, $validTiers, true)) {
        json_fail('VALIDATION_ERROR', 'Tier must be one of: ' . implode(', ', $validTiers));
    }
    
    $validDifficulties = ['beginner', 'intermediate', 'advanced'];
    if (!in_array($difficulty, $validDifficulties, true)) {
        json_fail('VALIDATION_ERROR', 'Difficulty must be one of: ' . implode(', ', $validDifficulties));
    }
    
    global $mysqli;
    
    // Check if model exists
    $stmt = $mysqli->prepare("SELECT id FROM mtm_models WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare model existence check');
    }
    
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        json_fail('NOT_FOUND', 'Model not found', 404);
    }
    $stmt->close();
    
    // Check for duplicate title (excluding current model)
    $stmt = $mysqli->prepare("SELECT id FROM mtm_models WHERE title = ? AND id != ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare duplicate check query');
    }
    
    $stmt->bind_param('si', $title, $modelId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        json_fail('DUPLICATE_TITLE', 'A model with this title already exists');
    }
    $stmt->close();
    
    // Update the model
    $stmt = $mysqli->prepare("
        UPDATE mtm_models 
        SET title = ?, description = ?, tier = ?, difficulty = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare model update query');
    }
    
    $stmt->bind_param('ssssiis', $title, $description, $tier, $difficulty, $isActive ? 1 : 0, $modelId);
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to update model: ' . $error);
    }
    
    $stmt->close();
    
    // Update rules if provided
    if (isset($input['rules']) && is_array($input['rules'])) {
        // Delete existing rules
        $deleteStmt = $mysqli->prepare("DELETE FROM mtm_model_rules WHERE model_id = ?");
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $modelId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }
        
        // Insert new rules
        $rulesStmt = $mysqli->prepare("
            INSERT INTO mtm_model_rules (model_id, rule_key, rule_value, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        
        if ($rulesStmt) {
            foreach ($input['rules'] as $rule) {
                if (isset($rule['key']) && isset($rule['val'])) {
                    $ruleKey = trim($rule['key']);
                    $ruleValue = (float)$rule['val'];
                    $rulesStmt->bind_param('isd', $modelId, $ruleKey, $ruleValue);
                    $rulesStmt->execute();
                }
            }
            $rulesStmt->close();
        }
    }
    
    // Log admin action
    if (function_exists('log_admin_action')) {
        log_admin_action('model_update', sprintf(
            'Admin updated MTM model: %s (ID: %d) - Tier: %s, Difficulty: %s',
            $title,
            $modelId,
            $tier,
            $difficulty
        ), [
            'admin_id' => $adminId,
            'target_type' => 'mtm_model',
            'target_id' => $modelId,
            'metadata' => [
                'model_id' => $modelId,
                'title' => $title,
                'tier' => $tier,
                'difficulty' => $difficulty,
                'description' => $description,
                'is_active' => $isActive,
                'rules_count' => count($input['rules'] ?? [])
            ],
            'severity' => 'medium'
        ]);
    }
    
    json_ok([
        'model_id' => $modelId,
        'title' => $title,
        'tier' => $tier,
        'difficulty' => $difficulty,
        'description' => $description,
        'is_active' => $isActive,
        'updated_at' => date('Y-m-d H:i:s')
    ], 'MTM model updated successfully', [
        'model_id' => $modelId,
        'admin_id' => $adminId
    ], 200, 'admin_mtm_model_update');
    
} catch (Exception $e) {
    app_log('error', 'Admin MTM model update error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to update MTM model: ' . $e->getMessage());
}
?>