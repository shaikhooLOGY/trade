<?php
// trade_unlock_request.php — Simple version without complex table dependencies
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function t($s){ return trim((string)$s); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_ok($x){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$x); }

function log_error($msg) {
    error_log("UnlockRequest: " . $msg);
}

// Get trade info - simple query
$trade_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$trade_query = $mysqli->prepare("SELECT id, symbol, entry_date, outcome, exit_price FROM trades WHERE id=? AND user_id=? LIMIT 1");
$trade_query->bind_param('ii', $trade_id, $user_id);
$trade_query->execute();
$trade = $trade_query->get_result()->fetch_assoc();
$trade_query->close();

if (!$trade) { 
    log_error("Trade not found: $trade_id for user $user_id");
    header('Location: /dashboard.php'); 
    exit; 
}

// Determine if trade is closed
$closed = false;
if (!empty($trade['outcome']) && strtoupper($trade['outcome']) !== 'OPEN') {
    $closed = true;
}
if (!empty($trade['exit_price']) && (float)$trade['exit_price'] > 0) {
    $closed = true;
}

$flash = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_ok($_POST['csrf'] ?? '')) {
    $reason = t($_POST['reason'] ?? '');
    
    log_error("Processing unlock request for trade $trade_id, reason: '$reason'");
    
    if ($closed && !empty($reason)) {
        try {
            // Check if unlock_status column exists
            $check_col = $mysqli->query("SHOW COLUMNS FROM trades LIKE 'unlock_status'");
            $has_unlock_status = $check_col->num_rows > 0;
            $check_col->close();
            
            log_error("unlock_status column exists: " . ($has_unlock_status ? "YES" : "NO"));
            
            if ($has_unlock_status) {
                // Try to update unlock_status
                $update_stmt = $mysqli->prepare("UPDATE trades SET unlock_status='pending', unlock_requested_by=? WHERE id=? AND user_id=?");
                $update_stmt->bind_param('iii', $user_id, $trade_id, $user_id);
                
                if ($update_stmt->execute()) {
                    $affected = $update_stmt->affected_rows;
                    log_error("Update successful, affected rows: $affected");
                    
                    if ($affected > 0) {
                        $_SESSION['flash'] = 'Unlock request sent successfully!';
                        header('Location: /dashboard.php'); exit;
                    } else {
                        $flash = 'No changes made. Trade may already be unlocked or you may not have permission.';
                    }
                } else {
                    $error = $update_stmt->error;
                    log_error("Update failed: $error");
                    $flash = "Update failed: $error";
                }
                $update_stmt->close();
            } else {
                // No unlock_status column - just store reason in session for now
                $_SESSION['unlock_request_' . $trade_id] = [
                    'reason' => $reason,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'user_id' => $user_id
                ];
                
                log_error("Stored unlock request in session for trade $trade_id");
                $_SESSION['flash'] = 'Unlock request noted (feature coming soon)!';
                header('Location: /dashboard.php'); exit;
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            log_error("Exception: $error");
            $flash = "Error: $error";
        }
    } else {
        $flash = 'Trade must be closed and reason must be provided.';
    }
}

$title = "Request Unlock — Shaikhoology";
include __DIR__ . '/header.php';
?>
<div style="max-width:720px;margin:22px auto;padding:0 16px">
  <h2>Request Unlock</h2>

  <?php if($flash): ?>
    <div style="background:#fef3c7;border:1px solid #f59e0b55;padding:10px;border-radius:10px;margin-bottom:12px"><?=$flash?></div>
  <?php endif; ?>

  <div style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 10px 28px rgba(16,24,40,.08);margin-bottom:12px">
    <div style="font-weight:800"><?=h($trade['symbol'])?></div>
    <div style="opacity:.7">
      Entry: <?=h($trade['entry_date'])?> • 
      Exit: <?=h(!empty($trade['exit_price']) ? $trade['exit_price'] : 'N/A')?> • 
      Outcome: <?=h(!empty($trade['outcome']) ? $trade['outcome'] : 'N/A')?>
    </div>
    <div style="font-size:11px;color:#666;margin-top:5px">
      Status: <?= $closed ? '<span style="color:green">Closed</span>' : '<span style="color:orange">Open</span>' ?>
    </div>
  </div>

  <?php if(!$closed): ?>
    <div style="background:#fee2e2;border:1px solid #ef444455;padding:10px;border-radius:10px">
      Open trade — unlock is only for closed trades.
    </div>
  <?php else: ?>
    <form method="post" style="display:grid;gap:10px">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="id" value="<?=$trade_id?>">
      <label>Reason for unlock request
        <textarea name="reason" rows="4" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px" required placeholder="Please explain why you need to unlock this trade..."></textarea>
      </label>
      <div>
        <button type="submit" style="background:#5a2bd9;color:#fff;border:0;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer">Submit Request</button>
        <a href="/dashboard.php" style="margin-left:8px;text-decoration:none">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
// Debug info for troubleshooting
console.log('Trade ID: <?= $trade_id ?>');
console.log('User ID: <?= $user_id ?>');
console.log('Trade closed: <?= $closed ? "yes" : "no" ?>');
</script>

<?php include __DIR__ . '/footer.php'; ?>