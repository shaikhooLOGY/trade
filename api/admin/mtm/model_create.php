<?php
/**
 * api/admin/mtm/model_create.php
 * 
 * Admin API - Create MTM Model
 * POST /api/admin/mtm/model_create.php
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
    $adminUser = require_admin_json('Admin access required for model creation');
    $adminId = (int)$adminUser['id'];
    
    // Check CSRF for mutating operations
    csrf_api_middleware();
    
    // Handle idempotency
    $idempotencyKey = validate_idempotency_key();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_fail('INVALID_JSON', 'Invalid JSON input', 400);
    }
    
    // Validate required fields
    if (empty($input['title']) || empty($input['tier'])) {
        json_fail('VALIDATION_ERROR', 'Missing required fields: title, tier', 400);
    }
    
    $title = trim($input['title']);
    $tier = strtolower(trim($input['tier']));
    $difficulty = strtolower(trim($input['difficulty'] ?? 'beginner'));
    $description = trim($input['description'] ?? '');
    $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;
    
    // Validate fields
    if (strlen($title) < 3 || strlen($title) > 255) {
        json_fail('VALIDATION_ERROR', 'Title must be between 3 and 255 characters', 400);
    }
    
    $validTiers = ['basic', 'intermediate', 'advanced'];
    if (!in_array($tier, $validTiers, true)) {
        json_fail('VALIDATION_ERROR', 'Tier must be one of: ' . implode(', ', $validTiers), 400);
    }
    
    $validDifficulties = ['beginner', 'intermediate', 'advanced'];
    if (!in_array($difficulty, $validDifficulties, true)) {
        json_fail('VALIDATION_ERROR', 'Difficulty must be one of: ' . implode(', ', $validDifficulties), 400);
    }
    
    global $mysqli;
    
    // Check for duplicate title
    $stmt = $mysqli->prepare("SELECT id FROM mtm_models WHERE title = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare duplicate check query');
    }
    
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        json_fail('DUPLICATE_TITLE', 'A model with this title already exists', 409);
    }
    $stmt->close();
    
    // Create the model
    $stmt = $mysqli->prepare("
        INSERT INTO mtm_models (title, description, tier, difficulty, is_active, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare model creation query');
    }
    
    $stmt->bind_param('ssssiis', $title, $description, $tier, $difficulty, $isActive ? 1 : 0, $adminId);
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to create model: ' . $error);
    }
    
    $modelId = $stmt->insert_id;
    $stmt->close();
    
    // Process rules if provided
    if (!empty($input['rules']) && is_array($input['rules'])) {
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
        log_admin_action('model_create', sprintf(
            'Admin created MTM model: %s (ID: %d) - Tier: %s, Difficulty: %s',
            $title,
            $modelId,
            $tier,
            $difficulty
        ), [
            'admin_id' => $adminId,
            'target_type' => 'mtm_model',
            'target_id' => $modelId,
            'metadata' => [
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
        'created_at' => date('Y-m-d H:i:s')
    ], 'MTM model created successfully', [
        'model_id' => $modelId,
        'admin_id' => $adminId
    ], 201, 'admin_mtm_model_create');
    
} catch (Exception $e) {
    app_log('error', 'Admin MTM model creation error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to create MTM model: ' . $e->getMessage(), 500);
}
?>