<?php
/**
 * api/admin/users/search.php
 *
 * Admin API - Search and manage users
 * GET /api/admin/users/search.php?q=john&page=1&limit=20
 */

require_once __DIR__ . '/../_bootstrap.php';

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
    json_fail('METHOD_NOT_ALLOWED', 'Only GET method is allowed');
}

try {
    // Require admin authentication
    $adminUser = require_admin_json('Admin access required');
    $adminId = (int)$adminUser['id'];
    
    // Check CSRF for API endpoints
    csrf_api_middleware();
    
    // Get query parameters
    $searchQuery = trim($_GET['q'] ?? '');
    $statusFilter = $_GET['status'] ?? null;
    $roleFilter = $_GET['role'] ?? null;
    $verifiedFilter = $_GET['verified'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20))); // Max 100 per page
    $sortBy = $_GET['sort'] ?? 'name'; // name, email, created_at, last_login, total_trades
    $sortOrder = strtoupper($_GET['order'] ?? 'ASC'); // ASC or DESC
    
    // Validate parameters
    $validStatuses = ['active', 'pending', 'suspended', 'banned'];
    $validRoles = ['user', 'admin', 'moderator'];
    $validSortFields = ['name', 'email', 'created_at', 'last_login', 'total_trades', 'total_enrollments'];
    
    if ($statusFilter !== null && !in_array($statusFilter, $validStatuses, true)) {
        json_fail('VALIDATION_ERROR', 'Invalid status filter. Must be one of: ' . implode(', ', $validStatuses));
    }
    
    if ($roleFilter !== null && !in_array($roleFilter, $validRoles, true)) {
        json_fail('VALIDATION_ERROR', 'Invalid role filter. Must be one of: ' . implode(', ', $validRoles));
    }
    
    if ($verifiedFilter !== null && !in_array($verifiedFilter, ['true', 'false'], true)) {
        json_fail('VALIDATION_ERROR', 'Invalid verified filter. Must be true or false');
    }
    
    if (!in_array($sortBy, $validSortFields, true)) {
        json_fail('VALIDATION_ERROR', 'Invalid sort field. Must be one of: ' . implode(', ', $validSortFields));
    }
    
    if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
        json_fail('VALIDATION_ERROR', 'Invalid sort order. Must be ASC or DESC');
    }
    
    global $mysqli;
    
    // Build query conditions
    $whereConditions = [];
    $params = [];
    $paramTypes = '';
    
    // Add search query filter
    if (!empty($searchQuery)) {
        $whereConditions[] = '(u.name LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)';
        $searchTerm = '%' . $searchQuery . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $paramTypes .= 'sss';
    }
    
    // Add status filter
    if ($statusFilter !== null) {
        $whereConditions[] = 'u.status = ?';
        $params[] = $statusFilter;
        $paramTypes .= 's';
    }
    
    // Add role filter
    if ($roleFilter !== null) {
        $whereConditions[] = 'u.role = ?';
        $params[] = $roleFilter;
        $paramTypes .= 's';
    }
    
    // Add verified filter
    if ($verifiedFilter !== null) {
        $isVerified = $verifiedFilter === 'true' ? 1 : 0;
        $whereConditions[] = 'u.email_verified = ?';
        $params[] = $isVerified;
        $paramTypes .= 'i';
    }
    
    $whereClause = empty($whereConditions) ? '1=1' : implode(' AND ', $whereConditions);
    
    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*) as total
        FROM users u
        WHERE $whereClause
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
    $totalUsers = (int)$countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    // Build ORDER BY clause
    $orderByClause = '';
    switch ($sortBy) {
        case 'total_trades':
        case 'total_enrollments':
            $orderByClause = "ORDER BY stats.{$sortBy} {$sortOrder}, u.name ASC";
            break;
        default:
            $orderByClause = "ORDER BY u.{$sortBy} {$sortOrder}";
            break;
    }
    
    // Get users with detailed information
    $offset = ($page - 1) * $limit;
    
    // Main query with statistics
    $query = "
        SELECT 
            u.id,
            u.name,
            COALESCE(u.display_name, u.name) as display_name,
            u.email,
            u.status,
            u.role,
            u.email_verified,
            u.created_at,
            u.last_login,
            -- User statistics
            (SELECT COUNT(*) FROM trades WHERE trader_id = u.id OR user_id = u.id) as total_trades,
            (SELECT COUNT(*) FROM mtm_enrollments WHERE user_id = u.id) as total_enrollments,
            (SELECT COUNT(*) FROM mtm_enrollments WHERE user_id = u.id AND status = 'approved') as approved_enrollments,
            (SELECT COUNT(*) FROM mtm_enrollments WHERE user_id = u.id AND status = 'pending') as pending_enrollments,
            -- Recent activity (last 30 days)
            (SELECT COUNT(*) FROM trades t 
             WHERE (t.trader_id = u.id OR t.user_id = u.id) 
             AND t.opened_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_trades_30d,
            -- Profile completion
            u.profile_completion_score,
            u.location,
            u.timezone,
            u.bio
        FROM users u
        WHERE $whereClause
        $orderByClause
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes .= 'ii';
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare users query');
    }
    
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $user = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'display_name' => $row['display_name'],
            'email' => $row['email'],
            'status' => $row['status'],
            'role' => $row['role'],
            'email_verified' => (bool)$row['email_verified'],
            'created_at' => $row['created_at'],
            'last_login' => $row['last_login'],
            'profile_completion_score' => (int)$row['profile_completion_score'],
            'location' => $row['location'],
            'timezone' => $row['timezone'],
            'bio' => $row['bio'],
            'stats' => [
                'total_trades' => (int)$row['total_trades'],
                'total_enrollments' => (int)$row['total_enrollments'],
                'approved_enrollments' => (int)$row['approved_enrollments'],
                'pending_enrollments' => (int)$row['pending_enrollments'],
                'recent_trades_30d' => (int)$row['recent_trades_30d']
            ]
        ];
        
        // Add admin actions based on user status
        $user['admin_actions'] = [];
        if ($row['status'] === 'pending') {
            $user['admin_actions'][] = 'approve';
            $user['admin_actions'][] = 'reject';
        } elseif (in_array($row['status'], ['active', 'approved'])) {
            $user['admin_actions'][] = 'suspend';
        }
        
        if ($row['status'] === 'suspended') {
            $user['admin_actions'][] = 'unsuspend';
        }
        
        if ($row['email_verified'] === false) {
            $user['admin_actions'][] = 'resend_verification';
        }
        
        // Add role actions
        if ($row['role'] === 'user') {
            $user['admin_actions'][] = 'promote_to_admin';
        } elseif ($row['role'] === 'admin') {
            $user['admin_actions'][] = 'demote_to_user';
        }
        
        // Calculate user activity level
        if ($row['recent_trades_30d'] > 10) {
            $user['activity_level'] = 'high';
        } elseif ($row['recent_trades_30d'] > 3) {
            $user['activity_level'] = 'medium';
        } else {
            $user['activity_level'] = 'low';
        }
        
        $users[] = $user;
    }
    $stmt->close();
    
    // Calculate pagination info
    $totalPages = ceil($totalUsers / $limit);
    
    // Get additional summary statistics
    $summary = [
        'total_users' => $totalUsers,
        'by_status' => [],
        'by_role' => [],
        'verified_users' => 0,
        'active_traders' => 0
    ];
    
    // Get status breakdown
    try {
        $summaryQuery = "
            SELECT 
                status,
                COUNT(*) as count
            FROM users 
            WHERE $whereClause
            GROUP BY status
        ";
        
        $summaryStmt = $mysqli->prepare($summaryQuery);
        if ($summaryStmt) {
            if (!empty($params)) {
                $summaryStmt->bind_param($paramTypes, ...$params);
            }
            $summaryStmt->execute();
            $summaryResult = $summaryStmt->get_result();
            
            while ($statusRow = $summaryResult->fetch_assoc()) {
                $summary['by_status'][$statusRow['status']] = (int)$statusRow['count'];
            }
            $summaryStmt->close();
        }
        
        // Get role breakdown
        $roleQuery = "
            SELECT 
                role,
                COUNT(*) as count
            FROM users 
            WHERE $whereClause
            GROUP BY role
        ";
        
        $roleStmt = $mysqli->prepare($roleQuery);
        if ($roleStmt) {
            if (!empty($params)) {
                $roleStmt->bind_param($paramTypes, ...$params);
            }
            $roleStmt->execute();
            $roleResult = $roleStmt->get_result();
            
            while ($roleRow = $roleResult->fetch_assoc()) {
                $summary['by_role'][$roleRow['role']] = (int)$roleRow['count'];
            }
            $roleStmt->close();
        }
        
        // Get additional stats
        $statsQuery = "
            SELECT 
                COUNT(CASE WHEN email_verified = 1 THEN 1 END) as verified,
                (SELECT COUNT(DISTINCT trader_id) FROM trades WHERE opened_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 UNION 
                 SELECT COUNT(DISTINCT user_id) FROM trades WHERE opened_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as active_traders
            FROM users 
            WHERE $whereClause
        ";
        
        $statsStmt = $mysqli->prepare($statsQuery);
        if ($statsStmt) {
            if (!empty($params)) {
                $statsStmt->bind_param($paramTypes, ...$params);
            }
            $statsStmt->execute();
            $statsResult = $statsStmt->get_result();
            $stats = $statsResult->fetch_assoc();
            $summary['verified_users'] = (int)($stats['verified'] ?? 0);
            $summary['active_traders'] = (int)($stats['active_traders'] ?? 0);
            $statsStmt->close();
        }
        
    } catch (Exception $e) {
        app_log('error', 'Admin users search - summary query failed: ' . $e->getMessage());
    }
    
    // Log admin access
    app_log('info', sprintf(
        'Admin users search - Admin: %d, Query: %s, Page: %d/%d, Total: %d',
        $adminId,
        $searchQuery ?: 'All',
        $page,
        $totalPages,
        $totalUsers
    ));
    
    // Return success response with pagination
    $response = [
        'users' => $users,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalUsers,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_previous' => $page > 1
        ],
        'filters_applied' => [
            'query' => $searchQuery,
            'status' => $statusFilter,
            'role' => $roleFilter,
            'verified' => $verifiedFilter
        ],
        'sort' => [
            'field' => $sortBy,
            'order' => $sortOrder
        ],
        'summary' => $summary
    ];
    
    json_ok($response, 'Users retrieved successfully');
    
} catch (Exception $e) {
    app_log('error', 'Admin users search error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to retrieve users');
}