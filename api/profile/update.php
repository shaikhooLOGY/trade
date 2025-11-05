<?php
/**
 * api/profile/update.php
 *
 * Profile API - Update current user profile
 * POST /api/profile/update.php
 */

require_once __DIR__ . '/../_bootstrap.php';

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
    json_fail('METHOD_NOT_ALLOWED', 'Only POST method is allowed');
}

try {
    // Require authentication
    $user = require_login_json();
    $userId = (int)$user['id'];
    
    // Check CSRF for mutating operations
    csrf_api_middleware();
    
    // Get JSON input
    $input = get_json_input();
    
    // Validate that we have data to update
    if (empty($input)) {
        json_fail('VALIDATION_ERROR', 'No data provided for update');
    }
    
    // Check if password update requires re-authentication
    $isPasswordUpdate = isset($input['password']) && !empty($input['password']);
    if ($isPasswordUpdate) {
        // For password updates, require recent authentication (within 15 minutes)
        if (empty($_SESSION['last_auth_check']) || 
            (time() - $_SESSION['last_auth_check']) > 900) {
            json_forbidden('Password update requires recent authentication');
        }
    }
    
    global $mysqli;
    
    // Validate input fields
    $allowedFields = [
        'name', 'display_name', 'bio', 'location', 'timezone'
    ];
    
    $validationErrors = [];
    $updateFields = [];
    $updateValues = [];
    $updateTypes = '';
    
    // Validate name
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
    
    // Validate display_name
    if (isset($input['display_name'])) {
        $displayName = trim($input['display_name']);
        if (strlen($displayName) > 100) {
            $validationErrors['display_name'] = 'Display name must not exceed 100 characters';
        } elseif (!empty($displayName) && !preg_match('/^[a-zA-Z0-9\s\-_\.]+$/', $displayName)) {
            $validationErrors['display_name'] = 'Display name contains invalid characters';
        } else {
            $updateFields[] = 'display_name = ?';
            $updateValues[] = empty($displayName) ? null : $displayName;
            $updateTypes .= 's';
        }
    }
    
    // Validate bio
    if (isset($input['bio'])) {
        $bio = trim($input['bio']);
        if (strlen($bio) > 500) {
            $validationErrors['bio'] = 'Bio must not exceed 500 characters';
        } else {
            $updateFields[] = 'bio = ?';
            $updateValues[] = empty($bio) ? null : htmlspecialchars($bio, ENT_QUOTES, 'UTF-8');
            $updateTypes .= 's';
        }
    }
    
    // Validate location
    if (isset($input['location'])) {
        $location = trim($input['location']);
        if (strlen($location) > 100) {
            $validationErrors['location'] = 'Location must not exceed 100 characters';
        } else {
            $updateFields[] = 'location = ?';
            $updateValues[] = empty($location) ? null : $location;
            $updateTypes .= 's';
        }
    }
    
    // Validate timezone
    if (isset($input['timezone'])) {
        $timezone = trim($input['timezone']);
        if (!empty($timezone) && !in_array($timezone, timezone_identifiers_list(), true)) {
            $validationErrors['timezone'] = 'Invalid timezone provided';
        } else {
            $updateFields[] = 'timezone = ?';
            $updateValues[] = empty($timezone) ? null : $timezone;
            $updateTypes .= 's';
        }
    }
    
    // Validate and handle password update
    if (isset($input['password'])) {
        $password = $input['password'];
        if (strlen($password) < 8) {
            $validationErrors['password'] = 'Password must be at least 8 characters long';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
            $validationErrors['password'] = 'Password must contain at least one lowercase letter, one uppercase letter, and one number';
        } else {
            // Hash the new password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $updateFields[] = 'password = ?';
            $updateValues[] = $hashedPassword;
            $updateFields[] = 'last_password_change = NOW()';
            $updateTypes .= 's';
            
            // Also need to update session security - session will need regeneration after successful update
            $needSessionRegeneration = true;
        }
    }
    
    // Handle preferences update
    if (isset($input['preferences'])) {
        if (!is_array($input['preferences'])) {
            $validationErrors['preferences'] = 'Preferences must be a valid JSON object';
        } else {
            // Validate and filter preferences
            $allowedPreferences = ['theme', 'notifications', 'privacy', 'display'];
            $filteredPreferences = [];
            
            foreach ($input['preferences'] as $key => $value) {
                if (in_array($key, $allowedPreferences, true)) {
                    $filteredPreferences[$key] = $value;
                }
            }
            
            if (!empty($filteredPreferences)) {
                $updateFields[] = 'preferences = ?';
                $updateValues[] = json_encode($filteredPreferences);
                $updateTypes .= 's';
            }
        }
    }
    
    // If there are validation errors, return them
    if (!empty($validationErrors)) {
        json_validation_error($validationErrors, 'Profile update validation failed');
    }
    
    // If no fields to update, return
    if (empty($updateFields)) {
        json_fail('VALIDATION_ERROR', 'No valid fields provided for update');
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
        json_fail('SERVER_ERROR', 'Failed to update profile');
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
                json_not_found('User profile');
            }
        }
        
        // If user exists but no rows affected, it means the update didn't change anything
        json_fail('VALIDATION_ERROR', 'No changes made to profile');
    }
    
    // Regenerate session ID if password was updated (security best practice)
    if (isset($needSessionRegeneration) && $needSessionRegeneration) {
        session_regenerate_id(true);
        // Update last auth check time
        $_SESSION['last_auth_check'] = time();
    }
    
    // Get updated profile to return
    $stmt = $mysqli->prepare("
        SELECT 
            id, name, display_name, email, role, status, email_verified,
            bio, location, timezone, preferences, profile_completion_score
        FROM users 
        WHERE id = ?
    ");
    
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $updatedProfile = $result->fetch_assoc();
        $stmt->close();
        
        // Parse preferences if available
        if (!empty($updatedProfile['preferences'])) {
            $decodedPreferences = json_decode($updatedProfile['preferences'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $updatedProfile['preferences'] = $decodedPreferences;
            }
        }
    }
    
    // Log profile update
    $changedFields = array_keys($input);
    app_log('info', sprintf(
        'Profile updated - User: %d, Fields: %s',
        $userId,
        implode(', ', $changedFields)
    ));
    
    // Return success response
    json_ok([
        'profile' => $updatedProfile ?? [],
        'updated_fields' => array_keys($input),
        'message' => 'Profile updated successfully'
    ], 'Profile updated successfully');
    
} catch (Exception $e) {
    app_log('error', 'Profile update error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to update profile');
}