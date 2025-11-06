<?php
/**
 * api/profile/me.php
 *
 * Standardized Profile API - Get current user profile
 * GET /api/profile/me.php
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
api_rate_limit('profile_me', 60);

// Require authentication
require_login_json();

try {
    $userId = (int)$_SESSION['user_id'];
    global $mysqli;
    
    // Get user profile data
    $stmt = $mysqli->prepare("
        SELECT
            id, name, email, role, status, email_verified,
            trading_capital, funds_available, created_at, updated_at
        FROM users
        WHERE id = ?
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare profile query');
    }
    
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc();
    $stmt->close();
    
    if (!$profile) {
        json_error('User profile not found', 404);
    }
    
    // Get additional statistics
    $stats = [];
    
    try {
        // Get trade statistics
        $tradeStmt = $mysqli->prepare("
            SELECT
                COUNT(*) as total_trades,
                COUNT(CASE WHEN outcome = 'WIN' THEN 1 END) as winning_trades,
                COUNT(CASE WHEN outcome = 'OPEN' THEN 1 END) as open_trades,
                COALESCE(SUM(CASE WHEN outcome = 'WIN' THEN 1 WHEN outcome = 'LOSS' THEN -1 ELSE 0 END), 0) as net_outcome
            FROM trades
            WHERE user_id = ? AND (deleted_at IS NULL OR deleted_at = '')
        ");
        
        if ($tradeStmt) {
            $tradeStmt->bind_param('i', $userId);
            $tradeStmt->execute();
            $tradeResult = $tradeStmt->get_result();
            $stats = $tradeResult->fetch_assoc();
            $tradeStmt->close();
        }
        
        // Get enrollment statistics
        $enrollmentStmt = $mysqli->prepare("
            SELECT
                COUNT(*) as total_enrollments,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_enrollments,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_enrollments
            FROM mtm_enrollments
            WHERE trader_id = ?
        ");
        
        if ($enrollmentStmt) {
            $enrollmentStmt->bind_param('i', $userId);
            $enrollmentStmt->execute();
            $enrollmentResult = $enrollmentStmt->get_result();
            $enrollmentStats = $enrollmentResult->fetch_assoc();
            $enrollmentStmt->close();
            
            $stats = array_merge($stats, $enrollmentStats);
        }
        
    } catch (Exception $e) {
        app_log('error', 'Profile stats query failed: ' . $e->getMessage());
    }
    
    // Format response using unified JSON envelope
    $profileData = [
        'id' => (int)$profile['id'],
        'name' => $profile['name'],
        'email' => $profile['email'],
        'role' => $profile['role'],
        'status' => $profile['status'],
        'email_verified' => (bool)$profile['email_verified'],
        'trading_capital' => (float)($profile['trading_capital'] ?? 0),
        'funds_available' => (float)($profile['funds_available'] ?? 0),
        'created_at' => $profile['created_at'],
        'updated_at' => $profile['updated_at'],
        'statistics' => [
            'trades' => [
                'total' => (int)($stats['total_trades'] ?? 0),
                'winning' => (int)($stats['winning_trades'] ?? 0),
                'open' => (int)($stats['open_trades'] ?? 0),
                'net_outcome' => (int)($stats['net_outcome'] ?? 0),
                'win_rate' => $stats['total_trades'] > 0 ?
                    round(($stats['winning_trades'] / $stats['total_trades']) * 100, 2) : 0
            ],
            'enrollments' => [
                'total' => (int)($stats['total_enrollments'] ?? 0),
                'active' => (int)($stats['active_enrollments'] ?? 0),
                'completed' => (int)($stats['completed_enrollments'] ?? 0)
            ]
        ]
    ];
    
    // Log profile access
    if (function_exists('audit_admin_action')) {
        audit_admin_action($userId, 'read', 'profile', $userId, 'User accessed own profile');
    }
    
    json_success($profileData, 'Profile retrieved successfully', [
        'endpoint' => 'profile_me',
        'user_id' => $userId
    ]);
    
} catch (Exception $e) {
    json_error('Failed to retrieve profile: ' . $e->getMessage(), 500);
}