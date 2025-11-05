<?php
// includes/guard.php
// Auth + access checks. Auth functions moved to bootstrap.php.
// This file now only contains page-specific public/private logic.

/**
 * Public pages jahan guard redirect nahi karega.
 * File names lower-case hi rakho.
 */
if (!function_exists('guard_is_public')) {
    function guard_is_public(): bool {
        static $PUBLIC = [
            'index.php',
            'login.php',
            'register.php',
            'forgot_password.php',
            'verify_profile.php',
            'resend_verification.php',
            'pending_approval.php',
            // diagnostics (keep disabled in prod; only enable while debugging):
            // 'check.php','check1.php','check_bare.php','login_probe.php','dashboard_probe.php',
        ];
        $cur = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
        return in_array($cur, $PUBLIC, true);
    }
}

/**
 * Enhanced require_login with public page check.
 * Uses bootstrap.php version but with public page exemption.
 */
if (!function_exists('require_login_guarded')) {
    function require_login_guarded(): void {
        if (guard_is_public()) return; // public pages: no-op
        require_login(); // Use bootstrap.php version
    }
}

/**
 * Enhanced require_active_user with public page check and admins bypass.
 * Uses bootstrap.php version but with public page exemption.
 */
if (!function_exists('require_active_user_guarded')) {
    function require_active_user_guarded(bool $adminsBypass = true): void {
        if (guard_is_public()) return; // public pages: no-op

        $is_admin = (int)($_SESSION['is_admin'] ?? 0);
        if ($adminsBypass && $is_admin === 1) {
            return; // admins bypass
        }

        // Use bootstrap.php version
        require_active_user();
    }
}