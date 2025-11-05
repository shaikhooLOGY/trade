<?php
// CSRF Token Unification Shim
// Phase-3 Pre-Fix Pack implementation
// Standardizes $_SESSION['csrf'] across codebase

// Unified CSRF token handler
function get_csrf_token() {
    // Session should already be started via bootstrap.php
    
    // If unified token doesn't exist, try to migrate from legacy token
    if (empty($_SESSION['csrf'])) {
        if (!empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf'] = $_SESSION['csrf_token'];
            unset($_SESSION['csrf_token']);
        } else {
            // Generate new token if neither exists
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }
    
    return $_SESSION['csrf'];
}

// Unified CSRF token validation with timing-safe comparison
function validate_csrf($token) {
    if (empty($token)) {
        return false;
    }
    
    $stored_token = get_csrf_token();
    
    // Use hash_equals for timing-safe comparison
    return hash_equals($stored_token, $token);
}

// Ensure unified token is available
get_csrf_token();