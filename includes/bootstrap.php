<?php
/**
 * includes/bootstrap.php
 * - Main Bootstrap - Single Source of Truth
 * - Phase-3 Pre-Fix Pack implementation
 * - Start a hardened PHP session (once)
 * - Set security headers
 * - Provide CSRF + flash helpers
 * - Provide auth guards: require_login, require_active_user, require_admin
 */

// Load core dependencies in consistent order
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security/csrf_unify.php';

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

// ---------- Session management - start exactly once ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
  // Environment-based session configuration
  $appEnv = getenv('APP_ENV') ?: 'local';
  $isProduction = ($appEnv === 'prod' || $appEnv === 'production');
  
  // Cookie security flags based on environment
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || $isProduction;
  $httponly = getenv('SESSION_HTTPONLY') ?: true;
  $samesite = getenv('SESSION_SAMESITE') ?: 'Lax';
  
  // Configure session settings
  @ini_set('session.cookie_secure', $secure ? 1 : 0);
  @ini_set('session.cookie_httponly', $httponly ? 1 : 0);
  @ini_set('session.use_strict_mode', 1);
  @ini_set('session.use_only_cookies', 1);
  @ini_set('session.cookie_samesite', $samesite);

  // Shorter lifetime for prod; longer for local
  $lifetime = $isProduction ? 60*60*2 : 60*60*12; // 2h prod, 12h local
  @ini_set('session.gc_maxlifetime', $lifetime);
  
  session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => $httponly,
    'samesite' => $samesite,
  ]);

  session_start();
  
  // Regenerate session ID periodically for security
  if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
  } elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
  }
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

// Include CSRF unification shim for standardized token handling
require_once __DIR__ . '/security/csrf_unify.php';

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
    try {
      // Using mysqli::stat() as modern replacement for deprecated ping()
      return $m->stat() !== false;
    } catch (Throwable $e) {
      return false;
    }
  }
}
// ---------- Enhanced Security Functions ----------
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('session_regenerate_secure')) {
  /**
   * Regenerate session ID securely with optional data cleanup
   */
  function session_regenerate_secure(bool $deleteOld = true): bool {
    // Clear sensitive data before regeneration
    if ($deleteOld) {
      // Keep only essential session data
      $essentialData = [
        'user_id' => $_SESSION['user_id'] ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? 0,
        'status' => $_SESSION['status'] ?? '',
        'email_verified' => $_SESSION['email_verified'] ?? 0,
      ];
      $_SESSION = $essentialData;
    }
    
    $result = session_regenerate_id($deleteOld);
    
    // Update regeneration timestamp
    $_SESSION['last_regeneration'] = time();
    
    return $result;
  }
}

if (!function_exists('app_log')) {
  /**
   * Enhanced app_log with PII masking
   */
  function app_log(string $level, string $message): void {
    // Mask PII in the message
    $maskedMessage = mask_pii_in_logs($message);
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $maskedMessage" . PHP_EOL;
    
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
      mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/app.log';
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
  }
}

if (!function_exists('mask_pii_in_logs')) {
  /**
   * Mask PII in log messages for privacy protection
   */
  function mask_pii_in_logs(string $message): string {
    // Mask email addresses
    $message = preg_replace_callback(
      '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
      function($matches) {
        $email = $matches[0];
        $parts = explode('@', $email);
        $local = $parts[0];
        $domain = $parts[1];
        
        // Keep first and last character of local part
        if (strlen($local) > 2) {
          $maskedLocal = substr($local, 0, 1) . '***' . substr($local, -1);
        } else {
          $maskedLocal = substr($local, 0, 1) . '***';
        }
        
        return $maskedLocal . '@' . $domain;
      },
      $message
    );
    
    // Mask potential IP addresses (but keep legitimate ones for security)
    // Only mask obvious private/local IP patterns
    $message = preg_replace(
      '/\b(127\.0\.0\.1|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)\b/',
      '***.***.***.***',
      $message
    );
    
    return $message;
  }
}

if (!function_exists('login_success_audit')) {
  /**
   * Audit successful login with session regeneration
   */
  function login_success_audit(int $userId, string $email = ''): void {
    // Regenerate session ID on successful login
    session_regenerate_secure(true);
    
    // Log successful login
    $auditData = [
      'event_type' => 'login_success',
      'user_id' => $userId,
      'email_hash' => hash('sha256', $email),
      'timestamp' => date('c'),
      'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
      'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
      'session_id' => session_id()
    ];
    
    app_log('audit', json_encode($auditData));
  }
}