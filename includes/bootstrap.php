<?php
/**
 * includes/bootstrap.php
 * - Start a hardened PHP session (once)
 * - Set security headers
 * - Provide CSRF + flash helpers
 * - Provide auth guards: require_login, require_active_user, require_admin
 *
 * Assumes:
 *   - includes/env.php (defines APP_ENV and loads .env) is required before this file
 *   - config.php (creates $mysqli) is required before this file
 */

// ---------- Security headers (safe on all pages) ----------
if (!headers_sent()) {
  header_remove('X-Powered-By');
  header('X-Frame-Options: SAMEORIGIN');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('X-XSS-Protection: 0'); // modern browsers use CSP
  // Light CSP that doesn’t break forms/images; extend as needed.
  header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https: data:; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:;");
}

// ---------- Session (exactly once; ini BEFORE start) ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
  // Only set cookie_secure if HTTPS on (most Hostinger prod is HTTPS)
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    @ini_set('session.cookie_secure', 1);
  }
  @ini_set('session.cookie_httponly', 1);
  @ini_set('session.use_strict_mode', 1);
  @ini_set('session.use_only_cookies', 1);

  // Shorter lifetime for prod; longer for local
  $lifetime = (defined('APP_ENV') && APP_ENV === 'local') ? 60*60*12 : 60*60*2; // 12h local, 2h prod
  @ini_set('session.gc_maxlifetime', $lifetime);
  session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

// ---------- CSRF token ----------
if (empty($_SESSION['csrf'])) {
  try { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable $e) { $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}

if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    $t = htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf" value="'.$t.'">';
  }
}

if (!function_exists('csrf_verify')) {
  function csrf_verify(): void {
    $sent = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent)) {
      http_response_code(400);
      exit('Bad CSRF token');
    }
  }
}

// ---------- Flash helpers (string or keyed) ----------
if (!function_exists('flash_set')) {
  function flash_set(string $msg, string $type='success'): void {
    $_SESSION['flash'] = ['msg'=>$msg,'type'=>$type];
  }
}
if (!function_exists('flash_error')) {
  function flash_error(string $msg): void { flash_set($msg,'danger'); }
}
if (!function_exists('flash_out')) {
  function flash_out(): void {
    if (empty($_SESSION['flash'])) return;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $msg  = htmlspecialchars(is_array($f)?($f['msg']??''):("$f"), ENT_QUOTES, 'UTF-8');
    $type = is_array($f)?($f['type']??'success'):'success';
    $bg   = $type==='danger' ? '#fee2e2' : '#dcfce7';
    $bd   = $type==='danger' ? '#991b1b' : '#14532d';
    $fg   = $bd;
    echo '<div style="background:'.$bg.';border:1px solid '.$bd.';color:'.$fg.';padding:12px;text-align:center;font-weight:600;margin:0;">'.$msg.'</div>';
  }
}

// ---------- Auth guards ----------
if (!function_exists('require_login')) {
  function require_login(): void {
    if (empty($_SESSION['user_id'])) {
      header('Location: /login.php');
      exit;
    }
  }
}

if (!function_exists('require_active_user')) {
  function require_active_user(): void {
    $status = strtolower((string)($_SESSION['status'] ?? ''));
    $emailV = (int)($_SESSION['email_verified'] ?? 0);
    if (!in_array($status, ['active','approved'], true) || $emailV !== 1) {
      header('Location: /pending_approval.php');
      exit;
    }
  }
}

if (!function_exists('require_admin')) {
  function require_admin(): void {
    if (empty($_SESSION['is_admin'])) {
      http_response_code(302);
      header('Location: /login.php');
      exit;
    }
  }
}

// ---------- Small DB helpers ----------
if (!function_exists('db_ok')) {
  function db_ok(mysqli $m): bool {
    try { return $m->ping(); } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}