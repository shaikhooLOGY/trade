<?php
/**
 * E2E Registration API Endpoint
 * Simplified registration for E2E testing - returns JSON responses
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';
require_once __DIR__ . '/../../includes/security/csrf.php';

// E2E bypass for testing
$isE2E = getenv('APP_ENV') === 'local' && getenv('ALLOW_CSRF_BYPASS') === '1';

// Rate limit registration attempts
if (!$isE2E) {
    require_rate_limit('auth:register', 3);
}

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('METHOD_NOT_ALLOWED', 'Method not allowed', null, 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Fallback to form data
        $input = $_POST;
    }

    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');

    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        json_error('VALIDATION_ERROR', 'All fields are required', null, 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('VALIDATION_ERROR', 'Invalid email format', null, 400);
    }

    if (strlen($password) < 6) {
        json_error('VALIDATION_ERROR', 'Password must be at least 6 characters', null, 400);
    }

    // Check if user already exists
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->fetch_assoc()) {
        $stmt->close();
        json_error('ALREADY_EXISTS', 'Email already registered', null, 409);
    }
    $stmt->close();

    // Create user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $status = 'pending';
    $created_at = date('Y-m-d H:i:s');
    $initial_capital = 100000.00;

    $stmt = $mysqli->prepare("
        INSERT INTO users (name, email, password_hash, status, email_verified, verified, created_at)
        VALUES (?, ?, ?, ?, 0, 0, ?)
    ");
    $stmt->bind_param('sssss', $name, $email, $hash, $status, $created_at);

    if (!$stmt->execute()) {
        $stmt->close();
        json_error('DATABASE_ERROR', 'Database error: ' . $mysqli->error, null, 500);
    }

    $user_id = $stmt->insert_id;
    $stmt->close();

    // Add initial capital if supported
    if (column_exists($mysqli, 'users', 'trading_capital')) {
        $capStmt = $mysqli->prepare("UPDATE users SET trading_capital = ? WHERE id = ?");
        $capStmt->bind_param('di', $initial_capital, $user_id);
        $capStmt->execute();
        $capStmt->close();
    }

    if (column_exists($mysqli, 'users', 'funds_available')) {
        $faStmt = $mysqli->prepare("UPDATE users SET funds_available = ? WHERE id = ?");
        $faStmt->bind_param('di', $initial_capital, $user_id);
        $faStmt->execute();
        $faStmt->close();
    }

    // Simulate OTP verification for E2E tests
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp = date('Y-m-d H:i:s', time() + 15 * 60);

    $up = $mysqli->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE id = ?");
    $up->bind_param('ssi', $otp, $exp, $user_id);
    $up->execute();
    $up->close();

    // Mark as verified for E2E testing
    if ($isE2E) {
        $verifyStmt = $mysqli->prepare("UPDATE users SET email_verified = 1, verified = 1, status = 'active' WHERE id = ?");
        $verifyStmt->bind_param('i', $user_id);
        $verifyStmt->execute();
        $verifyStmt->close();
    }

    json_success([
        'user_id' => $user_id,
        'email' => $email,
        'name' => $name,
        'status' => $isE2E ? 'active' : 'pending',
        'verified' => $isE2E
    ], $isE2E ? 'User created and verified for E2E testing' : 'User created - email verification required', null, 201);

} catch (Exception $e) {
    error_log("Registration API error: " . $e->getMessage());
    json_error('SERVER_ERROR', 'Registration failed', null, 500);
}

/**
 * Helper functions
 */
function column_exists(mysqli $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) { $cache[$key] = false; return false; }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    $cache[$key] = $exists;
    return $exists;
}
?>