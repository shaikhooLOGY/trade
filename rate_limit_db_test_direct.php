<?php
/**
 * Direct database rate limiting test
 * Tests the rate_limits table and database-backed implementation
 */

require_once 'includes/config.php';
require_once 'includes/bootstrap.php';
require_once 'includes/security/ratelimit.php';

echo "=== DIRECT DATABASE RATE LIMITING TEST ===\n\n";

// Test 1: Check if rate_limits table exists
echo "1. CHECKING RATE LIMITS TABLE:\n";
try {
    $result = $mysqli->query("DESCRIBE rate_limits");
    if ($result) {
        echo "✅ rate_limits table exists\n";
        echo "Table structure:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  - {$row['Field']}: {$row['Type']}\n";
        }
    } else {
        echo "❌ rate_limits table does not exist\n";
        echo "Error: " . $mysqli->error . "\n";
    }
} catch (Exception $e) {
    echo "❌ Error checking table: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: Check table contents
echo "2. CURRENT RATE LIMITS TABLE CONTENTS:\n";
try {
    $result = $mysqli->query("SELECT * FROM rate_limits LIMIT 10");
    if ($result) {
        $count = $result->num_rows;
        echo "Found $count records in rate_limits table\n";
        while ($row = $result->fetch_assoc()) {
            echo "  - Bucket: {$row['bucket']}, Actor: {$row['actor_key']}, Count: {$row['count']}, Window: {$row['window_start']}\n";
        }
    } else {
        echo "❌ Cannot query rate_limits table\n";
    }
} catch (Exception $e) {
    echo "❌ Error querying table: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: Test rate limit function directly
echo "3. TESTING RATE LIMIT FUNCTION DIRECTLY:\n";

// Clear any existing test data
rate_limit_clear('test_direct', 'test:direct');

// Simulate 5 requests to test bucket (limit = 3)
echo "Testing bucket 'test_direct' with limit 3:\n";
for ($i = 1; $i <= 5; $i++) {
    $result = rate_limit('test_direct', 3);
    $status = $result['allowed'] ? 'ALLOWED' : 'BLOCKED';
    echo "  Request $i: $status (Count: {$result['count']}, Remaining: {$result['remaining']})\n";
}

// Check database state
echo "\n4. DATABASE STATE AFTER TEST:\n";
try {
    $result = $mysqli->query("SELECT * FROM rate_limits WHERE bucket = 'test_direct' ORDER BY updated_at DESC");
    if ($result && $result->num_rows > 0) {
        echo "Rate limit records found:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  - Actor: {$row['actor_key']}, Count: {$row['count']}, Window: {$row['window_start']}, Updated: {$row['updated_at']}\n";
        }
    } else {
        echo "❌ No rate limit records found in database\n";
    }
} catch (Exception $e) {
    echo "❌ Error checking database state: " . $e->getMessage() . "\n";
}

// Test 5: Test with require_rate_limit (this should exit on limit exceeded)
echo "\n5. TESTING require_rate_limit FUNCTION:\n";
echo "This will test if require_rate_limit() properly exits with 429:\n";

// Clear test data
rate_limit_clear('test_require', 'test:require');

try {
    echo "First 3 requests (should pass):\n";
    for ($i = 1; $i <= 3; $i++) {
        $result = require_rate_limit('test_require', 3);
        echo "  Request $i: PASSED\n";
    }
    
    echo "4th request (should trigger 429 and exit):\n";
    // This should exit with 429
    require_rate_limit('test_require', 3);
    echo "  ❌ UNEXPECTED: 4th request was allowed (should have been blocked)\n";
    
} catch (Exception $e) {
    echo "Exception during require_rate_limit test: " . $e->getMessage() . "\n";
}

echo "\n=== END DIRECT DATABASE TEST ===\n";
?>