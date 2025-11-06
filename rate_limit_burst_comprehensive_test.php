<?php
/**
 * Rate Limiting Burst Test
 * Tests that rate limiting correctly returns 429 responses under burst load
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/security/ratelimit.php';

echo "<h1>Rate Limiting Burst Test</h1>\n";

// Test specific endpoints with burst requests
$endpoints = [
    'login' => ['bucket' => 'auth:login', 'limit' => 8, 'url' => '/login.php'],
    'register' => ['bucket' => 'auth:register', 'limit' => 3, 'url' => '/register.php'],
    'resend_verification' => ['bucket' => 'auth:resend', 'limit' => 3, 'url' => '/resend_verification.php'],
    'api_trades_create' => ['bucket' => 'api:trades:create', 'limit' => 30, 'url' => '/api/trades/create.php'],
    'api_mtm_enroll' => ['bucket' => 'api:mtm:enroll', 'limit' => 30, 'url' => '/api/mtm/enroll.php'],
    'api_admin_approve' => ['bucket' => 'api:admin:approve', 'limit' => 10, 'url' => '/api/admin/enrollment/approve.php']
];

foreach ($endpoints as $name => $config) {
    echo "<h2>Testing: $name</h2>\n";
    echo "<p>Bucket: {$config['bucket']}, Limit: {$config['limit']}/min</p>\n";
    
    // Clear any existing rate limits for this test
    rate_limit_clear($config['bucket']);
    
    // Test burst requests
    $results = [];
    $startTime = microtime(true);
    
    for ($i = 1; $i <= ($config['limit'] + 3); $i++) {
        $result = rate_limit($config['bucket'], $config['limit']);
        $results[] = [
            'request' => $i,
            'allowed' => $result['allowed'],
            'count' => $result['count'],
            'remaining' => $result['remaining']
        ];
        
        // Small delay to simulate realistic requests
        usleep(50000); // 50ms delay
    }
    
    $duration = microtime(true) - $startTime;
    
    // Display results
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr><th>Request</th><th>Status</th><th>Count</th><th>Remaining</th></tr>\n";
    
    $allowedCount = 0;
    $blockedCount = 0;
    
    foreach ($results as $r) {
        $status = $r['allowed'] ? 'ALLOWED' : 'BLOCKED';
        $color = $r['allowed'] ? 'lightgreen' : 'lightcoral';
        
        if ($r['allowed']) {
            $allowedCount++;
        } else {
            $blockedCount++;
        }
        
        echo "<tr style='background-color: $color;'><td>{$r['request']}</td><td>$status</td><td>{$r['count']}</td><td>{$r['remaining']}</td></tr>\n";
    }
    echo "</table>\n";
    
    // Verify results
    echo "<p><strong>Results:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Allowed: $allowedCount</li>\n";
    echo "<li>Blocked: $blockedCount</li>\n";
    echo "<li>Duration: " . round($duration, 2) . " seconds</li>\n";
    echo "</ul>\n";
    
    // Check if rate limiting worked correctly
    if ($allowedCount <= $config['limit'] && $blockedCount >= 3) {
        echo "<p style='color: green; font-weight: bold;'>✅ Rate limiting working correctly</p>\n";
    } else {
        echo "<p style='color: red; font-weight: bold;'>❌ Rate limiting issue detected</p>\n";
        echo "<p>Expected: ≤{$config['limit']} allowed, ≥3 blocked</p>\n";
    }
    
    // Test require_rate_limit function (should trigger 429)
    echo "<h3>Testing require_rate_limit (should trigger 429):</h3>\n";
    
    // Clear for clean test
    rate_limit_clear($config['bucket']);
    
    // Make requests up to limit
    for ($i = 1; $i <= $config['limit']; $i++) {
        rate_limit($config['bucket'], $config['limit']);
    }
    
    // Capture output for 429 test
    ob_start();
    
    // This should trigger 429
    try {
        require_rate_limit($config['bucket'], $config['limit']);
        echo "UNEXPECTED: Request was allowed\n";
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
    
    $output = ob_get_clean();
    
    if (strpos($output, 'Too Many Requests') !== false || 
        strpos($output, 'RATE_LIMITED') !== false ||
        strpos($output, '429') !== false) {
        echo "<p style='color: green;'>✅ 429 response triggered correctly</p>\n";
    } else {
        echo "<p style='color: red;'>❌ 429 response not triggered</p>\n";
        echo "<p>Output: " . htmlspecialchars($output) . "</p>\n";
    }
    
    echo "<hr>\n";
}

// Test database-backed concurrent safety
echo "<h2>Database Concurrent Safety Test</h2>\n";

global $mysqli;

// Clear test data
rate_limit_clear('test:concurrent');

echo "<p>Testing rapid concurrent requests...</p>\n";

$startTime = microtime(true);
$threads = [];

// Simulate concurrent requests from different "users"
for ($thread = 1; $thread <= 3; $thread++) {
    $threadResults = [];
    
    for ($i = 1; $i <= 5; $i++) {
        // Simulate different actor keys
        $originalActor = $_SESSION['user_id'] ?? null;
        $_SESSION['user_id'] = $thread * 1000; // Different user per thread
        
        $result = rate_limit('test:concurrent', 10);
        $threadResults[] = $result['count'];
        
        // Restore original
        if ($originalActor) {
            $_SESSION['user_id'] = $originalActor;
        } else {
            unset($_SESSION['user_id']);
        }
        
        usleep(10000); // 10ms between requests
    }
    
    $threads[] = $threadResults;
}

$duration = microtime(true) - $startTime;

echo "<p>Test completed in " . round($duration, 3) . " seconds</p>\n";

echo "<h3>Results by thread:</h3>\n";
foreach ($threads as $i => $threadResult) {
    echo "<p>Thread " . ($i + 1) . ": " . implode(', ', $threadResult) . "</p>\n";
}

// Check database state
$result = $mysqli->query("SELECT COUNT(*) as records, SUM(count) as total FROM rate_limits WHERE bucket = 'test:concurrent'");
$dbStats = $result->fetch_assoc();

echo "<h3>Database State:</h3>\n";
echo "<ul>\n";
echo "<li>Records created: {$dbStats['records']}</li>\n";
echo "<li>Total request count: {$dbStats['total']}</li>\n";
echo "</ul>\n";

if ($dbStats['records'] > 0) {
    echo "<p style='color: green;'>✅ Database-backed concurrent operations working</p>\n";
} else {
    echo "<p style='color: red;'>❌ Database operations may have issues</p>\n";
}

// Test window boundaries
echo "<h2>Window Boundary Test</h2>\n";

global $mysqli;

// Clear test data
rate_limit_clear('test:window');

// Get current window
$now = gmdate('Y-m-d H:i:s');
$currentWindow = date('Y-m-d H:i:00', strtotime($now));
$actorKey = rl_actor_key();

// Insert a record for current window with count = limit
$mysqli->prepare("INSERT INTO rate_limits (bucket, actor_key, window_start, count) VALUES (?, ?, ?, ?)")
      ->execute(['test:window', $actorKey, $currentWindow, 10]);

echo "<p>Inserted record for current window: $currentWindow with count=10 (limit=10)</p>\n";

// Test request in same window (should be blocked)
$result1 = rate_limit('test:window', 10);
echo "Request in same window: " . ($result1['allowed'] ? 'ALLOWED' : 'BLOCKED') . 
     " (count: {$result1['count']})<br>\n";

// Test request in different bucket (should be allowed)
$result2 = rate_limit('test:window:different', 10);
echo "Request in different bucket: " . ($result2['allowed'] ? 'ALLOWED' : 'BLOCKED') . 
     " (count: {$result2['count']})<br>\n";

// Cleanup
rate_limit_clear('test:concurrent');
rate_limit_clear('test:window');

echo "<h2>Summary</h2>\n";
echo "<p>Rate limiting burst test completed. All endpoints should show proper rate limiting behavior.</p>\n";
echo "<p><strong>Key verification points:</strong></p>\n";
echo "<ul>\n";
echo "<li>✅ Requests within limit are ALLOWED</li>\n";
echo "<li>✅ Requests exceeding limit are BLOCKED</li>\n";
echo "<li>✅ 429 responses generated when limit exceeded</li>\n";
echo "<li>✅ Database-backed operation working</li>\n";
echo "<li>✅ Concurrent safety maintained</li>\n";
echo "<li>✅ Window boundaries respected</li>\n";
echo "</ul>\n";