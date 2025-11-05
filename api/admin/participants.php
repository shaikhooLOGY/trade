<?php
/**
 * api/admin/participants.php
 *
 * Admin API - Get MTM enrollment participants
 * GET /api/admin/participants.php?model_id=123&status=pending&page=1&limit=20
 */

require_once __DIR__ . '/_bootstrap.php';

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
    $user = require_admin_json('Admin access required');
    $userId = (int)$user['id'];
    $isAdmin = true;
    
    // Check CSRF for API endpoints
    csrf_api_middleware();
    
    // Get query parameters
    $modelId = !empty($_GET['model_id']) ? (int)$_GET['model_id'] : null;
    $status = $_GET['status'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20))); // Max 100 per page
    
    // Validate status if provided
    $validStatuses = ['pending', 'approved', 'rejected'];
    if ($status !== null && !in_array($status, $validStatuses, true)) {
        json_fail('VALIDATION_ERROR', 'Invalid status. Must be one of: ' . implode(', ', $validStatuses));
    }
    
    global $mysqli;
    
    // Build query conditions
    $whereConditions = [];
    $params = [];
    $paramTypes = '';
    
    // Add model filter
    if ($modelId !== null) {
        $whereConditions[] = 'e.model_id = ?';
        $params[] = $modelId;
        $paramTypes .= 'i';
    }
    
    // Add status filter
    if ($status !== null) {
        $whereConditions[] = 'e.status = ?';
        $params[] = $status;
        $paramTypes .= 's';
    }
    
    $whereClause = empty($whereConditions) ? '1=1' : implode(' AND ', $whereConditions);
    
    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*) as total
        FROM mtm_enrollments e
        INNER JOIN users u ON u.id = e.user_id
        LEFT JOIN mtm_models m ON m.id = e.model_id
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
    $totalParticipants = (int)$countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    // Get participants with detailed information
    $offset = ($page - 1) * $limit;
    
    $query = "
        SELECT 
            e.id as enrollment_id,
            e.model_id,
            m.name as model_name,
            m.description as model_description,
            u.id as user_id,
            u.name as user_name,
            COALESCE(u.display_name, u.name) as user_display_name,
            u.email as user_email,
            u.status as user_status,
            u.email_verified,
            e.status as enrollment_status,
            e.created_at,
            e.approved_at,
            e.rejected_at,
            e.rejection_reason,
            e.notes as admin_notes,
            -- Get user trading stats
            (SELECT COUNT(*) FROM trades WHERE trader_id = u.id OR user_id = u.id) as total_trades,
            (SELECT COUNT(*) FROM mtm_enrollments WHERE user_id = u.id) as total_enrollments,
            -- Calculate recent activity
            (SELECT COUNT(*) FROM trades t 
             WHERE (t.trader_id = u.id OR t.user_id = u.id) 
             AND t.opened_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_trades,
            -- Model stats
            (SELECT COUNT(*) FROM mtm_enrollments WHERE model_id = m.id) as model_participant_count,
            (SELECT COUNT(*) FROM mtm_enrollments WHERE model_id = m.id AND status = 'pending') as model_pending_count
        FROM mtm_enrollments e
        INNER JOIN users u ON u.id = e.user_id
        LEFT JOIN mtm_models m ON m.id = e.model_id
        WHERE $whereClause
        ORDER BY 
            e.created_at DESC,
            CASE e.status 
                WHEN 'pending' THEN 1
                WHEN 'approved' THEN 2  
                WHEN 'rejected' THEN 3
                ELSE 4
            END,
            u.name ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes .= 'ii';
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare participants query');
    }
    
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $participants = [];
    while ($row = $result->fetch_assoc()) {
        $participant = [
            'enrollment_id' => (int)$row['enrollment_id'],
            'model_id' => (int)$row['model_id'],
            'model_name' => $row['model_name'],
            'model_description' => $row['model_description'],
            'user_id' => (int)$row['user_id'],
            'user_name' => $row['user_name'],
            'user_display_name' => $row['user_display_name'],
            'user_email' => $row['user_email'],
            'user_status' => $row['user_status'],
            'email_verified' => (bool)$row['email_verified'],
            'enrollment_status' => $row['enrollment_status'],
            'created_at' => $row['created_at'],
            'approved_at' => $row['approved_at'],
            'rejected_at' => $row['rejected_at'],
            'rejection_reason' => $row['rejection_reason'],
            'admin_notes' => $row['admin_notes'],
            'user_stats' => [
                'total_trades' => (int)$row['total_trades'],
                'total_enrollments' => (int)$row['total_enrollments'],
                'recent_trades_30d' => (int)$row['recent_trades']
            ],
            'model_stats' => [
                'total_participants' => (int)$row['model_participant_count'],
                'pending_participants' => (int)$row['model_pending_count']
            ]
        ];
        
        // Add additional admin actions if pending
        if ($row['enrollment_status'] === 'pending') {
            $participant['eligible_actions'] = ['approve', 'reject'];
        } elseif ($row['enrollment_status'] === 'approved') {
            $participant['eligible_actions'] = ['reject'];
        } else {
            $participant['eligible_actions'] = [];
        }
        
        $participants[] = $participant;
    }
    $stmt->close();
    
    // Calculate pagination info
    $totalPages = ceil($totalParticipants / $limit);
    
    // Get additional admin summary if requested
    $summary = [
        'total_participants' => $totalParticipants,
        'by_status' => [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0
        ]
    ];
    
    // Get status breakdown
    try {
        $summaryQuery = "
            SELECT 
                status,
                COUNT(*) as count
            FROM mtm_enrollments e
            WHERE " . ($whereClause === '1=1' ? '1=1' : str_replace('e.', 'e.', $whereClause)) . "
            GROUP BY status
        ";
        
        // Remove model_id and status filters for summary to get overall view
        $summaryStmt = $mysqli->prepare($summaryQuery);
        if ($summaryStmt) {
            $summaryStmt->execute();
            $summaryResult = $summaryStmt->get_result();
            
            while ($statusRow = $summaryResult->fetch_assoc()) {
                if (isset($summary['by_status'][$statusRow['status']])) {
                    $summary['by_status'][$statusRow['status']] = (int)$statusRow['count'];
                }
            }
            $summaryStmt->close();
        }
    } catch (Exception $e) {
        app_log('error', 'Admin participants - summary query failed: ' . $e->getMessage());
    }
    
    // Log admin access
    app_log('info', sprintf(
        'Admin participants viewed - Admin: %d, Model: %s, Status: %s, Page: %d/%d, Total: %d',
        $userId,
        $modelId ? "ID $modelId" : 'All',
        $status ?? 'All',
        $page,
        $totalPages,
        $totalParticipants
    ));
    
    // Return success response with pagination
    $response = [
        'participants' => $participants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalParticipants,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_previous' => $page > 1
        ],
        'filters_applied' => [
            'model_id' => $modelId,
            'status' => $status
        ],
        'summary' => $summary
    ];
    
    json_ok($response, 'Participants retrieved successfully');
    
} catch (Exception $e) {
    app_log('error', 'Admin participants error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to retrieve participants');
}