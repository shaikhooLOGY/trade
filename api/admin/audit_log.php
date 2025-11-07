<?php
/**
 * api/admin/audit_log.php
 * 
 * Simple Admin API - Basic Audit Log
 * GET /api/admin/audit_log.php?limit=10
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Require admin authentication
require_admin_json('Admin access required');

try {
    global $mysqli;
    
    // Simple query to get recent audit events
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 10;
    
    // Check if audit_events table exists
    $checkTable = $mysqli->query("SHOW TABLES LIKE 'audit_events'");
    if ($checkTable->num_rows == 0) {
        // Table doesn't exist, return empty array
        json_ok([], 'Audit log retrieved (no events table)', [
            'count' => 0,
            'table_exists' => false
        ]);
        exit;
    }
    
    $stmt = $mysqli->prepare("
        SELECT * FROM audit_events 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare audit query');
    }
    
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'id' => (int)$row['id'],
            'event_type' => $row['event_type'],
            'event_category' => $row['event_category'],
            'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
            'admin_id' => $row['admin_id'] ? (int)$row['admin_id'] : null,
            'target_type' => $row['target_type'],
            'description' => $row['description'],
            'severity' => $row['severity'],
            'status' => $row['status'],
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    // Return success response
    json_ok($events, 'Audit log retrieved successfully', [
        'count' => count($events),
        'table_exists' => true
    ]);
    
} catch (Exception $e) {
    json_error('Failed to retrieve audit log: ' . $e->getMessage(), 500);
}