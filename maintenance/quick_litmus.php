<?php
/**
 * Quick Litmus Test - 60-Second Backend Readiness Probe
 *
 * Tests 7 critical endpoints for Phase-3 integration readiness
 * Exit code: 0 if all pass, 1 if any fail
 *
 * Usage: php maintenance/quick_litmus.php [--base=http://127.0.0.1:8084]
 */

declare(strict_types=1);

class QuickLitmusTest {
    private $results = [];
    private $totalLatency = 0;
    private $startTime;
    private $baseUrl;
    private $baseUrlSource = 'default'; // 'flag', 'env', or 'default'
    private $connectionFamily = 'v4'; // 'v4' or 'v6'
    
    // ANSI Color codes for CLI output
    const COLOR_GREEN = "\033[32m";
    const COLOR_RED = "\033[31m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_RESET = "\033[0m";
    const COLOR_BOLD = "\033[1m";
    
    // 7-endpoint probe configuration
    private $endpoints = [
        ['method' => 'GET', 'url' => '/api/health.php', 'expected' => 200, 'description' => 'Health check'],
        ['method' => 'GET', 'url' => '/api/profile/me.php', 'expected' => 401, 'description' => 'Profile endpoint (unauthorized)'],
        ['method' => 'POST', 'url' => '/api/trades/create.php', 'expected' => 403, 'description' => 'Trade creation (forbidden)'],
        ['method' => 'POST', 'url' => '/api/mtm/enroll.php', 'expected' => 403, 'description' => 'MTM enrollment (forbidden)'],
        ['method' => 'GET', 'url' => '/api/admin/e2e_status.php', 'expected' => 401, 'description' => 'E2E status (unauthorized)'],
        ['method' => 'POST', 'url' => '/api/agent/log.php', 'expected' => 401, 'description' => 'Agent log (unauthorized)'],
        ['method' => 'GET', 'url' => '/api/admin/agent/logs.php', 'expected' => 401, 'description' => 'Agent logs view (unauthorized)']
    ];
    
    public function __construct() {
        $this->startTime = microtime(true);
        $this->resolveBaseUrl();
    }
    
    private function showUsage(): void {
        echo "Quick Litmus Test - 60-Second Backend Readiness Probe\n";
        echo "Usage: php maintenance/quick_litmus.php [--base=http://127.0.0.1:8084]\n\n";
        echo "Options:\n";
        echo "  --base=<url>  Specify the base URL for testing (overrides env/default)\n";
        echo "  --help        Show this help message\n\n";
        echo "Base URL Resolution Order:\n";
        echo "  1. --base=<url> CLI flag\n";
        echo "  2. \$BASE_URL environment variable\n";
        echo "  3. Default: http://127.0.0.1:8084\n\n";
        echo "IPv4/IPv6 Handling:\n";
        echo "  - If host is 'localhost', will try IPv4 (127.0.0.1) first\n";
        echo "  - Falls back to IPv6 (::1) only if IPv4 fails\n\n";
        exit(0);
    }
    
    private function parseArgs(): array {
        $args = [];
        global $argv;
        
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--help' || $arg === '-h') {
                $this->showUsage();
            } elseif (preg_match('/^--base=(.+)$/', $arg, $matches)) {
                $args['base_url'] = $matches[1];
            } elseif ($arg === '--base' && isset($argv[$i + 1])) {
                $args['base_url'] = $argv[++$i];
            }
        }
        
        return $args;
    }
    
    private function resolveBaseUrl(): void {
        $args = $this->parseArgs();
        
        // Priority 1: --base CLI flag
        if (isset($args['base_url'])) {
            $this->baseUrl = $args['base_url'];
            $this->baseUrlSource = 'flag';
            return;
        }
        
        // Priority 2: BASE_URL environment variable
        $envBaseUrl = getenv('BASE_URL');
        if ($envBaseUrl !== false && $envBaseUrl !== '') {
            $this->baseUrl = $envBaseUrl;
            $this->baseUrlSource = 'env';
            return;
        }
        
        // Priority 3: Default
        $this->baseUrl = 'http://127.0.0.1:8084';
        $this->baseUrlSource = 'default';
    }
    
    // Legacy method - no longer used but kept for backwards compatibility
    private function getBaseUrl(): string {
        return $this->baseUrl;
    }
    
    private function makeRequest(string $method, string $url, array $data = []): array {
        $startTime = microtime(true);
        $fullUrl = $this->baseUrl . $url;
        $attemptedUrls = [];
        
        // Handle localhost -> IPv4/IPv6 deterministic connection
        $parsed = parse_url($fullUrl);
        $host = $parsed['host'] ?? '127.0.0.1';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $port = $parsed['port'] ?? 8084;
        
        if (strtolower($host) === 'localhost') {
            // First try IPv4 (127.0.0.1)
            $ipv4Url = "http://127.0.0.1:$port$path$query";
            $attemptedUrls[] = $ipv4Url;
            
            $result = $this->attemptConnection($ipv4Url, $method, $data, $startTime);
            $this->connectionFamily = 'v4';
            
            if ($result['success']) {
                return $result;
            }
            
            // If IPv4 failed, try IPv6 (::1)
            $ipv6Url = "http://[::1]:$port$path$query";
            $attemptedUrls[] = $ipv6Url;
            
            $result = $this->attemptConnection($ipv6Url, $method, $data, $startTime);
            $this->connectionFamily = 'v6';
            
            if ($result['success']) {
                return $result;
            }
            
            // Both failed
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            return [
                'success' => false,
                'status_code' => 0,
                'latency' => $latency,
                'error' => 'Connection failed - both IPv4 and IPv6 failed. Attempted: ' . implode(', ', $attemptedUrls)
            ];
        }
        
        // Non-localhost hosts - single attempt
        $result = $this->attemptConnection($fullUrl, $method, $data, $startTime);
        return $result;
    }
    
    private function attemptConnection(string $url, string $method, array $data, float $startTime): array {
        // Build HTTP context options
        $contextOptions = [
            'http' => [
                'method' => $method,
                'header' => [
                    'Content-Type: application/json',
                    'User-Agent: QuickLitmus/1.0'
                ],
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ];
        
        if (!empty($data)) {
            $contextOptions['http']['content'] = json_encode($data);
        }
        
        $context = stream_context_create($contextOptions);
        
        try {
            $response = @file_get_contents($url, false, $context);
            $latency = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
            
            if ($response === false) {
                return [
                    'success' => false,
                    'status_code' => 0,
                    'latency' => $latency,
                    'error' => "Connection failed to $url"
                ];
            }
            
            // Parse response headers to get status code
            $statusCode = $this->extractStatusCode($http_response_header ?? []);
            
            return [
                'success' => true,
                'status_code' => $statusCode,
                'latency' => $latency,
                'response' => $response
            ];
            
        } catch (Exception $e) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            return [
                'success' => false,
                'status_code' => 0,
                'latency' => $latency,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function extractStatusCode(array $headers): int {
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                return (int)$matches[1];
            }
        }
        return 0;
    }
    
    public function runTests(): void {
        echo self::COLOR_BOLD . "\n🔬 QUICK LITMUS TEST - 60-SECOND READINESS PROBE\n" . self::COLOR_RESET;
        echo "Resolved Base URL: {$this->baseUrl}\n";
        echo "Base URL Source: {$this->baseUrlSource} (flag/env/default)\n";
        echo "Connection Family: {$this->connectionFamily} (v4/v6)\n";
        echo "Started: " . date('Y-m-d H:i:s T') . "\n";
        echo str_repeat("─", 60) . "\n\n";
        
        $passed = 0;
        $failed = 0;
        
        foreach ($this->endpoints as $index => $endpoint) {
            $testNum = $index + 1;
            echo self::COLOR_BLUE . "[$testNum/7] Testing: {$endpoint['description']}" . self::COLOR_RESET . "\n";
            echo "  {$endpoint['method']} {$endpoint['url']} → expecting {$endpoint['expected']}\n";
            
            $data = [];
            if ($endpoint['method'] === 'POST') {
                // Add minimal POST data for POST requests
                $data = ['test' => 'quick_litmus_probe'];
            }
            
            $result = $this->makeRequest($endpoint['method'], $endpoint['url'], $data);
            $this->results[] = array_merge($endpoint, $result);
            $this->totalLatency += $result['latency'];
            
            if ($result['status_code'] === $endpoint['expected']) {
                echo "  " . self::COLOR_GREEN . "✅ PASS" . self::COLOR_RESET . " - Status: {$result['status_code']}, Latency: {$result['latency']}ms\n";
                $passed++;
            } else {
                echo "  " . self::COLOR_RED . "❌ FAIL" . self::COLOR_RESET . " - Expected: {$endpoint['expected']}, Got: {$result['status_code']}, Latency: {$result['latency']}ms\n";
                if (!empty($result['error'])) {
                    echo "  Error: {$result['error']}\n";
                }
                $failed++;
            }
            
            echo "\n";
            
            // Check if we've exceeded 60 seconds
            $elapsed = microtime(true) - $this->startTime;
            if ($elapsed > 60) {
                echo self::COLOR_YELLOW . "⚠️  TIMEOUT: 60-second limit reached after $testNum tests\n" . self::COLOR_RESET;
                break;
            }
        }
        
        $this->printSummary($passed, $failed);
    }
    
    private function printSummary(int $passed, int $failed): void {
        $totalTests = count($this->results);
        $avgLatency = $totalTests > 0 ? round($this->totalLatency / $totalTests, 2) : 0;
        $totalTime = round(microtime(true) - $this->startTime, 2);
        $passRate = $totalTests > 0 ? round(($passed / $totalTests) * 100, 1) : 0;
        
        echo str_repeat("─", 60) . "\n";
        echo self::COLOR_BOLD . "📊 QUICK LITMUS SUMMARY\n" . self::COLOR_RESET;
        echo "Total Tests: {$totalTests}\n";
        echo "Passed: " . self::COLOR_GREEN . "{$passed}" . self::COLOR_RESET . "\n";
        echo "Failed: " . self::COLOR_RED . "{$failed}" . self::COLOR_RESET . "\n";
        echo "Pass Rate: {$passRate}%\n";
        echo "Average Latency: {$avgLatency}ms\n";
        echo "Total Runtime: {$totalTime}s\n";
        
        if ($failed === 0) {
            echo "\n" . self::COLOR_GREEN . self::COLOR_BOLD . "✅ QUICK LITMUS GREEN GATE — safe for Phase-3 Integration" . self::COLOR_RESET . "\n";
            $this->createSuccessArtifacts($passRate);
            exit(0);
        } else {
            echo "\n" . self::COLOR_RED . self::COLOR_BOLD . "❌ QUICK LITMUS RED GATE — NOT ready for Phase-3 Integration" . self::COLOR_RESET . "\n";
            echo "Failed endpoints:\n";
            foreach ($this->results as $result) {
                if ($result['status_code'] !== $result['expected']) {
                    echo "  - {$result['method']} {$result['url']} (expected {$result['expected']}, got {$result['status_code']})\n";
                }
            }
            $this->createFailureArtifacts($passRate);
            exit(1);
        }
    }
    
    private function createSuccessArtifacts(float $passRate): void {
        $timestamp = date('Y-m-d\TH:i:s.v\Z');
        $gate = 'GREEN';
        
        // Create flag file
        file_put_contents('.phase3_litmus_quick_pass', $timestamp);
        
        // Update context
        $this->updateContext($timestamp, $passRate, $gate);
        
        // Create markdown report
        $this->createReport($timestamp, $passRate, $gate);
    }
    
    private function createFailureArtifacts(float $passRate): void {
        $timestamp = date('Y-m-d\TH:i:s.v\Z');
        $gate = 'RED';
        $this->updateContext($timestamp, $passRate, $gate);
        $this->createReport($timestamp, $passRate, $gate);
    }
    
    private function updateContext(string $timestamp, float $passRate, string $gate): void {
        $contextFile = 'context/project_context.json';
        if (file_exists($contextFile)) {
            $context = json_decode(file_get_contents($contextFile), true);
            if ($context) {
                $context['last_quick_litmus'] = [
                    'timestamp' => $timestamp,
                    'pass_rate' => $passRate . '%',
                    'gate' => $gate,
                    'resolved_base_url' => $this->baseUrl,
                    'base_url_source' => $this->baseUrlSource,
                    'connection_family' => $this->connectionFamily
                ];
                file_put_contents($contextFile, json_encode($context, JSON_PRETTY_PRINT));
            }
        }
    }
    
    private function createReport(string $timestamp, float $passRate, string $gate): void {
        $reportDir = 'reports/litmus';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        
        $reportFile = $reportDir . '/quick_litmus_summary.md';
        
        $report = "# Quick Litmus Test Summary\n\n";
        $report .= "**Timestamp:** {$timestamp}\n";
        $report .= "**Resolved Base URL:** {$this->baseUrl}\n";
        $report .= "**Base URL Source:** {$this->baseUrlSource} (flag/env/default)\n";
        $report .= "**Connection Family:** {$this->connectionFamily} (v4/v6)\n";
        $report .= "**Overall Gate:** {$gate}\n";
        $report .= "**Pass Rate:** {$passRate}%\n\n";
        
        $report .= "## Endpoint Results\n\n";
        $report .= "| Endpoint | Method | Expected | Actual | Status | Latency |\n";
        $report .= "|----------|--------|----------|--------|--------|----------|\n";
        
        foreach ($this->results as $result) {
            $status = ($result['status_code'] === $result['expected']) ? '✅ PASS' : '❌ FAIL';
            $report .= "| {$result['url']} | {$result['method']} | {$result['expected']} | {$result['status_code']} | {$status} | {$result['latency']}ms |\n";
        }
        
        $totalTests = count($this->results);
        $passed = count(array_filter($this->results, function($r) {
            return $r['status_code'] === $r['expected'];
        }));
        $failed = $totalTests - $passed;
        $avgLatency = $totalTests > 0 ? round($this->totalLatency / $totalTests, 2) : 0;
        
        $report .= "\n## Summary Statistics\n\n";
        $report .= "- **Total Tests:** {$totalTests}\n";
        $report .= "- **Passed:** {$passed}\n";
        $report .= "- **Failed:** {$failed}\n";
        $report .= "- **Average Latency:** {$avgLatency}ms\n\n";
        
        if ($gate === 'GREEN') {
            $report .= "## ✅ GREEN GATE STATUS\n\n";
            $report .= "All endpoint probes passed. System is ready for Phase-3 integration.\n";
            $report .= "Flag file created: `.phase3_litmus_quick_pass`\n";
        } else {
            $report .= "## ❌ RED GATE STATUS\n\n";
            $report .= "One or more endpoint probes failed. System is NOT ready for Phase-3 integration.\n";
            $report .= "Please review failed endpoints and resolve issues before proceeding.\n";
        }
        
        file_put_contents($reportFile, $report);
    }
}

// Run the test
if (php_sapi_name() === 'cli') {
    $test = new QuickLitmusTest();
    $test->runTests();
} else {
    // For web context, return JSON
    header('Content-Type: application/json');
    $test = new QuickLitmusTest();
    echo json_encode(['error' => 'This script must be run from CLI']);
    exit(1);
}