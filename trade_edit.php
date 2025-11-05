<?php
// trade_edit.php — PRODUCTION VERSION - Compatible and Stable
require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$uid = (int)$_SESSION['user_id'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fnum($n,$d=1){ if($n===null||$n==='') return '—'; return rtrim(rtrim(number_format((float)$n,$d,'.',''), '0'), '.'); }
function valnum($n,$d=4){ if($n===null||$n==='') return ''; return rtrim(rtrim(number_format((float)$n,$d,'.',''), '0'), '.'); }
function toNull($v){ $v = trim((string)$v); return ($v === '' ? null : $v); }
function toFloatOrNull($v){ $v = trim((string)$v); if ($v === '') return null; return (float)$v; }
function today(){ return date('Y-m-d'); }

// Helper to detect if a column exists in a table (cached)
function db_has_col(mysqli $m, string $table, string $col): bool {
    static $cache = [];
    $cache_key = $table . '.' . $col;

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    try {
        $stmt = $m->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $col);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $exists = ($row['cnt'] ?? 0) > 0;
        $cache[$cache_key] = $exists;
        return $exists;
    } catch (Exception $e) {
        $cache[$cache_key] = false;
        return false;
    }
}

// load trade
$trade_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$trade_id) {
    header('Location: /dashboard.php'); 
    exit;
}

$stmt = $mysqli->prepare("SELECT * FROM trades WHERE id=? AND user_id=? LIMIT 1");
$stmt->bind_param("ii", $trade_id, $uid);
$stmt->execute();
$trade = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trade) { 
    $_SESSION['flash'] = "Trade not found.";
    header('Location: /dashboard.php'); 
    exit; 
}

// Snapshot meta
$snapshot_inputs = [];
if (!empty($trade['rules_snapshot'])) {
    $decoded_snapshot = json_decode($trade['rules_snapshot'], true);
    if (is_array($decoded_snapshot)) {
        $snapshot_inputs = isset($decoded_snapshot['trade_inputs']) ? $decoded_snapshot['trade_inputs'] : $decoded_snapshot;
    }
}

$position_percent_value = $trade['position_percent'] ?? $trade['risk_pct'] ?? ($snapshot_inputs['position_percent'] ?? null);
$stop_loss_value        = $trade['stop_loss'] ?? ($snapshot_inputs['stop_loss'] ?? null);
$target_price_value     = $trade['target_price'] ?? ($snapshot_inputs['target_price'] ?? null);
$exit_price_value       = $trade['exit_price'] ?? ($snapshot_inputs['exit_price'] ?? null);
$analysis_link_value    = $trade['analysis_link'] ?? ($snapshot_inputs['analysis_link'] ?? null);
$notes_value            = $trade['notes'] ?? ($snapshot_inputs['notes'] ?? null);

// Check for lock/closed status
$is_locked_col      = db_has_col($mysqli, 'trades', 'is_locked');
$exit_price_col     = db_has_col($mysqli, 'trades', 'exit_price');
$close_date_col     = db_has_col($mysqli, 'trades', 'close_date');
$closed_at_col      = db_has_col($mysqli, 'trades', 'closed_at');
$position_percent_col = db_has_col($mysqli, 'trades', 'position_percent');
$risk_pct_col       = db_has_col($mysqli, 'trades', 'risk_pct');
$stop_loss_col      = db_has_col($mysqli, 'trades', 'stop_loss');
$target_price_col   = db_has_col($mysqli, 'trades', 'target_price');
$pl_percent_col     = db_has_col($mysqli, 'trades', 'pl_percent');
$rr_col             = db_has_col($mysqli, 'trades', 'rr');
$outcome_col        = db_has_col($mysqli, 'trades', 'outcome');
$analysis_link_col  = db_has_col($mysqli, 'trades', 'analysis_link');
$notes_col          = db_has_col($mysqli, 'trades', 'notes');
$rules_snapshot_col = db_has_col($mysqli, 'trades', 'rules_snapshot');

$isLocked = $is_locked_col ? ((int)($trade['is_locked'] ?? 0) === 1) : false;
$isClosed = $exit_price_col ? !is_null($trade['exit_price']) : !empty($trade['closed_at']);

if ($isClosed && $isLocked) {
    $_SESSION['flash'] = "⛔ This trade is locked. Request unlock to edit.";
    header('Location: /dashboard.php'); 
    exit;
}

$errors = [];
$saved  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf($_POST['csrf'] ?? '')) {
    // symbol & entry are fixed
    $symbol           = $trade['symbol'];
    $entry_price_f    = (float)$trade['entry_price'];

    // normalize inputs
    $position_percent_f = toFloatOrNull($_POST['position_percent'] ?? '');
    $stop_loss_f        = toFloatOrNull($_POST['stop_loss'] ?? '');
    $target_price_f     = toFloatOrNull($_POST['target_price'] ?? '');
    $exit_price_f       = toFloatOrNull($_POST['exit_price'] ?? '');
    
    // Optional fields
    $analysis_link = $analysis_link_col ? toNull($_POST['analysis_link'] ?? '') : null;
    $notes = $notes_col ? toNull($_POST['notes'] ?? '') : null;

    // update values for redisplay
    if ($position_percent_f !== null) $position_percent_value = $position_percent_f;
    if ($stop_loss_f !== null)        $stop_loss_value = $stop_loss_f;
    if ($target_price_f !== null)     $target_price_value = $target_price_f;
    if ($exit_price_f !== null)       $exit_price_value = $exit_price_f;
    if ($analysis_link !== null)      $analysis_link_value = $analysis_link;
    if ($notes !== null)              $notes_value = $notes;

    // validations
    if ($position_percent_f !== null && !is_numeric($position_percent_f)) $errors[] = "Position% must be numeric.";
    if ($stop_loss_f        !== null && !is_numeric($stop_loss_f))        $errors[] = "Stop loss must be numeric.";
    if ($target_price_f     !== null && !is_numeric($target_price_f))     $errors[] = "Target price must be numeric.";
    if ($exit_price_f       !== null && !is_numeric($exit_price_f))       $errors[] = "Exit price must be numeric.";

    // close_date: auto when exit present
    $close_date = $trade['close_date'] ?? null;
    if ($exit_price_f !== null && ($close_date === null || $close_date === '0000-00-00' || $close_date === '')) {
        $close_date = today();
    }

    // compute PL%
    $pl_percent = null;
    if ($exit_price_f !== null && $entry_price_f > 0) {
        $pl_percent = (($exit_price_f - $entry_price_f) / $entry_price_f) * 100.0;
    }

    // compute R:R (long)
    $rr = null;
    if ($stop_loss_f !== null && $target_price_f !== null) {
        $risk   = $entry_price_f - $stop_loss_f;
        $reward = $target_price_f - $entry_price_f;
        if ($risk > 0) $rr = $reward / $risk;
    }

    // outcome
    $outcome = 'OPEN';
    if ($exit_price_f !== null) {
        $tol = 0.10; // 10% tolerance
        $hitTarget = ($target_price_f !== null && $target_price_f > 0) ? ($exit_price_f >= $target_price_f * (1.0 - $tol)) : false;
        $hitSL     = ($stop_loss_f    !== null && $stop_loss_f    > 0) ? ($exit_price_f <= $stop_loss_f    * (1.0 + $tol)) : false;

        if ($hitTarget)      $outcome = 'TARGET HIT';
        elseif ($hitSL)      $outcome = 'SL HIT';
        else                 $outcome = 'MANUAL CLOSE';
    }

    if (!$errors) {
        // Build dynamic UPDATE query
        $update_fields = [];
        $params = [];
        $types = '';

        $percent_field = null;
        if ($position_percent_col) {
            $percent_field = 'position_percent';
        } elseif ($risk_pct_col) {
            $percent_field = 'risk_pct';
        }
        if ($percent_field !== null) {
            $update_fields[] = "{$percent_field} = ?";
            $params[] = $position_percent_f;
            $types .= 's';
        }

        if ($stop_loss_col) {
            $update_fields[] = "stop_loss = ?";
            $params[] = $stop_loss_f;
            $types .= 's';
        }
        if ($target_price_col) {
            $update_fields[] = "target_price = ?";
            $params[] = $target_price_f;
            $types .= 's';
        }
        if ($exit_price_col) {
            $update_fields[] = "exit_price = ?";
            $params[] = $exit_price_f;
            $types .= 's';
        }
        if ($close_date_col) {
            $update_fields[] = "close_date = ?";
            $params[] = $close_date;
            $types .= 's';
        }
        elseif ($closed_at_col) {
            if ($exit_price_f !== null) {
                $update_fields[] = "closed_at = NOW()";
            } else {
                $update_fields[] = "closed_at = NULL";
            }
        }
        if ($pl_percent_col) {
            $update_fields[] = "pl_percent = ?";
            $params[] = $pl_percent;
            $types .= 's';
        }
        if ($rr_col) {
            $update_fields[] = "rr = ?";
            $params[] = $rr;
            $types .= 's';
        }
        if ($outcome_col) {
            $update_fields[] = "outcome = ?";
            $params[] = $outcome;
            $types .= 's';
        }
        
        // P&L calculation
        $pnl_amount = null;
        $is_trade_being_closed = false;
        
        if ($exit_price_f !== null && $entry_price_f > 0) {
            $tot_cap = 100000; // Default capital
            
            // Get user's current trading capital
            try {
                $user_cap_stmt = $mysqli->prepare("SELECT trading_capital, funds_available FROM users WHERE id=? LIMIT 1");
                $user_cap_stmt->bind_param('i', $uid);
                $user_cap_stmt->execute();
                $user_cap_data = $user_cap_stmt->get_result()->fetch_assoc();
                $user_cap_stmt->close();
                $tot_cap = (float)($user_cap_data['trading_capital'] ?? $user_cap_data['funds_available'] ?? 100000);
            } catch (Exception $e) {
                $tot_cap = 100000; // Fallback
            }
            
            // Calculate quantity based on position percentage
            $quantity = 1;
            if ($position_percent_f !== null && $position_percent_f > 0) {
                $amount_invested = ($tot_cap * $position_percent_f) / 100;
                $quantity = $amount_invested / $entry_price_f;
            }
            
            // Calculate P&L amount
            $pnl_amount = ($exit_price_f - $entry_price_f) * $quantity;
            
            // Check if this trade is being closed now
            $was_closed_before = !empty($trade['exit_price']);
            $is_trade_being_closed = !$was_closed_before && $exit_price_f !== null;
            
            if (db_has_col($mysqli, 'trades', 'pnl')) {
                $update_fields[] = "pnl = ?";
                $params[] = $pnl_amount;
                $types .= 's';
            }
        }
        
        // Optional fields
        if ($analysis_link_col) {
            $update_fields[] = "analysis_link = ?";
            $params[] = $analysis_link;
            $types .= 's';
        }
        
        if ($notes_col) {
            $update_fields[] = "notes = ?";
            $params[] = $notes;
            $types .= 's';
        }
        
        if (empty($update_fields)) {
            $errors[] = "No updatable fields found in database schema.";
        } else {
            $sql = "UPDATE trades SET " . implode(', ', $update_fields) . " WHERE id = ? AND user_id = ?";
            $params[] = $trade_id;
            $params[] = $uid;
            $types .= 'ii';
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                // Save snapshot if needed
                if ($rules_snapshot_col) {
                    $snapshot_payload = [
                        'trade_inputs' => [
                            'position_percent' => $position_percent_f ?? $position_percent_value,
                            'stop_loss' => $stop_loss_f ?? $stop_loss_value,
                            'target_price' => $target_price_f ?? $target_price_value,
                            'exit_price' => $exit_price_f ?? $exit_price_value,
                            'analysis_link' => $analysis_link ?? $analysis_link_value,
                            'notes' => $notes ?? $notes_value
                        ]
                    ];
                    $json_snapshot = json_encode($snapshot_payload);
                    $snapStmt = $mysqli->prepare("UPDATE trades SET rules_snapshot = ? WHERE id = ? AND user_id = ?");
                    $snapStmt->bind_param('sii', $json_snapshot, $trade_id, $uid);
                    $snapStmt->execute();
                    $snapStmt->close();
                }

                // Update trade score
                if (file_exists(__DIR__ . '/trade_score.php')) {
                    require_once __DIR__ . '/trade_score.php';
                    if (function_exists('calculate_and_update_points')) {
                        calculate_and_update_points($mysqli, $trade_id, $uid);
                    }
                }
                
                // CAPITAL UPDATE ON TRADE CLOSURE
                if ($is_trade_being_closed && $exit_price_f !== null && $entry_price_f > 0) {
                    try {
                        // Calculate P&L using position percentage
                        $pnl_amount = 0;
                        if ($position_percent_f !== null && $position_percent_f > 0) {
                            $tot_cap = 100000; // Default
                            try {
                                $user_cap_stmt = $mysqli->prepare("SELECT trading_capital FROM users WHERE id=? LIMIT 1");
                                $user_cap_stmt->bind_param('i', $uid);
                                $user_cap_stmt->execute();
                                $user_cap_data = $user_cap_stmt->get_result()->fetch_assoc();
                                $user_cap_stmt->close();
                                $tot_cap = (float)($user_cap_data['trading_capital'] ?? 100000);
                            } catch (Exception $e) {
                                $tot_cap = 100000;
                            }
                            
                            $amount_invested = ($tot_cap * $position_percent_f) / 100;
                            $quantity = $amount_invested / $entry_price_f;
                            $pnl_amount = ($exit_price_f - $entry_price_f) * $quantity;
                        } else {
                            $pnl_amount = ($exit_price_f - $entry_price_f);
                        }
                        
                        // Update user capital
                        $user_stmt = $mysqli->prepare("SELECT funds_available, trading_capital FROM users WHERE id=? LIMIT 1");
                        $user_stmt->bind_param('i', $uid);
                        $user_stmt->execute();
                        $user_data = $user_stmt->get_result()->fetch_assoc();
                        $user_stmt->close();
                        
                        if ($user_data) {
                            $current_funds = (float)($user_data['funds_available'] ?? 0);
                            $current_capital = (float)($user_data['trading_capital'] ?? 100000);
                            
                            $new_funds = $current_funds + $pnl_amount;
                            $new_capital = $current_capital + $pnl_amount;
                            
                            $update_user_stmt = $mysqli->prepare("UPDATE users SET funds_available = ?, trading_capital = ? WHERE id = ?");
                            $update_user_stmt->bind_param('ddi', $new_funds, $new_capital, $uid);
                            $update_user_stmt->execute();
                            $update_user_stmt->close();
                        }
                    } catch (Exception $e) {
                        error_log("Capital update failed for trade {$trade_id}: " . $e->getMessage());
                    }
                }
                
                // Sync funds_available with trading_capital
                try {
                    $simple_update_stmt = $mysqli->prepare("UPDATE users SET funds_available = trading_capital WHERE id = ?");
                    $simple_update_stmt->bind_param('i', $uid);
                    $simple_update_stmt->execute();
                    $simple_update_stmt->close();
                } catch (Exception $e) {
                    error_log("Funds sync failed for user {$uid}: " . $e->getMessage());
                }
                
                $saved = true;
                $redirect_after = 3;
            } else {
                $errors[] = "Database error while saving: " . $mysqli->error;
            }
        }
    }
}

$symbol = $trade['symbol'];
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
body{font-family:Inter,system-ui,Arial,sans-serif;background:#f6f7fb;margin:0;color:#111}
.wrap{max-width:1100px;margin:20px auto;padding:0 16px}
.grid{display:grid;grid-template-columns:1fr;gap:12px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
input,select,textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;background:#fff}
input[readonly]{background:#f8fafc;color:#64748b}
textarea{min-height:90px}
.row{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn-primary{background:#5a2bd9;color:#fff}
.btn-ghost{background:#fff;border:1px solid #d1d5db}
label{font-weight:700;margin:8px 0 4px;display:block}
.help{color:#64748b;font-size:12px}
.success{background:#dcfce7;border:1px solid #14532d;color:#14532d;padding:10px;border-radius:8px;margin-bottom:16px}
.error{background:#fee2e2;border:1px solid #dc2626;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:16px}
@media (max-width:720px){.row{grid-template-columns:1fr}}
</style>

<div class="wrap">
    <?php if($errors): ?>
        <div class="error">
            <strong>Error:</strong> <?= h(implode(' ', $errors)) ?>
        </div>
    <?php endif; ?>

    <?php if ($saved): ?>
        <div class="success">
            ✅ Trade saved successfully! Redirecting to dashboard...
        </div>
        <script> 
            setTimeout(()=>{ 
                window.location.href='dashboard.php'; 
            }, <?= (int)($redirect_after ?? 3) ?>*1000); 
        </script>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin:0 0 16px 0">✏️ Edit Trade — <?=h($symbol)?></h2>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(get_csrf_token()) ?>">
            
            <div class="row">
                <div>
                    <label>Symbol</label>
                    <input type="text" value="<?=h($symbol)?>" readonly>
                </div>
                <div>
                    <label>Position %</label>
                    <input name="position_percent" inputmode="decimal" value="<?= h(valnum($position_percent_value,4)) ?>">
                    <div class="help">Asset allocation for this trade</div>
                </div>

                <div>
                    <label>Entry price (fixed)</label>
                    <input value="<?=h(fnum($trade['entry_price'],4))?>" readonly>
                </div>
                <div>
                    <label>Stop loss</label>
                    <input name="stop_loss" inputmode="decimal" value="<?= h(valnum($stop_loss_value,4)) ?>">
                </div>

                <div>
                    <label>Target price</label>
                    <input name="target_price" inputmode="decimal" value="<?= h(valnum($target_price_value,4)) ?>">
                </div>
                <div>
                    <label>Exit price</label>
                    <input name="exit_price" id="exit_price" inputmode="decimal" value="<?= h(valnum($exit_price_value,4)) ?>">
                </div>

                <div>
                    <label>Close date</label>
                    <input id="close_date" value="<?= h($trade['close_date'] ?? $trade['closed_at'] ?? '') ?>" readonly>
                    <div class="help">Auto-filled when exit price is set</div>
                </div>
                
                <?php if ($analysis_link_col): ?>
                <div>
                    <label>Analysis link</label>
                    <input name="analysis_link" value="<?= h($analysis_link_value ?? '') ?>">
                </div>
                <?php endif; ?>

                <?php if ($notes_col): ?>
                <div style="grid-column:1/-1">
                    <label>Notes (reason for taking this trade)</label>
                    <textarea rows="3" name="notes"><?= h($notes_value ?? '') ?></textarea>
                </div>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:12px;margin-top:16px">
                <button class="btn btn-primary" type="submit">💾 Save changes</button>
                <a class="btn btn-ghost" href="/dashboard.php">← Back to Dashboard</a>
            </div>
        </form>
    </div>
</div>

<script>
// Auto set close_date when exit price entered
const exitEl = document.getElementById('exit_price');
const closeEl = document.getElementById('close_date');
if (exitEl) {
  exitEl.addEventListener('input', () => {
    if (exitEl.value && !closeEl.value) {
      const d = new Date();
      const yyyy = d.getFullYear();
      const mm = String(d.getMonth()+1).padStart(2,'0');
      const dd = String(d.getDate()).padStart(2,'0');
      closeEl.value = `${yyyy}-${mm}-${dd}`;
    }
  });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>