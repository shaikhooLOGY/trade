<?php
/**
 * Admin API Bootstrap
 * Admin-specific initialization and security checks
 * Phase-3 Compliance Implementation: Standardized auth guard
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/http/json.php';
require_once __DIR__ . '/../../includes/security/ratelimit.php';
require_once __DIR__ . '/../../includes/logger/audit_log.php';
require_once __DIR__ . '/../../includes/security/auth.php';

// NOTE: Individual endpoints should call require_admin_auth_json()
// This ensures proper 401/403 separation for unauthenticated vs non-admin users