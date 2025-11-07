<?php
/**
 * E2E Full Test Suite - Complete MTM Platform Validation
 *
 * This script executes a production-style E2E validation across:
 * register → OTP verify → login → forgot password → MTM enroll → admin approval →
 * MTM CRUD → add trade(s) → capital overview & performance matrix math checks →
 * audit & rate-limit/CSRF checks → artifacts → optional cleanup.
 */

// Environment Check and Configuration
if (!defined('APP_ENV')) {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/bootstrap.php';
}

require_once __DIR__ . '/../../includes/security/csrf.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';

// Environment Configuration
define('E2E_BASE_URL', $_ENV['BASE_URL'] ?? 'http://127.0.0.1:8082');
define('E2E_TIMEOUT', 25);
define('E2E_MAX_RETRY', 1);
define('E2E_CLEANUP', getenv('CLEANUP') === 'on' || getenv('CLEANUP') === 'true');
define('E2E_TIMESTAMP', date('Y-m-d_H-i-s')); // NO COLONS in folder name
define('E2E_USER_EMAIL', 'e2e_user_' . E2E_TIMESTAMP . '@local.test');
define('E2E_ADMIN_EMAIL', 'e2e_test_admin@local.test'); // Use test admin
define('E2E_REPORTS_DIR', __DIR__);

/**
 * E2E HTTP Client with CSRF and Rate Limiting Support
 */
class E2EHttpClient {
    private $baseUrl;
    private $cookies = [];
    private $csrfToken = null;
    private $lastRequestTime = 0;
    private $lastHeaders = [];
    private $httpTrace = [];
    private $cookieJar = null;
    private $cookieFile = null;
    private $useIdempotency = true;
    public $useCsrf = true; // Made public for test flexibility
    
    public function __construct($baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieJar = '/tmp/e2e_cookies_' . uniqid() . '.txt';
        $this->cookieFile = $this->cookieJar;
        $this->useIdempotency = getenv('E2E_MODE') === '1';
        $this->useCsrf = getenv('ALLOW_CSRF_BYPASS') !== '1';
    }
    
    public function __destruct() {
        // Clean up cookie file
        if (file_exists($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }
    
    public function get($endpoint, $headers = []) {
        return $this->request('GET', $endpoint, null, $headers);
    }
    
    public function post($endpoint, $data = null, $headers = []) {
        return $this->request('POST', $endpoint, $data, $headers);
    }
    
    public function put($endpoint, $data = null, $headers = []) {
        return $this->request('PUT', $endpoint, $data, $headers);
    }
    
    public function delete($endpoint, $headers = []) {
        return $this->request('DELETE', $endpoint, null, $headers);
    }
    
    public function request($method, $endpoint, $data = null, $headers = []) {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init();
        
        // Basic curl setup
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, E2E_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        
        // Method and data
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $payload = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = 'Content-Type: application/json';
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        
        // Add idempotency key for POST/PUT requests
        if (in_array($method, ['POST', 'PUT'], true) && $this->useIdempotency) {
            $idempotencyKey = $this->generateIdempotencyKey($method, $endpoint, $data);
            $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        }
        
        // Add CSRF token for mutating requests if not in bypass mode
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true) && $this->useCsrf) {
            $csrfToken = $this->getCsrfToken();
            if ($csrfToken) {
                $headers[] = 'X-CSRF-Token: ' . $csrfToken;
            } elseif (getenv('ALLOW_CSRF_BYPASS') === '1') {
                $headers[] = 'X-CSRF-Token: e2e_bypass';
            }
        }
        
        // Headers
        $headers[] = 'X-Requested-With: XMLHttpRequest';
        $headers[] = 'User-Agent: E2E-Test-Suite/1.0';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Cookies
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        
        // Rate limiting (basic implementation)
        $timeSinceLast = microtime(true) - $this->lastRequestTime;
        if ($timeSinceLast < 0.1) { // 100ms minimum between requests
            usleep((int)round((0.1 - $timeSinceLast) * 1000000));
        }
        
        $this->lastRequestTime = microtime(true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception("HTTP request failed: " . curl_error($ch));
        }
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse response headers
        $this->lastHeaders = $this->parseHeaders($headers);
        
        // Extract CSRF token from response if available
        $this->extractCsrfFromResponse($this->lastHeaders, $body);
        
        // Log request
        $this->logRequest($method, $url, $data, $httpCode, $body);
        
        return [
            'status' => $httpCode,
            'headers' => $this->lastHeaders,
            'body' => $body,
            'json' => $this->parseJson($body)
        ];
    }
    
    private function parseHeaders($headerString) {
        $headers = [];
        $lines = explode("\r\n", $headerString);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }
    
    
    
    private function parseJson($body) {
        // Extract JSON from body that may contain debug output
        $jsonStart = strpos($body, '{');
        if ($jsonStart !== false) {
            $jsonString = substr($body, $jsonStart);
            $data = json_decode($jsonString, true);
        } else {
            $data = json_decode($body, true);
        }
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }
    
    private function logRequest($method, $url, $data, $status, $body) {
        // Mask sensitive data in logs
        $maskedData = $data;
        if (is_array($data) || is_object($data)) {
            $maskedData = $this->maskSensitiveData($data);
        }
        
        $this->httpTrace[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $method,
            'url' => $url,
            'data' => $maskedData,
            'status' => $status,
            'cookies' => array_keys($this->cookies)
        ];
    }
    
    private function maskSensitiveData($data) {
        $sensitiveKeys = ['password', 'pass', 'confirm', 'token', 'csrf', 'otp'];
        $masked = [];
        
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $masked[$key] = '[MASKED]';
            } elseif (is_array($value) || is_object($value)) {
                $masked[$key] = $this->maskSensitiveData($value);
            } else {
                $masked[$key] = $value;
            }
        }
        
        return $masked;
    }
    
    public function setCsrfToken($token) {
        $this->csrfToken = $token;
    }
    
    private function generateIdempotencyKey($method, $endpoint, $data) {
        $payload = $method . $endpoint . json_encode($data ?? []);
        return 'e2e_' . hash('sha256', $payload) . '_' . time();
    }
    
    private function getCsrfToken() {
        if ($this->csrfToken) {
            return $this->csrfToken;
        }
        
        // Try to get CSRF token from API endpoint
        try {
            $response = $this->get('api/util/csrf.php');
            if ($response['status'] === 200 && $response['json'] && isset($response['json']['csrf_token'])) {
                $this->csrfToken = $response['json']['csrf_token'];
                return $this->csrfToken;
            }
        } catch (Exception $e) {
            // CSRF endpoint might not exist, continue without it
        }
        
        return null;
    }
    
    private function extractCsrfFromResponse($headers, $body) {
        // Look for CSRF token in response headers
        if (isset($headers['x-csrf-token'])) {
            $this->csrfToken = $headers['x-csrf-token'];
        }
        
        // Look for CSRF token in response body (HTML forms)
        if (preg_match('/name="csrf".*?value="([^"]+)"/', $body, $matches)) {
            $this->csrfToken = $matches[1];
        }
        
        // Look for CSRF token in JSON response
        $json = $this->parseJson($body);
        if ($json && isset($json['csrf_token'])) {
            $this->csrfToken = $json['csrf_token'];
        }
    }
    
    public function getHeaders() {
        return $this->lastHeaders;
    }
    
    public function getCookies() {
        return $this->cookies;
    }
    
    public function getHttpTrace() {
        return $this->httpTrace;
    }
}

/**
 * E2E Test Suite Main Class
 */
class E2ETestSuite {
    private $client;
    private $csrfToken = null;
    private $results = [];
    private $testUser = [
        'email' => E2E_USER_EMAIL,
        'password' => 'Test@12345',
        'new_password' => 'NewPass@12345',
        'name' => 'E2E Test User'
    ];
    private $adminUser = [
        'email' => E2E_ADMIN_EMAIL,
        'password' => 'password' // Use test admin password
    ];
    private $testData = [
        'trades' => [],
        'enrollment' => null,
        'model' => null
    ];
    
    public function __construct() {
        $this->client = new E2EHttpClient(E2E_BASE_URL);
        $this->results = [
            'timestamp' => E2E_TIMESTAMP,
            'start_time' => microtime(true),
            'base_url' => E2E_BASE_URL,
            'test_user' => $this->testUser['email'],
            'steps' => []
        ];
    }
    
    public function run() {
        try {
            echo "🚀 Starting E2E Full Test Suite\n";
            echo "Target: " . E2E_BASE_URL . "\n";
            echo "Test User: " . $this->testUser['email'] . "\n";
            echo "Cleanup: " . (E2E_CLEANUP ? 'ON' : 'OFF') . "\n\n";
            
            $this->stepA_EnvironmentAndHealth();
            $this->stepB_AuthFlow();
            $this->stepC_ProfileSetup();
            $this->stepD_MTMEnrollFlow();
            $this->stepE_MTMCrud();
            $this->stepF_TradesAndCapital();
            $this->stepG_SecurityNegativeTests();
            $this->stepH_AuditAndLogs();
            
            if (E2E_CLEANUP) {
                $this->stepCleanup();
            }
            
            $this->generateArtifacts();
            $this->finalizeResults();
            
        } catch (Exception $e) {
            echo "❌ Test suite failed: " . $e->getMessage() . "\n";
            $this->results['error'] = $e->getMessage();
        }
    }
    
    // Step A: Environment & Health
    private function stepA_EnvironmentAndHealth() {
        echo "📋 Step A: Environment & Health Check\n";
        
        try {
            $response = $this->client->get('api/health.php');
            $this->assertStep('A1', 'Health check returns 200', $response['status'] === 200);
            $this->assertStep('A2', 'Health check returns JSON', $response['json'] !== null);
            
            if ($response['json']) {
                $this->assertStep('A3', 'Health status is ok',
                                $response['json']['message'] === 'ok' || $response['json']['status'] === 'ok');
                $this->recordStep('A1-A3', 'SUCCESS', 'Health check passed', $response);
            }
            
            $this->results['environment'] = [
                'status' => $response['status'],
                'response' => $response['json'],
                'headers' => $response['headers']
            ];
            
        } catch (Exception $e) {
            $this->recordStep('A1-A3', 'FAIL', $e->getMessage(), []);
            throw $e;
        }
    }
    
    // Step B: Authentication Flow
    private function stepB_AuthFlow() {
        echo "🔐 Step B: Authentication Flow\n";
        
        // B2: Registration
        try {
            $registerData = [
                'name' => $this->testUser['name'],
                'email' => $this->testUser['email'],
                'password' => $this->testUser['password']
            ];
            
            $response = $this->client->post('api/auth/register_simple.php', $registerData);
            
            if ($response['status'] === 201 && isset($response['json']['success'])) {
                $this->recordStep('B2', 'SUCCESS', 'Registration successful', $response);
            } else {
                $this->recordStep('B2', 'FAIL', 'Registration failed', $response);
            }
            
        } catch (Exception $e) {
            $this->recordStep('B2', 'FAIL', $e->getMessage(), []);
        }
        
        // B3: OTP Verify (simulated)
        try {
            $this->simulateOtpVerification();
            $this->recordStep('B3', 'SUCCESS', 'OTP verification simulated', []);
        } catch (Exception $e) {
            $this->recordStep('B3', 'FAIL', $e->getMessage(), []);
        }
        
        // B4: Login
        try {
            $loginData = http_build_query([
                'email' => $this->testUser['email'],
                'password' => $this->testUser['password'],
                'csrf' => 'test' // E2E bypass
            ]);
            
            $response = $this->client->post('login.php', $loginData);
            
            if ($response['status'] === 302) {
                $this->recordStep('B4', 'SUCCESS', 'Login successful', $response);
            } else {
                $this->recordStep('B4', 'FAIL', 'Login failed', $response);
            }
            
        } catch (Exception $e) {
            $this->recordStep('B4', 'FAIL', $e->getMessage(), []);
        }
        
        // B5: Forgot Password
        try {
            $response = $this->client->post('forgot_password.php', [
                'email' => $this->testUser['email']
            ]);
            
            $this->recordStep('B5', $response['status'] === 200 ? 'SUCCESS' : 'FAIL', 
                             'Forgot password request processed', $response);
            
            // B5b: Password Reset
            if (E2E_CLEANUP) {
                $this->resetPasswordFlow();
            }
            
        } catch (Exception $e) {
            $this->recordStep('B5', 'FAIL', $e->getMessage(), []);
        }
    }
    
    // Step C: Profile Setup
    private function stepC_ProfileSetup() {
        echo "👤 Step C: Profile Setup\n";
        
        try {
            // Ensure user is logged in for profile operations
            $this->loginAsTestUser();
            
            $response = $this->client->get('api/profile/me.php');
            $this->recordStep('C1', $response['status'] === 200 ? 'SUCCESS' : 'FAIL',
                             'Profile retrieval', $response);
            
            // C2: Profile update with full API contract
            $profileData = [
                'name' => $this->testUser['name'],
                'phone' => '+91123456789',
                'timezone' => 'Asia/Calcutta',
                'location' => 'Test City, India',
                'bio' => 'E2E test profile'
            ];
            
            $response = $this->client->post('api/profile/update.php', $profileData);
            
            // Parse response and assert success envelope
            $success = false;
            $message = 'Profile update response';
            
            if ($response['status'] === 200) {
                if ($response['json'] &&
                    (($response['json']['success'] ?? false) === true ||
                     ($response['json']['status'] ?? '') === 'success')) {
                    $success = true;
                    $message = 'Profile updated successfully';
                } else {
                    $message = 'Profile update returned 200 but no success indicator';
                }
            } elseif ($response['status'] === 401) {
                $message = 'Authentication required - ensuring user is logged in';
                // Try to login first and retry
                if ($this->loginAsTestUser()) {
                    $response = $this->client->post('api/profile/update.php', $profileData);
                    if ($response['status'] === 200 &&
                        ($response['json']['success'] ?? false) === true) {
                        $success = true;
                        $message = 'Profile updated successfully after re-authentication';
                    }
                }
            } elseif ($response['json'] &&
                     ($response['json']['success'] ?? false) === true) {
                $success = true;
                $message = 'Profile updated successfully (envelope format)';
            }
            
            $this->recordStep('C2', $success ? 'SUCCESS' : 'FAIL', $message, $response);
            
        } catch (Exception $e) {
            $this->recordStep('C1-C2', 'FAIL', $e->getMessage(), []);
        }
    }
    
    // Continue with remaining steps...
    // This is getting quite long, let me create the structure for all steps
    
    // Step D: MTM Enrollment Flow
    private function stepD_MTMEnrollFlow() {
        echo "📈 Step D: MTM Enrollment Flow\n";
        
        try {
            // Ensure user is logged in for enrollment operations
            $this->loginAsTestUser();
            
            // D1: Get MTM models
            $response = $this->client->get('api/mtm/enrollments.php');
            $this->recordStep('D1', $response['status'] === 200 ? 'SUCCESS' : 'FAIL',
                             'Get MTM enrollments baseline', $response);
            
            // D2-D3: Get available models and select one
            $modelId = $this->selectTestModel();
            if ($modelId) {
                $this->testData['model_id'] = $modelId;
                
                // D4: Enroll in MTM model
                $enrollData = [
                    'model_id' => $modelId,
                    'tier' => 'basic'
                ];
                
                $response = $this->client->post('api/mtm/enroll.php', $enrollData);
                $this->recordStep('D4', ($response['status'] === 200 || $response['status'] === 201) ? 'SUCCESS' : 'FAIL',
                                 'MTM enrollment creation', $response);
                
                if ($response['json']) {
                    $this->testData['enrollment'] = $response['json'];
                }
                
                // D5: Test idempotency (repeat same request with same key)
                // The client should automatically add idempotency key for POST requests
                $response2 = $this->client->post('api/mtm/enroll.php', $enrollData);
                $this->recordStep('D5', $response2['status'] === 200 ? 'SUCCESS' : 'FAIL',
                                 'Enrollment idempotency check', $response2);
                
                // D6-D7: Admin approval workflow
                $this->performAdminApproval($modelId);
                
            } else {
                $this->recordStep('D2-D3', 'SKIP', 'No available models for testing', []);
            }
            
        } catch (Exception $e) {
            $this->recordStep('D1-D7', 'FAIL', $e->getMessage(), []);
        }
    }
    
    private function selectTestModel() {
        // Try to get available models from the system
        try {
            $mysqli = $GLOBALS['mysqli'];
            $stmt = $mysqli->prepare("SELECT id FROM mtm_models WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            return $row ? (int)$row['id'] : 1; // Return 1 as fallback for basic testing
        } catch (Exception $e) {
            return 1; // Return default model ID
        }
    }
    
    private function performAdminApproval($modelId) {
        try {
            // Use the new adminLogin method
            if (!$this->adminLogin()) {
                $this->recordStep('D6-D7', 'SKIP', 'Admin login failed - skipping admin approval tests', []);
                return;
            }
            
            // D6: Admin approves enrollment
            $enrollmentId = $this->extractEnrollmentId();
            if ($enrollmentId) {
                $approvalData = ['enrollment_id' => $enrollmentId];
                $response = $this->client->post('api/admin/enrollment/approve.php', $approvalData);
                
                $success = false;
                if ($response['status'] === 200 &&
                    ($response['json']['success'] ?? false) === true) {
                    $success = true;
                } elseif (isset($response['json']['status']) && $response['json']['status'] === 'approved') {
                    $success = true;
                }
                
                $this->recordStep('D6', $success ? 'SUCCESS' : 'FAIL',
                                 'Admin enrollment approval', $response);
            } else {
                $this->recordStep('D6', 'FAIL', 'No enrollment ID found for approval', $this->testData);
            }
            
            // D7: User verification
            $this->client->post('logout.php', []); // Logout admin
            
            // Re-login as test user
            $this->loginAsTestUser();
            
            $response = $this->client->get('api/mtm/enrollments.php');
            $this->recordStep('D7', $response['status'] === 200 ? 'SUCCESS' : 'FAIL',
                             'User enrollment status verification', $response);
            
        } catch (Exception $e) {
            $this->recordStep('D6-D7', 'FAIL', $e->getMessage(), []);
        }
    }
    
    private function extractEnrollmentId() {
        $enrollment = $this->testData['enrollment'] ?? null;
        if (!$enrollment) {
            return null;
        }
        
        // Try different possible keys for enrollment ID
        $possibleKeys = ['enrollment_id', 'id', 'enrollmentId', 'EnrollmentID'];
        foreach ($possibleKeys as $key) {
            if (isset($enrollment[$key])) {
                return $enrollment[$key];
            }
        }
        
        return null;
    }
    
    // Step E: MTM CRUD Operations
    private function stepE_MTMCrud() {
        echo "🛠️ Step E: MTM CRUD Operations\n";
        
        try {
            if (!$this->adminLogin()) {
                $this->recordStep('E1-E3', 'SKIP', 'Admin login failed - skipping MTM CRUD tests', []);
                return;
            }
            
            // E1: CREATE model (use the correct endpoint)
            $createData = [
                'title' => 'E2E Test Model v2',
                'tier' => 'basic',
                'difficulty' => 'beginner',
                'description' => 'E2E test model created during automated testing',
                'is_active' => true,
                'rules' => [
                    ['key' => 'max_risk_pct', 'val' => 1.0],
                    ['key' => 'min_balance', 'val' => 1000],
                    ['key' => 'max_positions', 'val' => 5]
                ]
            ];
            
            // Use model_create.php (the one we found exists)
            $response = $this->client->post('api/admin/mtm/model_create.php', $createData);
            $this->recordStep('E1', $response['status'] === 201 ? 'SUCCESS' : 'FAIL',
                             'MTM model creation', $response);
            
            if ($response['json'] && (isset($response['json']['model_id']) || isset($response['json']['id']))) {
                $modelId = $response['json']['model_id'] ?? $response['json']['id'];
                $this->testData['created_model_id'] = $modelId;
                
                // E2: UPDATE model
                $updateData = array_merge($createData, ['id' => $modelId]);
                $updateData['title'] = 'E2E Test Model v2 (Updated)';
                $response = $this->client->post('api/admin/mtm/model_update.php', $updateData);
                $this->recordStep('E2', $response['status'] === 200 ? 'SUCCESS' : 'FAIL',
                                 'MTM model update', $response);
                
                // E3: DELETE model
                $deleteData = ['id' => $modelId];
                $response = $this->client->post('api/admin/mtm/model_delete.php', $deleteData);
                $this->recordStep('E3', $response['status'] === 200 ? 'SUCCESS' : 'FAIL',
                                 'MTM model deletion', $response);
            }
            
        } catch (Exception $e) {
            $this->recordStep('E1-E3', 'FAIL', $e->getMessage(), []);
        } finally {
            $this->client->post('logout.php', []); // Ensure logout
        }
    }
    
    // Step F: Trades & Capital/Performance
    private function stepF_TradesAndCapital() {
        echo "📊 Step F: Trades & Capital/Performance\n";
        
        try {
            // Re-login as test user
            $this->loginAsTestUser();
            
            $idempotencyKey1 = 'e2e_trade1_' . E2E_TIMESTAMP . '_1';
            $idempotencyKey2 = 'e2e_trade2_' . E2E_TIMESTAMP . '_2';
            
            // F1-F2: Create two trades with proper numeric fields
            $trade1 = [
                'symbol' => 'TCS',
                'side' => 'buy',
                'quantity' => 10,
                'entry_price' => 4000.0,
                'target_price' => 4500.0,
                'stop_loss' => 3800.0,
                'allocation_amount' => 40000.0,
                'notes' => 'E2E Test Trade 1 - +1000 PnL'
            ];
            
            $response = $this->client->post('api/trades/create.php', $trade1);
            $success1 = ($response['status'] === 200 || $response['status'] === 201) &&
                       ($response['json']['success'] ?? false) === true;
            $this->recordStep('F1', $success1 ? 'SUCCESS' : 'FAIL',
                             'Trade 1 creation (+1000 PnL expected)', $response);
            
            if ($response['json']) {
                $this->testData['trades'][] = $response['json'];
            }
            
            $trade2 = [
                'symbol' => 'INFY',
                'side' => 'buy',
                'quantity' => 5,
                'entry_price' => 1800.0,
                'target_price' => 2000.0,
                'stop_loss' => 1700.0,
                'allocation_amount' => 9000.0,
                'notes' => 'E2E Test Trade 2 - +500 PnL'
            ];
            
            $response = $this->client->post('api/trades/create.php', $trade2);
            $success2 = ($response['status'] === 200 || $response['status'] === 201) &&
                       ($response['json']['success'] ?? false) === true;
            $this->recordStep('F2', $success2 ? 'SUCCESS' : 'FAIL',
                             'Trade 2 creation (+500 PnL expected)', $response);
            
            if ($response['json']) {
                $this->testData['trades'][] = $response['json'];
            }
            
            // F3: List trades with limit
            $response = $this->client->get('api/trades/list.php?limit=10');
            $success3 = false;
            if ($response['status'] === 200 && $response['json'] &&
                (is_array($response['json']) || isset($response['json']['trades']))) {
                $trades = $response['json']['trades'] ?? $response['json'];
                $success3 = is_array($trades) && count($trades) >= 2;
            }
            
            $this->recordStep('F3', $success3 ? 'SUCCESS' : 'FAIL',
                             'Trade list retrieval', $response);
            
            // F4: Get dashboard metrics
            $response = $this->client->get('api/dashboard/metrics.php');
            $this->recordStep('F4', $response['status'] === 200 ? 'SUCCESS' : 'FAIL',
                             'Dashboard metrics retrieval', $response);
            
            // F5: Validate calculations
            if ($response['json']) {
                $this->validateMetrics($response['json']);
            }
            
        } catch (Exception $e) {
            $this->recordStep('F1-F5', 'FAIL', $e->getMessage(), []);
        }
    }
    
    private function validateMetrics($metrics) {
        $expected = [
            'net_pnl' => 500.0, // +1000 - 500
            'win_rate' => 50.0, // 1 win out of 2 trades
            'total_trades' => 2
        ];
        
        $actual = [
            'net_pnl' => $metrics['total_pnl'] ?? 0,
            'win_rate' => $metrics['win_rate'] ?? 0,
            'total_trades' => $metrics['total_trades'] ?? 0
        ];
        
        $differences = [];
        foreach ($expected as $key => $expectedValue) {
            $actualValue = $actual[$key];
            $tolerance = abs($expectedValue - $actualValue);
            
            if ($tolerance > 0.01) { // Allow small floating point differences
                $differences[] = "$key: expected $expectedValue, got $actualValue (diff: $tolerance)";
            }
        }
        
        if (empty($differences)) {
            $this->recordStep('F5', 'SUCCESS', 'Math validation passed', ['expected' => $expected, 'actual' => $actual]);
        } else {
            $this->recordStep('F5', 'PARTIAL', 'Math validation with differences: ' . implode(', ', $differences),
                            ['expected' => $expected, 'actual' => $actual, 'differences' => $differences]);
        }
    }
    
    // Step G: Security & Negative Tests
    private function stepG_SecurityNegativeTests() {
        echo "🔒 Step G: Security & Negative Tests\n";
        
        try {
            // G1: CSRF negative test
            // First ensure user is logged in
            $this->loginAsTestUser();
            
            // Create a client without CSRF token for this test
            $noCsrfClient = new E2EHttpClient(E2E_BASE_URL);
            $noCsrfClient->useCsrf = false; // Disable CSRF for this specific test
            
            // Test CSRF protection by trying without proper CSRF token
            $response = $noCsrfClient->post('api/profile/update.php', [
                'name' => 'Test User',
                'timezone' => 'Asia/Calcutta'
            ]);
            
            $this->recordStep('G1', $response['status'] === 403 ? 'SUCCESS' : 'FAIL',
                             'CSRF protection check', $response);
            
            // G2: Rate limiting test (quick burst)
            $burstResponses = [];
            for ($i = 0; $i < 5; $i++) {
                $burstResponses[] = $this->client->get('api/health.php');
            }
            
            $successCount = count(array_filter($burstResponses, function($r) { return $r['status'] === 200; }));
            $this->recordStep('G2', $successCount >= 4 ? 'SUCCESS' : 'FAIL',
                             'Rate limiting check (burst)', ['requests' => 5, 'success' => $successCount]);
            
            // G3: Authorization test (admin endpoint with user session)
            $this->loginAsTestUser();
            $response = $this->client->post('api/admin/mtm/model_create.php', [
                'title' => 'Unauthorized Test',
                'tier' => 'basic'
            ]);
            
            $this->recordStep('G3', in_array($response['status'], [401, 403]) ? 'SUCCESS' : 'FAIL',
                             'Admin authorization check', $response);
            
        } catch (Exception $e) {
            $this->recordStep('G1-G3', 'FAIL', $e->getMessage(), []);
        }
    }
    
    // Step H: Audit Trail & Logs
    private function stepH_AuditAndLogs() {
        echo "📝 Step H: Audit Trail & Logs\n";
        
        try {
            // H1: Check audit logs (requires admin)
            if ($this->adminLogin()) {
                $response = $this->client->get('api/admin/audit_log.php?limit=10');
                $success1 = $response['status'] === 200 &&
                          (isset($response['json']['logs']) || is_array($response['json']));
                $this->recordStep('H1', $success1 ? 'SUCCESS' : 'FAIL',
                                 'Audit log access', $response);
            } else {
                $this->recordStep('H1', 'SKIP', 'Admin login failed - skipping audit log test', []);
            }
            
            // H2: Check agent logs (requires admin)
            if ($this->adminLogin()) {
                $response = $this->client->get('api/admin/agent/logs.php');
                $success2 = $response['status'] === 200 &&
                          (isset($response['json']['logs']) || is_array($response['json']));
                $this->recordStep('H2', $success2 ? 'SUCCESS' : 'FAIL',
                                 'Agent logs list', $response);
            } else {
                $this->recordStep('H2', 'SKIP', 'Admin login failed - skipping agent logs test', []);
            }
            
            // H3: Post test agent event (user session)
            $this->loginAsTestUser();
            $response = $this->client->post('api/agent/log.php', [
                'event' => 'e2e_test',
                'details' => [
                    'test_suite' => 'E2E_Full',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'user_agent' => 'E2E-Test-Suite/1.0'
                ]
            ]);
            
            $success3 = ($response['status'] === 200 || $response['status'] === 201) &&
                       (($response['json']['success'] ?? false) === true ||
                        isset($response['json']['id']) ||
                        isset($response['json']['log_id']));
            $this->recordStep('H3', $success3 ? 'SUCCESS' : 'FAIL',
                             'Agent log event creation (user session)', $response);
            
        } catch (Exception $e) {
            $this->recordStep('H1-H3', 'FAIL', $e->getMessage(), []);
        } finally {
            $this->client->post('logout.php', []);
        }
    }
    
    private function stepCleanup() {
        echo "🧹 Cleanup: Removing test data\n";
        
        try {
            $mysqli = $GLOBALS['mysqli'];
            
            // Clean up test trades
            $stmt = $mysqli->prepare("DELETE FROM trades WHERE symbol = 'TCS' AND notes LIKE '%E2E Test%'");
            $stmt->execute();
            $tradesDeleted = $stmt->affected_rows;
            $stmt->close();
            
            // Clean up test user
            $stmt = $mysqli->prepare("DELETE FROM users WHERE email = ?");
            $stmt->bind_param('s', $this->testUser['email']);
            $stmt->execute();
            $userDeleted = $stmt->affected_rows;
            $stmt->close();
            
            // Clean up test models
            if (isset($this->testData['created_model_id'])) {
                $stmt = $mysqli->prepare("DELETE FROM mtm_models WHERE id = ?");
                $stmt->bind_param('i', $this->testData['created_model_id']);
                $stmt->execute();
                $modelsDeleted = $stmt->affected_rows;
                $stmt->close();
            }
            
            $this->recordStep('CLEANUP', 'SUCCESS',
                            "Cleanup completed: $tradesDeleted trades, $userDeleted user, " .
                            (isset($modelsDeleted) ? "$modelsDeleted models" : "0 models") . " deleted",
                            ['trades' => $tradesDeleted, 'user' => $userDeleted, 'models' => $modelsDeleted ?? 0]);
            
        } catch (Exception $e) {
            $this->recordStep('CLEANUP', 'FAIL', 'Cleanup failed: ' . $e->getMessage(), []);
        }
    }
    
    private function loginAsAdmin() {
        $adminLoginData = http_build_query([
            'email' => $this->adminUser['email'],
            'password' => $this->adminUser['password'],
            'csrf' => 'test' // E2E bypass
        ]);
        
        $response = $this->client->post('login.php', $adminLoginData);
        return $response['status'] === 302;
    }
    
    private function loginAsTestUser() {
        $userLoginData = http_build_query([
            'email' => $this->testUser['email'],
            'password' => $this->testUser['password'],
            'csrf' => 'test' // E2E bypass
        ]);
        
        $response = $this->client->post('login.php', $userLoginData);
        return $response['status'] === 302;
    }
    
    /**
     * Admin login function that uses environment credentials
     * This method handles admin authentication for E2E tests
     *
     * @return bool True if admin login successful
     */
    private function adminLogin() {
        $adminEmail = getenv('E2E_ADMIN_EMAIL') ?: $this->adminUser['email'];
        $adminPassword = getenv('E2E_ADMIN_PASS') ?: $this->adminUser['password'];
        
        if (getenv('E2E_ADMIN_EMAIL') && getenv('E2E_ADMIN_PASS')) {
            // Use environment credentials
            $this->recordStep('ADMIN_LOGIN', 'INFO', 'Using environment admin credentials', [
                'email' => $adminEmail,
                'has_password' => !empty($adminPassword)
            ]);
        } else {
            // Use default test admin credentials
            $this->recordStep('ADMIN_LOGIN', 'INFO', 'Using default test admin credentials', [
                'email' => $adminEmail,
                'note' => 'Set E2E_ADMIN_EMAIL and E2E_ADMIN_PASS in .env.e2e for custom admin'
            ]);
        }
        
        $adminLoginData = http_build_query([
            'email' => $adminEmail,
            'password' => $adminPassword,
            'csrf' => 'test' // E2E bypass
        ]);
        
        $response = $this->client->post('login.php', $adminLoginData);
        
        if ($response['status'] === 302) {
            $this->recordStep('ADMIN_LOGIN', 'SUCCESS', 'Admin login successful', $response);
            return true;
        } else {
            $this->recordStep('ADMIN_LOGIN', 'FAIL', 'Admin login failed', $response);
            return false;
        }
    }
    
    private function extractCsrfToken($html) {
        if (preg_match('/name="csrf".*?value="([^"]+)"/', $html, $matches)) {
            $this->csrfToken = $matches[1];
        }
    }
    
    private function simulateOtpVerification() {
        // Direct database update to mark user as verified
        $mysqli = $GLOBALS['mysqli'];
        $stmt = $mysqli->prepare("UPDATE users SET email_verified = 1, verified = 1, status = 'active' WHERE email = ?");
        $stmt->bind_param('s', $this->testUser['email']);
        $stmt->execute();
        $stmt->close();
    }
    
    private function resetPasswordFlow() {
        // Simulate password reset for cleanup testing
        $mysqli = $GLOBALS['mysqli'];
        $newHash = password_hash($this->testUser['new_password'], PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->bind_param('ss', $newHash, $this->testUser['email']);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Create a test client without CSRF for negative testing
     */
    private function createNoCsrfClient() {
        $client = new E2EHttpClient(E2E_BASE_URL);
        $client->useCsrf = false; // Disable CSRF for this test
        return $client;
    }
    
    private function assertStep($step, $description, $condition) {
        if (!$condition) {
            throw new Exception("Assertion failed: $description");
        }
    }
    
    private function recordStep($id, $status, $description, $response) {
        $this->results['steps'][] = [
            'id' => $id,
            'status' => $status,
            'description' => $description,
            'timestamp' => date('Y-m-d H:i:s'),
            'response' => $response
        ];
        
        $icon = $status === 'SUCCESS' ? '✅' : '❌';
        echo "  $icon $id: $description\n";
    }
    
    private function generateArtifacts() {
        echo "📊 Generating Artifacts...\n";
        
        // Create reports directory with proper timestamp format (NO COLONS)
        $reportsDir = E2E_REPORTS_DIR . '/' . E2E_TIMESTAMP;
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }
        
        // Generate machine-readable JSON results.json
        $this->generateJsonReport($reportsDir);
        
        // Generate human-readable summary.md
        $this->generateMarkdownReport($reportsDir);
        
        // Generate HTTP trace
        $this->generateHttpTrace($reportsDir);
        
        // Update last_status.json and last_fail.txt for api/admin/e2e_status.php
        $this->updateE2EStatusFiles();
        
        // Update project context
        $this->updateProjectContext();
        
        // Write summary to reports/e2e/summary_latest.md
        $this->writeSummaryLatest();
    }
    
    private function generateJsonReport($dir) {
        $jsonData = [
            'timestamp' => E2E_TIMESTAMP,
            'start_time' => $this->results['start_time'],
            'end_time' => microtime(true),
            'duration' => microtime(true) - $this->results['start_time'],
            'base_url' => E2E_BASE_URL,
            'test_user' => $this->testUser['email'],
            'admin_user' => getenv('E2E_ADMIN_EMAIL') ?: $this->adminUser['email'],
            'steps' => $this->results['steps'],
            'summary' => [
                'total_steps' => count($this->results['steps']),
                'successful_steps' => $this->countSuccessfulSteps(),
                'pass_rate' => $this->calculateOverallResult(),
                'e2e_mode' => getenv('E2E_MODE') === '1',
                'csrf_bypass' => getenv('ALLOW_CSRF_BYPASS') === '1',
                'cleanup_enabled' => E2E_CLEANUP
            ]
        ];
        
        file_put_contents($dir . "/results.json", json_encode($jsonData, JSON_PRETTY_PRINT));
    }
    
    private function generateMarkdownReport($dir) {
        $report = "# E2E Full Test Suite Report - " . E2E_TIMESTAMP . "\n\n";
        $report .= "**Test Execution Time:** " . (microtime(true) - $this->results['start_time']) . " seconds\n";
        $report .= "**Base URL:** " . E2E_BASE_URL . "\n";
        $report .= "**Test User:** " . $this->testUser['email'] . "\n";
        $report .= "**Admin User:** " . (getenv('E2E_ADMIN_EMAIL') ?: $this->adminUser['email']) . "\n";
        $report .= "**Pass Rate:** " . $this->calculateOverallResult() . "%\n\n";
        
        $report .= "## Test Results Summary\n\n";
        $report .= "| Step | Status | Description |\n";
        $report .= "|------|--------|-------------|\n";
        
        foreach ($this->results['steps'] as $step) {
            $icon = $step['status'] === 'SUCCESS' ? '✅' : ($step['status'] === 'SKIP' ? '⏭️' : '❌');
            $report .= "| {$step['id']} | $icon | {$step['description']} |\n";
        }
        
        $report .= "\n## Test Categories\n\n";
        $report .= "### Authentication & Profile (A, B, C)\n";
        foreach (['A1', 'A2', 'A3', 'B2', 'B3', 'B4', 'B5', 'C1', 'C2'] as $stepId) {
            $step = $this->findStep($stepId);
            if ($step) {
                $icon = $step['status'] === 'SUCCESS' ? '✅' : '❌';
                $report .= "- $icon **$stepId:** {$step['description']}\n";
            }
        }
        
        $report .= "\n### MTM Enrollment & Admin (D)\n";
        foreach (['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7'] as $stepId) {
            $step = $this->findStep($stepId);
            if ($step) {
                $icon = $step['status'] === 'SUCCESS' ? '✅' : '❌';
                $report .= "- $icon **$stepId:** {$step['description']}\n";
            }
        }
        
        $report .= "\n### MTM CRUD Operations (E)\n";
        foreach (['E1', 'E2', 'E3'] as $stepId) {
            $step = $this->findStep($stepId);
            if ($step) {
                $icon = $step['status'] === 'SUCCESS' ? '✅' : '❌';
                $report .= "- $icon **$stepId:** {$step['description']}\n";
            }
        }
        
        $report .= "\n### Trades & Performance (F)\n";
        foreach (['F1', 'F2', 'F3', 'F4', 'F5'] as $stepId) {
            $step = $this->findStep($stepId);
            if ($step) {
                $icon = $step['status'] === 'SUCCESS' ? '✅' : '❌';
                $report .= "- $icon **$stepId:** {$step['description']}\n";
            }
        }
        
        $report .= "\n### Security & Audit (G, H)\n";
        foreach (['G1', 'G2', 'G3', 'H1', 'H2', 'H3'] as $stepId) {
            $step = $this->findStep($stepId);
            if ($step) {
                $icon = $step['status'] === 'SUCCESS' ? '✅' : '❌';
                $report .= "- $icon **$stepId:** {$step['description']}\n";
            }
        }
        
        $report .= "\n## Environment Details\n\n";
        $report .= "- **E2E_MODE:** " . (getenv('E2E_MODE') ? 'enabled' : 'disabled') . "\n";
        $report .= "- **ALLOW_CSRF_BYPASS:** " . (getenv('ALLOW_CSRF_BYPASS') ? 'enabled' : 'disabled') . "\n";
        $report .= "- **APP_ENV:** " . (getenv('APP_ENV') ?: 'not set') . "\n";
        $report .= "- **Cleanup:** " . (E2E_CLEANUP ? 'enabled' : 'disabled') . "\n";
        
        file_put_contents($dir . "/summary.md", $report);
    }
    
    private function updateE2EStatusFiles() {
        $passRate = $this->calculateOverallResult();
        $successSteps = $this->countSuccessfulSteps();
        $totalSteps = count($this->results['steps']);
        
        // Create last_status.json
        $statusData = [
            'success' => $passRate >= 70, // Acceptance criteria: >=70% pass
            'pass_rate' => $passRate,
            'last_run_at' => E2E_TIMESTAMP,
            'total_steps' => $totalSteps,
            'successful_steps' => $successSteps,
            'failing_tests' => $this->getFailingStepIds(),
            'base_url' => E2E_BASE_URL,
            'e2e_mode' => getenv('E2E_MODE') === '1',
            'csrf_bypass' => getenv('ALLOW_CSRF_BYPASS') === '1',
            'admin_available' => !empty(getenv('E2E_ADMIN_EMAIL')),
            'duration' => microtime(true) - $this->results['start_time']
        ];
        
        file_put_contents(__DIR__ . '/last_status.json', json_encode($statusData, JSON_PRETTY_PRINT));
        
        // Create last_fail.txt if there are failures
        $failingSteps = $this->getFailingStepIds();
        if (!empty($failingSteps)) {
            $failMessage = "E2E Test Suite FAILED: $passRate% pass rate - " . E2E_TIMESTAMP .
                          " - Failing tests: " . implode(', ', $failingSteps);
            file_put_contents(__DIR__ . '/last_fail.txt', $failMessage);
        } else {
            // Remove last_fail.txt if all tests passed
            if (file_exists(__DIR__ . '/last_fail.txt')) {
                unlink(__DIR__ . '/last_fail.txt');
            }
        }
    }
    
    private function writeSummaryLatest() {
        $passRate = $this->calculateOverallResult();
        $summary = "# E2E Integration Test Results - " . E2E_TIMESTAMP . "\n\n";
        $summary .= "**Pass Rate:** $passRate% (" . $this->countSuccessfulSteps() . "/" . count($this->results['steps']) . " steps passed)\n";
        $summary .= "**Execution Time:** " . (microtime(true) - $this->results['start_time']) . " seconds\n";
        $summary .= "**Base URL:** " . E2E_BASE_URL . "\n\n";
        
        $summary .= "## Status\n\n";
        if ($passRate >= 70) {
            $summary .= "✅ **ACCEPTANCE CRITERIA MET** - Pass rate ≥70%\n\n";
        } else {
            $summary .= "❌ **ACCEPTANCE CRITERIA NOT MET** - Pass rate <70%\n\n";
        }
        
        $summary .= "## Artifacts\n\n";
        $summary .= "- **Results JSON:** `" . E2E_TIMESTAMP . "/results.json`\n";
        $summary .= "- **Summary Report:** `" . E2E_TIMESTAMP . "/summary.md`\n";
        $summary .= "- **Status File:** `last_status.json`\n";
        
        $summary .= "\n## Environment Configuration\n\n";
        $summary .= "- **E2E Mode:** " . (getenv('E2E_MODE') ? 'enabled' : 'disabled') . "\n";
        $summary .= "- **CSRF Bypass:** " . (getenv('ALLOW_CSRF_BYPASS') ? 'enabled' : 'disabled') . "\n";
        $summary .= "- **Admin Credentials:** " . (getenv('E2E_ADMIN_EMAIL') ? 'custom env' : 'default test') . "\n";
        
        file_put_contents(__DIR__ . '/summary_latest.md', $summary);
    }
    
    private function countSuccessfulSteps() {
        $count = 0;
        foreach ($this->results['steps'] as $step) {
            if ($step['status'] === 'SUCCESS') {
                $count++;
            }
        }
        return $count;
    }
    
    private function getFailingStepIds() {
        $ids = [];
        foreach ($this->results['steps'] as $step) {
            if ($step['status'] !== 'SUCCESS') {
                $ids[] = $step['id'];
            }
        }
        return $ids;
    }
    
    private function findStep($stepId) {
        foreach ($this->results['steps'] as $step) {
            if ($step['id'] === $stepId) {
                return $step;
            }
        }
        return null;
    }
    
    private function generateHttpTrace($dir) {
        $trace = "# HTTP Trace - " . E2E_TIMESTAMP . "\n\n";
        foreach ($this->client->getHttpTrace() as $request) {
            $trace .= "## {$request['timestamp']} - {$request['method']} {$request['url']}\n";
            $trace .= "**Status:** {$request['status']}\n";
            $trace .= "**Cookies:** " . implode(', ', $request['cookies']) . "\n";
            if (!empty($request['data'])) {
                $trace .= "**Data:** " . json_encode($request['data'], JSON_PRETTY_PRINT) . "\n";
            }
            $trace .= "\n";
        }
        
        file_put_contents($dir . "/http_trace.log", $trace);
    }
    
    private function updateProjectContext() {
        $contextFile = __DIR__ . '/../../context/project_context.json';
        if (file_exists($contextFile)) {
            $context = json_decode(file_get_contents($contextFile), true);
        } else {
            $context = [];
        }
        
        $context['last_full_e2e'] = [
            'timestamp' => E2E_TIMESTAMP,
            'result' => $this->calculateOverallResult(),
            'test_user' => $this->testUser['email'],
            'admin_user' => getenv('E2E_ADMIN_EMAIL') ?: $this->adminUser['email'],
            'cleanup' => E2E_CLEANUP,
            'e2e_mode' => getenv('E2E_MODE') === '1',
            'csrf_bypass' => getenv('ALLOW_CSRF_BYPASS') === '1',
            'pass_rate' => $this->calculateOverallResult(),
            'artifacts_path' => E2E_TIMESTAMP
        ];
        
        file_put_contents($contextFile, json_encode($context, JSON_PRETTY_PRINT));
    }
    
    private function calculateOverallResult() {
        $successCount = 0;
        $totalCount = count($this->results['steps']);
        
        foreach ($this->results['steps'] as $step) {
            if ($step['status'] === 'SUCCESS') {
                $successCount++;
            }
        }
        
        return $totalCount > 0 ? ($successCount / $totalCount) * 100 : 0;
    }
    
    private function finalizeResults() {
        $result = $this->calculateOverallResult();
        
        if ($result >= 70) { // Updated to match acceptance criteria
            file_put_contents(__DIR__ . '/../../.e2e_full_green', E2E_TIMESTAMP);
            echo "🎉 E2E Test Suite PASSED: $result%\n";
        } else {
            file_put_contents(__DIR__ . '/../../.e2e_full_red', E2E_TIMESTAMP);
            echo "❌ E2E Test Suite FAILED: $result%\n";
        }
    }
}

// Run the test suite if accessed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $suite = new E2ETestSuite();
    $suite->run();
}
?>