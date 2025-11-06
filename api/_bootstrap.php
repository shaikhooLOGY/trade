<?php
// Unified API Bootstrap - Core Refactor Pack Implementation
// All API endpoints now use the unified core bootstrap

// Load the unified core bootstrap
require_once __DIR__ . '/../core/bootstrap.php';

// Ensure JSON content type is set for API responses
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Log API request start
if (function_exists('app_log')) {
    app_log('info', sprintf(
        'API Request: %s %s from %s (User: %s)',
        $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        $_SERVER['REQUEST_URI'] ?? 'unknown',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SESSION['user_id'] ?? 'anonymous'
    ));
}