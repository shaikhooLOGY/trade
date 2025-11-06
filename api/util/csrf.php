<?php
/**
 * api/util/csrf.php
 *
 * CSRF Utility API - Get CSRF token
 * GET /api/util/csrf.php
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

// Require authentication
require_login_json();

// Check CSRF for API endpoints
csrf_api_middleware();

// Return CSRF token using the unified shim
json_ok(['csrf' => get_csrf_token()], 'CSRF token retrieved successfully');