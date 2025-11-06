<?php
/**
 * api/admin/audit_log.php
 * 
 * Admin API - Audit Log Management and Viewing
 * Phase 3 Compliance Implementation
 * 
 * Provides:
 * - GET /api/admin/audit_log.php - List audit events with filtering and pagination
 * - GET /api/admin/audit_log.php?action=statistics - Get audit statistics for compliance reporting
 * - POST /api/admin/audit_log.php?action=cleanup - Clean up old audit events
 * - GET /api/admin/audit_log.php?action=export - Export audit logs for compliance reporting
 * 
 * @version 1.0.0
 * @created 2025-11-06
 * @author Shaikhoology Platform Team
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Require admin authentication
$adminUser = require_admin_json('Admin access required for audit log management');
$adminId = (int)$adminUser['id'];

try {
    // Parse the action from query parameters
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            handleAuditLogList();
            break;
            
        case 'statistics':
            handleAuditStatistics();
            break;
            
        case 'cleanup':
            handleAuditCleanup();
            break;
            
        case 'export':
            handleAuditExport();
            break;
            
        default:
            json_fail('INVALID_ACTION', 'Invalid action specified', [
                'available_actions' => ['list', 'statistics', 'cleanup', 'export']
            ], 400);
    }
    
} catch (Exception $e) {
    app_log('error', 'Admin audit log API error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to process audit log request');
}

/**
 * Handle audit log listing with filtering and pagination
 */
function handleAuditLogList(): void {
    global $adminId;
    
    // Only allow GET requests for listing
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_fail('METHOD_NOT_ALLOWED', 'Only GET method is allowed for audit log listing');
    }
    
    // Get filter parameters
    $filters = [
        'event_type' => $_GET['event_type'] ?? null,
        'event_category' => $_GET['event_category'] ?? null,
        'user_id' => isset($_GET['user_id']) ? (int)$_GET['user_id'] : null,
        'admin_id' => isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null,
        'target_type' => $_GET['target_type'] ?? null,
        'severity' => $_GET['severity'] ?? null,
        'status' => $_GET['status'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
    ];
    
    // Pagination parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    // Sorting parameters
    $order_by = $_GET['order_by'] ?? 'created_at';
    $order_dir = $_GET['order_dir'] ?? 'DESC';
    
    // Validate order parameters
    $valid_order_fields = ['created_at', 'event_type', 'event_category', 'severity', 'user_id', 'admin_id'];
    if (!in_array($order_by, $valid_order_fields)) {
        $order_by = 'created_at';
    }
    $order_dir = strtoupper($order_dir) === 'ASC' ? 'ASC' : 'DESC';
    
    // Get audit events using the audit logging system
    if (function_exists('get_audit_events')) {
        $result = get_audit_events($filters, $limit, $offset, $order_by, $order_dir);
        
        // Log admin access to audit logs
        if (function_exists('log_admin_action')) {
            log_admin_action('audit_log_view', sprintf(
                'Admin viewed audit logs - Page: %d, Limit: %d, Filters: %s',
                $page,
                $limit,
                json_encode(array_filter($filters))
            ), [
                'admin_id' => $adminId,
                'target_type' => 'audit_log',
                'metadata' => [
                    'page' => $page,
                    'limit' => $limit,
                    'filters' => $filters,
                    'total_results' => $result['pagination']['total']
                ],
                'severity' => 'medium'
            ]);
        }
        
        json_ok($result, 'Audit logs retrieved successfully', [
            'applied_filters' => array_filter($filters),
            'sorting' => ['order_by' => $order_by, 'order_dir' => $order_dir]
        ], 200, 'admin_audit_log_view');
        
    } else {
        json_fail('AUDIT_SYSTEM_ERROR', 'Audit logging system not available');
    }
}

/**
 * Handle audit statistics request for compliance reporting
 */
function handleAuditStatistics(): void {
    global $adminId;
    
    // Only allow GET requests for statistics
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_fail('METHOD_NOT_ALLOWED', 'Only GET method is allowed for audit statistics');
    }
    
    $period = $_GET['period'] ?? 'month';
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    
    // Validate period parameter
    $valid_periods = ['day', 'week', 'month', 'year'];
    if (!in_array($period, $valid_periods)) {
        $period = 'month';
    }
    
    // Get audit statistics using the audit logging system
    if (function_exists('get_audit_statistics')) {
        $statistics = get_audit_statistics($period, $start_date, $end_date);
        
        // Log admin access to audit statistics
        if (function_exists('log_admin_action')) {
            log_admin_action('audit_statistics_view', sprintf(
                'Admin viewed audit statistics - Period: %s, Date range: %s to %s',
                $period,
                $statistics['date_range']['start'],
                $statistics['date_range']['end']
            ), [
                'admin_id' => $adminId,
                'target_type' => 'audit_statistics',
                'metadata' => [
                    'period' => $period,
                    'date_range' => $statistics['date_range'],
                    'total_events' => $statistics['summary']['total_events']
                ],
                'severity' => 'medium'
            ]);
        }
        
        json_ok($statistics, 'Audit statistics retrieved successfully', [
            'compliance_report' => [
                'generated_at' => date('c'),
                'generated_by' => $adminId,
                'report_type' => 'audit_statistics',
                'period' => $period
            ]
        ], 200, 'admin_audit_statistics_view');
        
    } else {
        json_fail('AUDIT_SYSTEM_ERROR', 'Audit statistics system not available');
    }
}

/**
 * Handle audit cleanup request
 */
function handleAuditCleanup(): void {
    global $adminId;
    
    // Only allow POST requests for cleanup
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail('METHOD_NOT_ALLOWED', 'Only POST method is allowed for audit cleanup');
    }
    
    // Check CSRF for mutating operations
    csrf_api_middleware();
    
    // Get cleanup parameters
    $dry_run = isset($_GET['dry_run']) ? (bool)$_GET['dry_run'] : false;
    $category = $_GET['category'] ?? null;
    $severity = $_GET['severity'] ?? null;
    
    if (function_exists('cleanup_audit_events')) {
        if ($dry_run) {
            // For dry run, just return what would be cleaned up
            global $mysqli;
            
            $where_conditions = ["created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)"];
            $params = [];
            $param_types = '';
            
            if ($category) {
                $valid_categories = ['user_action', 'admin_action', 'system_event', 'security_event', 'trade_action', 'mtm_action', 'profile_action'];
                if (in_array($category, $valid_categories)) {
                    $where_conditions[] = "event_category = ?";
                    $params[] = $category;
                    $param_types .= 's';
                }
            }
            
            if ($severity) {
                $valid_severities = ['low', 'medium', 'high', 'critical'];
                if (in_array($severity, $valid_severities)) {
                    $where_conditions[] = "severity = ?";
                    $params[] = $severity;
                    $param_types .= 's';
                }
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            $count_query = "SELECT COUNT(*) as count FROM audit_events WHERE $where_clause";
            
            $stmt = $mysqli->prepare($count_query);
            if ($stmt && !empty($params)) {
                $stmt->bind_param($param_types, ...$params);
            }
            
            $count = 0;
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                $count = (int)$result->fetch_assoc()['count'];
                $stmt->close();
            }
            
            $result = [
                'success' => true,
                'dry_run' => true,
                'message' => "Dry run: Found $count events that would be cleaned up",
                'parameters' => [
                    'category' => $category,
                    'severity' => $severity,
                    'estimated_count' => $count
                ],
                'timestamp' => date('c')
            ];
            
        } else {
            // Perform actual cleanup
            $result = cleanup_audit_events();
        }
        
        // Log admin cleanup action
        if (function_exists('log_admin_action')) {
            log_admin_action($dry_run ? 'audit_cleanup_dry_run' : 'audit_cleanup_execute', sprintf(
                'Admin %s audit cleanup - Category: %s, Severity: %s',
                $dry_run ? 'performed dry run of' : 'executed',
                $category ?: 'all',
                $severity ?: 'all'
            ), [
                'admin_id' => $adminId,
                'target_type' => 'audit_cleanup',
                'metadata' => [
                    'dry_run' => $dry_run,
                    'category' => $category,
                    'severity' => $severity,
                    'result' => $result
                ],
                'severity' => 'high'
            ]);
        }
        
        $message = $dry_run ? 'Audit cleanup dry run completed' : 'Audit cleanup executed successfully';
        json_ok($result, $message, [
            'cleanup_parameters' => [
                'dry_run' => $dry_run,
                'category' => $category,
                'severity' => $severity
            ]
        ], 200, 'admin_audit_cleanup');
        
    } else {
        json_fail('AUDIT_SYSTEM_ERROR', 'Audit cleanup system not available');
    }
}

/**
 * Handle audit log export for compliance reporting
 */
function handleAuditExport(): void {
    global $adminId;
    
    // Only allow GET requests for export
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_fail('METHOD_NOT_ALLOWED', 'Only GET method is allowed for audit export');
    }
    
    // Get export parameters
    $format = $_GET['format'] ?? 'json'; // json, csv
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $filters = [
        'event_type' => $_GET['event_type'] ?? null,
        'event_category' => $_GET['event_category'] ?? null,
        'user_id' => isset($_GET['user_id']) ? (int)$_GET['user_id'] : null,
        'admin_id' => isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null,
        'severity' => $_GET['severity'] ?? null,
        'status' => $_GET['status'] ?? null,
    ];
    
    // Add date filters
    if ($date_from) $filters['date_from'] = $date_from;
    if ($date_to) $filters['date_to'] = $date_to;
    
    // Validate format
    $valid_formats = ['json', 'csv'];
    if (!in_array($format, $valid_formats)) {
        $format = 'json';
    }
    
    // Get all matching audit events (no pagination for export)
    if (function_exists('get_audit_events')) {
        $result = get_audit_events($filters, 10000, 0, 'created_at', 'DESC'); // Large limit for export
        
        $export_data = [
            'export_info' => [
                'generated_at' => date('c'),
                'generated_by' => $adminId,
                'format' => $format,
                'filters_applied' => array_filter($filters),
                'total_records' => count($result['events'])
            ],
            'data' => $result['events']
        ];
        
        if ($format === 'csv') {
            // Convert to CSV format
            $csv_data = convertToCSV($result['events']);
            $filename = sprintf('audit_export_%s.csv', date('Y-m-d_H-i-s'));
            
            // Set CSV headers
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, must-revalidate');
            
            echo $csv_data;
            exit;
            
        } else {
            // Return JSON response
            json_ok($export_data, 'Audit export generated successfully', [
                'export_info' => $export_data['export_info']
            ], 200, 'admin_audit_export');
        }
        
        // Log admin export action
        if (function_exists('log_admin_action')) {
            log_admin_action('audit_export', sprintf(
                'Admin exported audit logs - Format: %s, Records: %d, Period: %s to %s',
                $format,
                count($result['events']),
                $date_from ?: 'all',
                $date_to ?: 'all'
            ), [
                'admin_id' => $adminId,
                'target_type' => 'audit_export',
                'metadata' => [
                    'format' => $format,
                    'filters' => array_filter($filters),
                    'record_count' => count($result['events'])
                ],
                'severity' => 'high'
            ]);
        }
        
    } else {
        json_fail('AUDIT_SYSTEM_ERROR', 'Audit export system not available');
    }
}

/**
 * Convert audit events array to CSV format
 * 
 * @param array $events Audit events array
 * @return string CSV formatted data
 */
function convertToCSV(array $events): string {
    if (empty($events)) {
        return "No data available\n";
    }
    
    // Define CSV headers
    $headers = [
        'id', 'event_type', 'event_category', 'user_id', 'admin_id', 'target_type', 'target_id',
        'description', 'ip_address', 'user_agent', 'severity', 'status', 'created_at',
        'user_name', 'user_email', 'admin_name', 'admin_email'
    ];
    
    $csv_lines = [];
    $csv_lines[] = implode(',', $headers);
    
    foreach ($events as $event) {
        $row = [];
        foreach ($headers as $header) {
            $value = $event[$header] ?? '';
            
            // Escape CSV fields that contain commas, quotes, or newlines
            if (is_string($value) && (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false)) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            
            $row[] = $value;
        }
        $csv_lines[] = implode(',', $row);
    }
    
    return implode("\n", $csv_lines);
}