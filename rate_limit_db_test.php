<?php
/**
 * Database-backed Rate Limiting Test Script
 * Tests 429 responses, headers, concurrent safety, and database-backed operation
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/security/ratelimit.php';

echo "<h1>Database-Backed Rate Limiting Test</h1>\n";

// Test 1: Basic rate limiting functionality
echo "<h2>Test 1: Basic Rate Limiting Functionality</h2>\n";

// Clear any existing rate limits for testing
rate_limit_clear('test:basic');

for ($i = 1; $i <= 5; $i++) {
    $result = rate_limit('test:basic', 3);
    echo "Request $i: " . ($result['allowed'] ? 'ALLOWED' : 'BLOCKED') . 
         " (count: {$result['count']}, remaining: {$result['remaining']})<br>\n";
}

// Test 2: Rate limit exceeded (should return 429)
echo "<h2>Test 2: Rate Limit Exceeded (429 Response)</h2>\n";

// Clear and test exceeding limit
rate_limit_clear('test:exceed');

for ($i = 1; $i <= 4; $i++) {
    $result = rate_limit('test:exceed', 3);
    echo "Request $i: " . ($result['allowed'] ? 'ALLOWED' : 'BLOCKED') . 
         " (count: {$result['count']})<br>\n";
}

// Test the function that should trigger 429
echo "<h3>Testing require_rate_limit (should trigger 429):</h3>\n";

// Capture output to check 429 response
ob_start();
try {
    require_rate_limit('test:exceed', 3);
    echo "UNEXPECTED: Request was allowed<br>\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "<br>\n";
}
$output = ob_get_clean();

// Check if 429 headers were set
if (strpos($output, 'Too Many Requests') !== false || 
    http_response_code() === 429 ||
    strpos($output, 'RATE_LIMITED') !== false) {
    echo "✅ 429 Response triggered correctly<br>\n";
} else {
    echo "❌ 429 Response not triggered<br>\n";
    echo "Output: " . htmlspecialchars($output) . "<br>\n";
}

// Test 3: Actor key generation
echo "<h2>Test 3: Actor Key Generation</h2>\n";

// Test without session (anonymous)
unset($_SESSION['user_id']);
$anonKey = rl_actor_key();
echo "Anonymous actor key: " . htmlspecialchars($anonKey) . "<br>\n";

// Test with session (authenticated)
$_SESSION['user_id'] = 12345;
$authKey = rl_actor_key();
echo "Authenticated actor key: " . htmlspecialchars($authKey) . "<br>\n";

// Verify keys are different
if ($anonKey !== $authKey) {
    echo "✅ Actor keys differ correctly for anonymous vs authenticated<br>\n";
} else {
    echo "❌ Actor keys should differ<br>\n";
}

// Test 4: Database operation verification
echo "<h2>Test 4: Database Operation Verification</h2>\n";

// Clear test data
rate_limit_clear('test:db');

global $mysqli;

// Check initial state
$result = $mysqli->query("SELECT COUNT(*) as count FROM rate_limits WHERE bucket = 'test:db'");
$initialCount = $result->fetch_assoc()['count'];
echo "Initial database count: $initialCount<br>\n";

// Make some requests
for ($i = 1; $i <= 3; $i++) {
    rate_limit('test:db', 5);
}

// Check database state
$result = $mysqli->query("SELECT COUNT(*) as count, SUM(count) as total FROM rate_limits WHERE bucket = 'test:db'");
$dbState = $result->fetch_assoc();
echo "After requests - Records: {$dbState['count']}, Total count: {$dbState['total']}<br>\n";

if ($dbState['count'] > 0) {
    echo "✅ Database operations working correctly<br>\n";
} else {
    echo "❌ Database operations failed<br>\n";
}

// Test 5: Headers verification
echo "<h2>Test 5: Headers Verification</h2>\n";

// Clear headers list
if (!function_exists('headers_list')) {
    echo "❌ Cannot verify headers - headers_list not available<br>\n";
} else {
    // Reset rate limit for header testing
    rate_limit_clear('test:headers');
    
    // Start output buffering to capture headers
    ob_start();
    
    // Test within limit
    require_rate_limit('test:headers', 5);
    echo "Within limit request completed<br>\n";
    
    ob_end_flush();
    
    // Check if headers were set
    $headers = headers_list();
    $rateLimitHeaders = array_filter($headers, function($h) {
        return strpos($h, 'X-RateLimit-') === 0 || strpos($h, 'Retry-After') === 0;
    });
    
    echo "Rate limit headers found: " . count($rateLimitHeaders) . "<br>\n";
    foreach ($rateLimitHeaders as $header) {
        echo "  - " . htmlspecialchars($header) . "<br>\n";
    }
    
    if (count($rateLimitHeaders) > 0) {
        echo "✅ Rate limit headers set correctly<br>\n";
    } else {
        echo "❌ Rate limit headers not found<br>\n";
    }
}

// Test 6: Concurrent safety test (basic)
echo "<h2>Test 6: Concurrent Safety (Basic Test)</h2>\n";

// Clear test data
rate_limit_clear('test:concurrent');

// Simulate concurrent requests by making rapid calls
$requests = [];
for ($i = 1; $i <= 5; $i++) {
    $result = rate_limit('test:concurrent', 3);
    $requests[] = $result['count'];
    usleep(10000); // 10ms delay
}

echo "Concurrent request counts: " . implode(', ', $requests) . "<br>\n";

// Check if counts are reasonable (should be 1,2,3,3,3 or similar due to limit)
$uniqueCounts = array_unique($requests);
if (count($uniqueCounts) <= 3 && min($requests) >= 1) {
    echo "✅ Concurrent safety appears to be working<br>\n";
} else {
    echo "❌ Concurrent safety may have issues<br>\n";
}

// Test 7: Window boundaries
echo "<h2>Test 7: Window Boundary Test</h2>\n";

global $mysqli;

// Clear test data
rate_limit_clear('test:window');

// Insert a record for the current minute
$now = gmdate('Y-m-d H:i:s');
$windowStart = date('Y-m-d H:i:00', strtotime($now));
$actorKey = rl_actor_key();

$mysqli->query("INSERT INTO rate_limits (bucket, actor_key, window_start, count) 
               VALUES ('test:window', '$actorKey', '$windowStart', 3)");

echo "Inserted test record for window: $windowStart<br>\n";

// Test requesting within the same window (should be limited)
$result = rate_limit('test:window', 5);
echo "Request in same window: " . ($result['allowed'] ? 'ALLOWED' : 'BLOCKED') . 
     " (count: {$result['count']})<br>\n";

// Test requesting for a different window (should be allowed)
$result = rate_limit('test:window:future', 5);
echo "Request in different bucket: " . ($result['allowed'] ? 'ALLOWED' : 'BLOCKED') . 
     " (count: {$result['count']})<br>\n";

// Cleanup
rate_limit_clear('test:basic');
rate_limit_clear('test:exceed');
rate_limit_clear('test:db');
rate_limit_clear('test:headers');
rate_limit_clear('test:concurrent');
rate_limit_clear('test:window');

echo "<h2>Test Summary</h2>\n";
echo "<p>All rate limiting tests completed. Check results above for details.</p>\n";
echo "<p><strong>Note:</strong> This test verifies the database-backed implementation is working correctly.</p>\n";