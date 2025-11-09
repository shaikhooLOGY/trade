<?php
// includes/functions.php
// Common helpers for Shaikhoology (idempotent, safe to re-include)

if (session_status() === PHP_SESSION_NONE) {
    // bootstrap normally starts session; this is defensive
    session_start();
}

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
   CSRF
----------------------------------- */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_verify')) {
    function csrf_verify($token): bool {
        if (!isset($_SESSION['csrf']) || !is_string($token)) return false;
        return hash_equals($_SESSION['csrf'], (string)$token);
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

/* --------------------------------
   OTP System for Email Verification
----------------------------------- */

/**
 * Generate a secure 6-digit OTP
 */
if (!function_exists('otp_generate_secure_otp')) {
    function otp_generate_secure_otp(): string {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Create user_otps table if it doesn't exist
 */
if (!function_exists('otp_create_database_table')) {
    function otp_create_database_table(mysqli $mysqli): bool {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS user_otps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                otp_hash VARCHAR(255) NOT NULL COMMENT 'Hashed OTP code for security',
                expires_at DATETIME NOT NULL COMMENT 'When this OTP expires',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                verified_at DATETIME DEFAULT NULL COMMENT 'When user successfully verified this OTP',
                attempts INT NOT NULL DEFAULT 0 COMMENT 'Number of verification attempts',
                max_attempts INT NOT NULL DEFAULT 3 COMMENT 'Maximum allowed attempts before lockout',
                is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this OTP is still valid',
                email_sent_at DATETIME DEFAULT NULL COMMENT 'When OTP email was sent',
                ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address that requested OTP',
                
                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at),
                INDEX idx_user_active (user_id, is_active),
                INDEX idx_email_sent (email_sent_at),
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OTP codes for email verification'";
            
            $mysqli->query($sql);
            return true;
        } catch (Exception $e) {
            app_log("Failed to create user_otps table: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Send OTP verification email
 */
if (!function_exists('otp_send_verification_email')) {
    function otp_send_verification_email(int $user_id, string $email, string $name): bool {
        $mysqli = load_db();
        if (!$mysqli) return false;
        
        // Ensure OTP table exists
        otp_create_database_table($mysqli);
        
        // Clean up any existing active OTPs for this user
        try {
            $cleanup = $mysqli->prepare("UPDATE user_otps SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE");
            $cleanup->bind_param('i', $user_id);
            $cleanup->execute();
            $cleanup->close();
        } catch (Exception $e) {
            app_log("Failed to cleanup existing OTPs: " . $e->getMessage());
        }
        
        // Generate new OTP
        $otp_code = otp_generate_secure_otp();
        $otp_hash = password_hash($otp_code, PASSWORD_DEFAULT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes')); // 30 minutes expiry
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            // Store OTP in database
            $stmt = $mysqli->prepare("INSERT INTO user_otps (user_id, otp_hash, expires_at, email_sent_at, ip_address) VALUES (?, ?, ?, NOW(), ?)");
            $stmt->bind_param('isss', $user_id, $otp_hash, $expires_at, $ip_address);
            $stmt->execute();
            $stmt->close();
            
            // Get email template
            $email_subject = "Your Shaikhoology Verification Code";
            $email_body = otp_get_email_template($name, $otp_code);
            
            // Send email
            require_once __DIR__ . '/../mailer.php';
            $sent = sendMail($email, $email_subject, $email_body, strip_tags($email_body));
            
            if ($sent) {
                app_log("OTP email sent successfully to: $email for user: $user_id");
                return true;
            } else {
                app_log("Failed to send OTP email to: $email for user: $user_id");
                return false;
            }
            
        } catch (Exception $e) {
            app_log("Error sending OTP email: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get professional OTP email template
 */
if (!function_exists('otp_get_email_template')) {
    function otp_get_email_template(string $name, string $otp_code): string {
        $site_name = "Shaikhoology Trading Club";
        $support_email = "support@shaikhoology.com";
        $current_year = date('Y');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Email Verification Code - $site_name</title>
        </head>
        <body style='margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f5f5f5;line-height:1.6'>
            <div style='max-width:600px;margin:0 auto;background:#ffffff;box-shadow:0 0 20px rgba(0,0,0,0.1)'>
                <!-- Header -->
                <div style='background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:40px 20px;text-align:center'>
                    <h1 style='color:#ffffff;margin:0;font-size:28px;font-weight:bold;letter-spacing:1px'>$site_name</h1>
                    <p style='color:#ffffff;margin:10px 0 0 0;opacity:0.9;font-size:16px'>Trading Psychology & Community</p>
                </div>
                
                <!-- Content -->
                <div style='padding:40px 30px'>
                    <h2 style='color:#333333;margin:0 0 20px 0;font-size:24px'>Hello, " . htmlspecialchars($name, ENT_QUOTES) . "!</h2>
                    
                    <p style='color:#666666;margin:0 0 20px 0;font-size:16px'>
                        Thank you for joining the $site_name community. To complete your registration, 
                        please verify your email address using the verification code below:
                    </p>
                    
                    <!-- OTP Code Box -->
                    <div style='background:#f8f9fa;border:2px solid #e9ecef;border-radius:12px;padding:30px;margin:30px 0;text-align:center'>
                        <p style='color:#666666;margin:0 0 15px 0;font-size:14px;text-transform:uppercase;letter-spacing:1px'>Your Verification Code</p>
                        <div style='font-size:36px;font-weight:bold;color:#333333;letter-spacing:8px;font-family:monospace;background:#ffffff;border-radius:8px;padding:15px;display:inline-block;box-shadow:0 2px 8px rgba(0,0,0,0.1)'>
                            $otp_code
                        </div>
                    </div>
                    
                    <!-- Instructions -->
                    <div style='background:#e8f4fd;border-left:4px solid #007bff;padding:20px;margin:30px 0;border-radius:0 8px 8px 0'>
                        <h3 style='color:#0056b3;margin:0 0 10px 0;font-size:16px'>📝 Instructions:</h3>
                        <ul style='color:#666666;margin:0;padding-left:20px'>
                            <li>Enter this code in the verification page</li>
                            <li>Code expires in <strong>30 minutes</strong></li>
                            <li>You have 3 attempts to verify</li>
                            <li>For security, never share this code with anyone</li>
                        </ul>
                    </div>
                    
                    <!-- Security Notice -->
                    <div style='background:#fff3cd;border:1px solid #ffeaa7;border-radius:8px;padding:15px;margin:20px 0'>
                        <p style='color:#856404;margin:0;font-size:14px'>
                            <strong>🔒 Security Notice:</strong> If you didn't request this verification code, 
                            please ignore this email. Your account remains secure.
                        </p>
                    </div>
                </div>
                
                <!-- Footer -->
                <div style='background:#f8f9fa;padding:30px;text-align:center;border-top:1px solid #e9ecef'>
                    <p style='color:#6c757d;margin:0 0 10px 0;font-size:14px'>
                        This email was sent from $site_name
                    </p>
                    <p style='color:#6c757d;margin:0;font-size:12px'>
                        © $current_year Shaikhoology Trading Club. All rights reserved.
                    </p>
                    <p style='color:#6c757d;margin:10px 0 0 0;font-size:12px'>
                        Need help? Contact us at <a href='mailto:$support_email' style='color:#007bff'>$support_email</a>
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}

/**
 * Verify OTP code
 */
if (!function_exists('otp_verify_code')) {
    function otp_verify_code(int $user_id, string $otp_code): array {
        $mysqli = load_db();
        if (!$mysqli) {
            return ['success' => false, 'message' => 'Database connection failed'];
        }
        
        try {
            // Get the latest active OTP for this user
            $stmt = $mysqli->prepare("SELECT id, otp_hash, expires_at, attempts, max_attempts, is_active FROM user_otps WHERE user_id = ? AND is_active = TRUE ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $otp_record = $result->fetch_assoc();
            $stmt->close();
            
            if (!$otp_record) {
                return ['success' => false, 'message' => 'No verification code found. Please request a new one.'];
            }
            
            // Check if OTP has expired
            if (strtotime($otp_record['expires_at']) < time()) {
                // Deactivate expired OTP
                $deactivate = $mysqli->prepare("UPDATE user_otps SET is_active = FALSE WHERE id = ?");
                $deactivate->bind_param('i', $otp_record['id']);
                $deactivate->execute();
                $deactivate->close();
                
                return ['success' => false, 'message' => 'Verification code has expired. Please request a new one.'];
            }
            
            // Check if max attempts reached
            if ((int)$otp_record['attempts'] >= (int)$otp_record['max_attempts']) {
                // Deactivate OTP due to max attempts
                $deactivate = $mysqli->prepare("UPDATE user_otps SET is_active = FALSE WHERE id = ?");
                $deactivate->bind_param('i', $otp_record['id']);
                $deactivate->execute();
                $deactivate->close();
                
                return ['success' => false, 'message' => 'Maximum verification attempts reached. Please request a new code.'];
            }
            
            // Verify the OTP code
            if (!password_verify($otp_code, $otp_record['otp_hash'])) {
                // Increment attempts
                $update_attempts = $mysqli->prepare("UPDATE user_otps SET attempts = attempts + 1 WHERE id = ?");
                $update_attempts->bind_param('i', $otp_record['id']);
                $update_attempts->execute();
                $update_attempts->close();
                
                $remaining_attempts = (int)$otp_record['max_attempts'] - ((int)$otp_record['attempts'] + 1);
                return [
                    'success' => false, 
                    'message' => "Invalid verification code. {$remaining_attempts} attempts remaining.",
                    'remaining_attempts' => $remaining_attempts
                ];
            }
            
            // Success - mark OTP as verified and update user email_verified status
            $mysqli->begin_transaction();
            
            // Mark OTP as verified
            $verify_otp = $mysqli->prepare("UPDATE user_otps SET is_active = FALSE, verified_at = NOW() WHERE id = ?");
            $verify_otp->bind_param('i', $otp_record['id']);
            $verify_otp->execute();
            $verify_otp->close();
            
            // Update user's email_verified status
            $update_user = $mysqli->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
            $update_user->bind_param('i', $user_id);
            $update_user->execute();
            $update_user->close();
            
            // Update user status to profile_pending
            update_user_status_profile_pending($user_id);
            
            // Update session
            $_SESSION['email_verified'] = 1;
            $_SESSION['status'] = 'profile_pending';
            
            $mysqli->commit();
            
            app_log("Email verified successfully for user: $user_id - status updated to profile_pending");
            
            return ['success' => true, 'message' => 'Email verified successfully!'];
            
        } catch (Exception $e) {
            if (isset($mysqli)) {
                $mysqli->rollback();
            }
            app_log("Error verifying OTP: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred during verification. Please try again.'];
        }
    }
}

/**
 * Check rate limiting for OTP requests
 */
if (!function_exists('otp_rate_limit_check')) {
    function otp_rate_limit_check(int $user_id, string $email): array {
        $mysqli = load_db();
        if (!$mysqli) {
            return ['allowed' => true, 'message' => '']; // Allow if DB fails
        }
        
        try {
            // Check if OTP was sent in the last 5 minutes
            $stmt = $mysqli->prepare("SELECT email_sent_at FROM user_otps WHERE user_id = ? AND email_sent_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND is_active = TRUE ORDER BY email_sent_at DESC LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $recent_otp = $result->fetch_assoc();
            $stmt->close();
            
            if ($recent_otp) {
                $next_allowed = strtotime($recent_otp['email_sent_at']) + 300; // 5 minutes
                $wait_time = $next_allowed - time();
                return [
                    'allowed' => false,
                    'message' => "Please wait " . ceil($wait_time / 60) . " minutes before requesting a new code.",
                    'wait_seconds' => $wait_time
                ];
            }
            
            return ['allowed' => true, 'message' => ''];
            
        } catch (Exception $e) {
            app_log("Error checking rate limit: " . $e->getMessage());
            return ['allowed' => true, 'message' => '']; // Allow if check fails
        }
    }
}

/**
 * Clean up expired OTPs
 */
if (!function_exists('otp_cleanup_expired')) {
    function otp_cleanup_expired(): int {
        $mysqli = load_db();
        if (!$mysqli) return 0;
        
        try {
            $stmt = $mysqli->prepare("UPDATE user_otps SET is_active = FALSE WHERE expires_at < NOW() AND is_active = TRUE");
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected > 0) {
                app_log("Cleaned up $affected expired OTP records");
            }
            
            return $affected;
        } catch (Exception $e) {
            app_log("Error cleaning up expired OTPs: " . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Get user's OTP verification status
 */
if (!function_exists('otp_get_user_status')) {
    function otp_get_user_status(int $user_id): array {
        $mysqli = load_db();
        if (!$mysqli) {
            return ['has_active_otp' => false, 'email_verified' => false, 'message' => 'Database unavailable'];
        }
        
        try {
            // Check for active OTP
            $stmt = $mysqli->prepare("SELECT expires_at, email_sent_at, attempts, max_attempts FROM user_otps WHERE user_id = ? AND is_active = TRUE ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $otp_record = $result->fetch_assoc();
            $stmt->close();
            
            // Check user's email_verified status
            $user_stmt = $mysqli->prepare("SELECT email_verified FROM users WHERE id = ?");
            $user_stmt->bind_param('i', $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_record = $user_result->fetch_assoc();
            $user_stmt->close();
            
            $email_verified = (int)($user_record['email_verified'] ?? 0) === 1;
            
            if ($email_verified) {
                return [
                    'has_active_otp' => false,
                    'email_verified' => true,
                    'message' => 'Email already verified'
                ];
            }
            
            if ($otp_record) {
                $expires_at = strtotime($otp_record['expires_at']);
                $email_sent_at = strtotime($otp_record['email_sent_at']);
                $time_remaining = $expires_at - time();
                $can_resend = (time() - $email_sent_at) > 300; // 5 minutes
                
                return [
                    'has_active_otp' => true,
                    'email_verified' => false,
                    'expires_at' => $otp_record['expires_at'],
                    'time_remaining' => $time_remaining,
                    'can_resend' => $can_resend,
                    'attempts_used' => (int)$otp_record['attempts'],
                    'max_attempts' => (int)$otp_record['max_attempts'],
                    'message' => $time_remaining > 0 ? 'OTP active' : 'OTP expired'
                ];
            }
            
            return [
                'has_active_otp' => false,
                'email_verified' => false,
                'message' => 'No active OTP found'
            ];
            
        } catch (Exception $e) {
            app_log("Error getting OTP status: " . $e->getMessage());
            return [
                'has_active_otp' => false,
                'email_verified' => false,
                'message' => 'Error checking status'
            ];
        }
    }
}
}


/* --------------------------------
   Profile Completion System Functions
----------------------------------- */

/**
 * Get user's profile completion status
 */
if (!function_exists('get_user_profile_completion_status')) {
    function get_user_profile_completion_status(int $user_id): string {
        $mysqli = load_db();
        if (!$mysqli) return 'not_started';
        
        try {
            // Try to get from user_profiles table first
            $stmt = $mysqli->prepare("SELECT profile_completion_status FROM user_profiles WHERE user_id=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                
                if ($row) {
                    return $row['profile_completion_status'] ?? 'not_started';
                }
            }
        } catch (Exception $e) {
            app_log("Error getting profile completion status: " . $e->getMessage());
        }
        
        // Fallback: check if user has basic profile data in users table
        try {
            $stmt = $mysqli->prepare("SELECT full_name, phone, trading_experience FROM users WHERE id=?");
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                
                if ($row && !empty($row['full_name']) && !empty($row['phone']) && !empty($row['trading_experience'])) {
                    return 'completed';
                } elseif ($row && (!empty($row['full_name']) || !empty($row['phone']) || !empty($row['trading_experience']))) {
                    return 'in_progress';
                }
            }
        } catch (Exception $e) {
            app_log("Error checking fallback profile status: " . $e->getMessage());
        }
        
        return 'not_started';
    }
}

/**
 * Update user status to profile_pending after OTP verification
 */
if (!function_exists('update_user_status_profile_pending')) {
    function update_user_status_profile_pending(int $user_id): bool {
        $mysqli = load_db();
        if (!$mysqli) return false;
        
        try {
            // Check if status column exists in users table
            if (db_has_col($mysqli, 'users', 'status')) {
                $stmt = $mysqli->prepare("UPDATE users SET status='profile_pending' WHERE id=?");
                $stmt->bind_param('i', $user_id);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
            return false;
        } catch (Exception $e) {
            app_log("Error updating user status: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Update user status to admin_review after profile completion
 */
if (!function_exists('update_user_status_admin_review')) {
    function update_user_status_admin_review(int $user_id): bool {
        $mysqli = load_db();
        if (!$mysqli) return false;
        
        try {
            // Check if status column exists in users table
            if (db_has_col($mysqli, 'users', 'status')) {
                $stmt = $mysqli->prepare("UPDATE users SET status='admin_review' WHERE id=?");
                $stmt->bind_param('i', $user_id);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
            return false;
        } catch (Exception $e) {
            app_log("Error updating user status: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Save profile data to user_profiles table
 */
if (!function_exists('save_profile_data')) {
    function save_profile_data(int $user_id, array $profile_data): bool {
        $mysqli = load_db();
        if (!$mysqli) return false;
        
        try {
            // Check if user_profiles table exists
            if (!db_has_table($mysqli, 'user_profiles')) {
                app_log("user_profiles table does not exist, cannot save profile data");
                return false;
            }
            
            // Prepare fields for insertion/update
            $fields = [];
            $values = [];
            $types = '';
            $params = [];
            
            foreach ($profile_data as $key => $value) {
                if (in_array($key, ['csrf_token', 'current_step', 'save_step', 'auto_save'])) {
                    continue; // Skip system fields
                }
                
                $fields[] = "`$key`=?";
                $types .= 's';
                $params[] = is_array($value) ? json_encode($value) : (string)$value;
            }
            
            if (empty($fields)) {
                return false;
            }
            
            // Add updated timestamp
            $fields[] = 'updated_at=NOW()';
            
            $sql = "INSERT INTO user_profiles (user_id, " . implode(', ', $fields) . ") 
                    VALUES ($user_id, " . implode(', ', array_fill(0, count($params), '?')) . ") 
                    ON DUPLICATE KEY UPDATE " . implode(', ', array_slice($fields, 1));
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                app_log("Failed to prepare profile save statement: " . $mysqli->error);
                return false;
            }
            
            $stmt->bind_param('i' . $types, $user_id, ...$params);
            $result = $stmt->execute();
            $stmt->close();
            
            if ($result) {
                // Update profile completion status
                update_profile_completion_status($user_id, 'in_progress');
            }
            
            return $result;
        } catch (Exception $e) {
            app_log("Error saving profile data: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Update profile completion status
 */
if (!function_exists('update_profile_completion_status')) {
    function update_profile_completion_status(int $user_id, string $status): bool {
        $mysqli = load_db();
        if (!$mysqli) return false;
        
        try {
            // Check if user_profiles table exists
            if (!db_has_table($mysqli, 'user_profiles')) {
                return false;
            }
            
            $stmt = $mysqli->prepare("UPDATE user_profiles SET profile_completion_status=?, profile_completion_date=NOW() WHERE user_id=?");
            $stmt->bind_param('si', $status, $user_id);
            $result = $stmt->execute();
            $stmt->close();
            
            return $result;
        } catch (Exception $e) {
            app_log("Error updating profile completion status: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get profile completeness percentage
 */
if (!function_exists('calculate_profile_completeness')) {
    function calculate_profile_completeness(int $user_id): int {
        $mysqli = load_db();
        if (!$mysqli) return 0;
        
        try {
            // Load profile fields configuration
            $config_file = __DIR__ . '/../profile_fields.php';
            if (!file_exists($config_file)) {
                return 0;
            }
            
            $config = require $config_file;
            $total_fields = 0;
            $completed_fields = 0;
            
            // Count total required fields
            foreach ($config as $section) {
                foreach ($section['fields'] as $field) {
                    if (!empty($field['required'])) {
                        $total_fields++;
                    }
                }
            }
            
            if ($total_fields === 0) {
                return 100;
            }
            
            // Check completion status from user_profiles table
            $stmt = $mysqli->prepare("SELECT profile_completion_status FROM user_profiles WHERE user_id=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                
                if ($row) {
                    switch ($row['profile_completion_status']) {
                        case 'completed':
                            return 100;
                        case 'in_progress':
                            // Calculate based on basic fields filled
                            $basic_fields = ['full_name', 'age', 'location', 'phone', 'trading_experience_years'];
                            $stmt2 = $mysqli->prepare("SELECT full_name, age, location, phone, trading_experience_years FROM user_profiles WHERE user_id=? LIMIT 1");
                            if ($stmt2) {
                                $stmt2->bind_param('i', $user_id);
                                $stmt2->execute();
                                $result2 = $stmt2->get_result();
                                $profile = $result2->fetch_assoc();
                                $stmt2->close();
                                
                                if ($profile) {
                                    foreach ($basic_fields as $field) {
                                        if (!empty($profile[$field])) {
                                            $completed_fields++;
                                        }
                                    }
                                }
                            }
                            return (int)round(($completed_fields / count($basic_fields)) * 100);
                        case 'not_started':
                            return 0;
                        default:
                            return 25; // Some progress made
                    }
                }
            }
            
            return 0;
        } catch (Exception $e) {
            app_log("Error calculating profile completeness: " . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Validate profile data comprehensively
 */
if (!function_exists('validate_profile_data')) {
    function validate_profile_data(array $profile_data): array {
        $errors = [];
        $warnings = [];
        
        // Personal Information validation
        if (!empty($profile_data['age'])) {
            $age = (int)$profile_data['age'];
            if ($age < 18) {
                $errors[] = "You must be at least 18 years old to join.";
            } elseif ($age > 100) {
                $errors[] = "Please enter a valid age.";
            }
        }
        
        // Financial information validation
        if (!empty($profile_data['trading_capital'])) {
            $capital = (float)$profile_data['trading_capital'];
            if ($capital < 1000) {
                $warnings[] = "Trading capital below $1,000 may limit your trading opportunities.";
            } elseif ($capital > 1000000) {
                $warnings[] = "Please verify your trading capital amount.";
            }
        }
        
        if (!empty($profile_data['trading_budget_percentage'])) {
            $percentage = (float)$profile_data['trading_budget_percentage'];
            if ($percentage > 30) {
                $warnings[] = "Allocating more than 30% of income to trading is considered high risk.";
            }
        }
        
        // Trading experience validation
        if (!empty($profile_data['trading_experience_years'])) {
            $experience = (int)$profile_data['trading_experience_years'];
            $age = (int)($profile_data['age'] ?? 0);
            
            if ($age > 0 && $experience > ($age - 16)) {
                $errors[] = "Trading experience cannot exceed your age minus 16 years.";
            }
        }
        
        // Psychology assessment validation
        if (!empty($profile_data['emotional_control_rating'])) {
            $rating = (int)$profile_data['emotional_control_rating'];
            if ($rating < 3) {
                $warnings[] = "Low emotional control rating. Consider additional training before starting.";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}

/* --------------------------------
   Email Notification System for Admin Approval
----------------------------------- */

/**
 * Send approval/rejection email to user
 */
if (!function_exists('send_approval_email')) {
    function send_approval_email(string $email, string $name, bool $approved, string $reason = ''): bool {
        try {
            require_once __DIR__ . '/../mailer.php';
            
            if ($approved) {
                $subject = "Welcome to Shaikhoology! Your Application Has Been Approved";
                $html_body = get_approval_email_template($name);
                $text_body = strip_tags($html_body);
            } else {
                $subject = "Shaikhoology Application Update - Additional Information Required";
                $html_body = get_rejection_email_template($name, $reason);
                $text_body = strip_tags($html_body);
            }
            
            $sent = sendMail($email, $subject, $html_body, $text_body);
            
            if ($sent) {
                app_log("Approval email sent successfully to: $email (approved: $approved)");
            } else {
                app_log("Failed to send approval email to: $email");
            }
            
            return $sent;
        } catch (Exception $e) {
            app_log("Error sending approval email: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get approval email template
 */
if (!function_exists('get_approval_email_template')) {
    function get_approval_email_template(string $name): string {
        $site_name = "Shaikhoology Trading Club";
        $current_year = date('Y');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Welcome to $site_name!</title>
        </head>
        <body style='margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f5f5f5;line-height:1.6'>
            <div style='max-width:600px;margin:0 auto;background:#ffffff;box-shadow:0 0 20px rgba(0,0,0,0.1)'>
                <!-- Header -->
                <div style='background:linear-gradient(135deg,#059669 0%,#047857 100%);padding:40px 20px;text-align:center'>
                    <h1 style='color:#ffffff;margin:0;font-size:28px;font-weight:bold;letter-spacing:1px'>$site_name</h1>
                    <p style='color:#ffffff;margin:10px 0 0 0;opacity:0.9;font-size:16px'>Trading Psychology & Community</p>
                </div>
                
                <!-- Content -->
                <div style='padding:40px 30px'>
                    <h2 style='color:#1f2937;margin:0 0 20px 0;font-size:24px'>🎉 Congratulations, " . htmlspecialchars($name, ENT_QUOTES) . "!</h2>
                    
                    <p style='color:#4b5563;margin:0 0 20px 0;font-size:16px'>
                        Great news! Your application to join the <strong>$site_name</strong> has been <strong style='color:#059669'>approved</strong>.
                    </p>
                    
                    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:20px;margin:30px 0'>
                        <h3 style='color:#166534;margin:0 0 10px 0;font-size:18px'>✅ What's Next?</h3>
                        <ul style='color:#15803d;margin:0;padding-left:20px'>
                            <li><strong>Login to your account</strong> - You can now access all platform features</li>
                            <li><strong>Complete your profile</strong> - Add your trading experience and goals</li>
                            <li><strong>Start trading</strong> - Begin your journey in the championship league</li>
                            <li><strong>Join the community</strong> - Connect with other disciplined traders</li>
                        </ul>
                    </div>
                    
                    <p style='color:#4b5563;margin:0 0 20px 0;font-size:16px'>
                        We look forward to seeing you grow as a disciplined trader. Remember, the path to trading excellence is built on psychology, discipline, and continuous learning.
                    </p>
                    
                    <div style='text-align:center;margin:30px 0'>
                        <a href='" . (defined('SITE_URL') ? SITE_URL : 'http://localhost:8000') . "/login.php'
                           style='background:linear-gradient(135deg,#059669,#047857);color:#ffffff;padding:12px 30px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block'>
                            Login to Your Account
                        </a>
                    </div>
                </div>
                
                <!-- Footer -->
                <div style='background:#f8f9fa;padding:30px;text-align:center;border-top:1px solid #e9ecef'>
                    <p style='color:#6c757d;margin:0 0 10px 0;font-size:14px'>
                        This email was sent from $site_name
                    </p>
                    <p style='color:#6c757d;margin:0;font-size:12px'>
                        © $current_year Shaikhoology Trading Club. All rights reserved.
                    </p>
                    <p style='color:#6c757d;margin:10px 0 0 0;font-size:12px'>
                        Need help? Contact us at support@shaikhoology.com
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}

/**
 * Get rejection email template
 */
if (!function_exists('get_rejection_email_template')) {
    function get_rejection_email_template(string $name, string $reason): string {
        $site_name = "Shaikhoology Trading Club";
        $current_year = date('Y');
        $reason_text = !empty($reason) ? htmlspecialchars($reason, ENT_QUOTES) : "additional information is required to complete your application";
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Shaikhoology Application Update</title>
        </head>
        <body style='margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f5f5f5;line-height:1.6'>
            <div style='max-width:600px;margin:0 auto;background:#ffffff;box-shadow:0 0 20px rgba(0,0,0,0.1)'>
                <!-- Header -->
                <div style='background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%);padding:40px 20px;text-align:center'>
                    <h1 style='color:#ffffff;margin:0;font-size:28px;font-weight:bold;letter-spacing:1px'>$site_name</h1>
                    <p style='color:#ffffff;margin:10px 0 0 0;opacity:0.9;font-size:16px'>Trading Psychology & Community</p>
                </div>
                
                <!-- Content -->
                <div style='padding:40px 30px'>
                    <h2 style='color:#1f2937;margin:0 0 20px 0;font-size:24px'>Application Update</h2>
                    
                    <p style='color:#4b5563;margin:0 0 20px 0;font-size:16px'>
                        Hello " . htmlspecialchars($name, ENT_QUOTES) . ", thank you for your interest in joining <strong>$site_name</strong>.
                    </p>
                    
                    <p style='color:#4b5563;margin:0 0 20px 0;font-size:16px'>
                        After careful review, we need to request additional information before we can complete your application. Specifically, <strong>$reason_text</strong>.
                    </p>
                    
                    <div style='background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:20px;margin:30px 0'>
                        <h3 style='color:#92400e;margin:0 0 10px 0;font-size:18px'>📝 Next Steps</h3>
                        <ul style='color:#b45309;margin:0;padding-left:20px'>
                            <li><strong>Log back into your account</strong> and complete the required information</li>
                            <li><strong>Review your profile</strong> - ensure all sections are properly filled</li>
                            <li><strong>Resubmit for review</strong> - we will re-evaluate your application</li>
                            <li><strong>Contact support</strong> if you have questions about the requirements</li>
                        </ul>
                    </div>
                    
                    <p style='color:#4b5563;margin:0 0 20px 0;font-size:16px'>
                        We appreciate your patience and look forward to welcoming you to our community of disciplined traders.
                    </p>
                    
                    <div style='text-align:center;margin:30px 0'>
                        <a href='" . (defined('SITE_URL') ? SITE_URL : 'http://localhost:8000') . "/login.php'
                           style='background:linear-gradient(135deg,#dc2626,#b91c1c);color:#ffffff;padding:12px 30px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block'>
                            Log In to Update Profile
                        </a>
                    </div>
                </div>
                
                <!-- Footer -->
                <div style='background:#f8f9fa;padding:30px;text-align:center;border-top:1px solid #e9ecef'>
                    <p style='color:#6c757d;margin:0 0 10px 0;font-size:14px'>
                        This email was sent from $site_name
                    </p>
                    <p style='color:#6c757d;margin:0;font-size:12px'>
                        © $current_year Shaikhoology Trading Club. All rights reserved.
                    </p>
                    <p style='color:#6c757d;margin:10px 0 0 0;font-size:12px'>
                        Need help? Contact us at support@shaikhoology.com
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
