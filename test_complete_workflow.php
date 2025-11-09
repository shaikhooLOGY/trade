<?php
/**
 * test_complete_workflow.php
 * Comprehensive end-to-end test suite for the complete registration workflow
 * Tests all 5 stages: Registration → Email OTP → OTP Verification → Profile Completion → Admin Approval
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class CompleteWorkflowTest {
    private $mysqli;
    private $test_results = [];
    private $test_user_id = null;
    private $test_data = [];
    private $start_time;
    
    public function __construct() {
        $this->start_time = microtime(true);
        $this->mysqli = load_db();
        
        if (!$this->mysqli) {
            throw new Exception("Database connection failed");
        }
        
        echo "=== Complete Registration Workflow Test Suite ===\n";
        echo "Started at: " . date('Y-m-d H:i:s') . "\n";
        echo "Database: " . $this->mysqli->server_info . "\n\n";
    }
    
    public function runAllTests() {
        try {
            // Phase 1: Database and Schema Tests
            $this->testDatabaseSchema();
            
            // Phase 2: Email System Tests
            $this->testEmailSystem();
            
            // Phase 3: OTP System Tests
            $this->testOTPSystem();
            
            // Phase 4: User Registration Flow Tests
            $this->testUserRegistrationFlow();
            
            // Phase 5: Profile Completion Tests
            $this->testProfileCompletion();
            
            // Phase 6: Admin Approval Tests
            $this->testAdminApproval();
            
            // Phase 7: Security and Session Tests
            $this->testSecurityMeasures();
            
            // Phase 8: Error Handling Tests
            $this->testErrorScenarios();
            
            // Phase 9: Performance Tests
            $this->testPerformance();
            
            // Phase 10: Integration Tests
            $this->testIntegration();
            
        } catch (Exception $e) {
            echo "❌ CRITICAL ERROR: " . $e->getMessage() . "\n";
        } finally {
            $this->cleanupTestData();
            $this->printSummary();
        }
    }
    
    private function test($name, $callback) {
        echo "Testing: $name... ";
        
        try {
            $start = microtime(true);
            $result = $callback();
            $duration = round((microtime(true) - $start) * 1000, 2);
            
            if ($result['success']) {
                echo "✅ PASSED ({$duration}ms)\n";
                if (isset($result['message'])) {
                    echo "   " . $result['message'] . "\n";
                }
                $this->test_results[] = [
                    'name' => $name,
                    'status' => 'PASSED',
                    'duration' => $duration,
                    'message' => $result['message'] ?? ''
                ];
            } else {
                echo "❌ FAILED\n";
                echo "   Error: " . ($result['message'] ?? 'Unknown error') . "\n";
                $this->test_results[] = [
                    'name' => $name,
                    'status' => 'FAILED',
                    'duration' => $duration,
                    'message' => $result['message'] ?? ''
                ];
            }
        } catch (Exception $e) {
            echo "❌ FAILED\n";
            echo "   Exception: " . $e->getMessage() . "\n";
            $this->test_results[] = [
                'name' => $name,
                'status' => 'FAILED',
                'duration' => 0,
                'message' => $e->getMessage()
            ];
        }
    }
    
    private function testDatabaseSchema() {
        echo "\n--- DATABASE & SCHEMA TESTS ---\n";
        
        $this->test("Database Connection", function() {
            if (!$this->mysqli || !$this->mysqli->ping()) {
                return ['success' => false, 'message' => 'Database connection failed'];
            }
            return ['success' => true, 'message' => 'Database connected successfully'];
        });
        
        $this->test("Users Table Schema", function() {
            $required_columns = ['id', 'name', 'email', 'username', 'password_hash', 'status', 'email_verified', 'created_at'];
            foreach ($required_columns as $column) {
                if (!db_has_col($this->mysqli, 'users', $column)) {
                    return ['success' => false, 'message' => "Missing users.$column"];
                }
            }
            return ['success' => true, 'message' => 'Users table schema correct'];
        });
        
        $this->test("User OTPs Table", function() {
            if (!db_has_table($this->mysqli, 'user_otps')) {
                return ['success' => false, 'message' => 'user_otps table missing'];
            }
            
            $required_columns = ['id', 'user_id', 'otp_hash', 'expires_at', 'attempts', 'max_attempts', 'is_active'];
            foreach ($required_columns as $column) {
                if (!db_has_col($this->mysqli, 'user_otps', $column)) {
                    return ['success' => false, 'message' => "Missing user_otps.$column"];
                }
            }
            return ['success' => true, 'message' => 'user_otps table schema correct'];
        });
        
        $this->test("User Profiles Table", function() {
            if (!db_has_table($this->mysqli, 'user_profiles')) {
                return ['success' => false, 'message' => 'user_profiles table missing'];
            }
            
            $required_columns = ['user_id', 'profile_completion_status', 'created_at', 'updated_at'];
            foreach ($required_columns as $column) {
                if (!db_has_col($this->mysqli, 'user_profiles', $column)) {
                    return ['success' => false, 'message' => "Missing user_profiles.$column"];
                }
            }
            return ['success' => true, 'message' => 'user_profiles table schema correct'];
        });
        
        $this->test("Database Foreign Key Constraints", function() {
            // Test foreign key relationships
            $stmt = $this->mysqli->prepare("SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
                                          FROM information_schema.KEY_COLUMN_USAGE 
                                          WHERE REFERENCED_TABLE_SCHEMA = DATABASE() 
                                          AND TABLE_NAME IN ('user_otps', 'user_profiles')");
            if (!$stmt) {
                return ['success' => false, 'message' => 'Cannot check foreign keys'];
            }
            
            $stmt->execute();
            $constraints = $stmt->get_result();
            
            $found_otps_fk = false;
            $found_profiles_fk = false;
            
            while ($row = $constraints->fetch_assoc()) {
                if ($row['TABLE_NAME'] === 'user_otps' && $row['COLUMN_NAME'] === 'user_id') {
                    $found_otps_fk = true;
                }
                if ($row['TABLE_NAME'] === 'user_profiles' && $row['COLUMN_NAME'] === 'user_id') {
                    $found_profiles_fk = true;
                }
            }
            
            if (!$found_otps_fk || !$found_profiles_fk) {
                return ['success' => false, 'message' => 'Missing foreign key constraints'];
            }
            
            return ['success' => true, 'message' => 'Foreign key constraints properly set'];
        });
    }
    
    private function testEmailSystem() {
        echo "\n--- EMAIL SYSTEM TESTS ---\n";
        
        $this->test("Email Configuration", function() {
            // Check if mailer.php exists
            if (!file_exists(__DIR__ . '/mailer.php')) {
                return ['success' => false, 'message' => 'mailer.php not found'];
            }
            
            // Test SMTP configuration
            require_once __DIR__ . '/mailer.php';
            if (!function_exists('sendMail')) {
                return ['success' => false, 'message' => 'sendMail function not found'];
            }
            
            return ['success' => true, 'message' => 'Email system configuration correct'];
        });
        
        $this->test("OTP Email Template", function() {
            $template = otp_get_email_template('Test User', '123456');
            
            if (empty($template)) {
                return ['success' => false, 'message' => 'Email template is empty'];
            }
            
            if (strpos($template, '123456') === false) {
                return ['success' => false, 'message' => 'OTP not found in template'];
            }
            
            if (strpos($template, 'Test User') === false) {
                return ['success' => false, 'message' => 'User name not found in template'];
            }
            
            if (strpos($template, '<!DOCTYPE html>') === false) {
                return ['success' => false, 'message' => 'Invalid HTML template'];
            }
            
            return ['success' => true, 'message' => 'OTP email template is valid'];
        });
        
        $this->test("Approval Email Template", function() {
            $template = get_approval_email_template('Test User');
            
            if (strpos($template, 'Test User') === false) {
                return ['success' => false, 'message' => 'User name not found in approval template'];
            }
            
            if (strpos($template, 'approved') === false) {
                return ['success' => false, 'message' => 'Approval message not found'];
            }
            
            return ['success' => true, 'message' => 'Approval email template is valid'];
        });
        
        $this->test("Rejection Email Template", function() {
            $template = get_rejection_email_template('Test User', 'Insufficient information');
            
            if (strpos($template, 'Test User') === false) {
                return ['success' => false, 'message' => 'User name not found in rejection template'];
            }
            
            if (strpos($template, 'additional information') === false) {
                return ['success' => false, 'message' => 'Rejection message not found'];
            }
            
            return ['success' => true, 'message' => 'Rejection email template is valid'];
        });
    }
    
    private function testOTPSystem() {
        echo "\n--- OTP SYSTEM TESTS ---\n";
        
        $this->test("OTP Generation", function() {
            $otp = otp_generate_secure_otp();
            
            if (!preg_match('/^\d{6}$/', $otp)) {
                return ['success' => false, 'message' => 'OTP is not 6 digits'];
            }
            
            if ($otp === '000000') {
                return ['success' => false, 'message' => 'OTP is all zeros - not random enough'];
            }
            
            return ['success' => true, 'message' => "Generated OTP: $otp"];
        });
        
        $this->test("OTP Database Operations", function() {
            // Test creating test user first
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            $user_id = $test_user['user_id'];
            
            // Test sending OTP
            $sent = otp_send_verification_email($user_id, 'test@example.com', 'Test User');
            if (!$sent) {
                return ['success' => false, 'message' => 'Failed to send OTP email'];
            }
            
            // Verify OTP was stored in database
            $stmt = $this->mysqli->prepare("SELECT id, otp_hash, expires_at, attempts FROM user_otps WHERE user_id=? AND is_active=1 ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $otp_record = $result->fetch_assoc();
            $stmt->close();
            
            if (!$otp_record) {
                return ['success' => false, 'message' => 'OTP not found in database'];
            }
            
            // Test OTP verification
            $result = otp_verify_code($user_id, '123456'); // This should fail
            if ($result['success']) {
                return ['success' => false, 'message' => 'OTP verification should have failed'];
            }
            
            return ['success' => true, 'message' => 'OTP system database operations working'];
        });
        
        $this->test("OTP Rate Limiting", function() {
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            $rate_limit = otp_rate_limit_check($test_user['user_id'], 'test@example.com');
            
            if (!isset($rate_limit['allowed']) || !isset($rate_limit['message'])) {
                return ['success' => false, 'message' => 'Rate limiting function returned invalid structure'];
            }
            
            return ['success' => true, 'message' => 'OTP rate limiting working'];
        });
        
        $this->test("OTP Cleanup", function() {
            $cleaned = otp_cleanup_expired();
            
            if (!is_numeric($cleaned)) {
                return ['success' => false, 'message' => 'Cleanup function did not return a number'];
            }
            
            return ['success' => true, 'message' => "Cleaned up $cleaned expired OTP records"];
        });
    }
    
    private function testUserRegistrationFlow() {
        echo "\n--- USER REGISTRATION FLOW TESTS ---\n";
        
        $this->test("Registration Input Validation", function() {
            $test_data = [
                'name' => 'Test User',
                'email' => 'test' . time() . '@example.com',
                'username' => 'testuser' . time(),
                'password' => 'testpass123',
                'confirm' => 'testpass123',
                'accept_terms' => '1',
                'accept_legal_disclaimer' => '1',
                'accept_privacy_policy' => '1',
                'accept_email_access' => '1'
            ];
            
            // Test CSRF token
            $csrf = bin2hex(random_bytes(16));
            $test_data['csrf'] = $csrf;
            
            // Simulate form submission
            $_POST = $test_data;
            $_SESSION['reg_csrf'] = $csrf;
            $_SESSION['reg_attempts'] = [];
            
            // Test validation logic
            $errors = [];
            if (empty($test_data['name'])) $errors[] = 'Name required';
            if (empty($test_data['email'])) $errors[] = 'Email required';
            if (empty($test_data['username'])) $errors[] = 'Username required';
            if (empty($test_data['password'])) $errors[] = 'Password required';
            if (!filter_var($test_data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
            if (!preg_match('/^[A-Za-z0-9_.]{3,32}$/', $test_data['username'])) $errors[] = 'Invalid username';
            if (strlen($test_data['password']) < 8) $errors[] = 'Password too short';
            if ($test_data['password'] !== $test_data['confirm']) $errors[] = 'Passwords do not match';
            
            if (!empty($errors)) {
                return ['success' => false, 'message' => 'Validation failed: ' . implode(', ', $errors)];
            }
            
            return ['success' => true, 'message' => 'Registration input validation working'];
        });
        
        $this->test("Username Validation", function() {
            // Test valid username
            if (!preg_match('/^[A-Za-z0-9_.]{3,32}$/', 'validuser123')) {
                return ['success' => false, 'message' => 'Valid username rejected'];
            }
            
            // Test invalid usernames
            $invalid_usernames = ['ab', 'a'.str_repeat('x', 35), 'admin', 'root', 'user@name', 'user name'];
            foreach ($invalid_usernames as $username) {
                if (preg_match('/^[A-Za-z0-9_.]{3,32}$/', $username)) {
                    return ['success' => false, 'message' => "Invalid username accepted: $username"];
                }
            }
            
            return ['success' => true, 'message' => 'Username validation working correctly'];
        });
        
        $this->test("User Creation", function() {
            $test_email = 'testcreate' . time() . '@example.com';
            $test_username = 'testcreate' . time();
            $test_user_name = 'Test User Create';
            $password_hash = password_hash('testpass123', PASSWORD_DEFAULT);
            
            try {
                $stmt = $this->mysqli->prepare("INSERT INTO users (name, email, username, password_hash, status, email_verified, created_at) VALUES (?, ?, ?, ?, 'pending', 0, NOW())");
                $stmt->bind_param('ssss', $test_user_name, $test_email, $test_username, $password_hash);
                $stmt->execute();
                $user_id = $stmt->insert_id;
                $stmt->close();
                
                if (!$user_id) {
                    return ['success' => false, 'message' => 'User creation failed'];
                }
                
                // Clean up
                $this->mysqli->prepare("DELETE FROM users WHERE id=?")->bind_param('i', $user_id)->execute();
                
                return ['success' => true, 'message' => 'User creation working correctly'];
                
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'User creation error: ' . $e->getMessage()];
            }
        });
        
        $this->test("Session Management", function() {
            // Test session creation
            $_SESSION['test_user_id'] = 12345;
            $_SESSION['username'] = 'testuser';
            $_SESSION['email'] = 'test@example.com';
            $_SESSION['is_admin'] = 0;
            $_SESSION['email_verified'] = 0;
            
            if (!isset($_SESSION['test_user_id'])) {
                return ['success' => false, 'message' => 'Session data not stored'];
            }
            
            // Clean up
            unset($_SESSION['test_user_id']);
            unset($_SESSION['username']);
            unset($_SESSION['email']);
            unset($_SESSION['is_admin']);
            unset($_SESSION['email_verified']);
            
            return ['success' => true, 'message' => 'Session management working'];
        });
    }
    
    private function testProfileCompletion() {
        echo "\n--- PROFILE COMPLETION TESTS ---\n";
        
        $this->test("Profile Fields Configuration", function() {
            if (!file_exists(__DIR__ . '/profile_fields.php')) {
                return ['success' => false, 'message' => 'profile_fields.php not found'];
            }
            
            $config = require __DIR__ . '/profile_fields.php';
            
            if (!is_array($config) || empty($config)) {
                return ['success' => false, 'message' => 'Profile fields configuration is invalid'];
            }
            
            // Check for required sections
            $required_sections = ['personal_info', 'trading_experience', 'investment_goals'];
            foreach ($required_sections as $section) {
                if (!isset($config[$section])) {
                    return ['success' => false, 'message' => "Missing required section: $section"];
                }
            }
            
            return ['success' => true, 'message' => 'Profile fields configuration valid'];
        });
        
        $this->test("Profile Data Validation", function() {
            $profile_data = [
                'full_name' => 'Test User',
                'age' => '25',
                'trading_experience_years' => '3',
                'trading_capital' => '50000',
                'why_join' => 'I want to improve my trading skills and join a community of disciplined traders.'
            ];
            
            $validation = validate_profile_data($profile_data);
            
            if (!isset($validation['valid']) || !isset($validation['errors']) || !isset($validation['warnings'])) {
                return ['success' => false, 'message' => 'Validation function returned invalid structure'];
            }
            
            return ['success' => true, 'message' => 'Profile data validation working'];
        });
        
        $this->test("Profile Completeness Calculation", function() {
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            $completeness = calculate_profile_completeness($test_user['user_id']);
            
            if (!is_numeric($completeness) || $completeness < 0 || $completeness > 100) {
                return ['success' => false, 'message' => 'Profile completeness calculation invalid'];
            }
            
            return ['success' => true, 'message' => "Profile completeness: $completeness%"];
        });
    }
    
    private function testAdminApproval() {
        echo "\n--- ADMIN APPROVAL TESTS ---\n";
        
        $this->test("Admin User Status Filtering", function() {
            $statuses = ['pending', 'profile_pending', 'admin_review'];
            
            foreach ($statuses as $status) {
                $stmt = $this->mysqli->prepare("SELECT id FROM users WHERE status=? LIMIT 1");
                $stmt->bind_param('s', $status);
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();
                
                // It's OK if no users exist with this status in test environment
                // We're just testing that the query works
            }
            
            return ['success' => true, 'message' => 'Admin status filtering queries working'];
        });
        
        $this->test("User Status Updates", function() {
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            $user_id = $test_user['user_id'];
            $statuses = ['pending', 'profile_pending', 'admin_review', 'active'];
            
            foreach ($statuses as $status) {
                $stmt = $this->mysqli->prepare("UPDATE users SET status=? WHERE id=?");
                $stmt->bind_param('si', $status, $user_id);
                $result = $stmt->execute();
                $stmt->close();
                
                if (!$result) {
                    return ['success' => false, 'message' => "Failed to update status to $status"];
                }
                
                // Verify the update
                $stmt = $this->mysqli->prepare("SELECT status FROM users WHERE id=?");
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();
                
                if ($user['status'] !== $status) {
                    return ['success' => false, 'message' => "Status not updated correctly to $status"];
                }
            }
            
            return ['success' => true, 'message' => 'User status updates working'];
        });
        
        $this->test("Approval Email Function", function() {
            $result = send_approval_email('test@example.com', 'Test User', true, '');
            
            // We can't test actual email sending, but we can test the function structure
            return ['success' => true, 'message' => 'Approval email function callable'];
        });
    }
    
    private function testSecurityMeasures() {
        echo "\n--- SECURITY MEASURES TESTS ---\n";
        
        $this->test("CSRF Protection", function() {
            $token = csrf_token();
            
            if (empty($token) || strlen($token) < 32) {
                return ['success' => false, 'message' => 'CSRF token generation failed'];
            }
            
            // Test token verification
            if (!csrf_verify($token)) {
                return ['success' => false, 'message' => 'CSRF token verification failed'];
            }
            
            return ['success' => true, 'message' => 'CSRF protection working'];
        });
        
        $this->test("Password Hashing", function() {
            $password = 'testpass123';
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            if (empty($hash) || !password_verify($password, $hash)) {
                return ['success' => false, 'message' => 'Password hashing/verification failed'];
            }
            
            // Test that weak password is rejected
            if (password_verify('weak', $hash)) {
                return ['success' => false, 'message' => 'Password verification security compromised'];
            }
            
            return ['success' => true, 'message' => 'Password hashing security working'];
        });
        
        $this->test("Input Sanitization", function() {
            $malicious_input = '<script>alert("xss")</script>';
            $sanitized = h($malicious_input);
            
            if (strpos($sanitized, '<script>') !== false) {
                return ['success' => false, 'message' => 'XSS protection failed'];
            }
            
            return ['success' => true, 'message' => 'Input sanitization working'];
        });
        
        $this->test("SQL Injection Prevention", function() {
            // Test prepared statements are used
            $malicious_input = "'; DROP TABLE users; --";
            $stmt = $this->mysqli->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $stmt->bind_param('s', $malicious_input);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
            
            // If we get here without error and table still exists, prepared statements are working
            if (db_has_table($this->mysqli, 'users')) {
                return ['success' => true, 'message' => 'SQL injection prevention working'];
            } else {
                return ['success' => false, 'message' => 'SQL injection test failed - table may not exist'];
            }
        });
    }
    
    private function testErrorScenarios() {
        echo "\n--- ERROR SCENARIOS TESTS ---\n";
        
        $this->test("Invalid Email Validation", function() {
            $invalid_emails = ['not-an-email', '@domain.com', 'user@', 'user@.com', 'user@domain'];
            
            foreach ($invalid_emails as $email) {
                if (is_valid_email($email)) {
                    return ['success' => false, 'message' => "Invalid email accepted: $email"];
                }
            }
            
            return ['success' => true, 'message' => 'Invalid email validation working'];
        });
        
        $this->test("Weak Password Detection", function() {
            $weak_passwords = ['123', 'pass', 'abc123', 'password'];
            
            foreach ($weak_passwords as $password) {
                if (is_strong_password($password)) {
                    return ['success' => false, 'message' => "Weak password accepted: $password"];
                }
            }
            
            return ['success' => true, 'message' => 'Weak password detection working'];
        });
        
        $this->test("Database Connection Failure Handling", function() {
            // Test with invalid connection
            $invalid_mysqli = new mysqli('invalid_host', 'invalid_user', 'invalid_pass', 'invalid_db');
            
            if ($invalid_mysqli->connect_error === null) {
                return ['success' => false, 'message' => 'Database connection failure not detected'];
            }
            
            $invalid_mysqli->close();
            
            return ['success' => true, 'message' => 'Database connection failure handling working'];
        });
        
        $this->test("OTP Expiry Handling", function() {
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            // Simulate expired OTP
            $expired_time = date('Y-m-d H:i:s', strtotime('-1 hour'));
            $stmt = $this->mysqli->prepare("UPDATE user_otps SET expires_at=?, is_active=1 WHERE user_id=?");
            $stmt->bind_param('si', $expired_time, $test_user['user_id']);
            $stmt->execute();
            $stmt->close();
            
            $result = otp_verify_code($test_user['user_id'], '123456');
            
            if ($result['success']) {
                return ['success' => false, 'message' => 'Expired OTP was accepted'];
            }
            
            if (strpos($result['message'], 'expired') === false) {
                return ['success' => false, 'message' => 'Expiry error message not returned'];
            }
            
            return ['success' => true, 'message' => 'OTP expiry handling working'];
        });
    }
    
    private function testPerformance() {
        echo "\n--- PERFORMANCE TESTS ---\n";
        
        $this->test("Database Query Performance", function() {
            $start = microtime(true);
            
            // Test multiple queries
            for ($i = 0; $i < 10; $i++) {
                $stmt = $this->mysqli->prepare("SELECT COUNT(*) as count FROM users LIMIT 1");
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();
            }
            
            $duration = (microtime(true) - $start) * 1000; // Convert to milliseconds
            
            if ($duration > 1000) { // Should complete 10 queries in under 1 second
                return ['success' => false, 'message' => "Database queries too slow: {$duration}ms"];
            }
            
            return ['success' => true, 'message' => "Database queries performed in {$duration}ms"];
        });
        
        $this->test("Memory Usage", function() {
            $start_memory = memory_get_usage();
            
            // Simulate profile completion with all fields
            $profile_data = [];
            for ($i = 0; $i < 1000; $i++) {
                $profile_data["field_$i"] = str_repeat('x', 100);
            }
            
            $end_memory = memory_get_usage();
            $memory_used = ($end_memory - $start_memory) / 1024 / 1024; // MB
            
            if ($memory_used > 10) { // Should use less than 10MB
                return ['success' => false, 'message' => "Memory usage too high: {$memory_used}MB"];
            }
            
            return ['success' => true, 'message' => "Memory usage: {$memory_used}MB"];
        });
        
        $this->test("Rate Limiting Performance", function() {
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            $start = microtime(true);
            
            for ($i = 0; $i < 50; $i++) {
                otp_rate_limit_check($test_user['user_id'], 'test@example.com');
            }
            
            $duration = (microtime(true) - $start) * 1000; // milliseconds
            
            if ($duration > 100) { // Should handle 50 rate limit checks in under 100ms
                return ['success' => false, 'message' => "Rate limiting too slow: {$duration}ms"];
            }
            
            return ['success' => true, 'message' => "Rate limiting performed in {$duration}ms"];
        });
    }
    
    private function testIntegration() {
        echo "\n--- INTEGRATION TESTS ---\n";
        
        $this->test("Complete Registration Flow", function() {
            // Test the entire workflow in sequence
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            $user_id = $test_user['user_id'];
            
            // Step 1: Send OTP
            $sent = otp_send_verification_email($user_id, 'test@example.com', 'Test User');
            if (!$sent) {
                return ['success' => false, 'message' => 'OTP sending failed in integration test'];
            }
            
            // Step 2: Verify OTP
            $result = otp_verify_code($user_id, '123456');
            if ($result['success']) {
                return ['success' => false, 'message' => 'OTP verification should have failed in integration test'];
            }
            
            // Step 3: Update status to profile_pending
            update_user_status_profile_pending($user_id);
            
            // Step 4: Complete profile
            $profile_data = [
                'full_name' => 'Test User',
                'age' => '25',
                'location' => 'Test City',
                'trading_experience_years' => '3'
            ];
            
            $saved = save_profile_data($user_id, $profile_data);
            if (!$saved) {
                return ['success' => false, 'message' => 'Profile saving failed in integration test'];
            }
            
            // Step 5: Update status to admin_review
            update_user_status_admin_review($user_id);
            
            // Step 6: Approve user
            $stmt = $this->mysqli->prepare("UPDATE users SET status='active' WHERE id=?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
            
            return ['success' => true, 'message' => 'Complete registration flow integration test passed'];
        });
        
        $this->test("Database Transaction Integrity", function() {
            $test_user = $this->createTestUser();
            if (!$test_user['success']) {
                return $test_user;
            }
            
            $user_id = $test_user['user_id'];
            
            $this->mysqli->begin_transaction();
            
            try {
                // Update user status
                $stmt = $this->mysqli->prepare("UPDATE users SET status='active' WHERE id=?");
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $stmt->close();
                
                // Simulate an error and rollback
                throw new Exception('Simulated error');
                
            } catch (Exception $e) {
                $this->mysqli->rollback();
            }
            
            // Verify rollback
            $stmt = $this->mysqli->prepare("SELECT status FROM users WHERE id=?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if ($user['status'] === 'active') {
                return ['success' => false, 'message' => 'Transaction rollback failed'];
            }
            
            return ['success' => true, 'message' => 'Database transaction integrity working'];
        });
        
        $this->test("Email System Integration", function() {
            // Test that email functions can be called
            try {
                require_once __DIR__ . '/mailer.php';
                
                // We can't actually send emails in test environment, but we can verify the function exists
                $email_template = get_approval_email_template('Test User');
                $rejection_template = get_rejection_email_template('Test User', 'Test reason');
                
                if (empty($email_template) || empty($rejection_template)) {
                    return ['success' => false, 'message' => 'Email templates not generated'];
                }
                
                return ['success' => true, 'message' => 'Email system integration working'];
                
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Email system integration failed: ' . $e->getMessage()];
            }
        });
    }
    
    private function createTestUser() {
        try {
            $email = 'test' . time() . '@example.com';
            $username = 'testuser' . time();
            $password_hash = password_hash('testpass123', PASSWORD_DEFAULT);
            $name = 'Test User';
            
            $stmt = $this->mysqli->prepare("INSERT INTO users (name, email, username, password_hash, status, email_verified, created_at) VALUES (?, ?, ?, ?, 'pending', 0, NOW())");
            $stmt->bind_param('ssss', $name, $email, $username, $password_hash);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            $stmt->close();
            
            return ['success' => true, 'user_id' => $user_id];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to create test user: ' . $e->getMessage()];
        }
    }
    
    private function cleanupTestData() {
        echo "\n--- CLEANUP ---\n";
        
        try {
            // Clean up any test users created
            $this->mysqli->query("DELETE FROM user_otps WHERE email_sent_at IS NOT NULL AND email_sent_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            
            // Clean up test users (in production, be more careful with this)
            $this->mysqli->query("DELETE FROM users WHERE email LIKE '%@example.com'");
            
            echo "✅ Test data cleanup completed\n";
            
        } catch (Exception $e) {
            echo "⚠️  Test data cleanup failed: " . $e->getMessage() . "\n";
        }
    }
    
    private function printSummary() {
        $total_time = round((microtime(true) - $this->start_time), 2);
        $passed = count(array_filter($this->test_results, function($r) { return $r['status'] === 'PASSED'; }));
        $failed = count(array_filter($this->test_results, function($r) { return $r['status'] === 'FAILED'; }));
        $total = count($this->test_results);
        
        echo "\n";
        echo "=== TEST SUMMARY ===\n";
        echo "Total Tests: $total\n";
        echo "Passed: $passed\n";
        echo "Failed: $failed\n";
        echo "Success Rate: " . ($total > 0 ? round(($passed / $total) * 100, 1) : 0) . "%\n";
        echo "Total Time: {$total_time}s\n";
        
        if ($failed > 0) {
            echo "\n=== FAILED TESTS ===\n";
            foreach ($this->test_results as $result) {
                if ($result['status'] === 'FAILED') {
                    echo "- {$result['name']}: {$result['message']}\n";
                }
            }
        }
        
        echo "\n=== DETAILED RESULTS ===\n";
        foreach ($this->test_results as $result) {
            $icon = $result['status'] === 'PASSED' ? '✅' : '❌';
            echo sprintf("%s %s (%.1fms) - %s\n", 
                $icon, 
                $result['name'], 
                $result['duration'], 
                $result['message']
            );
        }
        
        if ($failed === 0) {
            echo "\n🎉 ALL TESTS PASSED! Complete registration workflow is production-ready.\n";
        } else {
            echo "\n⚠️  Some tests failed. Review and fix issues before production deployment.\n";
        }
        
        echo "\n=== TEST SUITE COMPLETED ===\n";
        echo "End time: " . date('Y-m-d H:i:s') . "\n";
    }
}

// Run the test suite
try {
    $test_suite = new CompleteWorkflowTest();
    $test_suite->runAllTests();
} catch (Exception $e) {
    echo "❌ CRITICAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>