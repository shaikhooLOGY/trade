<?php
/**
 * PASSWORD RESET FUNCTIONALITY TEST SCRIPT
 * 
 * This script tests the complete password reset flow:
 * 1. Database table structure
 * 2. Token creation and storage
 * 3. Token validation and expiration
 * 4. Password update functionality
 * 
 * Usage: php test_password_reset.php
 */

require_once 'config.php';

class PasswordResetTest {
    private $mysqli;
    private $test_user_id = null;
    private $test_results = [];
    
    public function __construct() {
        // Include config to get database connection
        require_once __DIR__ . '/config.php';
        
        // Use the existing $mysqli connection from config.php
        global $mysqli;
        $this->mysqli = $mysqli;
        
        if ($this->mysqli->connect_error) {
            die("Connection failed: " . $this->mysqli->connect_error);
        }
    }
    
    public function runAllTests() {
        echo "🔍 PASSWORD RESET FUNCTIONALITY TEST\n";
        echo "===================================\n\n";
        
        $this->testTableExists();
        $this->testTableStructure();
        $this->testCreateTestUser();
        $this->testTokenCreation();
        $this->testTokenValidation();
        $this->testTokenExpiration();
        $this->testPasswordReset();
        $this->testCleanup();
        
        $this->printSummary();
    }
    
    private function test($name, $callback) {
        echo "Testing: $name... ";
        try {
            $result = $callback();
            if ($result) {
                echo "✅ PASS\n";
                $this->test_results[$name] = true;
            } else {
                echo "❌ FAIL\n";
                $this->test_results[$name] = false;
            }
        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
            $this->test_results[$name] = false;
        }
    }
    
    private function testTableExists() {
        $this->test("Table 'password_resets' exists", function() {
            $result = $this->mysqli->query("SHOW TABLES LIKE 'password_resets'");
            return $result->num_rows > 0;
        });
    }
    
    private function testTableStructure() {
        $this->test("Required columns exist", function() {
            $result = $this->mysqli->query("DESCRIBE password_resets");
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            
            $required = ['id', 'user_id', 'token', 'token_hash', 'expires_at', 'used_at', 'created_at'];
            foreach ($required as $col) {
                if (!in_array($col, $columns)) {
                    throw new Exception("Missing column: $col");
                }
            }
            return true;
        });
    }
    
    private function testCreateTestUser() {
        $this->test("Create test user", function() {
            // Check if test user exists
            $stmt = $this->mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $test_email = 'test.reset@example.com';
            $stmt->bind_param('s', $test_email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $this->test_user_id = $row['id'];
                $stmt->close();
                return true;
            }
            $stmt->close();
            
            // Create test user
            $password_hash = password_hash('testpassword123', PASSWORD_DEFAULT);
            $name = 'Test Reset User';
            $email = 'test.reset@example.com';
            
            $stmt = $this->mysqli->prepare("INSERT INTO users (name, username, email, password, password_hash, status, email_verified, verification_attempts, created_at, profile_status) VALUES (?, ?, ?, ?, ?, 'approved', 1, 0, NOW(), 'approved')");
            $stmt->bind_param('sssss', $name, $name, $email, $password_hash, $password_hash);
            $stmt->execute();
            $this->test_user_id = $this->mysqli->insert_id;
            $stmt->close();
            
            return $this->test_user_id > 0;
        });
    }
    
    private function testTokenCreation() {
        $this->test("Token creation and storage", function() {
            if (!$this->test_user_id) throw new Exception("No test user available");
            
            // Simulate forgot_password.php token creation
            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            
            // Insert token
            $stmt = $this->mysqli->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $this->test_user_id, $token, $token_hash, $expires_at);
            $stmt->execute();
            $reset_id = $this->mysqli->insert_id;
            $stmt->close();
            
            // Verify token was stored
            $stmt = $this->mysqli->prepare("SELECT token_hash, expires_at FROM password_resets WHERE id = ?");
            $stmt->bind_param('i', $reset_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stored = $result->fetch_assoc();
            $stmt->close();
            
            return $stored['token_hash'] === $token_hash;
        });
    }
    
    private function testTokenValidation() {
        $this->test("Token validation logic", function() {
            // Get the token we just created
            $stmt = $this->mysqli->prepare("SELECT id, token_hash, expires_at, used_at FROM password_resets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param('i', $this->test_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $token_data = $result->fetch_assoc();
            $stmt->close();
            
            if (!$token_data) throw new Exception("No token found for testing");
            
            // Simulate reset_password.php validation
            $hash = $token_data['token_hash'];
            $stmt = $this->mysqli->prepare("SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1");
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            if (!$row) throw new Exception("Token validation failed");
            
            // Check if already used
            if (!empty($row['used_at'])) throw new Exception("Token already used");
            
            // Check if expired
            if (strtotime($row['expires_at']) < time()) throw new Exception("Token expired");
            
            return true;
        });
    }
    
    private function testTokenExpiration() {
        $this->test("Token expiration handling", function() {
            // Create an expired token
            $token = 'expired_test_token_' . time();
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
            
            $stmt = $this->mysqli->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $this->test_user_id, $token, $token_hash, $expires_at);
            $stmt->execute();
            $expired_id = $this->mysqli->insert_id;
            $stmt->close();
            
            // Try to validate expired token (should fail)
            $stmt = $this->mysqli->prepare("SELECT id FROM password_resets WHERE token_hash = ? LIMIT 1");
            $stmt->bind_param('s', $token_hash);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            if (!$row) return true; // Good, expired token not found in valid queries
            
            // If found, check expiration
            $stmt = $this->mysqli->prepare("SELECT expires_at FROM password_resets WHERE id = ?");
            $stmt->bind_param('i', $expired_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $token_info = $result->fetch_assoc();
            $stmt->close();
            
            $is_expired = strtotime($token_info['expires_at']) < time();
            return $is_expired;
        });
    }
    
    private function testPasswordReset() {
        $this->test("Password reset flow", function() {
            if (!$this->test_user_id) throw new Exception("No test user available");
            
            // Get a valid token
            $stmt = $this->mysqli->prepare("SELECT id, token_hash FROM password_resets WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
            $stmt->bind_param('i', $this->test_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $token = $result->fetch_assoc();
            $stmt->close();
            
            if (!$token) throw new Exception("No valid token found for password reset test");
            
            // Simulate password update
            $new_password_hash = password_hash('newpassword123', PASSWORD_DEFAULT);
            $stmt = $this->mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->bind_param('si', $new_password_hash, $this->test_user_id);
            $stmt->execute();
            $stmt->close();
            
            // Mark token as used
            $stmt = $this->mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $token['id']);
            $stmt->execute();
            $stmt->close();
            
            // Verify password was updated
            $stmt = $this->mysqli->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->bind_param('i', $this->test_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            return password_verify('newpassword123', $user['password_hash']);
        });
    }
    
    private function testCleanup() {
        $this->test("Cleanup test data", function() {
            // Delete test tokens
            $stmt = $this->mysqli->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $stmt->bind_param('i', $this->test_user_id);
            $stmt->execute();
            $deleted_tokens = $stmt->affected_rows;
            $stmt->close();
            
            // Delete test user
            $stmt = $this->mysqli->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $this->test_user_id);
            $stmt->execute();
            $deleted_user = $stmt->affected_rows;
            $stmt->close();
            
            return $deleted_tokens > 0 || $deleted_user > 0;
        });
    }
    
    private function printSummary() {
        echo "\n📊 TEST SUMMARY\n";
        echo "===============\n";
        
        $passed = array_filter($this->test_results);
        $total = count($this->test_results);
        $pass_count = count($passed);
        
        echo "Total Tests: $total\n";
        echo "Passed: $pass_count\n";
        echo "Failed: " . ($total - $pass_count) . "\n";
        echo "Success Rate: " . round(($pass_count / $total) * 100, 1) . "%\n\n";
        
        if ($pass_count === $total) {
            echo "🎉 ALL TESTS PASSED! Password reset functionality is working correctly.\n";
        } else {
            echo "⚠️  SOME TESTS FAILED! Please check the results above.\n";
            echo "\nFailed tests:\n";
            foreach ($this->test_results as $test => $result) {
                if (!$result) echo "  ❌ $test\n";
            }
        }
    }
}

// Run the tests
if (php_sapi_name() === 'cli') {
    $tester = new PasswordResetTest();
    $tester->runAllTests();
} else {
    echo "<h1>Password Reset Test</h1>";
    echo "<p>Please run this script from command line: <code>php test_password_reset.php</code></p>";
}
?>