<?php
/**
 * Phase 3 Audit & Agent Log Verification
 * Checks last 10 entries in audit and agent logging tables
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment and config
require_once 'includes/env.php';
require_once 'config.php';

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'database' => $dbName,
    'audit_logs' => [],
    'agent_logs' => [],
    'audit_events' => [],
    'summary' => []
];

echo "=== PHASE 3 AUDIT & AGENT LOG VERIFICATION ===\n";
echo "Timestamp: " . $results['timestamp'] . "\n";
echo "Database: {$dbName}\n\n";

try {
    $pdo = new PDO("mysql:host=" . $dbHost . ";port=" . $dbPort . ";dbname=" . $dbName, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // 1. Check audit_logs table
    echo "1. AUDIT LOGS TABLE ANALYSIS\n";
    echo str_repeat("-", 50) . "\n";
    
    try {
        $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10");
        $audit_logs = $stmt->fetchAll();
        $results['audit_logs'] = $audit_logs;
        
        if (empty($audit_logs)) {
            echo "❌ audit_logs: TABLE EMPTY (0 entries)\n";
            echo "Status: CRITICAL - No audit trail data\n";
        } else {
            echo "✓ audit_logs: " . count($audit_logs) . " entries found\n";
            foreach ($audit_logs as $log) {
                echo "  - ID: {$log['id']}, Action: {$log['action']}, Time: {$log['created_at']}\n";
            }
        }
    } catch (Exception $e) {
        echo "❌ audit_logs: ERROR - " . $e->getMessage() . "\n";
        echo "Status: CRITICAL - Table missing or inaccessible\n";
    }
    
    // 2. Check agent_logs table
    echo "\n2. AGENT LOGS TABLE ANALYSIS\n";
    echo str_repeat("-", 50) . "\n";
    
    try {
        $stmt = $pdo->query("SELECT * FROM agent_logs ORDER BY created_at DESC LIMIT 10");
        $agent_logs = $stmt->fetchAll();
        $results['agent_logs'] = $agent_logs;
        
        if (empty($agent_logs)) {
            echo "❌ agent_logs: TABLE EMPTY (0 entries)\n";
            echo "Status: WARNING - No agent activity logged\n";
        } else {
            echo "✓ agent_logs: " . count($agent_logs) . " entries found\n";
            foreach ($agent_logs as $log) {
                echo "  - ID: {$log['id']}, Event: {$log['event_type']}, Time: {$log['created_at']}\n";
            }
        }
    } catch (Exception $e) {
        echo "❌ agent_logs: ERROR - " . $e->getMessage() . "\n";
        echo "Status: CRITICAL - Table missing or inaccessible\n";
    }
    
    // 3. Check audit_events table (should be the main one)
    echo "\n3. AUDIT EVENTS TABLE ANALYSIS\n";
    echo str_repeat("-", 50) . "\n";
    
    try {
        $stmt = $pdo->query("SELECT * FROM audit_events ORDER BY created_at DESC LIMIT 10");
        $audit_events = $stmt->fetchAll();
        $results['audit_events'] = $audit_events;
        
        if (empty($audit_events)) {
            echo "❌ audit_events: TABLE EMPTY (0 entries)\n";
            echo "Status: CRITICAL - No audit events recorded\n";
        } else {
            echo "✓ audit_events: " . count($audit_events) . " entries found\n";
            foreach ($audit_events as $event) {
                echo "  - ID: {$event['id']}, Type: {$event['event_type']}, User: {$event['user_id']}, Time: {$event['created_at']}\n";
            }
        }
    } catch (Exception $e) {
        echo "❌ audit_events: ERROR - " . $e->getMessage() . "\n";
        echo "Status: CRITICAL - Table missing or inaccessible\n";
    }
    
    // 4. Verify required fields are present
    echo "\n4. FIELD COMPLETENESS VERIFICATION\n";
    echo str_repeat("-", 50) . "\n";
    
    $required_fields = ['actor_id', 'event_type', 'timestamp', 'ip_address'];
    $field_check_results = [];
    
    foreach (['audit_events'] as $table) {
        if (!empty($results[$table])) {
            $sample = $results[$table][0];
            $missing_fields = [];
            
            foreach ($required_fields as $field) {
                if (!isset($sample[$field]) && $sample[$field] !== null) {
                    $missing_fields[] = $field;
                }
            }
            
            if (empty($missing_fields)) {
                echo "✓ {$table}: All required fields present\n";
                $field_check_results[$table] = true;
            } else {
                echo "❌ {$table}: Missing fields: " . implode(', ', $missing_fields) . "\n";
                $field_check_results[$table] = false;
            }
        }
    }
    
    // 5. Summary statistics
    echo "\n5. AUDIT LOG STATISTICS\n";
    echo str_repeat("-", 50) . "\n";
    
    $stats = [];
    
    // Count total entries
    $stats['audit_logs_total'] = count($results['audit_logs']);
    $stats['agent_logs_total'] = count($results['agent_logs']);
    $stats['audit_events_total'] = count($results['audit_events']);
    
    // Recent activity (last 24 hours)
    try {
        $recent_stmt = $pdo->query("SELECT COUNT(*) as count FROM audit_events WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stats['recent_audit_events'] = $recent_stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['recent_audit_events'] = 0;
    }
    
    // Event types breakdown
    try {
        $types_stmt = $pdo->query("SELECT event_type, COUNT(*) as count FROM audit_events GROUP BY event_type ORDER BY count DESC LIMIT 5");
        $stats['event_types'] = $types_stmt->fetchAll();
    } catch (Exception $e) {
        $stats['event_types'] = [];
    }
    
    echo "Total audit_logs entries: {$stats['audit_logs_total']}\n";
    echo "Total agent_logs entries: {$stats['agent_logs_total']}\n";
    echo "Total audit_events entries: {$stats['audit_events_total']}\n";
    echo "Recent audit events (24h): {$stats['recent_audit_events']}\n";
    
    if (!empty($stats['event_types'])) {
        echo "Top event types:\n";
        foreach ($stats['event_types'] as $type) {
            echo "  - {$type['event_type']}: {$type['count']} events\n";
        }
    }
    
    $results['summary'] = $stats;
    
    // 6. Overall assessment
    echo "\n6. AUDIT SYSTEM ASSESSMENT\n";
    echo str_repeat("-", 50) . "\n";
    
    $audit_score = 0;
    $max_score = 100;
    
    // Table existence and data (40 points)
    if ($stats['audit_events_total'] > 0) $audit_score += 20;
    if ($stats['audit_logs_total'] > 0) $audit_score += 10;
    if ($stats['agent_logs_total'] > 0) $audit_score += 10;
    
    // Recent activity (30 points)
    if ($stats['recent_audit_events'] > 0) $audit_score += 30;
    
    // Field completeness (30 points)
    if ($field_check_results['audit_events'] ?? false) $audit_score += 30;
    
    echo "Audit System Score: {$audit_score}/{$max_score} (" . round(($audit_score/$max_score)*100, 1) . "%)\n";
    
    if ($audit_score >= 80) {
        echo "Status: EXCELLENT (GREEN)\n";
    } elseif ($audit_score >= 60) {
        echo "Status: GOOD (YELLOW)\n";
    } else {
        echo "Status: POOR (RED)\n";
    }
    
} catch (PDOException $e) {
    echo "CRITICAL: Database connection failed - " . $e->getMessage() . "\n";
}

// Save results
file_put_contents('reports/phase3_verification/audit_log_raw_data.json', json_encode($results, JSON_PRETTY_PRINT));
echo "\n=== AUDIT LOG VERIFICATION COMPLETE ===\n";
echo "Results saved to: reports/phase3_verification/audit_log_raw_data.json\n";

?>