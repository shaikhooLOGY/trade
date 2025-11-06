<?php
/**
 * Admin API Bootstrap
 * Admin-specific initialization and security checks
 * Phase-3 Litmus Auto-Fix Pack: Ensures proper loading of core components
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/http/json.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';
require_once __DIR__ . '/../../includes/logger/audit_log.php';

// Admin session check
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== 1) {
    json_error("UNAUTHORIZED", "Login required", null, 401);
}