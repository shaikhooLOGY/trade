<?php
// resend_otp.php — API endpoint for resending OTP verification codes
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Set JSON response header
header('Content-Type: application/json');

// Must be logged in
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Get user data
$mysqli = db();
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$stmt = $mysqli->prepare("SELECT name, email, email_verified FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Check if email is already verified
if ((int)$user['email_verified'] === 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email is already verified']);
    exit;
}

// Check rate limiting
$rate_limit = otp_rate_limit_check($user_id, $user['email']);

if (!$rate_limit['allowed']) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false, 
        'message' => $rate_limit['message'],
        'wait_seconds' => $rate_limit['wait_seconds'] ?? null
    ]);
    exit;
}

// Resend OTP
$resent = otp_send_verification_email($user_id, $user['email'], $user['name']);

if ($resent) {
    echo json_encode([
        'success' => true, 
        'message' => 'New verification code sent to your email!'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to send verification code. Please try again later.'
    ]);
}
?>