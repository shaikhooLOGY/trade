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
    $userId = (int)($_SESSION['user_id'] ?? 0);
    
    if ($userId === 0) {
        json_error('Authentication required', 401);
    }
    
    global $mysqli;
    
    // Get user profile data
    $stmt = $mysqli->prepare("
        SELECT
            id, name, email, role, status, email_verified, created_at, updated_at
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
    
    // Get additional statistics (simplified)
    $stats = ['total_trades' => 0, 'open_trades' => 0, 'total_enrollments' => 0, 'active_enrollments' => 0, 'completed_enrollments' => 0];
    
    // Format response using unified JSON envelope
    $profileData = [
        'id' => (int)$profile['id'],
        'name' => $profile['name'],
        'email' => $profile['email'],
        'role' => $profile['role'],
        'status' => $profile['status'],
        'email_verified' => (bool)$profile['email_verified'],
        'trading_capital' => 0.0,
        'funds_available' => 0.0,
        'created_at' => $profile['created_at'],
        'updated_at' => $profile['updated_at'],
        'statistics' => [
            'trades' => [
                'total' => (int)($stats['total_trades'] ?? 0),
                'winning' => 0,
                'open' => (int)($stats['open_trades'] ?? 0),
                'net_outcome' => 0,
                'win_rate' => 0
            ],
            'enrollments' => [
                'total' => (int)($stats['total_enrollments'] ?? 0),
                'active' => (int)($stats['active_enrollments'] ?? 0),
                'completed' => (int)($stats['completed_enrollments'] ?? 0)
            ]
        ]
    ];
    
    // Log profile access (optional)
    try {
        if (function_exists('audit_admin_action')) {
            audit_admin_action($userId, 'read', 'profile', $userId, 'User accessed own profile');
        }
    } catch (Exception $e) {
        // Log the error but don't fail the request
        if (function_exists('app_log')) {
            app_log('error', 'Profile audit log error: ' . $e->getMessage());
        }
    }
    
    json_success($profileData, 'Profile retrieved successfully', [
        'endpoint' => 'profile_me',
        'user_id' => $userId
    ]);
    
} catch (Exception $e) {
    json_error('Failed to retrieve profile: ' . $e->getMessage(), 500);
}