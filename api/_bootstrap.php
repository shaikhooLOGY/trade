<?php
// Common API Bootstrap for all API endpoints
// Phase-3 Production Readiness Implementation

// Bootstrap chain - must be loaded first for every API file
require_once __DIR__ . '/../includes/bootstrap.php';

// Security infrastructure
require_once __DIR__ . '/../includes/security/csrf_unify.php';
require_once __DIR__ . '/../includes/security/ratelimit.php';
require_once __DIR__ . '/../includes/guard.php';

// Load API helper functions
require_once __DIR__ . '/../includes/http/json.php';
require_once __DIR__ . '/../includes/mtm/mtm_service.php';
require_once __DIR__ . '/../includes/mtm/mtm_validation.php';

// Initialize idempotency support if not already done
if (!function_exists('get_idempotency_key')) {
    function get_idempotency_key(): ?string {
        $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? null;
        return $key && strlen($key) >= 10 ? $key : null;
    }
    
    function process_idempotency_request(string $key, callable $handler): void {
        global $mysqli;
        
        // Check if this idempotency key was used before
        $stmt = $mysqli->prepare("SELECT response_data, status_code FROM idempotency_keys WHERE key_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        if ($stmt) {
            $keyHash = hash('sha256', $key);
            $stmt->bind_param('s', $keyHash);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result->fetch_assoc();
            $stmt->close();
            
            if ($existing) {
                // Return cached response
                header('X-Idempotency-Replayed: true');
                http_response_code((int)$existing['status_code']);
                echo $existing['response_data'];
                exit;
            }
        }
        
        // Process the actual request and cache the response
        ob_start();
        try {
            $handler();
            $response = ob_get_clean();
            $statusCode = http_response_code();
            
            // Cache the response
            if ($stmt) {
                $stmt = $mysqli->prepare("INSERT INTO idempotency_keys (key_hash, request_hash, response_data, status_code, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE response_data = VALUES(response_data), status_code = VALUES(status_code), created_at = NOW()");
                if ($stmt) {
                    $requestHash = hash('sha256', file_get_contents('php://input') . $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI']);
                    $stmt->bind_param('sssi', $keyHash, $requestHash, $response, $statusCode);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            echo $response;
        } catch (Exception $e) {
            ob_end_clean();
            throw $e;
        }
    }
}