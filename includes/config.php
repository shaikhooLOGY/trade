<?php
/**
 * Shim: old includes/config.php
 * Always delegate to top-level config.php to avoid prod/local mismatch.
 */
require_once __DIR__ . '/../config.php';
return;
