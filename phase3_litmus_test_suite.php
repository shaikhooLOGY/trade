<?php
/**
 * Phase-3 Litmus GREEN Guard - Comprehensive API Verification Suite
 * Tests 7 critical endpoints, OpenAPI parity, and rate limiting headers
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

class LitmusTestSuite {
    private $baseUrl = 'http://127.0.0.1:8082';
    private $results = [];
    private $timestamp = '2025-11-06_21-42';
    private $reportsDir = 'reports/litmus/2025-11-06_21-42';
    
    public function __construct() {
        echo "=== Phase-3 Litmus GREEN Guard Test Suite ===\n";
        echo "Timestamp: {$this->timestamp}\n";
        echo "Base URL: {$this->baseUrl}\n\n";
    }
    
    private function makeRequest($path, $method = 'GET', $data = null, $headers = []) {
        $url = $this->baseUrl . $path;
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        curl_close($ch);
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        return [
            'status' => $httpCode,
            'headers' => $headers,
            'body' => $body,
            'url' => $url
        ];
    }
    
    private function isJsonResponse($body) {
        $json = json_decode($body, true);
        return (json_last_error() === JSON_ERROR_NONE);
    }
    
    private function testHealthCheck() {
        echo "A. Testing GET /api/health.php → 200 JSON\n";
        $response = $this->makeRequest('/api/health.php');
        
        $passed = ($response['status'] === 200 && $this->isJsonResponse($response['body']));
        $data = json_decode($response['body'], true);
        $success = $data['success'] ?? false;
        
        $this->results['A'] = [
            'endpoint' => 'GET /api/health.php',
            'expected' => '200 JSON {success:true}',
            'status' => $response['status'],
            'passed' => $passed && $success,
            'response' => $response['body'],
            'headers' => $response['headers']
        ];
        
        echo "   Result: {$response['status']} " . ($passed && $success ? "✓ PASS" : "✗ FAIL") . "\n\n";
    }
    
    private function testProfileUnauthorized() {
        echo "B. Testing GET /api/profile/me.php (unauth) → 401 JSON\n";
        $response = $this->makeRequest('/api/profile/me.php');
        
        $isJson = $this->isJsonResponse($response['body']);
        $passed = ($response['status'] === 401 && $isJson);
        
        $this->results['B'] = [
            'endpoint' => 'GET /api/profile/me.php',
            'expected' => '401 JSON (no HTML)',
            'status' => $response['status'],
            'passed' => $passed,
            'response' => $response['body'],
            'headers' => $response['headers']
        ];
        
        echo "   Result: {$response['status']} " . ($passed ? "✓ PASS" : "✗ FAIL") . "\n\n";
    }
    
    private function testTradesCreateNoCsrf() {
        echo "C. Testing POST /api/trades/create.php w/o CSRF → 403 JSON\n";
        $response = $this->makeRequest('/api/trades/create.php', 'POST', ['test' => 'data']);
        
        $isJson = $this->isJsonResponse($response['body']);
        $passed = ($response['status'] === 403 && $isJson);
        
        $this->results['C'] = [
            'endpoint' => 'POST /api/trades/create.php',
            'expected' => '403 JSON (not 401)',
            'status' => $response['status'],
            'passed' => $passed,
            'response' => $response['body'],
            'headers' => $response['headers']
        ];
        
        echo "   Result: {$response['status']} " . ($passed ? "✓ PASS" : "✗ FAIL") . "\n\n";
    }
    
    private function testMtmEnrollNoCsrf() {
        echo "D. Testing POST /api/mtm/enroll.php w/o CSRF → 403 JSON\n";
        $response = $this->makeRequest('/api/mtm/enroll.php', 'POST', ['test' => 'data']);
        
        $isJson = $this->isJsonResponse($response['body']);
        $passed = ($response['status'] === 403 && $isJson);
        
        $this->results['D'] = [
            'endpoint' => 'POST /api/mtm/enroll.php',
            'expected' => '403 JSON',
            'status' => $response['status'],
            'passed' => $passed,
            'response' => $response['body'],
            'headers' => $response['headers']
        ];
        
        echo "   Result: {$response['status']} " . ($passed ? "✓ PASS" : "✗ FAIL") . "\n\n";
    }
    
    private function testAdminE2eStatusUnauthorized() {
        echo "E. Testing GET /api/admin/e2e_status.php (unauth) → 401 JSON\n";
        $response = $this->makeRequest('/api/admin/e2e_status.php');
        
        $isJson = $this->isJsonResponse($response['body']);
        $passed = ($response['status'] === 401 && $isJson);
        
        $this->results['E'] = [
            'endpoint' => 'GET /api/admin/e2e_status.php',
            'expected' => '401 JSON (no HTML)',
            'status' => $response['status'],
            'passed' => $passed,
            'response' => $response['body'],
            'headers' => $response['headers']
        ];
        
        echo "   Result: {$response['status']} " . ($passed ? "✓ PASS" : "✗ FAIL") . "\n\n";
    }
    
    private function testAgentLogUnauthorized() {
        echo "F. Testing POST /api/agent/log.php (unauth) → 401 JSON\n";
        $response = $this->makeRequest('/api/agent/log.php', 'POST', ['test' => 'data']);
        
        $isJson = $this->isJsonResponse($response['body']);
        $passed = ($response['status'] === 401 && $isJson);
        
        $this->results['F'] = [
            'endpoint' => 'POST /api/agent/log.php',
            'expected' => '401 JSON',
            'status' => $response['status'],
            'passed' => $passed,
            'response' => $response['body'],
            'headers' => $response['headers']
        ];
        
        echo "   Result: {$response['status']} " . ($passed ? "✓ PASS" : "✗ FAIL") . "\n\n";
    }
    
    private function testAdminAgentLogsUnauthorized() {
        echo "G. Testing GET /api/admin/agent/logs.php (unauth) → 401 JSON\n";
        $response = $this->makeRequest('/api/admin/agent/logs.php');
        
        $isJson = $this->isJsonResponse($response['body']);
        $passed = ($response['status'] === 401 && $isJson);
        
        $this->results['G'] = [
            'endpoint' => 'GET /api/admin/agent/logs.php',
            'expected' => '401 JSON',
            'status' => $response['status'],
            'passed' => $passed,
            'response' => $response['body'],
            'headers' => $response['headers']
        ];
        
        echo "   Result: {$response['status']} " . ($passed ? "✓ PASS" : "✗ FAIL") . "\n\n";
    }
    
    private function testRateLimitHeaders() {
        echo "=== Rate Limit Header Spot Check ===\n";
        echo "Testing /api/agent/log.php 3x quickly for X-RateLimit headers\n";
        
        $headers = [];
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->makeRequest('/api/agent/log.php', 'POST', ['test' => 'data']);
            $headers[] = $response['headers'];
            echo "   Request {$i}: {$response['status']}\n";
            usleep(100000); // 100ms delay
        }
        
        // Check for rate limit headers
        $rateLimitHeaders = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];
        $hasRateLimitHeaders = false;
        
        foreach ($headers as $h) {
            foreach ($rateLimitHeaders as $header) {
                if (stripos($h, $header) !== false) {
                    $hasRateLimitHeaders = true;
                    break 2;
                }
            }
        }
        
        $this->results['rate_limit_headers'] = [
            'has_rate_limit_headers' => $hasRateLimitHeaders,
            'headers_sample' => $headers[0] ?? '',
            'passed' => $hasRateLimitHeaders
        ];
        
        echo "   Rate Limit Headers: " . ($hasRateLimitHeaders ? "✓ PRESENT" : "✗ MISSING") . "\n\n";
    }
    
    public function runAllTests() {
        // Run the 7 main tests
        $this->testHealthCheck();
        $this->testProfileUnauthorized();
        $this->testTradesCreateNoCsrf();
        $this->testMtmEnrollNoCsrf();
        $this->testAdminE2eStatusUnauthorized();
        $this->testAgentLogUnauthorized();
        $this->testAdminAgentLogsUnauthorized();
        
        // Run rate limit header check
        $this->testRateLimitHeaders();
        
        // Generate reports
        $this->generateReports();
        
        // Determine final result
        $this->determineFinalStatus();
    }
    
    private function generateReports() {
        // Generate API 7 probe results
        $api7Results = [];
        $passedCount = 0;
        
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $key) {
            if (isset($this->results[$key])) {
                $api7Results[$key] = $this->results[$key];
                if ($this->results[$key]['passed']) {
                    $passedCount++;
                }
            }
        }
        
        file_put_contents("{$this->reportsDir}/api_7_probe.json", json_encode($api7Results, JSON_PRETTY_PRINT));
        
        // Generate summary.md
        $this->generateSummary($passedCount);
        
        // Generate headers sample
        if (isset($this->results['rate_limit_headers'])) {
            file_put_contents("{$this->reportsDir}/headers_sample.txt", $this->results['rate_limit_headers']['headers_sample']);
        }
        
        // Check OpenAPI parity (placeholder - will be implemented)
        $this->checkOpenApiParity();
    }
    
    private function generateSummary($passedCount) {
        $total = 7;
        $passRate = round(($passedCount / $total) * 100, 1);
        
        $summary = "# Phase-3 Litmus GREEN Guard - Summary Report\n\n";
        $summary .= "**Timestamp:** {$this->timestamp}\n";
        $summary .= "**Base URL:** {$this->baseUrl}\n\n";
        
        $summary .= "## Test Results (7/7)\n\n";
        $summary .= "| Check | Endpoint | Expected | Result | Status |\n";
        $summary .= "|-------|----------|----------|--------|--------|\n";
        
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $key) {
            if (isset($this->results[$key])) {
                $result = $this->results[$key];
                $status = $result['passed'] ? "✅ PASS" : "❌ FAIL";
                $summary .= "| {$key} | {$result['endpoint']} | {$result['expected']} | {$result['status']} | {$status} |\n";
            }
        }
        
        $summary .= "\n## Rate Limit Headers\n\n";
        if (isset($this->results['rate_limit_headers'])) {
            $rl = $this->results['rate_limit_headers'];
            $status = $rl['passed'] ? "✅ PRESENT" : "❌ MISSING";
            $summary .= "**X-RateLimit Headers:** {$status}\n";
        }
        
        $summary .= "\n## Final Result\n\n";
        $summary .= "**Pass Rate:** {$passRate}% ({$passedCount}/{$total})\n";
        
        if ($passedCount === $total) {
            $summary .= "**Status:** 🟢 GREEN (All 7 checks passed)\n";
        } else {
            $summary .= "**Status:** 🔴 RED (" . ($total - $passedCount) . " checks failed)\n";
            
            $summary .= "\n### Failed Checks\n\n";
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $key) {
                if (isset($this->results[$key]) && !$this->results[$key]['passed']) {
                    $result = $this->results[$key];
                    $summary .= "- **Check {$key}:** {$result['endpoint']} - Expected {$result['expected']}, got {$result['status']}\n";
                }
            }
        }
        
        file_put_contents("{$this->reportsDir}/summary.md", $summary);
    }
    
    private function checkOpenApiParity() {
        $openapiFile = 'docs/openapi.yaml';
        $parity = [];
        
        if (!file_exists($openapiFile)) {
            $parity['error'] = 'OpenAPI file not found';
            file_put_contents("{$this->reportsDir}/openapi_parity.json", json_encode($parity, JSON_PRETTY_PRINT));
            return;
        }
        
        // Check specific endpoints in the OpenAPI file
        $openapiContent = file_get_contents($openapiFile);
        
        $endpoints = [
            '/api/health.php' => ['get' => [200]],
            '/api/profile/me.php' => ['get' => [401]],
            '/api/trades/create.php' => ['post' => [403]],
            '/api/mtm/enroll.php' => ['post' => [403]],
            '/api/admin/e2e_status.php' => ['get' => [401]],
            '/api/agent/log.php' => ['post' => [401]],
            '/api/admin/agent/logs.php' => ['get' => [401]]
        ];
        
        foreach ($endpoints as $path => $methods) {
            foreach ($methods as $method => $expectedCodes) {
                $pathKey = $path;
                $methodKey = strtolower($method);
                
                // Simple regex check for endpoint and response codes
                $pattern = '/'.preg_quote($pathKey, '/').'.*?'.preg_quote($methodKey, '/').'.*?responses.*?('.implode('|', $expectedCodes).')/i';
                $match = preg_match($pattern, $openapiContent);
                
                $parity[$pathKey][$methodKey] = [
                    'expected' => $expectedCodes,
                    'actual' => $match ? $expectedCodes : [],
                    'match' => $match > 0,
                    'status' => $match > 0 ? 'PASS' : 'FAIL'
                ];
            }
        }
        
        // Overall parity status
        $allPassed = true;
        foreach ($parity as $endpoint) {
            foreach ($endpoint as $check) {
                if (!$check['match']) {
                    $allPassed = false;
                    break 2;
                }
            }
        }
        
        $parity['overall_status'] = $allPassed ? 'PASS' : 'FAIL';
        
        file_put_contents("{$this->reportsDir}/openapi_parity.json", json_encode($parity, JSON_PRETTY_PRINT));
    }
    
    private function determineFinalStatus() {
        $passedCount = 0;
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $key) {
            if (isset($this->results[$key]) && $this->results[$key]['passed']) {
                $passedCount++;
            }
        }
        
        echo "=== FINAL RESULTS ===\n";
        echo "Passed: {$passedCount}/7 checks\n";
        echo "Rate Limit Headers: " . (isset($this->results['rate_limit_headers']) && $this->results['rate_limit_headers']['passed'] ? "YES" : "NO") . "\n";
        
        if ($passedCount === 7) {
            // Write green flag
            file_put_contents(".litmus_green_{$this->timestamp}", "Phase-3 Litmus GREEN Guard passed at {$this->timestamp}");
            echo "Status: 🟢 GREEN - All tests passed!\n";
        } else {
            echo "Status: 🔴 RED - Some tests failed\n";
        }
        
        // Update context
        $this->updateContext($passedCount);
    }
    
    private function updateContext($passedCount) {
        $contextFile = 'context/project_context.json';
        $context = [];
        
        if (file_exists($contextFile)) {
            $context = json_decode(file_get_contents($contextFile), true) ?: [];
        }
        
        $passRate = round(($passedCount / 7) * 100, 1);
        $gate = ($passedCount === 7) ? 'GREEN' : 'RED';
        
        $context['phase'] = 'P3-litmus';
        $context['gate'] = $gate;
        $context['last_litmus_ts'] = $this->timestamp;
        $context['pass_rate'] = $passRate . '%';
        
        file_put_contents($contextFile, json_encode($context, JSON_PRETTY_PRINT));
        echo "Context updated: {$gate} ({$passRate}%)\n";
    }
}
?>

// Run the test suite
$suite = new LitmusTestSuite();
$suite->runAllTests();
?>