<?php
// Simple debug script to test registration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bootstrap.php';

$test_email = 'debug_test_' . time() . '@local.test';
$test_password = 'Test@12345';

echo "Testing registration for: $test_email\n";

try {
    // Test direct database registration
    $hash = password_hash($test_password, PASSWORD_DEFAULT);
    $status = 'pending';
    $created_at = date('Y-m-d H:i:s');
    
    $ins = $GLOBALS['mysqli']->prepare("
        INSERT INTO users (name,email,password,password_hash,status,email_verified,verified,created_at)
        VALUES (?,?,?,?,?,0,0,?)
    ");
    
    if (!$ins) {
        echo "Prepare failed: " . $GLOBALS['mysqli']->error . "\n";
    } else {
        $ins->bind_param('ssssss', 'Debug Test User', $test_email, $test_password, $hash, $status, $created_at);
        if ($ins->execute()) {
            echo "Direct DB registration successful! User ID: " . $ins->insert_id . "\n";
            $ins->close();
            
            // Test login
            $login_check = $GLOBALS['mysqli']->prepare("SELECT * FROM users WHERE email = ?");
            $login_check->bind_param('s', $test_email);
            $login_check->execute();
            $user = $login_check->get_result()->fetch_assoc();
            
            if ($user) {
                echo "User found in database:\n";
                echo "  ID: " . $user['id'] . "\n";
                echo "  Status: " . $user['status'] . "\n";
                echo "  Verified: " . $user['email_verified'] . "\n";
                
                // Test direct login
                $login_success = false;
                if (password_verify($test_password, $user['password_hash'])) {
                    echo "Direct password verification SUCCESS\n";
                    $login_success = true;
                } else {
                    echo "Direct password verification FAILED\n";
                }
                
                if ($user['password'] && password_verify($test_password, $user['password'])) {
                    echo "Legacy password verification SUCCESS\n";
                    $login_success = true;
                } else {
                    echo "Legacy password verification FAILED\n";
                }
                
                if ($login_success) {
                    echo "LOGIN SHOULD WORK!\n";
                }
            }
        } else {
            echo "Execute failed: " . $ins->error . "\n";
        }
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}