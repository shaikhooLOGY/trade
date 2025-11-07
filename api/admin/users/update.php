<?php
/**
 * api/admin/users/update.php
 *
 * Admin API - User Management Actions
 * POST /api/admin/users/update.php
 *
 * Handles user actions like approve, reject, send_back, promote, demote, delete
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

// Require admin authentication
$adminUser = require_admin_json('Admin access required');
$adminId = (int)$adminUser['id'];

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$id = (int)($input['id'] ?? 0);
$action = trim($input['action'] ?? '');
$csrf = $input['csrf'] ?? '';
$reason = trim($input['reason'] ?? '');

// Validate inputs
if ($id <= 0) {
    json_fail('VALIDATION_ERROR', 'Invalid user ID provided');
}

if (empty($action)) {
    json_fail('VALIDATION_ERROR', 'Action is required');
}

if (empty($csrf) || !validate_csrf($csrf)) {
    json_fail('CSRF_VALIDATION_FAILED', 'Invalid CSRF token');
}

try {
    global $mysqli;
    
    switch ($action) {
        case 'approve': {
            $sql = "UPDATE users SET status='active', profile_status='approved',
                                     last_reviewed_by=?, last_reviewed_at=NOW()
                    WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare approval query');
            }
            $stmt->bind_param('ii', $adminId, $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} approved user {$id}");
                json_ok([], 'User approved successfully');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to approve user');
            }
            break;
        }

        case 'send_back': {
            if (empty($reason)) {
                json_fail('VALIDATION_ERROR', 'Reason is required for sending back');
            }
            
            $sql = "UPDATE users SET status='needs_update', profile_status='needs_update',
                                     rejection_reason=?, last_reviewed_by=?, last_reviewed_at=NOW()
                    WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare send back query');
            }
            $stmt->bind_param('sii', $reason, $adminId, $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} sent back user {$id} with reason: {$reason}");
                json_ok([], 'User sent back for updates');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to send back user');
            }
            break;
        }

        case 'send_back_detail': {
            $pfFile = __DIR__ . '/../../../profile_fields.php';
            $profile_fields = file_exists($pfFile) ? include $pfFile : [];
            
            $statusMap = [];
            $commentMap = [];
            
            // Process field status from input
            foreach ($profile_fields as $field => $cfg) {
                $ok = !empty($input['ok_'.$field]);
                $comment = trim((string)($input['comment_'.$field] ?? ''));
                if ($ok) {
                    $statusMap[$field] = 'ok';
                } elseif ($comment !== '') {
                    $statusMap[$field] = 'needs_update';
                    $commentMap[$field] = $comment;
                }
            }
            
            $status_json = !empty($statusMap) ? json_encode($statusMap) : null;
            $comments_json = !empty($commentMap) ? json_encode($commentMap) : null;
            
            $sql = "UPDATE users SET profile_status='needs_update', status='needs_update',
                                     profile_field_status=?, profile_comments=?,
                                     last_reviewed_by=?, last_reviewed_at=NOW()
                    WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare detailed send back query');
            }
            $stmt->bind_param('ssii', $status_json, $comments_json, $adminId, $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} sent back user {$id} with detailed feedback");
                json_ok([], 'User sent back with detailed feedback');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to send back user with details');
            }
            break;
        }

        case 'reject': {
            if (empty($reason)) {
                json_fail('VALIDATION_ERROR', 'Reason is required for rejection');
            }
            
            $sql = "UPDATE users SET status='rejected', profile_status='rejected',
                                     rejection_reason=?, last_reviewed_by=?, last_reviewed_at=NOW()
                    WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare rejection query');
            }
            $stmt->bind_param('sii', $reason, $adminId, $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} rejected user {$id} with reason: {$reason}");
                json_ok([], 'User rejected');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to reject user');
            }
            break;
        }

        case 'activate': {
            $sql = "UPDATE users SET status='pending', profile_status='needs_update',
                                     last_reviewed_by=?, last_reviewed_at=NOW()
                    WHERE id=? AND status='rejected'";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare activation query');
            }
            $stmt->bind_param('ii', $adminId, $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} re-activated user {$id}");
                json_ok([], 'User re-activated (pending update)');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to activate user');
            }
            break;
        }

        case 'promote': {
            $sql = "UPDATE users SET is_admin=1, role='admin', promoted_by=?, promoted_at=NOW() WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare promotion query');
            }
            $stmt->bind_param('ii', $adminId, $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} promoted user {$id} to admin");
                json_ok([], 'User promoted to admin');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to promote user');
            }
            break;
        }

        case 'demote': {
            $sql = "UPDATE users SET is_admin=0, role='user' WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare demotion query');
            }
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} demoted user {$id} to user");
                json_ok([], 'Admin demoted to user');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to demote user');
            }
            break;
        }

        case 'delete': {
            // Check if user exists and get their name for logging
            $checkStmt = $mysqli->prepare("SELECT name, email FROM users WHERE id=?");
            $checkStmt->bind_param('i', $id);
            $checkStmt->execute();
            $userResult = $checkStmt->get_result();
            $user = $userResult->fetch_assoc();
            $checkStmt->close();
            
            if (!$user) {
                json_fail('NOT_FOUND', 'User not found');
            }
            
            $sql = "DELETE FROM users WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare deletion query');
            }
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                app_log('info', "Admin {$adminId} deleted user {$id} ({$user['name']} / {$user['email']})");
                json_ok([], 'User deleted');
            } else {
                json_fail('DATABASE_ERROR', 'Failed to delete user');
            }
            break;
        }

        default:
            json_fail('INVALID_ACTION', 'Unknown action: ' . $action);
    }
    
} catch (Exception $e) {
    app_log('error', 'Admin user update error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to process user action: ' . $e->getMessage());
}