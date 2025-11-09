<?php
/**
 * test_workflow_structure.php
 * Simplified test to verify registration workflow structure and logic
 * Tests the components that don't require live database connection
 */

echo "=== Registration Workflow Structure Test ===\n";
echo "Testing workflow structure and components...\n\n";

$tests_passed = 0;
$tests_failed = 0;
$test_results = [];

function run_test($name, $callback) {
    global $tests_passed, $tests_failed, $test_results;
    
    echo "Testing: $name... ";
    
    try {
        $result = $callback();
        if ($result['success']) {
            echo "✅ PASSED\n";
            if (isset($result['message'])) {
                echo "   " . $result['message'] . "\n";
            }
            $tests_passed++;
            $test_results[] = ['name' => $name, 'status' => 'PASSED', 'message' => $result['message'] ?? ''];
        } else {
            echo "❌ FAILED\n";
            echo "   Error: " . ($result['message'] ?? 'Unknown error') . "\n";
            $tests_failed++;
            $test_results[] = ['name' => $name, 'status' => 'FAILED', 'message' => $result['message'] ?? ''];
        }
    } catch (Exception $e) {
        echo "❌ FAILED\n";
        echo "   Exception: " . $e->getMessage() . "\n";
        $tests_failed++;
        $test_results[] = ['name' => $name, 'status' => 'FAILED', 'message' => $e->getMessage()];
    }
}

// Test 1: File Structure Validation
run_test("Registration Workflow File Structure", function() {
    $required_files = [
        'register.php',
        'pending_approval.php',
        'profile_completion.php',
        'profile_fields.php',
        'includes/functions.php',
        'includes/env.php',
        'config.php',
        'admin/admin_dashboard.php',
        'admin/users.php'
    ];
    
    $missing_files = [];
    foreach ($required_files as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            $missing_files[] = $file;
        }
    }
    
    if (!empty($missing_files)) {
        return ['success' => false, 'message' => 'Missing files: ' . implode(', ', $missing_files)];
    }
    
    return ['success' => true, 'message' => 'All required workflow files present'];
});

// Test 2: PHP Syntax Validation
run_test("PHP Syntax Validation", function() {
    $php_files = [
        'register.php',
        'pending_approval.php',
        'profile_completion.php',
        'profile_fields.php',
        'includes/functions.php',
        'admin/admin_dashboard.php',
        'admin/users.php'
    ];
    
    $errors = [];
    foreach ($php_files as $file) {
        $full_path = __DIR__ . '/' . $file;
        if (file_exists($full_path)) {
            $output = [];
            $return_code = 0;
            exec("php -l " . escapeshellarg($full_path) . " 2>&1", $output, $return_code);
            
            if ($return_code !== 0) {
                $errors[] = $file . ': ' . implode(' ', $output);
            }
        }
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Syntax errors found: ' . implode(', ', $errors)];
    }
    
    return ['success' => true, 'message' => 'All PHP files have valid syntax'];
});

// Test 3: Profile Fields Configuration
run_test("Profile Fields Configuration", function() {
    if (!file_exists(__DIR__ . '/profile_fields.php')) {
        return ['success' => false, 'message' => 'profile_fields.php not found'];
    }
    
    $config = require __DIR__ . '/profile_fields.php';
    
    if (!is_array($config) || empty($config)) {
        return ['success' => false, 'message' => 'Profile fields configuration is invalid'];
    }
    
    $expected_sections = [
        'personal_info',
        'trading_experience', 
        'investment_goals',
        'financial_info',
        'psychology_assessment',
        'why_join'
    ];
    
    foreach ($expected_sections as $section) {
        if (!isset($config[$section])) {
            return ['success' => false, 'message' => "Missing required section: $section"];
        }
        
        if (!isset($config[$section]['title']) || !isset($config[$section]['fields'])) {
            return ['success' => false, 'message' => "Invalid section structure: $section"];
        }
    }
    
    return ['success' => true, 'message' => 'Profile fields configuration valid with ' . count($config) . ' sections'];
});

// Test 4: Database Schema Files
run_test("Database Schema Files", function() {
    $schema_files = [
        'create_user_otps_table.sql',
        'create_user_profiles_table.sql'
    ];
    
    foreach ($schema_files as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            return ['success' => false, 'message' => "Missing schema file: $file"];
        }
        
        $content = file_get_contents(__DIR__ . '/' . $file);
        if (strpos($content, 'CREATE TABLE') === false) {
            return ['success' => false, 'message' => "Invalid schema file: $file (no CREATE TABLE found)"];
        }
    }
    
    return ['success' => true, 'message' => 'Database schema files present and valid'];
});

// Test 5: Security Functions
run_test("Security Functions Available", function() {
    if (!file_exists(__DIR__ . '/includes/functions.php')) {
        return ['success' => false, 'message' => 'functions.php not found'];
    }
    
    $functions_content = file_get_contents(__DIR__ . '/includes/functions.php');
    
    $required_functions = [
        'csrf_token',
        'csrf_verify', 
        'h',
        'is_valid_email',
        'is_strong_password',
        'otp_generate_secure_otp',
        'otp_get_email_template',
        'get_approval_email_template',
        'get_rejection_email_template'
    ];
    
    $missing_functions = [];
    foreach ($required_functions as $func) {
        if (strpos($functions_content, 'function ' . $func) === false) {
            $missing_functions[] = $func;
        }
    }
    
    if (!empty($missing_functions)) {
        return ['success' => false, 'message' => 'Missing functions: ' . implode(', ', $missing_functions)];
    }
    
    return ['success' => true, 'message' => 'All required security functions available'];
});

// Test 6: Configuration System
run_test("Configuration System", function() {
    if (!file_exists(__DIR__ . '/includes/env.php')) {
        return ['success' => false, 'message' => 'env.php not found'];
    }
    
    if (!file_exists(__DIR__ . '/config.php')) {
        return ['success' => false, 'message' => 'config.php not found'];
    }
    
    require_once __DIR__ . '/includes/env.php';
    
    if (!defined('APP_ENV')) {
        return ['success' => false, 'message' => 'APP_ENV not defined'];
    }
    
    return ['success' => true, 'message' => 'Configuration system loaded correctly'];
});

// Test 7: Workflow Logic Structure
run_test("Registration Workflow Logic", function() {
    $workflow_files = [
        '1. User Registration' => 'register.php',
        '2. Email OTP' => 'pending_approval.php', 
        '3. Profile Completion' => 'profile_completion.php',
        '4. Admin Review' => 'admin/users.php'
    ];
    
    $issues = [];
    
    foreach ($workflow_files as $step => $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            $issues[] = "Missing file for $step: $file";
        }
    }
    
    if (!empty($issues)) {
        return ['success' => false, 'message' => 'Workflow issues: ' . implode('; ', $issues)];
    }
    
    return ['success' => true, 'message' => 'Registration workflow logic structure validated'];
});

// Test 8: Email System Structure
run_test("Email System Structure", function() {
    if (!file_exists(__DIR__ . '/mailer.php')) {
        return ['success' => false, 'message' => 'mailer.php not found'];
    }
    
    if (!file_exists(__DIR__ . '/includes/functions.php')) {
        return ['success' => false, 'message' => 'functions.php not found'];
    }
    
    $functions_content = file_get_contents(__DIR__ . '/includes/functions.php');
    
    $template_functions = [
        'get_approval_email_template',
        'get_rejection_email_template',
        'otp_get_email_template'
    ];
    
    foreach ($template_functions as $func) {
        if (strpos($functions_content, 'function ' . $func) === false) {
            return ['success' => false, 'message' => "Email template function missing: $func"];
        }
    }
    
    return ['success' => true, 'message' => 'Email system structure validated'];
});

// Test 9: Admin Interface Structure
run_test("Admin Interface Structure", function() {
    $admin_files = [
        'admin/admin_dashboard.php',
        'admin/users.php'
    ];
    
    foreach ($admin_files as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            return ['success' => false, 'message' => "Missing admin file: $file"];
        }
    }
    
    $users_content = file_get_contents(__DIR__ . '/admin/users.php');
    
    if (strpos($users_content, 'require_admin') === false) {
        return ['success' => false, 'message' => 'Admin authentication not implemented in users.php'];
    }
    
    if (strpos($users_content, 'status') === false) {
        return ['success' => false, 'message' => 'User status filtering not implemented'];
    }
    
    return ['success' => true, 'message' => 'Admin interface structure validated'];
});

// Test 10: Security Patterns
run_test("Security and Validation Patterns", function() {
    $register_content = file_get_contents(__DIR__ . '/register.php');
    
    $security_patterns = [
        'csrf' => 'CSRF protection',
        'password_hash' => 'Password hashing', 
        'filter_var' => 'Input validation',
        'htmlspecialchars' => 'Output escaping',
        'hash_equals' => 'Timing-safe comparison'
    ];
    
    $missing_patterns = [];
    foreach ($security_patterns as $pattern => $description) {
        if (strpos($register_content, $pattern) === false) {
            $missing_patterns[] = $description;
        }
    }
    
    if (!empty($missing_patterns)) {
        return ['success' => false, 'message' => 'Missing security patterns: ' . implode(', ', $missing_patterns)];
    }
    
    return ['success' => true, 'message' => 'Security and validation patterns implemented'];
});

// Test 11: User Status Management
run_test("User Status Management", function() {
    $schema_content = file_get_contents(__DIR__ . '/create_user_profiles_table.sql');
    
    $status_types = [
        'pending',
        'profile_pending', 
        'admin_review',
        'active',
        'approved'
    ];
    
    foreach ($status_types as $status) {
        if (strpos($schema_content, $status) === false) {
            return ['success' => false, 'message' => "Status type not defined in schema: $status"];
        }
    }
    
    return ['success' => true, 'message' => 'User status management properly defined'];
});

// Test 12: Environment Configuration
run_test("Environment Configuration", function() {
    $env_files = ['.env.local', '.env'];
    $env_found = false;
    
    foreach ($env_files as $file) {
        if (file_exists(__DIR__ . '/' . $file)) {
            $env_found = true;
            $content = file_get_contents(__DIR__ . '/' . $file);
            
            $required_vars = ['DB_HOST', 'DB_USER', 'DB_NAME'];
            foreach ($required_vars as $var) {
                if (strpos($content, $var) === false) {
                    return ['success' => false, 'message' => "Missing environment variable: $var in $file"];
                }
            }
        }
    }
    
    if (!$env_found) {
        return ['success' => false, 'message' => 'No environment configuration file found'];
    }
    
    return ['success' => true, 'message' => 'Environment configuration properly set up'];
});

// Test 13: HTML Template Structure
run_test("HTML Template Structure", function() {
    $template_files = [
        'register.php',
        'pending_approval.php',
        'profile_completion.php',
        'admin/admin_dashboard.php',
        'admin/users.php'
    ];
    
    foreach ($template_files as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            continue;
        }
        
        $content = file_get_contents(__DIR__ . '/' . $file);
        
        // Check for basic HTML structure
        if (strpos($content, '<!DOCTYPE html>') === false && strpos($content, '<html') === false) {
            return ['success' => false, 'message' => "Missing HTML structure in $file"];
        }
        
        if (strpos($content, '</head>') === false || strpos($content, '</body>') === false) {
            return ['success' => false, 'message' => "Incomplete HTML structure in $file"];
        }
    }
    
    return ['success' => true, 'message' => 'All HTML templates have proper structure'];
});

// Test 14: JavaScript Functionality
run_test("JavaScript Functionality", function() {
    $files_with_js = [
        'register.php',
        'profile_completion.php'
    ];
    
    foreach ($files_with_js as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            continue;
        }
        
        $content = file_get_contents(__DIR__ . '/' . $file);
        
        if (strpos($content, '<script>') !== false) {
            if (strpos($content, 'addEventListener') === false) {
                return ['success' => false, 'message' => "Missing event listeners in $file"];
            }
        }
    }
    
    return ['success' => true, 'message' => 'JavaScript functionality properly implemented'];
});

// Test 15: Error Handling
run_test("Error Handling Patterns", function() {
    $error_patterns = [
        'try' => 'Exception handling',
        'catch' => 'Exception catching',
        'error_log' => 'Error logging',
        'throw' => 'Exception throwing'
    ];
    
    $functions_content = file_get_contents(__DIR__ . '/includes/functions.php');
    
    foreach ($error_patterns as $pattern => $description) {
        if (strpos($functions_content, $pattern) === false) {
            return ['success' => false, 'message' => "Missing error handling pattern: $description"];
        }
    }
    
    return ['success' => true, 'message' => 'Error handling patterns properly implemented'];
});

// Summary
echo "\n=== TEST SUMMARY ===\n";
echo "Total Tests: " . ($tests_passed + $tests_failed) . "\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";
echo "Success Rate: " . (($tests_passed + $tests_failed) > 0 ? round(($tests_passed / ($tests_passed + $tests_failed)) * 100, 1) : 0) . "%\n";

if ($tests_failed > 0) {
    echo "\n=== FAILED TESTS ===\n";
    foreach ($test_results as $result) {
        if ($result['status'] === 'FAILED') {
            echo "- {$result['name']}: {$result['message']}\n";
        }
    }
}

echo "\n=== DETAILED RESULTS ===\n";
foreach ($test_results as $result) {
    $icon = $result['status'] === 'PASSED' ? '✅' : '❌';
    echo sprintf("%s %s - %s\n", $icon, $result['name'], $result['message']);
}

if ($tests_failed === 0) {
    echo "\n🎉 ALL STRUCTURE TESTS PASSED! Registration workflow structure is properly implemented.\n";
} else {
    echo "\n⚠️  Some structure tests failed. Review and fix issues before proceeding.\n";
}

echo "\n=== STRUCTURE TEST COMPLETED ===\n";
echo "End time: " . date('Y-m-d H:i:s') . "\n";
?>