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
api_rate_limit('admin_agent_logs', 10);

// Require admin authentication
require_admin_json();

try {
    global $mysqli;
    
    // Parse query parameters for filtering
    $filters = [];
    $params = [];
    $paramTypes = [];
    $whereConditions = ['1=1']; // Always true base condition
    
    // Actor filter
    if (isset($_GET['actor']) && trim($_GET['actor']) !== '') {
        $filters['actor'] = trim($_GET['actor']);
        $whereConditions[] = 'actor LIKE ?';
        $params[] = '%' . $filters['actor'] . '%';
        $paramTypes[] = 's';
    }
    
    // Source filter
    if (isset($_GET['source']) && trim($_GET['source']) !== '') {
        $filters['source'] = trim($_GET['source']);
        if (!in_array($filters['source'], ['kilo', 'codex', 'cursor', 'manual'], true)) {
            json_error('Invalid source filter. Must be: kilo, codex, cursor, or manual', 400);
        }
        $whereConditions[] = 'source = ?';
        $params[] = $filters['source'];
        $paramTypes[] = 's';
    }
    
    // Action filter
    if (isset($_GET['action']) && trim($_GET['action']) !== '') {
        $filters['action'] = trim($_GET['action']);
        $whereConditions[] = 'action LIKE ?';
        $params[] = '%' . $filters['action'] . '%';
        $paramTypes[] = 's';
    }
    
    // Date range filters
    if (isset($_GET['date_from']) && trim($_GET['date_from']) !== '') {
        $dateFrom = DateTime::createFromFormat('Y-m-d', $_GET['date_from']);
        if ($dateFrom === false) {
            json_error('Invalid date_from format. Use YYYY-MM-DD', 400);
        }
        $filters['date_from'] = $dateFrom->format('Y-m-d');
        $whereConditions[] = 'DATE(created_at) >= ?';
        $params[] = $filters['date_from'];
        $paramTypes[] = 's';
    }
    
    if (isset($_GET['date_to']) && trim($_GET['date_to']) !== '') {
        $dateTo = DateTime::createFromFormat('Y-m-d', $_GET['date_to']);
        if ($dateTo === false) {
            json_error('Invalid date_to format. Use YYYY-MM-DD', 400);
        }
        $filters['date_to'] = $dateTo->format('Y-m-d');
        $whereConditions[] = 'DATE(created_at) <= ?';
        $params[] = $filters['date_to'];
        $paramTypes[] = 's';
    }
    
    // Pagination
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) 
        ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $params[] = $limit;
    $paramTypes[] = 'i';
    
    // Cursor for pagination (last ID from previous page)
    $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : 0;
    if ($cursor > 0) {
        $whereConditions[] = 'id < ?';
        $params[] = $cursor;
        $paramTypes[] = 'i';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get agent logs with filtering and pagination
    $sql = "SELECT * FROM agent_logs WHERE $whereClause ORDER BY id DESC LIMIT ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare agent logs query');
    }
    
    $stmt->bind_param(implode('', $paramTypes), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $events = [];
    $lastId = 0;
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'id' => (int)$row['id'],
            'timestamp' => $row['created_at'],
            'actor' => $row['actor'],
            'source' => $row['source'],
            'action' => $row['action'],
            'target' => $row['target'],
            'summary' => $row['summary'],
            'payload' => $row['payload'] ? json_decode($row['payload'], true) : null,
            'user_id' => (int)$row['user_id']
        ];
        $lastId = (int)$row['id'];
    }
    $stmt->close();
    
    // Get next cursor for pagination
    $nextCursor = null;
    if (count($events) === $limit) {
        $nextCursor = $lastId;
    }
    
    // Log admin access to agent logs
    $adminId = (int)$_SESSION['user_id'];
    if (function_exists('audit_admin_action')) {
        audit_admin_action($adminId, 'read', 'agent_logs', null, 
            'Admin accessed agent activity logs with filters: ' . json_encode($filters));
    }
    
    json_success([
        'events' => $events,
        'next_cursor' => $nextCursor,
        'filters_applied' => $filters,
        'pagination' => [
            'limit' => $limit,
            'count' => count($events),
            'has_more' => $nextCursor !== null
        ]
    ], 'Agent logs retrieved successfully', [
        'endpoint' => 'admin_agent_logs',
        'filters_count' => count($filters)
    ]);
    
} catch (Exception $e) {
    json_error('Failed to retrieve agent logs: ' . $e->getMessage(), 500);
}