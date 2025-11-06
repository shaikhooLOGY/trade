<?php
include 'includes/env.php';

echo "=== Environment Variable Test ===\n";
echo "APP_ENV (defined constant): " . (defined('APP_ENV') ? APP_ENV : 'undefined') . "\n";
echo "APP_ENV (getenv): " . (getenv('APP_ENV') ?: 'undefined') . "\n";
echo "APP_ENV (\$_ENV): " . ($_ENV['APP_ENV'] ?? 'undefined') . "\n";
echo "APP_ENV (\$GLOBALS): " . ($GLOBALS['APP_ENV'] ?? 'undefined') . "\n";

echo "\nAPP_FEATURE_APIS (getenv): " . (getenv('APP_FEATURE_APIS') ?: 'undefined') . "\n";
echo "APP_FEATURE_APIS (\$_ENV): " . ($_ENV['APP_FEATURE_APIS'] ?? 'undefined') . "\n";
echo "APP_FEATURE_APIS (\$GLOBALS): " . ($GLOBALS['APP_FEATURE_APIS'] ?? 'undefined') . "\n";

echo "\nBASE_URL (getenv): " . (getenv('BASE_URL') ?: 'undefined') . "\n";
echo "BASE_URL (\$_ENV): " . ($_ENV['BASE_URL'] ?? 'undefined') . "\n";
echo "BASE_URL (\$GLOBALS): " . ($GLOBALS['BASE_URL'] ?? 'undefined') . "\n";

echo "\n=== Additional env vars ===\n";
echo "DB_HOST: " . (getenv('DB_HOST') ?: 'undefined') . "\n";
echo "DB_DATABASE: " . (getenv('DB_DATABASE') ?: 'undefined') . "\n";