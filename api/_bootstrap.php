<?php
// Common API Bootstrap for all API endpoints
// Phase-3 Pre-Fix Pack implementation

// Bootstrap chain - must be loaded first for every API file
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/security/csrf_unify.php';

// Load API helper functions
require_once __DIR__ . '/../includes/http/json.php';
require_once __DIR__ . '/../includes/mtm/mtm_service.php';
require_once __DIR__ . '/../includes/mtm/mtm_validation.php';

// JSON response helpers are loaded from includes/http/json.php

// CSRF token is now handled by the unified shim
// No redundant initialization needed - csrf_unify.php handles this