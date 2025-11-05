<?php
/**
 * includes/env.php
 * Robust environment + .env loader for Shaikhoology platform
 * Safely loads APP_ENV and DB credentials for any environment (local / prod)
 */

if (!defined('APP_ENV')) {
    $appEnv = 'local'; // default if .env missing

    $envFile = __DIR__ . '/.env';
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
 * Make DB credentials globally accessible
 */
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $key) {
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