<?php
// guard.php — gatekeeper for verified & approved users (with safe public-page bypass)
if (session_status() === PHP_SESSION_NONE) session_start();

/*
|--------------------------------------------------------------------------
| 0) BYPASS on public/open pages (guard is NO-OP if included there)
|--------------------------------------------------------------------------
*/
$PUBLIC_SCRIPTS = [
    'register.php',
    'login.php',
    'forgot_password.php',
    'verify_profile.php',
    'pending_approval.php',
];
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (in_array($currentScript, $PUBLIC_SCRIPTS, true)) {
    return; // do nothing on public pages
}

/*
|--------------------------------------------------------------------------
| 1) Must be authenticated
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 2) Rules / knobs
|--------------------------------------------------------------------------
| If true, admins can access even if not yet approved or not email-verified.
| Set to false if you want admins to also pass full checks.
*/
$ALLOW_ADMINS_WITHOUT_APPROVAL = true;

/*
|--------------------------------------------------------------------------
| 3) Normalize session flags
|--------------------------------------------------------------------------
*/
$status         = strtolower((string)($_SESSION['status'] ?? ''));       // 'active'|'approved'|'pending'|'rejected'|'unverified'|'needs_update'...
$email_verified = (int)($_SESSION['email_verified'] ?? 0);               // 0/1
$is_admin       = (int)($_SESSION['is_admin'] ?? 0);                     // 0/1
$email          = (string)($_SESSION['email'] ?? '');

/*
|--------------------------------------------------------------------------
| 4) Admin bypass (optional)
|--------------------------------------------------------------------------
*/
$adminBypass = ($ALLOW_ADMINS_WITHOUT_APPROVAL && $is_admin === 1);

/*
|--------------------------------------------------------------------------
| 5) Enforce email verification first (non-admins)
|--------------------------------------------------------------------------
*/
if (!$adminBypass && $email_verified !== 1) {
    $to = '/verify_profile.php';
    if ($email !== '') $to .= '?email=' . urlencode($email);
    header('Location: ' . $to);
    exit;
}

/*
|--------------------------------------------------------------------------
| 6) Enforce approval (non-admins): redirect to pending page
|--------------------------------------------------------------------------
*/
$approved = in_array($status, ['approved', 'active', 'enabled', 'verified'], true);
if (!$adminBypass && !$approved) {
    // Covers 'pending', 'needs_update', 'rejected', 'unverified', etc.
    header('Location: /pending_approval.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 7) If reached: access granted
|--------------------------------------------------------------------------
*/
return;