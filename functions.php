<?php
// includes/functions.php
// Common helpers for Shaikhoology (idempotent, safe to re-include)

// Session managed by bootstrap.php
require_once __DIR__ . '/includes/bootstrap.php';

/* --------------------------------
   DB handle (prefers $GLOBALS['mysqli'])
----------------------------------- */
if (!function_exists('load_db')) {
    function load_db() {
        if (!empty($GLOBALS['mysqli'])) return $GLOBALS['mysqli'];
        if (file_exists(__DIR__ . '/config.php')) {
            require_once __DIR__ . '/config.php';
        } elseif (file_exists(__DIR__ . '/../includes/config.php')) {
            require_once __DIR__ . '/../includes/config.php';
        } elseif (file_exists(__DIR__ . '/../config.php')) {
            require_once __DIR__ . '/../config.php';
        }
        return $GLOBALS['mysqli'] ?? null;
    }
}

/* --------------------------------
   Escaping / HTML helpers
----------------------------------- */
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('esc')) {
    // Backward-compat alias (some pages use esc())
    function esc($v) { return h($v); }
}

/* --------------------------------
   Redirect & flash
----------------------------------- */
if (!function_exists('redirect')) {
    function redirect(string $url) {
        header('Location: '.$url);
        exit;
    }
}
if (!function_exists('flash_set')) {
    function flash_set(string $msg) { $_SESSION['flash'] = (string)$msg; }
}
if (!function_exists('flash_get')) {
    function flash_get(): string {
        if (!empty($_SESSION['flash'])) { $m = $_SESSION['flash']; unset($_SESSION['flash']); return (string)$m; }
        return '';
    }
}

/* --------------------------------
   CSRF (Unified via csrf_unify.php)
----------------------------------- */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        // Use unified CSRF helper - require the unify file if not already loaded
        static $loaded = false;
        if (!$loaded) {
            $csrfUnifyPath = __DIR__ . '/includes/security/csrf_unify.php';
            if (file_exists($csrfUnifyPath)) {
                require_once $csrfUnifyPath;
            }
            $loaded = true;
        }
        return get_csrf_token();
    }
}
if (!function_exists('csrf_verify')) {
    function csrf_verify($token): bool {
        // Use unified CSRF helper - require the unify file if not already loaded
        static $loaded = false;
        if (!$loaded) {
            $csrfUnifyPath = __DIR__ . '/includes/security/csrf_unify.php';
            if (file_exists($csrfUnifyPath)) {
                require_once $csrfUnifyPath;
            }
            $loaded = true;
        }
        return validate_csrf($token);
    }
}

/* --------------------------------
   Validators
----------------------------------- */
if (!function_exists('is_valid_email')) {
    function is_valid_email($e): bool { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
}
if (!function_exists('is_strong_password')) {
    function is_strong_password($p): bool { return is_string($p) && strlen($p) >= 8; }
}

/* --------------------------------
   Logging (best-effort)
----------------------------------- */
if (!function_exists('app_log')) {
    function app_log($msg) {
        $line = '['.date('c').'] '.(is_scalar($msg)?$msg:json_encode($msg)).PHP_EOL;
        @file_put_contents(dirname(__DIR__).'/logs/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}

/* --------------------------------
   Session/user helpers
----------------------------------- */
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
}
if (!function_exists('current_user')) {
    function current_user(): ?array {
        if (empty($_SESSION['user_id'])) return null;
        $uid = (int)$_SESSION['user_id'];

        $fallback = [
            'id' => $uid,
            'username' => $_SESSION['username'] ?? $_SESSION['name'] ?? $_SESSION['email'] ?? 'Member',
            'email' => $_SESSION['email'] ?? null,
            'is_admin' => !empty($_SESSION['is_admin']),
            'status' => $_SESSION['status'] ?? 'active',
            'email_verified' => $_SESSION['email_verified'] ?? 0,
        ];

        $mysqli = load_db();
        if (!$mysqli) return $fallback;

        $columns = [
            'id',
            'name AS username',
            'email',
            'role',
        ];

        if (db_has_col($mysqli, 'users', 'trading_capital')) {
            $columns[] = 'trading_capital';
        }
        if (db_has_col($mysqli, 'users', 'funds_available')) {
            $columns[] = 'funds_available';
        }

        $sql = 'SELECT '.implode(', ', $columns).' FROM users WHERE id=? LIMIT 1';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) return $fallback;
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        return $row ?: $fallback;
    }
}
if (!function_exists('require_admin')) {
    function require_admin(): void {
        if (empty($_SESSION['user_id']) || (int)($_SESSION['is_admin'] ?? 0) !== 1) {
            header('Location: /login.php');
            exit;
        }
    }
}
if (!function_exists('require_login')) {
    function require_login(): void {
        if (!is_logged_in()) {
            header('Location: /login.php');
            exit;
        }
    }
}
if (!function_exists('require_active_user')) {
    function require_active_user(): void {
        if (!is_logged_in()) {
            header('Location: /login.php');
            exit;
        }
        $status = strtolower((string)($_SESSION['status'] ?? ''));
        $emailVer = (int)($_SESSION['email_verified'] ?? 0);
        if ($emailVer !== 1 || !in_array($status, ['active', 'approved'], true)) {
            header('Location: /pending_approval.php');
            exit;
        }
    }
}

// Helper to detect if a column exists in a table (cached)
if (!function_exists('db_has_col')) {
    function db_has_col(mysqli $m, string $table, string $col): bool {
        static $cache = [];
        $key = $table.'.'.$col;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $stmt = $m->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->bind_param('ss', $table, $col);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $cache[$key] = ((int)($row['cnt'] ?? 0) > 0);
        } catch (Throwable $e) { return $cache[$key] = false; }
    }
}

// Helper to detect if a table exists (cached)
if (!function_exists('db_has_table')) {
    function db_has_table(mysqli $m, string $table): bool {
        static $cache = [];
        $table = strtolower($table);
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $m->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $cache[$table] = ((int)($row['cnt'] ?? 0) > 0);
        } catch (Throwable $e) { return $cache[$table] = false; }
    }
}

// --------------------------------
// MTM tier labels
// --------------------------------
if (!isset($GLOBALS['mtm_tier_labels_cache'])) {
    $GLOBALS['mtm_tier_labels_cache'] = null;
}

if (!function_exists('mtm_default_tier_labels')) {
    function mtm_default_tier_labels(): array {
        return [
            'easy' => 'Tier 1',
            'moderate' => 'Tier 2',
            'hard' => 'Tier 3',
        ];
    }
}

if (!function_exists('mtm_ensure_tier_table')) {
    function mtm_ensure_tier_table(?mysqli $m): bool {
        if (!$m) return false;

        try {
            if (db_has_table($m, 'mtm_tier_labels')) {
                return true;
            }
        } catch (Throwable $e) {
            return false;
        }

        try {
            $m->query("CREATE TABLE IF NOT EXISTS mtm_tier_labels (
                tier_key ENUM('easy','moderate','hard') NOT NULL PRIMARY KEY,
                display_name VARCHAR(120) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if ($stmt = $m->prepare("INSERT IGNORE INTO mtm_tier_labels (tier_key, display_name) VALUES (?, ?)")) {
                foreach (mtm_default_tier_labels() as $key => $label) {
                    $tierKey = $key;
                    $display = $label;
                    $stmt->bind_param('ss', $tierKey, $display);
                    $stmt->execute();
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            return false;
        }

        try {
            return db_has_table($m, 'mtm_tier_labels');
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mtm_reset_tier_labels_cache')) {
    function mtm_reset_tier_labels_cache(): void {
        $GLOBALS['mtm_tier_labels_cache'] = null;
    }
}

if (!function_exists('mtm_get_tier_labels')) {
    function mtm_get_tier_labels(?mysqli $m): array {
        $cache = $GLOBALS['mtm_tier_labels_cache'];
        if (is_array($cache)) {
            return $cache;
        }

        $labels = mtm_default_tier_labels();
        if (!$m) {
            return $GLOBALS['mtm_tier_labels_cache'] = $labels;
        }

        if (!mtm_ensure_tier_table($m)) {
            return $GLOBALS['mtm_tier_labels_cache'] = $labels;
        }

        try {
            if ($res = $m->query("SELECT tier_key, display_name FROM mtm_tier_labels")) {
                while ($row = $res->fetch_assoc()) {
                    $key = $row['tier_key'] ?? '';
                    $value = trim((string)($row['display_name'] ?? ''));
                    if ($value !== '' && isset($labels[$key])) {
                        $labels[$key] = $value;
                    }
                }
                $res->free();
            }
        } catch (Throwable $e) {
            return $GLOBALS['mtm_tier_labels_cache'] = $labels;
        }

        return $GLOBALS['mtm_tier_labels_cache'] = $labels;
    }
}

if (!function_exists('mtm_save_tier_labels')) {
    function mtm_save_tier_labels(?mysqli $m, array $labels): bool {
        if (!$m) {
            return false;
        }

        $current = mtm_get_tier_labels($m);
        $defaults = mtm_default_tier_labels();

        try {
            if (!mtm_ensure_tier_table($m)) {
                return false;
            }

            if (!$stmt = $m->prepare("REPLACE INTO mtm_tier_labels (tier_key, display_name) VALUES (?, ?)")) {
                return false;
            }

            foreach (['easy','moderate','hard'] as $tierKey) {
                if (!array_key_exists($tierKey, $labels)) {
                    continue;
                }
                $value = trim((string)$labels[$tierKey]);
                if ($value === '') {
                    $value = $defaults[$tierKey];
                }
                $stmt->bind_param('ss', $tierKey, $value);
                $stmt->execute();
                $current[$tierKey] = $value;
            }

            $stmt->close();
            mtm_reset_tier_labels_cache();
            mtm_get_tier_labels($m); // refresh cache
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mtm_format_tier_label')) {
    function mtm_format_tier_label(array $labels, string $difficulty): string {
        $defaults = mtm_default_tier_labels();
        $difficulty = strtolower($difficulty);
        $base = $defaults[$difficulty] ?? ucfirst($difficulty);
        $custom = trim((string)($labels[$difficulty] ?? ''));
        if ($custom === '' || strcasecmp($custom, $base) === 0) {
            return $base;
        }
        return $base . ' — ' . $custom;
    }
}


// --------------------------------
// Funds
// --------------------------------
if (!function_exists('get_user_reserved_amount')) {
    function get_user_reserved_amount($uid): float {
        $mysqli = load_db(); if (!$mysqli) return 0.0;
        $uid = (int)$uid;
        $has_alloc = db_has_col($mysqli, 'trades', 'allocation_amount');
        if (!$has_alloc) return 0.0;
        $has_closed_at = db_has_col($mysqli, 'trades', 'closed_at');
        $has_close_date = db_has_col($mysqli, 'trades', 'close_date');
        $has_deleted_at = db_has_col($mysqli, 'trades', 'deleted_at');
        $has_outcome = db_has_col($mysqli, 'trades', 'outcome');
        $openConds = [];
        if ($has_closed_at) $openConds[] = "(closed_at IS NULL OR closed_at='')";
        elseif ($has_close_date) $openConds[] = 'close_date IS NULL';
        if ($has_outcome) $openConds[] = "UPPER(COALESCE(outcome,'OPEN'))='OPEN'";
        if (empty($openConds)) $openConds[] = '1=1';
        $where = 'user_id=? AND ('.implode(' OR ', $openConds).')';
        if ($has_deleted_at) $where .= " AND (deleted_at IS NULL OR deleted_at='')";
        $sql = "SELECT COALESCE(SUM(allocation_amount),0) AS reserved FROM trades WHERE $where";
        $stmt = $mysqli->prepare($sql); if (!$stmt) return 0.0;
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return isset($row['reserved']) ? (float)$row['reserved'] : 0.0;
    }
}
if (!function_exists('get_user_available_funds')) {
    function get_user_available_funds($uid): float {
        $mysqli = load_db(); if (!$mysqli) return 0.0;
        $uid = (int)$uid;
        $has_fa = db_has_col($mysqli, 'users', 'funds_available');
        $has_tc = db_has_col($mysqli, 'users', 'trading_capital');
        $field = $has_fa ? 'funds_available' : ($has_tc ? 'trading_capital' : null);
        if ($field === null) return 0.0;
        $sql = "SELECT $field AS amount FROM users WHERE id=?";
        $stmt = $mysqli->prepare($sql); if (!$stmt) return 0.0;
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $funds = isset($row['amount']) ? (float)$row['amount'] : 0.0;
        $reserved = get_user_reserved_amount($uid);
        $avail = $funds - $reserved;
        return $avail >= 0 ? $avail : 0.0;
    }
}
if (!function_exists('get_user_capital')) {
    function get_user_capital($uid): float {
        $mysqli = load_db(); if (!$mysqli) return 100000.0;
        $uid = (int)$uid;
        $has_tc = db_has_col($mysqli, 'users', 'trading_capital');
        $has_fa = db_has_col($mysqli, 'users', 'funds_available');
        if (!$has_tc && !$has_fa) return 100000.0;
        $fields = [];
        if ($has_tc) $fields[] = 'trading_capital';
        if ($has_fa) $fields[] = 'funds_available';
        $sql = 'SELECT '.implode(',', $fields).' FROM users WHERE id=?';
        $stmt = $mysqli->prepare($sql); if (!$stmt) return 100000.0;
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $tc = $has_tc ? (float)($row['trading_capital'] ?? 0) : 0.0;
        $fa = $has_fa ? (float)($row['funds_available'] ?? 0) : 0.0;
        if ($has_tc && $tc > 0) return $tc;
        if ($has_fa) return $fa;
        return 100000.0;
    }
}

/* --------------------------------
   Points (single definition)
----------------------------------- */
if (!function_exists('compute_points_from_trade')) {
    function compute_points_from_trade(array $t): int {
        $pl = isset($t['pl_percent']) && $t['pl_percent'] !== '' ? (float)$t['pl_percent'] : null;
        $outcome = isset($t['outcome']) ? strtoupper(trim($t['outcome'])) : 'OPEN';
        $rr = isset($t['rr']) && $t['rr'] !== '' ? (float)$t['rr'] : null;
        $analysis = !empty($t['analysis_link']);

        if ($outcome === 'OPEN') return 0;

        $base = $pl !== null ? (int) round($pl) : 0;
        if ($base === 0 && $pl !== null) {
            if ($pl > 0.2) $base = 1;
            if ($pl < -0.2) $base = -1;
        }

        $bonus = 0;
        if ($base > 0 && $rr !== null) {
            if     ($rr >= 5)   $bonus += 5;
            elseif ($rr >= 3)   $bonus += 3;
            elseif ($rr >= 2)   $bonus += 2;
            elseif ($rr >= 1.5) $bonus += 1;
        }
        if ($analysis) $bonus += 1;

        $points = $base + $bonus;
        if ($points > 100) $points = 100;
        if ($points < -100) $points = -100;
        return (int)$points;
    }
}
