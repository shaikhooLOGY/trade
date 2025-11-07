<?php
/**
 * profile_update.php
 * 
 * Profile Update Handler - API Integration Endpoint
 * Handles profile update form processing via API endpoints
 */

// Enable CORS for API calls
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Include bootstrap
    require_once __DIR__ . '/includes/bootstrap.php';
    
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit();
    }
    
    // Get form data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST; // Fallback to form data
    }
    
    // Extract allowed fields
    $allowedFields = ['name', 'phone', 'timezone'];
    $updateData = [];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateData[$field] = trim($input[$field]);
        }
    }
    
    if (empty($updateData)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid fields provided for update']);
        exit();
    }
    
    // 🔁 API Integration: Forward to profile update API
    $api_url = __DIR__ . '/api/profile/update.php';
    
    // Prepare the API request
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? ''
            ],
            'content' => json_encode($updateData)
        ]
    ]);
    
    // Make the API call
    $response = @file_get_contents($api_url, false, $context);
    
    if ($response === false) {
        throw new Exception('Failed to connect to profile API');
    }
    
    // Return the API response
    header('Content-Type: application/json');
    echo $response;
    
} catch (Exception $e) {
    // Log error
    error_log('Profile update error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Profile update failed: ' . $e->getMessage()
    ]);
}
?>