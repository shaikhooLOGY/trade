<?php
if (!defined('APP_ENV')) {
  define('APP_ENV', $_SERVER['APP_ENV'] ?? 'local'); // local|staging|prod
}
if (!function_exists('db_assert_database')) {
  function db_assert_database(mysqli $m, string $expected, bool $strict = false): void {
    $res = $m->query('SELECT DATABASE() db'); $db = $res ? ($res->fetch_assoc()['db'] ?? '') : '';
    if ($strict && $db !== $expected) { die('DB mismatch: connected=' . $db . ' expected=' . $expected); }
  }
}