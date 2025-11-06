<?php
/**
 * api/agent/log.php
 *
 * Agent Log API - Record agent activity event
 * POST /api/agent/log.php
 *
 * Records an agent activity event for PM/agent monitoring and tracking.
 * Security: Requires active user authentication
 * Rate Limiting: 30 requests per minute
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
api_rate_limit('agent_log', 30);

// Require authentication
require_login_json();

// Check CSRF token
validate_csrf_api();

try {
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_error('Invalid JSON input', 400);
    }
    
    // Validate required fields
    $requiredFields = ['actor', 'source', 'action', 'target', 'summary'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            json_error("Missing required field: $field", 400);
        }
    }
    
    // Validate source values
    $validSources = ['kilo', 'codex', 'cursor', 'manual'];
    if (!in_array($input['source'], $validSources, true)) {
        json_error('Invalid source. Must be one of: ' . implode(', ', $validSources), 400);
    }
    
    $userId = (int)$_SESSION['user_id'];
    $timestamp = date('Y-m-d H:i:s');
    
    global $mysqli;
    
    // Insert agent log event
    $stmt = $mysqli->prepare("
        INSERT INTO agent_logs (user_id, actor, source, action, target, summary, payload, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare agent log query');
    }
    
    $payload = $input['payload'] ?? null;
    $payloadJson = $payload ? json_encode($payload) : null;
    
    $stmt->bind_param('isssssss', 
        $userId,
        $input['actor'],
        $input['source'], 
        $input['action'],
        $input['target'],
        $input['summary'],
        $payloadJson,
        $timestamp
    );
    
    $stmt->execute();
    $eventId = $mysqli->insert_id;
    $stmt->close();
    
    // Log agent activity in audit trail
    if (function_exists('audit_admin_action')) {
        audit_admin_action($userId, 'agent_log', 'agent_event', $eventId,
            sprintf('Agent event recorded: %s from %s', $input['action'], $input['source']));
    }
    
    json_success([
        'event_id' => $eventId,
        'timestamp' => $timestamp
    ], 'Agent event recorded successfully', [
        'endpoint' => 'agent_log',
        'actor' => $input['actor'],
        'source' => $input['source'],
        'action' => $input['action']
    ]);
    
} catch (Exception $e) {
    json_error('Failed to record agent event: ' . $e->getMessage(), 500);
}