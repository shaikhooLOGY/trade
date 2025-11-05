<?php
// reset_password.php — verify token & set new password (production-safe)
// Session and security handling centralized via bootstrap.php
require_once __DIR__ . '/includes/bootstrap.php';

/* ---------- small helpers ---------- */
function has_column(mysqli $db, string $table, string $col): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    if ($st = $db->prepare($sql)) {
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $st->store_result();
        $ok = $st->num_rows > 0;
        $st->close();
        return $ok;
    }
    return false;
}
$has_token_hash = has_column($mysqli, 'password_resets', 'token_hash');
$has_used_at    = has_column($mysqli, 'password_resets', 'used_at');
$has_remember   = has_column($mysqli, 'users', 'remember_token');

/* ---------- CSRF ---------- */
// Use unified CSRF system - get token from centralized implementation
$csrf = get_csrf_token();

/* ---------- initial state ---------- */
$token  = trim((string)($_GET['token'] ?? ''));
$valid  = false;
$err    = '';
$ok     = '';
$userId = null;
$resetId= null;

/* ---------- validate token ---------- */
if ($token !== '') {
    if ($has_token_hash) {
        // Preferred path: token_hash + used_at
        $hash = hash('sha256', $token);
        $sql  = "SELECT pr.id, pr.user_id, pr.expires_at"
              . ($has_used_at ? ", pr.used_at" : "")
              . " FROM password_resets pr WHERE pr.token_hash = ? LIMIT 1";
        if ($st = $mysqli->prepare($sql)) {
            $st->bind_param('s', $hash);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                if ($has_used_at && !empty($row['used_at'])) {
                    $err = 'This reset link has already been used.';
                } elseif (strtotime($row['expires_at']) < time()) {
                    $err = 'This reset link has expired.';
                } else {
                    $valid   = true;
                    $userId  = (int)$row['user_id'];
                    $resetId = (int)$row['id'];
                }
            } else {
                $err = 'Invalid reset link.';
            }
        } else {
            $err = 'Unable to verify link right now.';
        }
    } else {
        // Legacy path: plain token column, expires_at; no used_at
        if ($st = $mysqli->prepare("SELECT id, user_id, expires_at FROM password_resets WHERE token=? LIMIT 1")) {
            $st->bind_param('s', $token);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                if (strtotime($row['expires_at']) < time()) {
                    $err = 'This reset link has expired.';
                } else {
                    $valid   = true;
                    $userId  = (int)$row['user_id'];
                    $resetId = (int)$row['id'];
                }
            } else {
                $err = 'Invalid reset link.';
            }
        } else {
            $err = 'Unable to verify link right now.';
        }
    }
} else {
    $err = 'Invalid reset link.';
}

/* ---------- handle form submit ---------- */
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf'] ?? '')) {
        $err = 'Security check failed. Please reload and try again.';
    } else {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password_confirm'] ?? '');
        if (strlen($p1) < 8) {
            $err = 'Password must be at least 8 characters.';
        } elseif ($p1 !== $p2) {
            $err = 'Passwords do not match.';
        } else {
            $pwdHash = password_hash($p1, PASSWORD_DEFAULT);
            $mysqli->begin_transaction();
            try {
                // Update password (and clear remember_token if column exists)
                if ($has_remember) {
                    $sql = "UPDATE users SET password_hash=?, remember_token=NULL WHERE id=?";
                } else {
                    $sql = "UPDATE users SET password_hash=? WHERE id=?";
                }
                if (!$st = $mysqli->prepare($sql)) { throw new Exception('Prepare users update failed'); }
                $st->bind_param('si', $pwdHash, $userId);
                $st->execute();
                $st->close();

                // Mark reset as used (if used_at col), else delete token
                if ($has_used_at) {
                    if ($st = $mysqli->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?")) {
                        $st->bind_param('i', $resetId);
                        $st->execute();
                        $st->close();
                    }
                } else {
                    if ($st = $mysqli->prepare("DELETE FROM password_resets WHERE id=?")) {
                        $st->bind_param('i', $resetId);
                        $st->execute();
                        $st->close();
                    }
                }

                $mysqli->commit();
                $ok   = 'Your password has been updated. You can now log in.';
                $valid = false; // hide form after success
            } catch (Throwable $e) {
                $mysqli->rollback();
                error_log('reset_password error: '.$e->getMessage());
                $err = 'Something went wrong while updating your password. Please try again.';
            }
        }
    }
}

/* ---------- UI ---------- */
include __DIR__ . '/header.php';
?>
<main class="container" style="max-width:820px;margin:32px auto;background:#fff;padding:28px;border-radius:12px;box-shadow:0 12px 30px rgba(15,20,40,0.06)">
  <h2 style="margin:0 0 6px;color:#1a1a1a">🔒 Reset your password</h2>
  <p style="color:#666;margin:0 0 16px">Choose a new password for your account.</p>

  <?php if ($ok): ?>
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:6px;margin:12px 0;">
      <?= htmlspecialchars($ok) ?>
    </div>
    <p><a href="login.php" style="color:#4a22c8;font-weight:700;text-decoration:none">Go to login</a></p>
  <?php elseif ($err): ?>
    <div style="background:#fff4f4;border:1px solid #f5c2c2;color:#7a1a1a;padding:10px;border-radius:6px;margin:12px 0;">
      <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>

  <?php if ($valid): ?>
    <form method="post" novalidate style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label style="display:block;font-weight:600;margin-bottom:6px">New password</label>
      <input name="password" type="password" required minlength="8"
             style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px;font-size:15px">

      <label style="display:block;font-weight:600;margin:14px 0 6px">Confirm password</label>
      <input name="password_confirm" type="password" required minlength="8"
             style="width:100%;padding:12px;border:1px solid #e6e9ef;border-radius:8px;font-size:15px">

      <button type="submit"
              style="display:block;width:100%;background:linear-gradient(90deg,#6a3af7,#2d1fb7);color:#fff;padding:12px;border-radius:8px;border:0;font-weight:700;cursor:pointer;margin-top:12px">
        Update password
      </button>
    </form>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>