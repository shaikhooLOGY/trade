<?php
/**
 * api/profile/me.php
 * 
 * Profile API - Get current user profile
 * GET /api/profile/me.php
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
    // Require authentication
    $user = require_login_json();
    $userId = (int)$user['id'];
    
    global $mysqli;
    
    // Get comprehensive user profile data
    $stmt = $mysqli->prepare("
        SELECT 
            id, name, display_name, email, role, status, email_verified,
            bio, location, timezone, preferences, profile_completion_score,
            created_at, updated_at, last_login
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
        json_not_found('User profile');
    }
    
    // Parse preferences if available
    if (!empty($profile['preferences'])) {
        $decodedPreferences = json_decode($profile['preferences'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $profile['preferences'] = $decodedPreferences;
        }
    }
    
    // Get additional statistics
    $stats = [];
    
    try {
        // Get trade statistics
        $tradeStmt = $mysqli->prepare("
            SELECT 
                COUNT(*) as total_trades,
                COUNT(CASE WHEN outcome = 'win' THEN 1 END) as winning_trades,
                SUM(CASE 
                    WHEN side = 'buy' THEN quantity * (COALESCE(close_price, price) - price)
                    ELSE quantity * (price - COALESCE(close_price, price))
                END) as total_pnl
            FROM trades 
            WHERE (trader_id = ? OR user_id = ?)
        ");
        
        if ($tradeStmt) {
            $tradeStmt->bind_param('ii', $userId, $userId);
            $tradeStmt->execute();
            $tradeResult = $tradeStmt->get_result();
            $stats = $tradeResult->fetch_assoc();
            $tradeStmt->close();
        }
        
        // Get enrollment statistics
        $enrollmentStmt = $mysqli->prepare("
            SELECT 
                COUNT(*) as total_enrollments,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_enrollments,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_enrollments
            FROM mtm_enrollments 
            WHERE user_id = ?
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
    
    // Format response
    $profileData = [
        'id' => (int)$profile['id'],
        'name' => $profile['name'],
        'display_name' => $profile['display_name'],
        'email' => $profile['email'],
        'role' => $profile['role'],
        'status' => $profile['status'],
        'email_verified' => (bool)$profile['email_verified'],
        'bio' => $profile['bio'],
        'location' => $profile['location'],
        'timezone' => $profile['timezone'],
        'preferences' => $profile['preferences'] ?? [],
        'profile_completion_score' => (int)($profile['profile_completion_score'] ?? 0),
        'created_at' => $profile['created_at'],
        'updated_at' => $profile['updated_at'],
        'last_login' => $profile['last_login'],
        'statistics' => [
            'trades' => [
                'total' => (int)($stats['total_trades'] ?? 0),
                'winning' => (int)($stats['winning_trades'] ?? 0),
                'win_rate' => $stats['total_trades'] > 0 ? 
                    round(($stats['winning_trades'] / $stats['total_trades']) * 100, 2) : 0,
                'total_pnl' => round((float)($stats['total_pnl'] ?? 0), 2)
            ],
            'enrollments' => [
                'total' => (int)($stats['total_enrollments'] ?? 0),
                'approved' => (int)($stats['approved_enrollments'] ?? 0),
                'pending' => (int)($stats['pending_enrollments'] ?? 0)
            ]
        ]
    ];
    
    // Log profile access
    app_log('info', sprintf(
        'Profile accessed - User: %d, Role: %s, Status: %s',
        $userId,
        $profile['role'],
        $profile['status']
    ));
    
    json_ok($profileData, 'Profile retrieved successfully');
    
} catch (Exception $e) {
    app_log('error', 'Profile me error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to retrieve profile');
}