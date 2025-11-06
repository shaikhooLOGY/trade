<?php
/**
 * includes/bootstrap.php
 * - DEPRECATED: Legacy Bootstrap Compatibility Layer
 * - This file now redirects to the unified core bootstrap
 * - Maintained for backward compatibility with existing code
 * - All new code should use: require_once __DIR__ . '/../core/bootstrap.php';
 */

// Redirect to unified core bootstrap
require_once __DIR__ . '/../core/bootstrap.php';

// Legacy compatibility functions that existing code might still depend on
if (!function_exists('db_ok')) {
    function db_ok(mysqli $m): bool {
        try {
            return $m->stat() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
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

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// Legacy compatibility aliases
if (!function_exists('legacy_require_login')) {
    function legacy_require_login(): void {
        require_login();
    }
}

if (!function_exists('legacy_require_admin')) {
    function legacy_require_admin(): void {
        require_admin();
    }
}