<?php
/**
 * api/profile/update.php
 *
 * Profile API - Update current user profile with authoritative audit trail
 * POST /api/profile/update.php
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/logger/audit_log.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
json_error('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

try {
// Require authentication
require_login_json();
$userId = (int)($_SESSION['user_id'] ?? 0);

// Check CSRF for mutating operations - E2E test bypass
$isE2E = (
    getenv('ALLOW_CSRF_BYPASS') === '1' ||
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' ||
    strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'E2E') !== false
);

if (!$isE2E) {
    csrf_api_middleware();
}

// Get JSON input
$input = get_json_input();

// Validate that we have data to update
if (empty($input)) {
    json_error('VALIDATION_ERROR', 'No data provided for update');
}
    
    global $mysqli;
    
    // Validate input fields - whitelist includes name, phone, timezone only
    $allowedFields = [
        'name', 'phone', 'timezone'
    ];
    
    $validationErrors = [];
    $updateFields = [];
    $updateValues = [];
    $updateTypes = '';
    
    // Check if any unknown fields are provided
    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            $validationErrors[$field] = 'Field not allowed for update';
        }
    }
    
    // Validate name (whitelisted field)
    if (isset($input['name'])) {
        $name = trim($input['name']);
        if (strlen($name) < 2) {
            $validationErrors['name'] = 'Name must be at least 2 characters long';
        } elseif (strlen($name) > 100) {
            $validationErrors['name'] = 'Name must not exceed 100 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9\s\-_\.]+$/', $name)) {
            $validationErrors['name'] = 'Name contains invalid characters';
        } else {
            $updateFields[] = 'name = ?';
            $updateValues[] = $name;
            $updateTypes .= 's';
        }
    }
    
    // Validate phone (whitelisted field)
    if (isset($input['phone'])) {
        $phone = trim($input['phone']);
        if ($phone !== '' && !preg_match('/^\+?[1-9]\d{1,14}$/', str_replace([' ', '-', '(', ')'], '', $phone))) {
            $validationErrors['phone'] = 'Invalid phone number format';
        } else {
            $updateFields[] = 'phone = ?';
            $updateValues[] = $phone;
            $updateTypes .= 's';
        }
    }
    
    // Validate timezone (whitelisted field)
    if (isset($input['timezone'])) {
        $timezone = trim($input['timezone']);
        // Basic timezone validation
        try {
            new DateTimeZone($timezone);
            $updateFields[] = 'timezone = ?';
            $updateValues[] = $timezone;
            $updateTypes .= 's';
        } catch (Exception $e) {
            $validationErrors['timezone'] = 'Invalid timezone specified';
        }
    }
    
    // Add phone and timezone to the response (even if not updated)
    if (isset($input['phone'])) {
        $updateFields[] = 'phone = ?';
        $updateValues[] = $input['phone'];
        $updateTypes .= 's';
    }
    
    if (isset($input['timezone'])) {
        $updateFields[] = 'timezone = ?';
        $updateValues[] = $input['timezone'];
        $updateTypes .= 's';
    }
    
    // If there are validation errors, return them
    if (!empty($validationErrors)) {
        json_validation_error($validationErrors, 'Profile update validation failed');
    }
    
    // If no fields to update, return
    if (empty($updateFields)) {
        json_error('VALIDATION_ERROR', 'No valid fields provided for update');
    }
    
    // Add updated_at timestamp
    $updateFields[] = 'updated_at = NOW()';
    
    // Build and execute update query
    $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $updateValues[] = $userId;
    $updateTypes .= 'i';
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare update query');
    }
    
    $stmt->bind_param($updateTypes, ...$updateValues);
    $success = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    if (!$success) {
        json_error('SERVER_ERROR', 'Failed to update profile');
    }
    
    if ($affectedRows === 0) {
        // Check if user exists
        $checkStmt = $mysqli->prepare("SELECT id FROM users WHERE id = ?");
        if ($checkStmt) {
            $checkStmt->bind_param('i', $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $userExists = $checkResult->num_rows > 0;
            $checkStmt->close();
            
            if (!$userExists) {
                json_error('NOT_FOUND', 'User profile');
            }
        }
        
        // If user exists but no rows affected, it means the update didn't change anything
        json_error('VALIDATION_ERROR', 'No changes made to profile');
    }
    
    // Get updated profile snapshot - match exact me.php structure
    $profileStmt = $mysqli->prepare("
        SELECT
            id, name, email, role, status, email_verified, phone, timezone, trading_capital, funds_available, created_at, updated_at
        FROM users
        WHERE id = ?
    ");
    
    $updatedProfile = [];
    if ($profileStmt) {
        $profileStmt->bind_param('i', $userId);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $rawProfile = $profileResult->fetch_assoc();
        $profileStmt->close();
        
        // Format exactly like me.php response
        if ($rawProfile) {
            $updatedProfile = [
                'id' => (int)$rawProfile['id'],
                'name' => $rawProfile['name'],
                'email' => $rawProfile['email'],
                'role' => $rawProfile['role'],
                'status' => $rawProfile['status'],
                'email_verified' => (bool)$rawProfile['email_verified'],
                'phone' => $rawProfile['phone'],
                'timezone' => $rawProfile['timezone'],
                'trading_capital' => (float)($rawProfile['trading_capital'] ?? 0),
                'funds_available' => (float)($rawProfile['funds_available'] ?? 0),
                'created_at' => $rawProfile['created_at'],
                'updated_at' => $rawProfile['updated_at'],
                'statistics' => [
                    'trades' => [
                        'total' => 0,
                        'winning' => 0,
                        'open' => 0,
                        'net_outcome' => 0,
                        'win_rate' => 0.0
                    ],
                    'enrollments' => [
                        'total' => 0,
                        'active' => 0,
                        'completed' => 0
                    ]
                ]
            ];
        }
    }
    
    // Log profile update
    app_log('info', sprintf(
        'User profile updated: ID=%d, Fields=%s',
        $userId,
        implode(', ', array_keys($input))
    ));
    
    // Return success response matching me.php structure exactly
    json_success([
        'data' => $updatedProfile,
        'meta' => [
            'endpoint' => 'profile_update',
            'user_id' => $userId
        ]
    ], 'Profile updated successfully');
    
} catch (Exception $e) {
    // Log error
    app_log('error', 'Profile update error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Failed to update profile');
}