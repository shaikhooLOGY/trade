<?php
header('Content-Type: application/json');
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$modelId = (int)($body['model_id'] ?? 0);
$tier = (string)($body['tier'] ?? '');
$csrf = (string)($body['csrf_token'] ?? '');

if (!isset($_SESSION['csrf_token']) || !$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'CSRF_MISMATCH', 'message' => 'Invalid CSRF token']);
    exit;
}

if ($modelId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'model_id must be a positive integer']);
    exit;
}

if (!in_array($tier, ['basic', 'intermediate', 'advanced'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'tier must be basic|intermediate|advanced']);
    exit;
}

// Include required files
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/guard.php';

// Include MTM modules
require_once __DIR__ . '/../../includes/mtm/mtm_validation.php';
require_once __DIR__ . '/../../includes/mtm/mtm_service.php';
require_once __DIR__ . '/../../includes/mtm/mtm_rules.php';

// Require authentication and active user
require_login();
require_active_user();

// Basic rate limiting (5 requests per minute per endpoint)
if (!isset($_SESSION['api_rate_limit'])) {
    $_SESSION['api_rate_limit'] = [];
}

$endpoint = 'mtm_enroll';
$now = time();
$window = 60; // 1 minute window

// Clean old entries
if (isset($_SESSION['api_rate_limit'][$endpoint])) {
    $_SESSION['api_rate_limit'][$endpoint] = array_filter(
        $_SESSION['api_rate_limit'][$endpoint],
        function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        }
    );
}

// Check rate limit
if (isset($_SESSION['api_rate_limit'][$endpoint]) &&
    count($_SESSION['api_rate_limit'][$endpoint]) >= 5) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Rate limit exceeded. Try again later.',
        'retry_after' => 60
    ]);
    exit;
}

// Record this request
$_SESSION['api_rate_limit'][$endpoint][] = $now;

// Verify model exists
$stmt = $GLOBALS['mysqli']->prepare("SELECT id FROM mtm_models WHERE id=? AND is_active=1 LIMIT 1");
$stmt->bind_param('i', $modelId);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'MODEL_NOT_FOUND', 'message' => 'Selected model not available']);
    exit;
}

// Call enrollment service
$result = mtm_enroll((int)$_SESSION['user_id'], $modelId, $tier);

if ($result['success']) {
    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'enrollment_id' => $result['enrollment_id'],
        'unlocked_task_id' => $result['unlocked_task_id']
    ]);
} else {
    // Handle specific error cases
    if ($result['error'] === 'ALREADY_ENROLLED') {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'code' => 'ALREADY_ENROLLED',
            'message' => 'Trader is already enrolled in this model'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'An error occurred during enrollment'
        ]);
    }
}