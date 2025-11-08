<?php
/**
 * includes/env.php
 * Production-grade environment + .env loader for Shaikhoology platform
 * Enforces ENV-first precedence, blocks fallbacks in production
 */

if (!defined('APP_ENV')) {
    $appEnv = 'local'; // default if .env missing

    $envFile = __DIR__ . '/../.env';
    if (is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments (# or ;)
            if (preg_match('/^\s*[#;]/', $line)) continue;

            // Parse key=value
            if (strpos($line, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if ($key !== '') {
                    // Remove quotes around values if present
                    $value = preg_replace('/^["\']|["\']$/', '', $value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    $GLOBALS[$key] = $value;
                }
            }
        }
        if (!empty($_ENV['APP_ENV'])) {
            $appEnv = strtolower(trim($_ENV['APP_ENV']));
        }
    }

    define('APP_ENV', $appEnv);
}

/**
 * Database configuration resolver with production safeguards
 */
if (!function_exists('db_config')) {
    function db_config(): array {
        // Priority: getenv() → $_ENV → $GLOBALS
        $host = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?: $GLOBALS['DB_HOST'] ?? null;
        $user = getenv('DB_USER') ?: $_ENV['DB_USER'] ?: $GLOBALS['DB_USER'] ?? null;
        $pass = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?: $GLOBALS['DB_PASS'] ?? null;
        $name = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?: $GLOBALS['DB_NAME'] ?? null;
        
        // Production: absolute prohibition of fallbacks
        if (APP_ENV === 'prod' || APP_ENV === 'production') {
            if (empty($host) || empty($user) || empty($pass) || empty($name)) {
                throw new Exception("Production DB configuration missing: host=$host, user=$user, pass=" . (empty($pass) ? 'MISSING' : 'SET') . ", name=$name");
            }
            if ($user === 'root' || $host === '127.0.0.1') {
                throw new Exception("Production DB configuration uses forbidden values: user=$user, host=$host");
            }
        }
        
        return [
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'name' => $name
        ];
    }
}

/**
 * Make DB credentials globally accessible (with validation)
 */
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $key) {
    if (!isset($GLOBALS[$key]) && !empty($_ENV[$key])) {
        $GLOBALS[$key] = $_ENV[$key];
    }
}

/**
 * Make SMTP credentials globally accessible
 */
foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_PASS', 'MAIL_FROM', 'MAIL_FROM_NAME'] as $key) {
    if (!isset($GLOBALS[$key]) && !empty($_ENV[$key])) {
        $GLOBALS[$key] = $_ENV[$key];
    }
}

/**
 * Helper: Ensure connected DB matches expected one (optional safety)
 */
if (!function_exists('db_assert_database')) {
    function db_assert_database(mysqli $m, string $expected, bool $strict = false): void {
        $res = @$m->query('SELECT DATABASE() db');
        $db = $res ? ($res->fetch_assoc()['db'] ?? '') : '';
        if ($strict && $db !== $expected) {
            http_response_code(500);
            die('❌ Wrong DB selected: ' . htmlspecialchars($db));
        }
    }
}