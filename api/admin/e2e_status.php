<?php
/**
 * api/admin/e2e_status.php
 *
 * Admin API - Get E2E test status and results
 * GET /api/admin/e2e_status.php
 */

require_once __DIR__ . '/_bootstrap.php';

// Standardized admin authentication with proper 401/403 handling
$admin = require_admin_auth_json();

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

// All security checks handled in bootstrap

try {
    // Check for latest E2E status file
    $statusFile = __DIR__ . '/../../reports/e2e/last_status.json';
    $lastRunFile = __DIR__ . '/../../reports/e2e/last_fail.txt';
    
    $status = [
        'available' => false,
        'last_run_at' => null,
        'pass_rate' => 0,
        'total_steps' => 0,
        'successful_steps' => 0,
        'failing_tests' => [],
        'success' => false,
        'heal_attempts' => 0,
        'auto_issue_link' => null,
        'healing_status' => 'none', // none, healing, healed, failed
        'base_url' => 'http://127.0.0.1:8082'
    ];
    
    if (file_exists($statusFile)) {
        $fileContent = file_get_contents($statusFile);
        $fileData = json_decode($fileContent, true);
        if ($fileData && json_last_error() === JSON_ERROR_NONE) {
            $status = array_merge($status, $fileData);
            $status['available'] = true;
        }
    }
    
    // Check for healing information
    $healLogsDir = __DIR__ . '/../../reports/e2e/heal_logs';
    $healingStatus = 'none';
    $healAttempts = 0;
    $autoIssueLink = null;
    
    if (is_dir($healLogsDir)) {
        // Check for recent healing activity
        $healFiles = glob($healLogsDir . '/heal_*.json');
        if (!empty($healFiles)) {
            // Get most recent heal summary
            usort($healFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $latestHealFile = $healFiles[0];
            $healContent = file_get_contents($latestHealFile);
            $healData = json_decode($healContent, true);
            
            if ($healData) {
                $healAttempts = $healData['heal_attempts'] ?? 0;
                
                // Determine healing status
                if ($healData['success'] ?? false) {
                    $healingStatus = 'healed';
                } else {
                    $healingStatus = 'failed';
                }
                
                // Check if this was a recent attempt (within last 2 hours)
                $healTime = strtotime($healData['end_time'] ?? $healData['start_time'] ?? '');
                if ($healTime && (time() - $healTime) < 7200) {
                    $healingStatus = $healData['success'] ? 'healed' : 'healing';
                }
            }
        }
        
        // Check for healing in progress
        $healLogs = glob($healLogsDir . '/heal_*.log');
        if (!empty($healLogs)) {
            usort($healLogs, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $latestLog = $healLogs[0];
            $logContent = file_get_contents($latestLog);
            
            // Check if healing is currently running
            if (strpos($logContent, 'E2E Self-Healing Started') !== false &&
                strpos($logContent, 'E2E Self-Healing Completed') === false) {
                $healingStatus = 'healing';
            }
        }
    }
    
    // Update status with healing information
    $status['heal_attempts'] = $healAttempts;
    $status['healing_status'] = $healingStatus;
    $status['auto_issue_link'] = $autoIssueLink;
    
    // Get list of recent E2E runs
    $reportsDir = __DIR__ . '/../../reports/e2e';
    $recentRuns = [];
    
    if (is_dir($reportsDir)) {
        $directories = glob($reportsDir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]_*/', GLOB_ONLYDIR);
        // Sort by modification time, most recent first
        usort($directories, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        foreach (array_slice($directories, 0, 5) as $dir) {
            $dirName = basename($dir);
            $jsonFile = $dir . 'E2E_FULL_' . $dirName . '.json';
            
            if (file_exists($jsonFile)) {
                $fileContent = file_get_contents($jsonFile);
                $fileData = json_decode($fileContent, true);
                
                if ($fileData && isset($fileData['steps'])) {
                    $totalSteps = count($fileData['steps']);
                    $successfulSteps = count(array_filter($fileData['steps'], function($step) {
                        return $step['status'] === 'SUCCESS';
                    }));
                    
                    $recentRuns[] = [
                        'timestamp' => $dirName,
                        'pass_rate' => $totalSteps > 0 ? round(($successfulSteps / $totalSteps) * 100, 1) : 0,
                        'total_steps' => $totalSteps,
                        'successful_steps' => $successfulSteps,
                        'failed_steps' => $totalSteps - $successfulSteps
                    ];
                }
            }
        }
    }
    
    // Return status response
    // Determine badge status
    $badgeStatus = 'none';
    if ($status['healing_status'] === 'healing') {
        $badgeStatus = 'healing';
    } elseif ($status['success'] && $status['pass_rate'] >= 85) {
        $badgeStatus = 'pass';
    } elseif ($status['healing_status'] === 'healed') {
        $badgeStatus = 'healed';
    } else {
        $badgeStatus = 'fail';
    }
    
    // Determine badge display
    $badgeDisplay = '';
    if ($status['healing_status'] === 'healing') {
        $badgeDisplay = '⚠️ Healing';
    } elseif ($status['success'] && $status['pass_rate'] >= 85) {
        $badgeDisplay = '✅ E2E Green';
    } elseif ($status['healing_status'] === 'healed') {
        $badgeDisplay = '🩹 E2E Healed';
    } else {
        $link = $status['auto_issue_link'] ? ' - ' . $status['auto_issue_link'] : '';
        $badgeDisplay = '❌ Failing' . $link;
    }
    
    json_ok([
        'status' => $status,
        'recent_runs' => $recentRuns,
        'summary' => [
            'target_pass_rate' => 85.0,
            'current_status' => $status['available'] ? ($status['pass_rate'] >= 85 ? 'PASS' : 'FAIL') : 'NO_DATA',
            'badge_status' => $badgeStatus,
            'badge_display' => $badgeDisplay
        ]
    ], 'E2E status retrieved successfully');
    
} catch (Exception $e) {
    app_log('error', 'E2E status error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to retrieve E2E status');
}