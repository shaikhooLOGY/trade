<?php
/**
 * api/trades/list.php
 *
 * Standardized Trades API - List user trades
 * GET /api/trades/list.php
 *
 * Get trade history for the authenticated user with optional filtering
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

// Apply rate limiting
api_rate_limit('trades_list', 30);

// Require authentication
require_login_json();

try {
    $userId = (int)$_SESSION['user_id'];
    global $mysqli;
    
    // Parse query parameters for filtering
    $filters = [];
    $params = [$userId];
    $paramTypes = ['i'];
    
    // Symbol filter
    if (isset($_GET['symbol']) && trim($_GET['symbol']) !== '') {
        $filters['symbol'] = '%' . trim($_GET['symbol']) . '%';
        $params[] = $filters['symbol'];
        $paramTypes[] = 's';
    }
    
    // Date range filters
    if (isset($_GET['from']) && trim($_GET['from']) !== '') {
        $dateFrom = DateTime::createFromFormat('Y-m-d', $_GET['from']);
        if ($dateFrom !== false) {
            $filters['from'] = $dateFrom->format('Y-m-d');
            $params[] = $filters['from'];
            $paramTypes[] = 's';
        }
    }
    
    if (isset($_GET['to']) && trim($_GET['to']) !== '') {
        $dateTo = DateTime::createFromFormat('Y-m-d', $_GET['to']);
        if ($dateTo !== false) {
            $filters['to'] = $dateTo->format('Y-m-d');
            $params[] = $filters['to'];
            $paramTypes[] = 's';
        }
    }
    
    // Validate pagination parameters
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes[] = 'i';
    $paramTypes[] = 'i';
    
    // Build WHERE clause
    $whereConditions = ['user_id = ?', '(deleted_at IS NULL OR deleted_at = "")'];
    
    if (isset($filters['symbol'])) {
        $whereConditions[] = 'symbol LIKE ?';
    }
    
    if (isset($filters['from'])) {
        $whereConditions[] = 'DATE(opened_at) >= ?';
    }
    
    if (isset($filters['to'])) {
        $whereConditions[] = 'DATE(opened_at) <= ?';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get trades with filtering
    $sql = "SELECT * FROM trades WHERE $whereClause ORDER BY opened_at DESC LIMIT ? OFFSET ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare trades query');
    }
    
    $stmt->bind_param(implode('', $paramTypes), ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $trades = [];
    while ($row = $result->fetch_assoc()) {
        $trades[] = [
            'id' => (int)$row['id'],
            'symbol' => $row['symbol'],
            'side' => $row['side'],
            'quantity' => (float)$row['quantity'],
            'price' => (float)$row['price'],
            'opened_at' => $row['opened_at'],
            'closed_at' => $row['closed_at'],
            'notes' => $row['notes'],
            'outcome' => $row['outcome'],
            'pl_percent' => $row['pl_percent'] ? (float)$row['pl_percent'] : null,
            'rr' => $row['rr'] ? (float)$row['rr'] : null,
            'analysis_link' => $row['analysis_link'],
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    // Log trades access
    if (function_exists('audit_admin_action')) {
        audit_admin_action($userId, 'read', 'trades', null, 'User accessed trades list');
    }
    
    // Return standardized response
    json_success($trades, 'Trades retrieved successfully', [
        'endpoint' => 'trades_list',
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($trades)
        ],
        'filters' => $filters
    ]);
    
} catch (Exception $e) {
    json_error('Failed to retrieve trades: ' . $e->getMessage(), 500);
}