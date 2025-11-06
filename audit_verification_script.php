<?php
/**
 * AUDIT VERIFICATION SCRIPT
 * Retrieves and analyzes audit log entries
 */

require_once 'includes/config.php';
require_once 'includes/bootstrap.php';
require_once 'includes/logger/audit_log.php';

echo "=== AUDIT TRAIL VERIFICATION REPORT ===\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";

// Get recent audit events
$result = $mysqli->query('SELECT * FROM audit_events ORDER BY created_at DESC LIMIT 10');

echo "1. RECENT AUDIT EVENTS (Last 10):\n";
echo "================================\n";

if ($result && $result->num_rows > 0) {
    $event_count = 0;
    while ($row = $result->fetch_assoc()) {
        $event_count++;
        echo "\nEvent #$event_count (ID: {$row['id']}):\n";
        echo "  - Actor ID: {$row['actor_id']}\n";
        echo "  - Action: {$row['action']}\n";
        echo "  - Entity: {$row['entity']}\n";
        echo "  - Entity ID: " . ($row['entity_id'] ?? 'NULL') . "\n";
        echo "  - Summary: " . substr($row['summary'], 0, 100) . "...\n";
        echo "  - IP Address: " . ($row['ip_address'] ?? 'NULL') . "\n";
        echo "  - Created: {$row['created_at']}\n";
    }
} else {
    echo "No audit events found in database.\n";
}

// Check our test events specifically
echo "\n\n2. TEST EVENTS ANALYSIS:\n";
echo "========================\n";

$test_actions = ['mtm_enrollment_test', 'enrollment_approve_test', 'trade_create_test'];
foreach ($test_actions as $action) {
    $stmt = $mysqli->prepare("SELECT * FROM audit_events WHERE action = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('s', $action);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $event = $result->fetch_assoc();
        echo "✓ $action:\n";
        echo "  - Event ID: {$event['id']}\n";
        echo "  - Actor: {$event['actor_id']}\n";
        echo "  - Entity: {$event['entity']}\n";
        echo "  - Entity ID: " . ($event['entity_id'] ?? 'NULL') . "\n";
        echo "  - Timestamp: {$event['created_at']}\n";
        echo "  - Summary: " . substr($event['summary'], 0, 80) . "...\n";
    } else {
        echo "✗ $action: No events found\n";
    }
    $stmt->close();
}

// Verify audit log structure
echo "\n\n3. AUDIT LOG DATABASE STRUCTURE:\n";
echo "==================================\n";

$columns = $mysqli->query('DESCRIBE audit_events');
if ($columns) {
    while ($col = $columns->fetch_assoc()) {
        echo "Field: {$col['Field']}, Type: {$col['Type']}, Null: {$col['Null']}, Key: {$col['Key']}, Default: {$col['Default']}\n";
    }
}

// Count total events
echo "\n\n4. AUDIT LOG STATISTICS:\n";
echo "=========================\n";

$total_result = $mysqli->query('SELECT COUNT(*) as total FROM audit_events');
if ($total_result) {
    $total = $total_result->fetch_assoc()['total'];
    echo "Total audit events: $total\n";
}

// Check for recent events (last hour)
$recent_result = $mysqli->query("SELECT COUNT(*) as recent FROM audit_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
if ($recent_result) {
    $recent = $recent_result->fetch_assoc()['recent'];
    echo "Events in last hour: $recent\n";
}

// Check events by entity type
echo "\n\n5. EVENTS BY ENTITY TYPE:\n";
echo "==========================\n";

$entity_result = $mysqli->query("SELECT entity, COUNT(*) as count FROM audit_events GROUP BY entity ORDER BY count DESC");
if ($entity_result) {
    while ($entity = $entity_result->fetch_assoc()) {
        echo "{$entity['entity']}: {$entity['count']} events\n";
    }
}

echo "\n\n6. AUDIT LOG API ENDPOINT TEST:\n";
echo "================================\n";

echo "Note: API endpoint requires admin authentication.\n";
echo "Direct database access provides more reliable verification.\n";
echo "API endpoint exists at: api/admin/audit_log.php\n";
echo "Expected JSON structure: {events: [...], pagination: {...}}\n";

echo "\n=== END VERIFICATION REPORT ===\n";