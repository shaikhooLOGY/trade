<?php
/**
 * api/trades/list.php
 *
 * Simple Trades API - List user trades
 * GET /api/trades/list.php
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

// Require authentication
require_login_json();

try {
    $userId = (int)$_SESSION['user_id'];
    global $mysqli;
    
    // Use user_id column from unified schema
    $query = "SELECT * FROM trades WHERE user_id = ? AND deleted_at IS NULL ORDER BY opened_at DESC LIMIT 50";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare trades query');
    }
    $stmt->bind_param('i', $userId);
    
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
            'closed_at' => $row['closed_at'] ?? null,
            'notes' => $row['notes'] ?? null,
            'outcome' => $row['outcome'] ?? null,
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    // Return success response
    json_ok($trades, 'Trades retrieved successfully', [
        'count' => count($trades)
    ]);
    
} catch (Exception $e) {
    json_error('Failed to retrieve trades: ' . $e->getMessage(), 500);
}