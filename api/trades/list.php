<?php
/**
 * api/trades/list.php
 *
 * Enhanced Trades API - List user trades with filtering and pagination
 * GET /api/trades/list.php
 *
 * Filters: user_id (from session), limit, offset, status (open/closed)
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
    json_error('METHOD_NOT_ALLOWED', 'Only GET method is allowed');
}

// Require authentication
require_login_json();

try {
    $userId = (int)$_SESSION['user_id'];
    global $mysqli;
    
    // Parse query parameters
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit'])
        ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = isset($_GET['offset']) && is_numeric($_GET['offset'])
        ? max(0, (int)$_GET['offset']) : 0;
    $status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : null;
    
    // Build WHERE clause
    $whereConditions = ['user_id = ?'];
    $params = [$userId];
    $paramTypes = ['i'];
    
    // Filter by status (open/closed)
    if (in_array($status, ['open', 'closed'], true)) {
        if ($status === 'open') {
            $whereConditions[] = 'outcome = "open"';
        } else {
            $whereConditions[] = 'outcome != "open"';
        }
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM trades WHERE $whereClause";
    $countStmt = $mysqli->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception('Failed to prepare count query');
    }
    $countStmt->bind_param(implode('', $paramTypes), ...$params);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = (int)$countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    // Get trades with pagination
    $query = "SELECT * FROM trades WHERE $whereClause ORDER BY opened_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes[] = 'i';
    $paramTypes[] = 'i';
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare trades query');
    }
    $stmt->bind_param(implode('', $paramTypes), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'symbol' => $row['symbol'],
            'quantity' => (float)$row['quantity'],
            'entry_price' => (float)$row['entry_price'],
            'exit_price' => $row['exit_price'] ? (float)$row['exit_price'] : null,
            'stop_loss' => $row['stop_loss'] ? (float)$row['stop_loss'] : null,
            'target_price' => $row['target_price'] ? (float)$row['target_price'] : null,
            'allocation_amount' => $row['allocation_amount'] ? (float)$row['allocation_amount'] : null,
            'position_percent' => $row['position_percent'] ? (float)$row['position_percent'] : null,
            'pnl' => (float)$row['pnl'],
            'outcome' => $row['outcome'],
            'notes' => $row['notes'],
            'opened_at' => $row['opened_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    $stmt->close();
    
    // Return success response in unified JSON envelope format
    json_success([
        'rows' => $rows,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ], 'Trades retrieved successfully');
    
} catch (Exception $e) {
    json_error('SERVER_ERROR', 'Failed to retrieve trades: ' . $e->getMessage());
}