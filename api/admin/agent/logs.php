<?php
/**
 * api/admin/agent/logs.php
 *
 * Admin Agent Log API - Get agent activity logs
 * GET /api/admin/agent/logs.php
 *
 * Admin endpoint for viewing agent activity logs with filtering and pagination.
 * Security: Requires admin privileges
 * Rate Limiting: 10 requests per minute
 */

require_once __DIR__ . '/../_bootstrap.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('HTTP/1.1 200 OK');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Idempotency-Key, X-CSRF-Token');
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

// Apply rate limiting (stricter for admin endpoint)
require_rate_limit('admin_agent_logs', 10);

// Require admin authentication
require_admin_json();

try {
    global $mysqli;
    
    // Parse query parameters for filtering
    $filters = [];
    $params = [];
    $paramTypes = [];
    $whereConditions = ['1=1']; // Always true base condition
    
    // User ID filter
    if (isset($_GET['user_id']) && trim($_GET['user_id']) !== '') {
        $userIdFilter = (int)$_GET['user_id'];
        $filters['user_id'] = $userIdFilter;
        $whereConditions[] = 'user_id = ?';
        $params[] = $userIdFilter;
        $paramTypes[] = 'i';
    }
    
    // Event filter (search in event field)
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $eventQuery = trim($_GET['q']);
        $filters['q'] = $eventQuery;
        $whereConditions[] = 'event LIKE ?';
        $params[] = '%' . $eventQuery . '%';
        $paramTypes[] = 's';
    }
    
    // Pagination
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit'])
        ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $params[] = $limit;
    $paramTypes[] = 'i';
    
    // Offset for pagination
    $offset = isset($_GET['offset']) && is_numeric($_GET['offset'])
        ? max(0, (int)$_GET['offset']) : 0;
    $params[] = $offset;
    $paramTypes[] = 'i';
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count for pagination info
    $countSql = "SELECT COUNT(*) as total FROM agent_logs WHERE $whereClause";
    $countStmt = $mysqli->prepare($countSql);
    if (!$countStmt) {
        throw new Exception('Failed to prepare agent logs count query');
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
    
    // Get agent logs with filtering and pagination
    $sql = "SELECT * FROM agent_logs WHERE $whereClause ORDER BY id DESC LIMIT ? OFFSET ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare agent logs query');
    }
    
    $stmt->bind_param(implode('', $paramTypes), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'event' => $row['event'],
            'meta' => $row['meta_json'] ? json_decode($row['meta_json'], true) : null,
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    // Log admin access to agent logs
    $adminId = (int)$_SESSION['user_id'];
    if (function_exists('audit_admin_action')) {
        audit_admin_action($adminId, 'read', 'agent_logs', null,
            'Admin accessed agent logs with filters: ' . json_encode($filters));
    }
    
    json_success([
        'items' => $items,
        'total' => (int)$totalCount,
        'limit' => $limit,
        'offset' => $offset
    ], 'Agent logs retrieved successfully');
    
} catch (Exception $e) {
    json_error('Failed to retrieve agent logs: ' . $e->getMessage(), 500);
}