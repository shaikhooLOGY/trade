<?php
// Test registration endpoint directly

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8082/register.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'E2E-Debug/1.0');

// First, get the CSRF token
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

echo "GET registration page:\n";
echo "Status: $httpCode\n";
echo "Body length: " . strlen($body) . "\n";
echo "First 500 chars:\n";
echo substr($body, 0, 500) . "\n\n";

// Extract CSRF token
$csrfToken = null;
if (preg_match('/name="csrf".*?value="([^"]+)"/', $body, $matches)) {
    $csrfToken = $matches[1];
    echo "CSRF Token: " . $csrfToken . "\n\n";
} else {
    echo "No CSRF token found!\n\n";
}

// Now try registration
if ($csrfToken) {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'name' => 'Test User',
        'email' => 'test_e2e_debug@local.test',
        'password' => 'Test@12345',
        'confirm' => 'Test@12345',
        'csrf' => $csrfToken
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'X-Requested-With: XMLHttpRequest'
    ]);
    
    $response2 = curl_exec($ch);
    $httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize2 = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    $headers2 = substr($response2, 0, $headerSize2);
    $body2 = substr($response2, $headerSize2);
    
    echo "POST registration:\n";
    echo "Status: $httpCode2\n";
    echo "Body length: " . strlen($body2) . "\n";
    echo "Headers:\n";
    echo $headers2 . "\n";
    echo "Body:\n";
    echo $body2 . "\n";
}

curl_close($ch);
?>