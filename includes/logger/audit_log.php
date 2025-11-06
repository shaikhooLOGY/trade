<?php
/**
 * includes/logger/audit_log.php
 *
 * Authoritative Audit Trail System for Shaikhoology TMS-MTM Platform
 * Phase 3 Compliance Implementation - Schema Aligned
 *
 * This module provides comprehensive audit logging using the authoritative
 * audit_events table structure with actor_id, action, entity, entity_id, summary.
 *
 * @author Shaikhoology Platform Team
 * @version 2.0.0
 * @created 2025-11-06
 * @schema_version 007_audit_fix
 */

if (!defined('AUDIT_LOG_INITIALIZED')) {
    define('AUDIT_LOG_INITIALIZED', true);
}

/**
 * BACKWARD COMPATIBILITY WRAPPERS
 * These functions maintain compatibility with existing codebase
 * while internally using the new authoritative schema
 */

// Legacy function wrapper for existing code
function log_user_action(string $event_type, string $description, array $options = []): int|false {
    $actor_id = $options['user_id'] ?? $_SESSION['user_id'] ?? null;
    $entity_id = $options['target_id'] ?? null;
    $summary = $description;
    
    return log_audit_event($actor_id, $event_type, 'user_action', $entity_id, $summary);
}

// Legacy function wrapper for existing code
function log_admin_action(string $event_type, string $description, array $options = []): int|false {
    $actor_id = $options['admin_id'] ?? $_SESSION['user_id'] ?? null;
    $entity_id = $options['target_id'] ?? null;
    $summary = $description;
    
    return log_audit_event($actor_id, $event_type, 'admin_action', $entity_id, $summary);
}

// Legacy function wrapper for existing code
function log_trade_action(string $event_type, string $description, array $options = []): int|false {
    $actor_id = $options['user_id'] ?? $_SESSION['user_id'] ?? null;
    $entity_id = $options['target_id'] ?? null;
    $summary = $description;
    
    return log_audit_event($actor_id, $event_type, 'trade', $entity_id, $summary);
}

// Legacy function wrapper for existing code
function log_mtm_action(string $event_type, string $description, array $options = []): int|false {
    $actor_id = $options['user_id'] ?? $_SESSION['user_id'] ?? null;
    $entity_id = $options['target_id'] ?? null;
    $summary = $description;
    
    return log_audit_event($actor_id, $event_type, 'mtm_model', $entity_id, $summary);
}

// Legacy function wrapper for existing code
function log_profile_action(string $event_type, string $description, array $options = []): int|false {
    $actor_id = $options['user_id'] ?? $_SESSION['user_id'] ?? null;
    $entity_id = $options['target_id'] ?? null;
    $summary = $description;
    
    return log_audit_event($actor_id, $event_type, 'profile', $entity_id, $summary);
}

// Legacy function wrapper for existing code
function log_security_event(string $event_type, string $description, array $options = []): int|false {
    $actor_id = $options['user_id'] ?? null;
    $entity_id = $options['target_id'] ?? null;
    $summary = $description;
    
    return log_audit_event($actor_id, $event_type, 'security', $entity_id, $summary);
}

// Legacy function wrapper for existing code
function log_system_event(string $event_type, string $description, array $options = []): int|false {
    $actor_id = $options['user_id'] ?? $_SESSION['user_id'] ?? null;
    $entity_id = $options['target_id'] ?? null;
    $summary = $description;
    
    return log_audit_event($actor_id, $event_type, 'system', $entity_id, $summary);
}

// Legacy function wrapper for existing code
function cleanup_audit_events(): array {
    return [
        'success' => true,
        'message' => 'Cleanup function not implemented in authoritative schema',
        'timestamp' => date('c')
    ];
}

/**
 * Core audit logging function using authoritative schema
 * 
 * @param int|null $actor_id User ID performing the action (auto-resolves from session if null)
 * @param string $action Action performed (e.g., 'create', 'update', 'delete', 'approve')
 * @param string $entity Entity type (e.g., 'user', 'trade', 'enrollment', 'profile')
 * @param int|null $entity_id ID of the entity being acted upon
 * @param string|null $summary Human-readable summary of the action
 * @param string|null $ip_address Client IP address (auto-detected if not provided)
 * 
 * @return int|false Event ID if successful, false otherwise
 */
function log_audit_event(
    ?int $actor_id, 
    string $action, 
    string $entity, 
    ?int $entity_id = null, 
    ?string $summary = null, 
    ?string $ip_address = null
): int|false {
    try {
        global $mysqli;
        
        // Validate required parameters
        if (empty($action) || empty($entity)) {
            throw new InvalidArgumentException('Action and entity are required');
        }
        
        // Resolve actor_id from session if not provided
        if ($actor_id === null) {
            $actor_id = $_SESSION['user_id'] ?? null;
        }
        
        // Validate actor_id
        if ($actor_id === null) {
            throw new InvalidArgumentException('Unable to resolve actor_id - user not authenticated');
        }
        
        // Auto-detect IP address if not provided
        if ($ip_address === null) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        }
        
        // Sanitize IP address for IPv6 compatibility
        if ($ip_address && !filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $ip_address = null;
        }
        
        // Prepare the SQL statement using authoritative schema
        $stmt = $mysqli->prepare("
            INSERT INTO audit_events (
                actor_id, action, entity, entity_id, summary, ip_address
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception('Failed to prepare audit log statement: ' . $mysqli->error);
        }
        
        // Bind parameters
        $stmt->bind_param(
            'ississ',
            $actor_id,
            $action,
            $entity,
            $entity_id,
            $summary,
            $ip_address
        );
        
        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception('Failed to execute audit log insert: ' . $stmt->error);
        }
        
        $event_id = $mysqli->insert_id;
        $stmt->close();
        
        // Log to application log for debugging
        if (function_exists('app_log')) {
            app_log('info', sprintf(
                'AUDIT_LOG: Event %d logged - Actor: %d, Action: %s, Entity: %s, Entity ID: %s',
                $event_id,
                $actor_id,
                $action,
                $entity,
                $entity_id ?: 'N/A'
            ));
        }
        
        return $event_id;
        
    } catch (Exception $e) {
        // Log the error but don't throw to avoid breaking the main application flow
        if (function_exists('app_log')) {
            app_log('error', 'AUDIT_LOG_ERROR: ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Log enrollment-related events
 * 
 * @param int|null $actor_id User ID performing the action
 * @param string $action Action performed (enroll, unenroll, etc.)
 * @param int|null $enrollment_id ID of the enrollment being acted upon
 * @param string|null $summary Custom summary
 * @return int|false Event ID if successful
 */
function audit_enroll(?int $actor_id = null, string $action = 'enroll', ?int $enrollment_id = null, ?string $summary = null): int|false {
    if ($summary === null) {
        $summary = "User {$action} enrollment" . ($enrollment_id ? " (ID: {$enrollment_id})" : '');
    }
    
    return log_audit_event($actor_id, $action, 'enrollment', $enrollment_id, $summary);
}

/**
 * Log approval/rejection events
 * 
 * @param int|null $actor_id Admin user ID performing the action
 * @param string $action Action performed (approve, reject)
 * @param string $entity Entity type being approved/rejected
 * @param int|null $entity_id ID of the entity being acted upon
 * @param string|null $summary Custom summary
 * @return int|false Event ID if successful
 */
function audit_approve(?int $actor_id = null, string $action = 'approve', string $entity = 'enrollment', ?int $entity_id = null, ?string $summary = null): int|false {
    if ($summary === null) {
        $summary = "Admin {$action}ed {$entity}" . ($entity_id ? " (ID: {$entity_id})" : '');
    }
    
    return log_audit_event($actor_id, $action, $entity, $entity_id, $summary);
}

/**
 * Log trade-related events
 * 
 * @param int|null $actor_id User ID performing the action
 * @param string $action Action performed (create, update, delete)
 * @param int|null $trade_id ID of the trade being acted upon
 * @param string|null $summary Custom summary
 * @return int|false Event ID if successful
 */
function audit_trade_create(?int $actor_id = null, string $action = 'create', ?int $trade_id = null, ?string $summary = null): int|false {
    if ($summary === null) {
        $summary = "User {$action}d trade" . ($trade_id ? " (ID: {$trade_id})" : '');
    }
    
    return log_audit_event($actor_id, $action, 'trade', $trade_id, $summary);
}

/**
 * Log profile update events
 * 
 * @param int|null $actor_id User ID performing the action
 * @param string $action Action performed (update, delete)
 * @param int|null $profile_id ID of the profile being updated
 * @param string|null $summary Custom summary
 * @return int|false Event ID if successful
 */
function audit_profile_update(?int $actor_id = null, string $action = 'update', ?int $profile_id = null, ?string $summary = null): int|false {
    if ($summary === null) {
        $summary = "User {$action}d profile" . ($profile_id ? " (ID: {$profile_id})" : '');
    }
    
    return log_audit_event($actor_id, $action, 'profile', $profile_id, $summary);
}

/**
 * Log general admin actions
 * 
 * @param int|null $actor_id Admin user ID
 * @param string $action Action performed
 * @param string $entity Entity type
 * @param int|null $entity_id Entity ID
 * @param string|null $summary Custom summary
 * @return int|false Event ID if successful
 */
function audit_admin_action(?int $actor_id = null, string $action = 'manage', string $entity = 'system', ?int $entity_id = null, ?string $summary = null): int|false {
    if ($summary === null) {
        $summary = "Admin {$action} {$entity}" . ($entity_id ? " (ID: {$entity_id})" : '');
    }
    
    return log_audit_event($actor_id, $action, $entity, $entity_id, $summary);
}

/**
 * Retrieve audit events with filtering and pagination (authoritative schema compatible)
 * 
 * @param array $filters Filter criteria:
 *   - actor_id: Filter by actor ID
 *   - action: Filter by action
 *   - entity: Filter by entity type
 *   - entity_id: Filter by entity ID
 *   - date_from: Filter from date (Y-m-d)
 *   - date_to: Filter to date (Y-m-d)
 * @param int $limit Maximum number of results (default: 50)
 * @param int $offset Offset for pagination (default: 0)
 * @param string $order_by Order by field (default: created_at)
 * @param string $order_dir Order direction (default: DESC)
 * 
 * @return array Array of audit events with pagination info
 */
function get_audit_events(
    array $filters = [],
    int $limit = 50,
    int $offset = 0,
    string $order_by = 'created_at',
    string $order_dir = 'DESC'
): array {
    try {
        global $mysqli;
        
        $where_conditions = [];
        $params = [];
        $param_types = '';
        
        // Build WHERE conditions based on filters
        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                switch ($field) {
                    case 'action':
                    case 'entity':
                        $where_conditions[] = "$field = ?";
                        $params[] = $value;
                        $param_types .= 's';
                        break;
                    case 'actor_id':
                    case 'entity_id':
                        $where_conditions[] = "$field = ?";
                        $params[] = (int)$value;
                        $param_types .= 'i';
                        break;
                    case 'date_from':
                        $where_conditions[] = "DATE(created_at) >= ?";
                        $params[] = $value;
                        $param_types .= 's';
                        break;
                    case 'date_to':
                        $where_conditions[] = "DATE(created_at) <= ?";
                        $params[] = $value;
                        $param_types .= 's';
                        break;
                }
            }
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Validate order parameters
        $valid_order_fields = ['created_at', 'action', 'entity', 'actor_id'];
        if (!in_array($order_by, $valid_order_fields)) {
            $order_by = 'created_at';
        }
        $order_dir = strtoupper($order_dir) === 'ASC' ? 'ASC' : 'DESC';
        
        // Build the query using authoritative schema
        $query = "
            SELECT 
                ae.*,
                u.name as actor_name,
                u.email as actor_email
            FROM audit_events ae
            LEFT JOIN users u ON u.id = ae.actor_id
            $where_clause
            ORDER BY ae.$order_by $order_dir
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        $param_types .= 'ii';
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception('Failed to prepare audit events query');
        }
        
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $events = [];
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        
        $stmt->close();
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) as total FROM audit_events ae $where_clause";
        $count_stmt = $mysqli->prepare($count_query);
        $total = 0;
        if ($count_stmt) {
            // Only bind parameters if we have filter conditions (not just limit/offset)
            if (!empty($where_conditions)) {
                $count_params = array_slice($params, 0, -2); // Remove limit and offset
                $count_param_types = substr($param_types, 0, -2);
                if (!empty($count_params)) {
                    $count_stmt->bind_param($count_param_types, ...$count_params);
                }
            }
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $total = (int)$count_result->fetch_assoc()['total'];
            $count_stmt->close();
        }
        
        return [
            'events' => $events,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ]
        ];
        
    } catch (Exception $e) {
        if (function_exists('app_log')) {
            app_log('error', 'Failed to retrieve audit events: ' . $e->getMessage());
        }
        return ['events' => [], 'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset, 'has_more' => false]];
    }
}

/**
 * Get audit statistics for compliance reporting
 *
 * @param string $period Period for statistics: day|week|month|year
 * @param string|null $start_date Start date for custom period (Y-m-d)
 * @param string|null $end_date End date for custom period (Y-m-d)
 *
 * @return array Audit statistics
 */
function get_audit_statistics(string $period = 'month', ?string $start_date = null, ?string $end_date = null): array {
    try {
        global $mysqli;
        
        // Set default date range based on period
        if (!$start_date || !$end_date) {
            switch ($period) {
                case 'day':
                    $start_date = date('Y-m-d', strtotime('-1 day'));
                    $end_date = date('Y-m-d');
                    break;
                case 'week':
                    $start_date = date('Y-m-d', strtotime('-1 week'));
                    $end_date = date('Y-m-d');
                    break;
                case 'month':
                    $start_date = date('Y-m-d', strtotime('-1 month'));
                    $end_date = date('Y-m-d');
                    break;
                case 'year':
                    $start_date = date('Y-m-d', strtotime('-1 year'));
                    $end_date = date('Y-m-d');
                    break;
                default:
                    $start_date = date('Y-m-d', strtotime('-1 month'));
                    $end_date = date('Y-m-d');
            }
        }
        
        // Get statistics query using authoritative schema
        $query = "
            SELECT
                action,
                entity,
                DATE(created_at) as event_date,
                COUNT(*) as event_count,
                COUNT(DISTINCT actor_id) as unique_actors
            FROM audit_events
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY action, entity, DATE(created_at)
            ORDER BY event_date DESC, action, entity
        ";
        
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $statistics = [];
        $summary = [
            'total_events' => 0,
            'unique_actors' => 0,
            'most_common_action' => '',
            'most_common_entity' => ''
        ];
        
        $action_counts = [];
        $entity_counts = [];
        
        while ($row = $result->fetch_assoc()) {
            $statistics[] = $row;
            
            $summary['total_events'] += $row['event_count'];
            $summary['unique_actors'] += $row['unique_actors'];
            
            $action_counts[$row['action']] = ($action_counts[$row['action']] ?? 0) + $row['event_count'];
            $entity_counts[$row['entity']] = ($entity_counts[$row['entity']] ?? 0) + $row['event_count'];
        }
        
        $stmt->close();
        
        // Find most common action and entity
        if (!empty($action_counts)) {
            arsort($action_counts);
            $summary['most_common_action'] = array_key_first($action_counts);
        }
        
        if (!empty($entity_counts)) {
            arsort($entity_counts);
            $summary['most_common_entity'] = array_key_first($entity_counts);
        }
        
        return [
            'period' => $period,
            'date_range' => ['start' => $start_date, 'end' => $end_date],
            'summary' => $summary,
            'details' => $statistics
        ];
        
    } catch (Exception $e) {
        if (function_exists('app_log')) {
            app_log('error', 'Failed to get audit statistics: ' . $e->getMessage());
        }
        return [
            'period' => $period,
            'date_range' => ['start' => $start_date, 'end' => $end_date],
            'summary' => [
                'total_events' => 0,
                'unique_actors' => 0,
                'most_common_action' => '',
                'most_common_entity' => ''
            ],
            'details' => []
        ];
    }
}

/**
 * Initialize audit logging for the current request
 * Call this function at the beginning of API endpoints or page loads
 */
function initialize_audit_logging(): void {
    // Log the request start if not already logged
    if (!defined('AUDIT_REQUEST_LOGGED')) {
        define('AUDIT_REQUEST_LOGGED', true);
        
        // Log system event for request
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $request_method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        
        audit_admin_action(
            $_SESSION['user_id'] ?? null,
            'request',
            'system',
            null,
            sprintf('Request started: %s %s from %s', $request_method, $request_uri, $ip)
        );
    }
}

/**
 * Check if audit logging is enabled and functional
 * 
 * @return bool True if audit logging is working
 */
function is_audit_logging_enabled(): bool {
    try {
        global $mysqli;
        
        // Test database connection and table existence
        $result = $mysqli->query("SHOW TABLES LIKE 'audit_events'");
        return $result && $result->num_rows > 0;
        
    } catch (Exception $e) {
        return false;
    }
}

// Auto-initialize audit logging if this file is included
if (function_exists('initialize_audit_logging')) {
    initialize_audit_logging();
}