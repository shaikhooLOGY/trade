<?php
// includes/guard.php
// Auth + access checks. Bootstrap loads this file.

// Make sure session exists (defensive; bootstrap already starts)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
 * Must be logged in, else redirect to login.
 */
if (!function_exists('require_login')) {
    function require_login(): void {
        if (guard_is_public()) return; // public pages: no-op
        if (empty($_SESSION['user_id'])) {
            header('Location: /login.php');
            exit;
        }
    }
}

/**
 * Must be verified + approved (non-admin). Admins bypass by default.
 */
if (!function_exists('require_active_user')) {
    function require_active_user(bool $adminsBypass = true): void {
        if (guard_is_public()) return; // public pages: no-op

        $is_admin       = (int)($_SESSION['is_admin'] ?? 0);
        $email_verified = (int)($_SESSION['email_verified'] ?? 0);
        $status         = strtolower((string)($_SESSION['status'] ?? ''));

        if ($adminsBypass && $is_admin === 1) {
            return;
        }

        if ($email_verified !== 1) {
            $email = $_SESSION['email'] ?? '';
            $to = '/verify_profile.php';
            if ($email !== '') $to .= '?email=' . urlencode($email);
            header('Location: ' . $to);
            exit;
        }

        if (!in_array($status, ['active', 'approved'], true)) {
            header('Location: /pending_approval.php');
            exit;
        }
    }
}