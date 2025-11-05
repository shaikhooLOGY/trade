<?php
// Common API Bootstrap for all API endpoints
// Phase-3 Pre-Fix Pack implementation

// Bootstrap chain - must be loaded first for every API file
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/security/csrf_unify.php';

// JSON response helpers
function json_ok($data = []) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

function json_fail($code, $message, $extra = []) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'code' => $code, 
        'message' => $message,
        'extra' => $extra
    ]);
    exit;
}

// CSRF token is now handled by the unified shim
// No redundant initialization needed - csrf_unify.php handles this