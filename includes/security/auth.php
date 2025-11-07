<?php
/**
 * includes/security/auth.php
 * Standardized Admin Authentication Guard for Shaikhoology TMS-MTM Platform
 * 
 * Provides consistent 401/403 handling for admin endpoints
 * Phase-3 Compliance Implementation
 */

/**
 * Require admin authentication and return proper HTTP status codes
 * 
 * @return array User snapshot for downstream use
 */
function require_admin_auth_json(): array {
    // 1) Not logged in → 401
    if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
        http_response_code(401);
        json_error('unauthorized', 'Authentication required.', null, 401);
    }

    // 2) Logged in but not admin → 403
    $u = $_SESSION['user'];
    $isAdmin = false;
    
    if (isset($u['role']) && $u['role'] === 'admin') {
        $isAdmin = true;
    }
    if (!$isAdmin && isset($u['is_admin']) && (int)$u['is_admin'] === 1) {
        $isAdmin = true;
    }

    if (!$isAdmin) {
        http_response_code(403);
        json_error('forbidden', 'Admin privileges required.', null, 403);
    }

    // 3) Return user snapshot for downstream use
    return $u;
}