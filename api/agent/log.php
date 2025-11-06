<?php
/**
 * api/agent/log.php
 *
 * Agent Log API - Create new agent log entry
 * POST /api/agent/log.php
 *
 * Creates a new agent log entry with proper authentication, rate limiting, and CSRF protection
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/security/csrf_guard.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Require authentication and active user
require_active_user_json();

// Rate limiting: 30 per minute for agent logging
require_rate_limit('agent_log', 30);

// CSRF protection
require_csrf_json();

try {
    global $mysqli;
    
    // Read JSON input
    $input = get_json_input();
    
    // Validate required fields
    if (!isset($input['event']) || empty($input['event'])) {
        json_error('Invalid input', 'Event field is required', null, 400);
    }
    
    $event = trim($input['event']);
    $meta = isset($input['meta']) ? $input['meta'] : null;
    
    if ($meta !== null && !is_array($meta)) {
        json_error('Invalid input', 'Meta must be an object', null, 400);
    }
    
    // Get user ID from session
    $userId = (int)$_SESSION['user_id'];
    
    // Insert into agent_logs table using guarded schema
    $stmt = $mysqli->prepare("
        INSERT INTO agent_logs (user_id, event, meta_json, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare agent log query: ' . $mysqli->error);
    }
    
    $metaJson = $meta !== null ? json_encode($meta) : null;
    $stmt->bind_param('iss', $userId, $event, $metaJson);
    
    $success = $stmt->execute();
    if (!$success) {
        throw new Exception('Failed to execute agent log query: ' . $stmt->error);
    }
    
    $logId = $mysqli->insert_id;
    $stmt->close();
    
    // Return success response
    json_success([
        'id' => $logId,
        'event' => $event,
        'user_id' => $userId
    ], 'Agent event logged successfully');
    
} catch (Exception $e) {
    json_error('Agent log error: ' . $e->getMessage(), 500);
}