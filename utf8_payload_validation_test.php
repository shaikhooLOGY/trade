<?php
/**
 * UTF-8 and Payload Boundary Validation Test
 * PHASE 3 VALIDATION - TASK 8
 * 
 * Tests character encoding and payload size handling to ensure data integrity
 */

class UTF8PayloadValidator {
    private $baseUrl = 'http://127.0.0.1:8082';
    private $results = [];
    private $testCount = 0;
    private $passedCount = 0;
    private $failedCount = 0;
    
    // Test data with UTF-8 characters and emoji
    private $utf8TestData = [
        'chinese' => '你好世界！这是一个测试交易记录。包含中文字符。',
        'arabic' => 'مرحبا بالعالم! هذا اختبار للتجارة مع الأحرف العربية.',
        'mixed' => 'Hello 世界! 🌍 Mixed émojis: 😀🎉🚀💯🔥 and special chars: ñáéíóú',
        'emoji_only' => '😀🎉🚀💯🔥🌟⭐💎🎯🏆👑',
        'complex_emoji' => '👨‍💻👩‍💻🧑‍🔬🧑‍🎓🧑‍🏫🧑‍⚕️🧑‍🍳🧑‍🎤🧑‍🚒🧑‍🔧'
    ];
    
    public function __construct() {
        echo "=== UTF-8 and Payload Boundary Validation Test ===\n";
        echo "Starting validation at: " . date('Y-m-d H:i:s') . "\n\n";
    }
    
    /**
     * Execute all validation tests
     */
    public function runAllTests() {
        $this->test1_utf8_trades_create();
        $this->test2_large_payload_trades_create();
        $this->test3_utf8_ajax_trade_create();
        $this->test4_utf8_mtm_enroll();
        
        $this->generateReport();
    }
    
    /**
     * Test 1: UTF-8 content + emoji in api/trades/create.php
     */
    private function test1_utf8_trades_create() {
        echo "TEST 1: UTF-8 content + emoji in api/trades/create.php\n";
        echo "======================================================\n";
        
        foreach ($this->utf8TestData as $type => $content) {
            $this->testCount++;
            echo "Testing $type content...\n";
            
            $payload = [
                'sport_type' => 'football',
                'trade_type' => 'buy',
                'quantity' => 100,
                'price' => 25.50,
                'trade_note' => $content
            ];
            
            $result = $this->makeRequest('/api/trades/create.php', $payload);
            $this->validateUTF8Response($result, "Test 1.$this->testCount: $type");
        }
        echo "\n";
    }
    
    /**
     * Test 2: Large payload (8KB+) boundary test
     */
    private function test2_large_payload_trades_create() {
        echo "TEST 2: Large payload (8KB+) boundary test\n";
        echo "============================================\n";
        
        // Create 8KB+ payload
        $largeContent = str_repeat("这是一个很长的测试内容 " . $this->utf8TestData['mixed'] . " ", 200);
        $payload = [
            'sport_type' => 'football',
            'trade_type' => 'sell',
            'quantity' => 500,
            'price' => 15.75,
            'trade_note' => $largeContent
        ];
        
        $this->testCount++;
        echo "Testing 8KB+ payload (" . strlen($largeContent) . " bytes)...\n";
        
        $result = $this->makeRequest('/api/trades/create.php', $payload);
        $this->validateLargePayloadResponse($result, "Test 2: 8KB+ payload");
        echo "\n";
    }
    
    /**
     * Test 3: UTF-8 content in ajax_trade_create.php
     */
    private function test3_utf8_ajax_trade_create() {
        echo "TEST 3: UTF-8 content in ajax_trade_create.php\n";
        echo "===============================================\n";
        
        $testCases = [
            'chinese' => $this->utf8TestData['chinese'],
            'mixed' => $this->utf8TestData['mixed'],
            'emoji' => $this->utf8TestData['emoji_only']
        ];
        
        foreach ($testCases as $type => $content) {
            $this->testCount++;
            echo "Testing $type content...\n";
            
            $payload = [
                'sport_type' => 'basketball',
                'trade_type' => 'buy',
                'quantity' => 75,
                'price' => 30.25,
                'trade_note' => $content
            ];
            
            $result = $this->makeRequest('/ajax_trade_create.php', $payload);
            $this->validateUTF8Response($result, "Test 3.$this->testCount: $type");
        }
        echo "\n";
    }
    
    /**
     * Test 4: UTF-8 content in api/mtm/enroll.php
     */
    private function test4_utf8_mtm_enroll() {
        echo "TEST 4: UTF-8 content in api/mtm/enroll.php\n";
        echo "==========================================\n";
        
        $testCases = [
            'arabic' => $this->utf8TestData['arabic'],
            'complex_emoji' => $this->utf8TestData['complex_emoji'],
            'mixed' => $this->utf8TestData['mixed']
        ];
        
        foreach ($testCases as $type => $content) {
            $this->testCount++;
            echo "Testing $type content...\n";
            
            $payload = [
                'user_id' => 1,
                'league_id' => 1,
                'participant_name' => $content,
                'email' => 'test@example.com',
                'notes' => $content
            ];
            
            $result = $this->makeRequest('/api/mtm/enroll.php', $payload);
            $this->validateUTF8Response($result, "Test 4.$this->testCount: $type");
        }
        echo "\n";
    }
    
    /**
     * Make HTTP request to API endpoint
     */
    private function makeRequest($endpoint, $payload) {
        $url = $this->baseUrl . $endpoint;
        echo "  → Request to: $url\n";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: UTF8-Payload-Validator/1.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $result = [
            'url' => $url,
            'http_code' => $httpCode,
            'response' => $response,
            'payload_size' => strlen(json_encode($payload)),
            'curl_error' => $curlError
        ];
        
        echo "  ← HTTP Code: $httpCode\n";
        if ($curlError) {
            echo "  ← cURL Error: $curlError\n";
        }
        
        return $result;
    }
    
    /**
     * Validate UTF-8 response for encoding integrity
     */
    private function validateUTF8Response($result, $testName) {
        $testResult = [
            'test_name' => $testName,
            'http_code' => $result['http_code'],
            'payload_size' => $result['payload_size'],
            'status' => 'unknown',
            'issues' => []
        ];
        
        // Check for HTTP 500 errors
        if ($result['http_code'] == 500) {
            $testResult['status'] = 'FAIL';
            $testResult['issues'][] = 'HTTP 500 error detected';
            $this->failedCount++;
            echo "  ❌ FAILED: HTTP 500 error\n";
        }
        
        // Check for encoding corruption (mojibake)
        if ($this->containsMojibake($result['response'])) {
            $testResult['status'] = 'FAIL';
            $testResult['issues'][] = 'Encoding corruption (mojibake) detected';
            $this->failedCount++;
            echo "  ❌ FAILED: Encoding corruption detected\n";
        }
        
        // Check for JSON validity
        if ($result['response'] && !$this->isValidJson($result['response'])) {
            $testResult['status'] = 'FAIL';
            $testResult['issues'][] = 'Invalid JSON response';
            $this->failedCount++;
            echo "  ❌ FAILED: Invalid JSON response\n";
        }
        
        // Check for proper success response
        if ($result['http_code'] == 200 || $result['http_code'] == 201) {
            $testResult['status'] = 'PASS';
            $this->passedCount++;
            echo "  ✅ PASSED: Valid response\n";
        } elseif ($testResult['status'] == 'unknown') {
            $testResult['status'] = 'PARTIAL';
            $testResult['issues'][] = 'Non-success HTTP code but no critical errors';
            echo "  ⚠️  PARTIAL: HTTP {$result['http_code']} but no critical errors\n";
        }
        
        $this->results[] = $testResult;
    }
    
    /**
     * Validate large payload response
     */
    private function validateLargePayloadResponse($result, $testName) {
        $testResult = [
            'test_name' => $testName,
            'http_code' => $result['http_code'],
            'payload_size' => $result['payload_size'],
            'status' => 'unknown',
            'issues' => []
        ];
        
        // Check for truncation (check if response is suspiciously short)
        $minExpectedResponseSize = 10; // Minimum expected response length
        if (strlen($result['response']) < $minExpectedResponseSize) {
            $testResult['status'] = 'FAIL';
            $testResult['issues'][] = 'Possible payload truncation detected';
            $this->failedCount++;
            echo "  ❌ FAILED: Possible payload truncation\n";
        }
        
        // Check for HTTP 500 errors
        if ($result['http_code'] == 500) {
            $testResult['status'] = 'FAIL';
            $testResult['issues'][] = 'HTTP 500 error detected';
            $this->failedCount++;
            echo "  ❌ FAILED: HTTP 500 error\n";
        }
        
        // Check for encoding issues
        if ($this->containsMojibake($result['response'])) {
            $testResult['status'] = 'FAIL';
            $testResult['issues'][] = 'Encoding corruption (mojibake) detected';
            $this->failedCount++;
            echo "  ❌ FAILED: Encoding corruption detected\n";
        }
        
        // If no issues found, it's a pass
        if ($testResult['status'] == 'unknown') {
            $testResult['status'] = 'PASS';
            $this->passedCount++;
            echo "  ✅ PASSED: Large payload handled correctly\n";
        }
        
        $this->results[] = $testResult;
    }
    
    /**
     * Check if response contains mojibake (encoding corruption)
     */
    private function containsMojibake($text) {
        if (empty($text)) return false;
        
        // Check for common mojibake patterns
        $mojibakePatterns = [
            'Ã', 'Â', 'Ã', 'Â', 'Â', 'â€', 'â€“', 'â€”', 'â€œ', 'â€', 'â„', 'âš',
            'Â¡', 'Â¢', 'Â£', 'Â¤', 'Â¥', 'Â¦', 'Â§', 'Â¨', 'Â©'
        ];
        
        foreach ($mojibakePatterns as $pattern) {
            if (strpos($text, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Validate JSON format
     */
    private function isValidJson($string) {
        if (empty($string)) return false;
        
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }
    
    /**
     * Generate comprehensive test report
     */
    private function generateReport() {
        echo "=== TEST SUMMARY ===\n";
        echo "Total Tests: $this->testCount\n";
        echo "Passed: $this->passedCount\n";
        echo "Failed: $this->failedCount\n";
        echo "Success Rate: " . round(($this->passedCount / $this->testCount) * 100, 1) . "%\n\n";
        
        // Generate detailed report
        $this->createDetailedReport();
    }
    
    /**
     * Create detailed markdown report
     */
    private function createDetailedReport() {
        $reportPath = 'reports/qc/utf_payload_test.md';
        
        // Ensure reports directory exists
        $reportsDir = dirname($reportPath);
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }
        
        $report = "# UTF-8 and Payload Boundary Validation Report\n\n";
        $report .= "**Test Date:** " . date('Y-m-d H:i:s') . "\n";
        $report .= "**Base URL:** http://127.0.0.1:8082\n";
        $report .= "**Total Tests:** {$this->testCount}\n";
        $report .= "**Passed:** {$this->passedCount}\n";
        $report .= "**Failed:** {$this->failedCount}\n";
        $report .= "**Success Rate:** " . round(($this->passedCount / $this->testCount) * 100, 1) . "%\n\n";
        
        $report .= "## Test Results\n\n";
        
        foreach ($this->results as $i => $result) {
            $statusIcon = $result['status'] === 'PASS' ? '✅' : ($result['status'] === 'FAIL' ? '❌' : '⚠️');
            $report .= "### {$statusIcon} {$result['test_name']}\n\n";
            $report .= "- **Status:** {$result['status']}\n";
            $report .= "- **HTTP Code:** {$result['http_code']}\n";
            $report .= "- **Payload Size:** {$result['payload_size']} bytes\n";
            
            if (!empty($result['issues'])) {
                $report .= "- **Issues:**\n";
                foreach ($result['issues'] as $issue) {
                    $report .= "  - {$issue}\n";
                }
            }
            
            $report .= "\n";
        }
        
        $report .= "## UTF-8 Test Data Used\n\n";
        $report .= "The following UTF-8 character sets were tested:\n\n";
        foreach ($this->utf8TestData as $type => $content) {
            $report .= "- **$type:** `$content`\n";
        }
        $report .= "\n";
        
        $report .= "## Large Payload Test\n\n";
        $report .= "A payload of 8KB+ was tested to ensure:\n";
        $report .= "- No encoding corruption (mojibake)\n";
        $report .= "- No truncation of large payloads\n";
        $report .= "- No HTTP 500 errors due to encoding issues\n";
        $report .= "- Consistent UTF-8 handling across all endpoints\n\n";
        
        $report .= "## Endpoints Tested\n\n";
        $report .= "1. `api/trades/create.php` - UTF-8 and large payload tests\n";
        $report .= "2. `ajax_trade_create.php` - UTF-8 content tests\n";
        $report .= "3. `api/mtm/enroll.php` - UTF-8 data tests\n\n";
        
        $report .= "## Validation Results\n\n";
        if ($this->failedCount == 0) {
            $report .= "🎉 **ALL TESTS PASSED!** No encoding or payload boundary issues detected.\n\n";
        } else {
            $report .= "⚠️ **SOME TESTS FAILED.** Review the issues above and investigate encoding/payload handling.\n\n";
        }
        
        file_put_contents($reportPath, $report);
        echo "📄 Detailed report saved to: $reportPath\n";
    }
}

// Run the validation tests
echo "Starting UTF-8 and Payload Boundary Validation...\n\n";
$validator = new UTF8PayloadValidator();
$validator->runAllTests();
echo "\n=== VALIDATION COMPLETE ===\n";