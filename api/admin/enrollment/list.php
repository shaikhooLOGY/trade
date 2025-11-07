<?php
/**
 * api/admin/enrollment/list.php
 *
 * Admin API - List all MTM enrollments with filtering and pagination
 * GET /api/admin/enrollment/list.php
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/security/csrf_guard.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Only GET method is allowed');
}

try {
    // Require admin authentication
    require_admin_json('Admin access required');
    $adminId = (int)$_SESSION['user_id'];
    
    // Rate limiting: 20 per minute
    require_rate_limit('api:admin:enrollment:list', 20);
    
    global $mysqli;
    
    // Get query parameters
    $modelId = isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0;
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(10, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // Build base query with filters
    $whereConditions = [];
    $params = [];
    $paramTypes = '';
    
    if ($modelId > 0) {
        $whereConditions[] = 'e.model_id = ?';
        $params[] = $modelId;
        $paramTypes .= 'i';
    }
    
    if (!empty($status)) {
        $whereConditions[] = 'e.status = ?';
        $params[] = $status;
        $paramTypes .= 's';
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count
    $countQuery = "
        SELECT COUNT(*) as total
        FROM mtm_enrollments e
        JOIN users u ON e.user_id = u.id
        JOIN mtm_models m ON e.model_id = m.id
        $whereClause
    ";
    
    $countStmt = $mysqli->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception('Failed to prepare count query');
    }
    
    if (!empty($params)) {
        $countStmt->bind_param($paramTypes, ...$params);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = (int)$countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    // Get enrollments with task progress
    $query = "
        SELECT
            e.id,
            e.user_id,
            e.model_id,
            e.tier,
            e.status,
            e.requested_at,
            e.approved_at,
            e.rejected_at,
            e.notes,
            u.name as user_name,
            u.email as user_email,
            m.title as model_title,
            m.code as model_code,
            COUNT(tp.id) as total_tasks,
            COUNT(CASE WHEN tp.status IN ('passed', 'failed') THEN 1 END) as completed_tasks,
            COUNT(CASE WHEN tp.status = 'passed' THEN 1 END) as passed_tasks,
            COUNT(CASE WHEN tp.status = 'unlocked' THEN 1 END) as unlocked_tasks
        FROM mtm_enrollments e
        JOIN users u ON e.user_id = u.id
        JOIN mtm_models m ON e.model_id = m.id
        LEFT JOIN mtm_task_progress tp ON e.id = tp.enrollment_id
        $whereClause
        GROUP BY e.id
        ORDER BY
            CASE e.status
                WHEN 'pending' THEN 1
                WHEN 'approved' THEN 2
                WHEN 'rejected' THEN 3
                WHEN 'dropped' THEN 4
                WHEN 'completed' THEN 5
            END,
            e.requested_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes .= 'ii';
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare enrollments query');
    }
    
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrollments = [];
    while ($row = $result->fetch_assoc()) {
        $enrollments[] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'model_id' => (int)$row['model_id'],
            'tier' => $row['tier'],
            'status' => $row['status'],
            'requested_at' => $row['requested_at'],
            'approved_at' => $row['approved_at'],
            'rejected_at' => $row['rejected_at'],
            'notes' => $row['notes'],
            'user_name' => $row['user_name'],
            'user_email' => $row['user_email'],
            'model_title' => $row['model_title'],
            'model_code' => $row['model_code'],
            'task_progress' => [
                'total' => (int)$row['total_tasks'],
                'completed' => (int)$row['completed_tasks'],
                'passed' => (int)$row['passed_tasks'],
                'unlocked' => (int)$row['unlocked_tasks']
            ]
        ];
    }
    $stmt->close();
    
    // Group enrollments by status for easier frontend handling
    $groupedEnrollments = [
        'pending' => array_filter($enrollments, fn($e) => $e['status'] === 'pending'),
        'approved' => array_filter($enrollments, fn($e) => $e['status'] === 'approved'),
        'rejected' => array_filter($enrollments, fn($e) => $e['status'] === 'rejected'),
        'dropped' => array_filter($enrollments, fn($e) => $e['status'] === 'dropped'),
        'completed' => array_filter($enrollments, fn($e) => $e['status'] === 'completed'),
        'all' => $enrollments
    ];
    
    // Return success response
    json_success([
        'enrollments' => $groupedEnrollments,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'pages' => ceil($totalCount / $limit)
        ],
        'filters' => [
            'model_id' => $modelId,
            'status' => $status
        ]
    ], 'Enrollments retrieved successfully');

} catch (Exception $e) {
    // Log admin error
    app_log('error', 'Admin enrollment list error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Failed to retrieve enrollments');
}