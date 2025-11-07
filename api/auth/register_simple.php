<?php
/**
 * Simple Registration API for E2E Testing
 * Minimal dependencies, no audit logging
 */

// Basic environment setup
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/bootstrap.php';

// E2E bypass for testing - check headers and request characteristics
$isE2E = (
    getenv('ALLOW_CSRF_BYPASS') === '1' ||
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' ||
    strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'E2E') !== false
);

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');

    // Basic validation
    if (empty($name) || empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'All fields are required']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email format']);
        exit;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        exit;
    }

    // Check if user already exists
    $stmt = $GLOBALS['mysqli']->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->fetch_assoc()) {
        $stmt->close();
        http_response_code(409);
        echo json_encode(['error' => 'Email already registered']);
        exit;
    }
    $stmt->close();

    // Create user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $status = 'pending';
    $created_at = date('Y-m-d H:i:s');
    $initial_capital = 100000.00;

    $stmt = $GLOBALS['mysqli']->prepare("
        INSERT INTO users (name, email, password, password_hash, status, email_verified, verified, created_at)
        VALUES (?, ?, ?, ?, ?, 0, 0, ?)
    ");
    $stmt->bind_param('ssssss', $name, $email, $password, $hash, $status, $created_at);

    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $GLOBALS['mysqli']->error]);
        exit;
    }

    $user_id = $stmt->insert_id;
    $stmt->close();

    // Add initial capital if supported (basic check)
    $columns = $GLOBALS['mysqli']->query("SHOW COLUMNS FROM users");
    $columnNames = [];
    while ($col = $columns->fetch_assoc()) {
        $columnNames[] = $col['Field'];
    }

    if (in_array('trading_capital', $columnNames)) {
        $capStmt = $GLOBALS['mysqli']->prepare("UPDATE users SET trading_capital = ? WHERE id = ?");
        $capStmt->bind_param('di', $initial_capital, $user_id);
        $capStmt->execute();
        $capStmt->close();
    }

    if (in_array('funds_available', $columnNames)) {
        $faStmt = $GLOBALS['mysqli']->prepare("UPDATE users SET funds_available = ? WHERE id = ?");
        $faStmt->bind_param('di', $initial_capital, $user_id);
        $faStmt->execute();
        $faStmt->close();
    }

    // Mark as verified for E2E testing
    if ($isE2E) {
        $verifyStmt = $GLOBALS['mysqli']->prepare("UPDATE users SET email_verified = 1, verified = 1, status = 'active' WHERE id = ?");
        $verifyStmt->bind_param('i', $user_id);
        if (!$verifyStmt->execute()) {
            error_log("Failed to verify E2E user: " . $verifyStmt->error);
        }
        $verifyStmt->close();
    }

    // Success response
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'user_id' => $user_id,
        'email' => $email,
        'name' => $name,
        'status' => $isE2E ? 'active' : 'pending',
        'verified' => $isE2E
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
}
?>