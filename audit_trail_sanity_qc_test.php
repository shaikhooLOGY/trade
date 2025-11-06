<?php
/**
 * AUDIT TRAIL SANITY QC TEST SCRIPT
 * Tests critical user actions and validates audit log integrity
 */

require_once 'includes/config.php';
require_once 'includes/bootstrap.php';
require_once 'includes/logger/audit_log.php';

echo "=== AUDIT TRAIL SANITY QC TEST ===\n\n";

// Initialize counters
$test_actions = [];
$audit_events_before = 0;
$audit_events_after = 0;

try {
    // Get current audit events count
    $result = $mysqli->query("SELECT COUNT(*) as count FROM audit_events");
    $audit_events_before = $result ? (int)$result->fetch_assoc()['count'] : 0;
    echo "1. INITIAL AUDIT STATE:\n";
    echo "   Current audit events count: $audit_events_before\n\n";
    
    echo "2. TESTING CRITICAL USER ACTIONS:\n\n";
    
    // TEST 1: MTM Enrollment Action
    echo "TEST 1: MTM Enrollment Action\n";
    echo "Action: log_mtm_action('mtm_enrollment_test', 'User enrolled in test model')\n";
    
    try {
        $event_id = log_mtm_action('mtm_enrollment_test', 'QC Test: User enrolled in test model at basic tier', [
            'user_id' => 1,
            'target_type' => 'mtm_model',
            'target_id' => 1,
            'metadata' => ['tier' => 'basic', 'test' => 'audit_sanity_qc'],
            'severity' => 'medium'
        ]);
        
        if ($event_id) {
            echo "✓ SUCCESS: MTM audit event logged with ID: $event_id\n";
            $test_actions[] = "MTM Enrollment - Event ID: $event_id";
        } else {
            echo "✗ FAILED: MTM audit event logging failed\n";
            $test_actions[] = "MTM Enrollment - FAILED";
        }
    } catch (Exception $e) {
        echo "✗ EXCEPTION: MTM audit event failed: " . $e->getMessage() . "\n";
        $test_actions[] = "MTM Enrollment - EXCEPTION: " . $e->getMessage();
    }
    echo "\n";
    
    // TEST 2: Admin Approval Action
    echo "TEST 2: Admin Approval Action\n";
    echo "Action: log_admin_action('enrollment_approve_test', 'QC Test: Admin approved enrollment')\n";
    
    try {
        $event_id = log_admin_action('enrollment_approve_test', 'QC Test: Admin approved enrollment for user 1', [
            'admin_id' => 1,
            'user_id' => 1,
            'target_type' => 'mtm_enrollment',
            'target_id' => 1,
            'metadata' => ['admin_notes' => 'QC Test approval', 'test' => 'audit_sanity_qc'],
            'severity' => 'high'
        ]);
        
        if ($event_id) {
            echo "✓ SUCCESS: Admin audit event logged with ID: $event_id\n";
            $test_actions[] = "Admin Approval - Event ID: $event_id";
        } else {
            echo "✗ FAILED: Admin audit event logging failed\n";
            $test_actions[] = "Admin Approval - FAILED";
        }
    } catch (Exception $e) {
        echo "✗ EXCEPTION: Admin audit event failed: " . $e->getMessage() . "\n";
        $test_actions[] = "Admin Approval - EXCEPTION: " . $e->getMessage();
    }
    echo "\n";
    
    // TEST 3: Trade Creation Action
    echo "TEST 3: Trade Creation Action\n";
    echo "Action: log_trade_action('trade_create_test', 'QC Test: Trade created')\n";
    
    try {
        $event_id = log_trade_action('trade_create_test', 'QC Test: Trade created - Symbol: TEST, Quantity: 100', [
            'user_id' => 1,
            'target_type' => 'trade',
            'target_id' => 999,
            'metadata' => [
                'symbol' => 'TEST',
                'side' => 'buy',
                'quantity' => 100,
                'price' => 50.00,
                'test' => 'audit_sanity_qc'
            ],
            'severity' => 'low'
        ]);
        
        if ($event_id) {
            echo "✓ SUCCESS: Trade audit event logged with ID: $event_id\n";
            $test_actions[] = "Trade Creation - Event ID: $event_id";
        } else {
            echo "✗ FAILED: Trade audit event logging failed\n";
            $test_actions[] = "Trade Creation - FAILED";
        }
    } catch (Exception $e) {
        echo "✗ EXCEPTION: Trade audit event failed: " . $e->getMessage() . "\n";
        $test_actions[] = "Trade Creation - EXCEPTION: " . $e->getMessage();
    }
    echo "\n";
    
    // Get audit events count after tests
    $result = $mysqli->query("SELECT COUNT(*) as count FROM audit_events");
    $audit_events_after = $result ? (int)$result->fetch_assoc()['count'] : 0;
    
    echo "3. AUDIT EVENTS SUMMARY:\n";
    echo "   Before tests: $audit_events_before\n";
    echo "   After tests:  $audit_events_after\n";
    echo "   New events:   " . ($audit_events_after - $audit_events_before) . "\n\n";
    
    echo "4. RECENT AUDIT EVENTS:\n";
    $result = $mysqli->query("SELECT * FROM audit_events ORDER BY created_at DESC LIMIT 10");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "   - ID: {$row['id']}, Actor: {$row['actor_id']}, Entity: {$row['entity']}, Action: {$row['action']}, Created: {$row['created_at']}\n";
            if ($row['details']) {
                $details = json_decode($row['details'], true);
                echo "     Details: " . json_encode($details) . "\n";
            }
        }
    } else {
        echo "   No audit events found\n";
    }
    echo "\n";
    
    echo "5. TEST RESULTS SUMMARY:\n";
    foreach ($test_actions as $action) {
        echo "   - $action\n";
    }
    echo "\n";
    
    // Check if audit API endpoint exists and is functional
    echo "6. AUDIT API ENDPOINT CHECK:\n";
    if (file_exists('api/admin/audit_log.php')) {
        echo "✓ api/admin/audit_log.php exists\n";
        
        // Try to query the audit API
        echo "   Testing audit API response...\n";
        ob_start();
        $_GET['limit'] = '5';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';
        
        try {
            include 'api/admin/audit_log.php';
            $api_output = ob_get_clean();
            
            if (strpos($api_output, '"success"') !== false) {
                echo "✓ Audit API appears functional\n";
            } else {
                echo "✗ Audit API response issue\n";
                echo "   Response: " . substr($api_output, 0, 200) . "...\n";
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo "✗ Audit API error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "✗ api/admin/audit_log.php missing\n";
    }
    
} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== END AUDIT TRAIL SANITY QC TEST ===\n";