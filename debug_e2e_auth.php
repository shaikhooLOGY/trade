<?php
/**
 * E2E Authentication Debug Test
 * Tests the login flow and session maintenance
 */

$baseUrl = 'http://127.0.0.1:8082';
$testEmail = 'e2e_test_working@local.test';
$testPassword = 'Test@12345';

echo "=== E2E Authentication Debug Test ===\n\n";

// Step 1: Register test user
echo "1. Registering test user...\n";
$registerData = [
    'name' => 'E2E Debug User',
    'email' => $testEmail,
    'password' => $testPassword
];

$ch = curl_init($baseUrl . '/api/auth/register_simple.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($registerData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Requested-With: XMLHttpRequest',
    'User-Agent: E2E-Debug/1.0'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   Register response: $httpCode\n";
echo "   Response: " . substr($response, 0, 200) . "...\n\n";

// Step 2: Verify email (simulate)
echo "2. Simulating email verification...\n";
require_once __DIR__ . '/config.php';
$stmt = $mysqli->prepare("UPDATE users SET email_verified = 1, verified = 1, status = 'active' WHERE email = ?");
$stmt->bind_param('s', $testEmail);
$stmt->execute();
$stmt->close();
echo "   Email verified in database\n\n";

// Step 3: Login and capture cookies
echo "3. Logging in and capturing session cookies...\n";
$loginData = http_build_query([
    'email' => $testEmail,
    'password' => $testPassword,
    'csrf' => 'test'
]);

$ch = curl_init($baseUrl . '/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Requested-With: XMLHttpRequest',
    'User-Agent: E2E-Debug/1.0'
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/e2e_cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/e2e_cookies.txt');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   Login response: $httpCode\n";
echo "   Final URL: " . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . "\n";
echo "   Response: " . substr($response, 0, 200) . "...\n\n";

// Step 4: Test API call with cookies
echo "4. Testing API call with session cookies...\n";
$ch = curl_init($baseUrl . '/api/profile/me.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Requested-With: XMLHttpRequest',
    'User-Agent: E2E-Debug/1.0'
]);
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/e2e_cookies.txt');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   API response: $httpCode\n";
echo "   Response: " . $response . "\n\n";

// Step 5: Test trade creation with session
echo "5. Testing trade creation with session...\n";
$tradeData = [
    'symbol' => 'TEST',
    'side' => 'buy',
    'quantity' => 1,
    'price' => 100.0,
    'opened_at' => date('Y-m-d H:i:s'),
    'notes' => 'Debug test trade'
];

$ch = curl_init($baseUrl . '/api/trades/create.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($tradeData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Requested-With: XMLHttpRequest',
    'User-Agent: E2E-Debug/1.0',
    'X-CSRF-Token: test'
]);
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/e2e_cookies.txt');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   Trade create response: $httpCode\n";
echo "   Response: " . $response . "\n\n";

echo "=== Debug Test Complete ===\n";
?>