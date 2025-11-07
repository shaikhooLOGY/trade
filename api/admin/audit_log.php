<?php
/**
 * api/admin/audit_log.php
 *
 * Enhanced Admin API - Audit Log with pagination and filtering
 * GET /api/admin/audit_log.php?event_type=...&user_id=...&limit=...&offset=...
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
    
    // Parse query parameters for filtering
    $filters = [];
    $params = [];
    $paramTypes = [];
    $whereConditions = ['1=1']; // Always true base condition
    
    // Event type filter
    if (isset($_GET['event_type']) && trim($_GET['event_type']) !== '') {
        $eventType = trim($_GET['event_type']);
        $filters['event_type'] = $eventType;
        $whereConditions[] = 'event_type LIKE ?';
        $params[] = '%' . $eventType . '%';
        $paramTypes[] = 's';
    }
    
    // User ID filter
    if (isset($_GET['user_id']) && trim($_GET['user_id']) !== '') {
        $userIdFilter = (int)$_GET['user_id'];
        $filters['user_id'] = $userIdFilter;
        $whereConditions[] = 'user_id = ?';
        $params[] = $userIdFilter;
        $paramTypes[] = 'i';
    }
    
    // Search query filter (q)
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $query = trim($_GET['q']);
        $filters['q'] = $query;
        $whereConditions[] = '(description LIKE ? OR event_type LIKE ?)';
        $params[] = '%' . $query . '%';
        $params[] = '%' . $query . '%';
        $paramTypes[] = 's';
        $paramTypes[] = 's';
    }
    
    // Since filter (start date)
    if (isset($_GET['since']) && trim($_GET['since']) !== '') {
        $since = trim($_GET['since']);
        $filters['since'] = $since;
        $whereConditions[] = 'created_at >= ?';
        $params[] = $since;
        $paramTypes[] = 's';
    }
    
    // Until filter (end date)
    if (isset($_GET['until']) && trim($_GET['until']) !== '') {
        $until = trim($_GET['until']);
        $filters['until'] = $until;
        $whereConditions[] = 'created_at <= ?';
        $params[] = $until;
        $paramTypes[] = 's';
    }
    
    // Pagination
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit'])
        ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $params[] = $limit;
    $paramTypes[] = 'i';
    
    // Offset for pagination
    $offset = isset($_GET['offset']) && is_numeric($_GET['offset'])
        ? max(0, (int)$_GET['offset']) : 0;
    $params[] = $offset;
    $paramTypes[] = 'i';
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count for pagination info
    $countSql = "SELECT COUNT(*) as total FROM audit_events WHERE $whereClause";
    $countStmt = $mysqli->prepare($countSql);
    if (!$countStmt) {
        throw new Exception('Failed to prepare audit log count query');
    }
    
    if (!empty($params) && count($params) > 2) { // Remove limit and offset for count
        $countParams = array_slice($params, 0, -2);
        $countParamTypes = array_slice($paramTypes, 0, -2);
        $countStmt->bind_param(implode('', $countParamTypes), ...$countParams);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    // Get audit events with filtering and pagination
    $sql = "SELECT * FROM audit_events WHERE $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare audit log query');
    }
    
    $stmt->bind_param(implode('', $paramTypes), ...$params);
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
    
    // Log admin access to audit log
    $adminId = (int)$_SESSION['user_id'];
    app_log('info', sprintf(
        'Admin accessed audit log: ID=%d, Filters=%s, Count=%d',
        $adminId,
        json_encode($filters),
        count($events)
    ));
    
    // Return success response in unified JSON envelope format
    json_success([
        'data' => [
            'rows' => $events,
            'meta' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($events)
            ]
        ]
    ], 'Audit log retrieved successfully');
    
} catch (Exception $e) {
    json_error('SERVER_ERROR', 'Failed to retrieve audit log: ' . $e->getMessage());
}