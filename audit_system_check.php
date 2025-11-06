<?php
/**
 * Audit System Health Check for QC Testing
 */

require_once 'includes/config.php';
require_once 'includes/bootstrap.php';
require_once 'includes/logger/audit_log.php';

echo "=== AUDIT SYSTEM HEALTH CHECK ===\n\n";

// Check if audit_events table exists and its schema
echo "1. CHECKING AUDIT_EVENTS TABLE SCHEMA:\n";
$result = $mysqli->query("SHOW CREATE TABLE audit_events");
if ($result) {
    $row = $result->fetch_assoc();
    echo "AUDIT_EVENTS TABLE SCHEMA:\n";
    echo $row['Create Table'] . "\n\n";
} else {
    echo "ERROR: audit_events table does not exist\n\n";
}

// Check table contents
echo "2. CHECKING TABLE CONTENTS:\n";
$result = $mysqli->query("SELECT COUNT(*) as count FROM audit_events");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Current audit events count: " . $row['count'] . "\n\n";
    
    // Get sample entries
    $result = $mysqli->query("SELECT * FROM audit_events ORDER BY created_at DESC LIMIT 3");
    if ($result && $result->num_rows > 0) {
        echo "SAMPLE AUDIT EVENTS:\n";
        while ($row = $result->fetch_assoc()) {
            echo "- ID: {$row['id']}, Actor: {$row['actor_id']}, Entity: {$row['entity']}, Action: {$row['action']}, Created: {$row['created_at']}\n";
        }
        echo "\n";
    }
} else {
    echo "Cannot query audit_events table\n\n";
}

// Check if the audit logging function can work
echo "3. TESTING AUDIT LOGGING FUNCTION:\n";
try {
    if (function_exists('log_audit_event')) {
        echo "✓ Audit logging function exists\n";
        
        // Test with a simple event
        $event_id = log_audit_event('qc_test', 'system_event', 'QC test event from audit sanity check', [
            'metadata' => ['test' => 'audit_sanity_qc'],
            'severity' => 'low'
        ]);
        
        if ($event_id) {
            echo "✓ SUCCESS: Test audit event logged with ID: $event_id\n\n";
        } else {
            echo "✗ FAILED: Audit event logging failed\n\n";
        }
    } else {
        echo "✗ Audit logging function not found\n\n";
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION during audit logging: " . $e->getMessage() . "\n\n";
}

// Check API endpoints that should generate audit events
echo "4. CHECKING TARGET API ENDPOINTS:\n";
$endpoints_to_test = [
    'api/mtm/enroll.php',
    'api/admin/enrollment/approve.php', 
    'api/trades/create.php',
    'api/trades/update.php'
];

foreach ($endpoints_to_test as $endpoint) {
    if (file_exists($endpoint)) {
        echo "✓ Found: $endpoint\n";
        
        // Check if the endpoint includes audit logging
        $content = file_get_contents($endpoint);
        if (strpos($content, 'log_') !== false || strpos($content, 'audit') !== false) {
            echo "  - Contains audit logging references\n";
        } else {
            echo "  - ⚠️  No audit logging references found\n";
        }
    } else {
        echo "✗ Missing: $endpoint\n";
    }
}
echo "\n";

echo "5. AUDIT LOG API ENDPOINT:\n";
if (file_exists('api/admin/audit_log.php')) {
    echo "✓ Found: api/admin/audit_log.php\n";
} else {
    echo "✗ Missing: api/admin/audit_log.php\n";
}
echo "\n";

echo "=== END AUDIT SYSTEM CHECK ===\n";