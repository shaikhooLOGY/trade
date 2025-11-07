// 🔁 API Integration: replaced direct DB with /api/admin/users/update.php
<?php
// admin/user_action.php — centralized admin user actions (API Integrated)
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['is_admin'])) {
  header('HTTP/1.1 403 Forbidden');
  exit('Access denied');
}

function back($msg) {
  $_SESSION['flash'] = $msg;
  header('Location: users.php');
  exit;
}

function callAdminApi($endpoint, $data = []) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? '',
                'X-Requested-With: XMLHttpRequest'
            ],
            'content' => http_build_query($data),
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($endpoint, false, $context);
    if ($response === false) {
        return ['error' => 'Failed to connect to API'];
    }
    
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON response'];
    }
    
    return $result;
}

$id   = (int)($_POST['id'] ?? 0);
$act  = trim($_POST['action'] ?? '');
$csrf = $_POST['csrf'] ?? '';

if ($id <= 0 || $csrf === '' || !validate_csrf($csrf)) {
  back('❌ Invalid request.');
}

// 🔁 API Integration: All user management now handled by /api/admin/users/update.php
$postData = [
    'id' => $id,
    'action' => $act,
    'csrf' => $csrf,
    'reason' => trim($_POST['reason'] ?? '')
];

// Add detailed field data for send_back_detail action
if ($act === 'send_back_detail') {
    $pfFile = __DIR__ . '/../profile_fields.php';
    $profile_fields = file_exists($pfFile) ? include $pfFile : [];
    
    foreach ($profile_fields as $field => $cfg) {
        $postData['ok_'.$field] = !empty($_POST['ok_'.$field]) ? '1' : '0';
        $postData['comment_'.$field] = trim((string)($_POST['comment_'.$field] ?? ''));
    }
}

// Make API call
$result = callAdminApi('/api/admin/users/update.php', $postData);

if (isset($result['success']) && $result['success']) {
    back($result['message'] ?? 'Action completed successfully.');
} else {
    $errorMsg = $result['message'] ?? 'Unknown error';
    if (strpos($errorMsg, 'CSRF') !== false) {
        back('❌ Invalid security token.');
    } elseif (strpos($errorMsg, 'VALIDATION') !== false) {
        back('❌ ' . $errorMsg);
    } else {
        back('❌ ' . $errorMsg);
    }
}