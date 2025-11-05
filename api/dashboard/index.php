<?php
/**
 * api/dashboard/index.php
 *
 * Dashboard API Router
 * Routes requests to appropriate dashboard endpoints
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
    
    // Parse the URL path to determine endpoint
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $pathInfo = parse_url($requestUri, PHP_URL_PATH);
    $pathParts = explode('/', trim($pathInfo, '/'));
    
    // Remove 'api' and 'dashboard' from the path
    $remainingPath = array_slice($pathParts, 2);
    
    // Route based on the remaining path
    if (empty($remainingPath) || $remainingPath[0] === '') {
        // Base dashboard endpoint - return dashboard overview
        json_ok([
            'message' => 'Dashboard API is available',
            'endpoints' => [
                'metrics' => '/api/dashboard/metrics.php',
                'overview' => '/api/dashboard/'
            ],
            'user_id' => $userId,
            'timestamp' => date('c')
        ], 'Dashboard API available');
        
    } elseif ($remainingPath[0] === 'metrics') {
        // Redirect to metrics endpoint
        header('Location: /api/dashboard/metrics.php');
        exit();
        
    } else {
        // Handle metric-specific requests like /m:30-30, /m:35-35
        if (preg_match('/^m:(\d+)-(\d+)$/', $remainingPath[0], $matches)) {
            $from = (int)$matches[1];
            $to = (int)$matches[2];
            
            // Check CSRF for API endpoints
            csrf_api_middleware();
            
            global $mysqli;
            
            // Get metrics for the specified range
            $metrics = [];
            
            try {
                // 1. Get active MTM models in the range
                $stmt = $mysqli->prepare("
                    SELECT id, name, status, start_date, end_date
                    FROM mtm_models 
                    WHERE status = 'active' 
                    AND (end_date IS NULL OR end_date >= CURDATE())
                    ORDER BY start_date DESC
                    LIMIT ? OFFSET ?
                ");
                
                if ($stmt) {
                    $stmt->bind_param('ii', $to, $from);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    while ($row = $result->fetch_assoc()) {
                        $metrics[] = [
                            'id' => (int)$row['id'],
                            'name' => $row['name'],
                            'status' => $row['status'],
                            'start_date' => $row['start_date'],
                            'end_date' => $row['end_date']
                        ];
                    }
                    $stmt->close();
                }
                
            } catch (Exception $e) {
                app_log('error', 'Dashboard metrics range query failed: ' . $e->getMessage());
            }
            
            // Log dashboard range access
            app_log('info', sprintf(
                'Dashboard metrics range accessed - User: %d, Range: %d-%d, Results: %d',
                $userId,
                $from,
                $to,
                count($metrics)
            ));
            
            json_ok([
                'range' => ['from' => $from, 'to' => $to],
                'metrics' => $metrics,
                'count' => count($metrics)
            ], 'Metrics for range retrieved successfully');
            
        } else {
            // Invalid endpoint
            json_fail('NOT_FOUND', 'Dashboard endpoint not found', [
                'requested' => $remainingPath[0],
                'available_endpoints' => ['metrics', 'm:XX-YY format']
            ], 404);
        }
    }
    
} catch (Exception $e) {
    app_log('error', 'Dashboard API error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to process dashboard request');
}