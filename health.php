<?php
// health.php — Shaikhoology production readiness check
// ----------------------------------------------------
// Use only for diagnostics. Disable display_errors after testing.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

// --- Core includes ---
require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/config.php';

// --- Optional token gate for prod ---
if (APP_ENV !== 'local') {
    $tokenExpected = $_ENV['HEALTH_TOKEN'] ?? $_SERVER['HEALTH_TOKEN'] ?? '';
    $tokenGiven    = $_GET['token'] ?? '';
    if ($tokenExpected && !hash_equals($tokenExpected, $tokenGiven)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
}

// --- Basic info ---
echo "app_env=" . APP_ENV . "\n";
echo "php_version=" . PHP_VERSION . "\n";

// --- Database connection test ---
try {
    if (!isset($mysqli) || !$mysqli instanceof mysqli) {
        throw new Exception('mysqli not initialized');
    }
    $res = $mysqli->query('SELECT DATABASE() db, 1 as ok');
    $row = $res ? $res->fetch_assoc() : ['db' => '', 'ok' => 0];
    echo "db_selected=" . $row['db'] . "\n";
    echo "db_ping=" . ($row['ok'] ? "ok" : "fail") . "\n";
} catch (Throwable $e) {
    echo "db_error=" . $e->getMessage() . "\n";
}

// --- Required tables ---
$required = [
    'users','trades','leaderboard',
    'mtm_models','mtm_tasks','mtm_enrollments','mtm_task_progress',
    'trade_concerns'
];

$missing = [];
foreach ($required as $t) {
    $safe = $mysqli->real_escape_string($t);
    $q = $mysqli->query("SHOW TABLES LIKE '$safe'");
    if (!$q || $q->num_rows === 0) {
        $missing[] = $t;
    }
}

echo "tables_missing=" . (empty($missing) ? "none" : implode(',', $missing)) . "\n";

// --- Spot-check columns (non-fatal) ---
function colMissing(mysqli $mysqli, string $table, string $col): bool {
    $r = $mysqli->query("SELECT DATABASE() d");
    $db = $r ? $r->fetch_assoc()['d'] : '';
    $sql = "SELECT COUNT(*) c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='" . $mysqli->real_escape_string($db) . "'
              AND TABLE_NAME='" . $mysqli->real_escape_string($table) . "'
              AND COLUMN_NAME='" . $mysqli->real_escape_string($col) . "'";
    $rs = $mysqli->query($sql);
    if (!$rs) return true;
    $c = (int)$rs->fetch_assoc()['c'];
    return $c === 0;
}

$colChecks = [
    ['users','name'],
    ['users','funds_available'],
    ['users','promoted_by'],
    ['users','status'],
    ['users','email_verified'],
    ['users','is_admin'],
    ['trades','entry_date'],
    ['trades','exit_price'],
    ['trades','pnl'],
    ['mtm_enrollments','joined_at'],
    ['mtm_enrollments','completed_at'],
    ['mtm_task_progress','passed_at'],
];

$missingCols = [];
foreach ($colChecks as [$t, $c]) {
    if (colMissing($mysqli, $t, $c)) {
        $missingCols[] = "$t.$c";
    }
}

echo "columns_missing=" . (empty($missingCols) ? "none" : implode(',', $missingCols)) . "\n";

// --- Final result ---
if (empty($missing) && empty($missingCols)) {
    echo "status=ok\n";
} else {
    echo "status=incomplete\n";
}