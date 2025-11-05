<?php
// TRADE_NEW_MINIMAL_VERSION.php - Simplified version for compatibility testing
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$uid = (int)$_SESSION['user_id'];

// Simple helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fnum($v){ return is_numeric($v) ? (float)$v : null; }
function toNull($v){ $v = trim((string)$v); return ($v === '' ? null : $v); }
function toFloatOrNull($v){ $v = trim((string)$v); if ($v === '') return null; return (float)$v; }

// Simple column detection
function has_col($db, $table, $col) {
    static $cache = array();
    $cache_key = $table . '.' . $col;
    
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    $result = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    $exists = ($result && $result->num_rows > 0);
    $cache[$cache_key] = $exists;
    return $exists;
}

// Check MTM enrollment safely
$mtm_task_id = isset($_GET['mtm_task']) ? (int)$_GET['mtm_task'] : 0;
$current_task = null;
$task_rules = null;

// Calculate real available balance (matching dashboard logic)
$default_capital = 100000.0;
$tot_cap = 0.0;
$funds_available_val = 0.0;
$has_user_trading_cap = has_col($mysqli, 'users', 'trading_capital');
$has_user_funds_available = has_col($mysqli, 'users', 'funds_available');

try {
    $fields = array();
    if ($has_user_trading_cap) $fields[] = "COALESCE(trading_capital,0) AS tc";
    if ($has_user_funds_available) $fields[] = "COALESCE(funds_available,0) AS fa";

    if (!empty($fields) && ($stmt = $mysqli->prepare("SELECT " . implode(', ', $fields) . " FROM users WHERE id = ? LIMIT 1"))) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc() ?: array();
        $stmt->close();

        $tc = (float)($data['tc'] ?? 0.0);
        $funds_available_val = (float)($data['fa'] ?? 0.0);

        if ($tc <= 0 && $funds_available_val <= 0) {
            $tot_cap = $default_capital;
            $funds_available_val = $default_capital;

            if ($has_user_trading_cap && ($uStmt = $mysqli->prepare("UPDATE users SET trading_capital = ? WHERE id = ?"))) {
                $uStmt->bind_param('di', $tot_cap, $uid);
                $uStmt->execute();
                $uStmt->close();
            }
            if ($has_user_funds_available && ($fStmt = $mysqli->prepare("UPDATE users SET funds_available = ? WHERE id = ?"))) {
                $fStmt->bind_param('di', $funds_available_val, $uid);
                $fStmt->execute();
                $fStmt->close();
            }
        } else {
            $tot_cap = $tc > 0 ? $tc : $funds_available_val;
        }
    }
} catch (Exception $e) {
    $tot_cap = $default_capital;
    $funds_available_val = $default_capital;
}

if ($tot_cap <= 0) {
    $tot_cap = $default_capital;
}
if (!$has_user_funds_available) {
    $funds_available_val = $tot_cap;
}

// Calculate reserved amount for open trades
$reserved = 0.0;
try {
    // Simple calculation - sum of position % for open trades
    if (has_col($mysqli, 'trades', 'position_percent') || has_col($mysqli, 'trades', 'risk_pct')) {
        $percent_col = has_col($mysqli, 'trades', 'position_percent') ? 'position_percent' : 'risk_pct';
        $conditions = array("user_id=?");
        $conditions[] = "UPPER(COALESCE(outcome,'OPEN'))='OPEN'";
        if (has_col($mysqli, 'trades', 'deleted_at')) {
            $conditions[] = "(deleted_at IS NULL OR deleted_at='')";
        }
        
        $sql = "SELECT COALESCE(SUM(`{$percent_col}`),0) as pct FROM trades WHERE " . implode(' AND ', $conditions);
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $res_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $pct = isset($res_data['pct']) ? (float)$res_data['pct'] : 0.0;
            $reserved = ($tot_cap * $pct) / 100.0;
        }
    }
} catch (Exception $e) {
    $reserved = 0.0;
}

// Calculate available funds (same as dashboard)
$available = $tot_cap - $reserved;

// Pass values to JavaScript
$js_total_capital = $tot_cap;
$js_available_funds = $available;

$errors = array();
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $symbol   = strtoupper(trim((string)($_POST['symbol'] ?? '')));
    $pos_pct  = fnum($_POST['position_percent'] ?? '');
    $entry    = fnum($_POST['entry_price'] ?? '');
    $sl       = fnum($_POST['stop_loss'] ?? '');
    $tgt      = fnum($_POST['target_price'] ?? '');
    
    if ($symbol === '') $errors[] = 'Symbol is required.';
    if ($pos_pct === null || $pos_pct <= 0 || $pos_pct > 100) $errors[] = 'Position % must be between 0 and 100.';
    if ($entry === null || $entry <= 0) $errors[] = 'Entry price is required.';
    if ($sl === null || $sl <= 0) $errors[] = 'Stop loss is required.';
    if ($tgt === null || $tgt <= 0) $errors[] = 'Target price is required.';
    
    // Server-side available funds check
    if ($available <= 0) {
        $errors[] = 'You do not have sufficient available funds to create a new trade.';
    } elseif ($pos_pct > 0) {
        $trade_cost = ($tot_cap * $pos_pct) / 100;
        if ($trade_cost > $available) {
            $errors[] = 'Position size (' . number_format($pos_pct, 2) . '%) requires ₹' . number_format($trade_cost, 2) . ' but only ₹' . number_format($available, 2) . ' available.';
        }
    }
    
    if (empty($errors)) {
        // Build dynamic INSERT query based on available columns
        $cols = array();
        $vals = array();
        $types = '';
        
        // Always available columns
        if (has_col($mysqli, 'trades', 'user_id')) {
            $cols[] = 'user_id';
            $vals[] = $uid;
            $types .= 'i';
        }
        
        if (has_col($mysqli, 'trades', 'symbol')) {
            $cols[] = 'symbol';
            $vals[] = $symbol;
            $types .= 's';
        }
        
        // Check for position percentage column (different names)
        if (has_col($mysqli, 'trades', 'position_percent')) {
            $cols[] = 'position_percent';
            $vals[] = $pos_pct;
            $types .= 'd';
        } elseif (has_col($mysqli, 'trades', 'risk_pct')) {
            $cols[] = 'risk_pct';
            $vals[] = $pos_pct;
            $types .= 'd';
        }
        
        // Required numeric columns
        if (has_col($mysqli, 'trades', 'entry_price')) {
            $cols[] = 'entry_price';
            $vals[] = $entry;
            $types .= 'd';
        }
        
        if (has_col($mysqli, 'trades', 'stop_loss')) {
            $cols[] = 'stop_loss';
            $vals[] = $sl;
            $types .= 'd';
        }
        
        if (has_col($mysqli, 'trades', 'target_price')) {
            $cols[] = 'target_price';
            $vals[] = $tgt;
            $types .= 'd';
        }
        
        // Optional date columns
        if (has_col($mysqli, 'trades', 'entry_date')) {
            $cols[] = 'entry_date';
            $vals[] = date('Y-m-d');
            $types .= 's';
        } elseif (has_col($mysqli, 'trades', 'created_at')) {
            $cols[] = 'created_at';
            $vals[] = date('Y-m-d H:i:s');
            $types .= 's';
        }
        
        // Optional columns
        if (has_col($mysqli, 'trades', 'analysis_link')) {
            $alink = trim((string)($_POST['analysis_link'] ?? ''));
            if ($alink !== '') {
                $cols[] = 'analysis_link';
                $vals[] = $alink;
                $types .= 's';
            }
        }
        
        if (has_col($mysqli, 'trades', 'notes')) {
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($notes !== '') {
                $cols[] = 'notes';
                $vals[] = $notes;
                $types .= 's';
            }
        }
        
        // Execute INSERT only if we have minimum required columns
        if (count($cols) >= 2) { // At least user_id and symbol
            $colSql = '`' . implode('`,`', $cols) . '`';
            $ph = rtrim(str_repeat('?,', count($cols)), ',');
            
            if ($stmt = $mysqli->prepare("INSERT INTO trades ({$colSql}) VALUES ({$ph})")) {
                $stmt->bind_param($types, ...$vals);
                if ($stmt->execute()) {
                    $saved = true;
                    // Update available funds after successful trade creation
                    $new_available = $available - ($tot_cap * $pos_pct / 100);
                    if (has_col($mysqli, 'users', 'funds_available')) {
                        $update_stmt = $mysqli->prepare("UPDATE users SET funds_available = ? WHERE id = ?");
                        $update_stmt->bind_param('di', $new_available, $uid);
                        $update_stmt->execute();
                        $update_stmt->close();
                    }
                } else {
                    $errors[] = 'Database error: ' . $mysqli->error;
                }
                $stmt->close();
            } else {
                $errors[] = 'Failed to prepare statement. Available columns: ' . implode(', ', $cols);
            }
        } else {
            $errors[] = 'Insufficient database columns available for trade creation.';
        }
    }
}

include __DIR__ . '/header.php';
?>

<style>
body{font-family:Inter,system-ui,Arial,sans-serif;background:#f6f7fb;margin:0;color:#111}
.wrap{max-width:1100px;margin:20px auto;padding:0 16px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-row{display:flex;flex-direction:column;gap:6px}
label{font-weight:700;color:#222}
input,textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;background:#fff}
textarea{min-height:96px;resize:vertical}
.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:#5a2bd9;color:#fff}
.btn-ghost{background:#fff;border:1px solid #d1d5db;color:#374151}
.actions{display:flex;gap:12px;margin-top:16px}
.error{background:#fee2e2;border:1px solid #dc2626;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:16px}
.success{background:#dcfce7;border:1px solid #14532d;color:#14532d;padding:10px;border-radius:8px;margin-bottom:16px}
@media(max-width:720px){.form-grid{grid-template-columns:1fr}}
</style>

<div class="wrap">
    <?php if (!empty($errors)): ?>
        <div class="error">
            <strong>Fix the following:</strong>
            <ul>
                <?php foreach($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($saved): ?>
        <div class="success">
            ✅ Trade saved successfully! <a href="dashboard.php">View Dashboard</a>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin:0 0 10px">
            ➕ New Trade Entry Form
        </h2>
        
        <!-- Personal Trade Context -->
        <div style="margin:14px 0 18px;padding:14px;border-radius:10px;display:flex;flex-direction:column;gap:6px;background:#f8fafc;border:1px solid #e2e8f0;color:#1f2937;">
            <div style="display:inline-flex;align-items:center;gap:6px;background:#0f172a;color:#fff;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;width:fit-content;">
                Personal Trade
            </div>
            <h3 style="margin:0;font-size:16px;font-weight:700;">Personal trading journal entry</h3>
            <p style="margin:0;font-size:14px;color:#4a5568;">This trade stays outside MTM programs and only impacts your personal analytics.</p>
        </div>
        
        <form method="post">
            <div class="form-grid">
                <div class="form-row">
                    <label>Entry Date</label>
                    <input type="text" value="<?= h(date('d/m/Y')) ?>" readonly style="background:#f8fafc;color:#555;">
                </div>
                
                <div class="form-row">
                    <label>Symbol</label>
                    <input type="text" name="symbol" placeholder="e.g. RELIANCE, BAJEL" required>
                </div>
                
                <div class="form-row">
                    <label>Position %</label>
                    <input type="number" name="position_percent" step="0.0001" id="position_percent" placeholder="e.g. 5" required>
                    <small style="color:#666;font-size:12px;">Asset allocation percentage</small>
                </div>
                
                <div class="form-row">
                    <label>Entry price</label>
                    <input type="number" name="entry_price" step="0.0001" id="entry_price" placeholder="e.g. 2400" required>
                </div>
                
                <div class="form-row">
                    <label>Stop loss</label>
                    <input type="number" name="stop_loss" step="0.0001" id="stop_loss" placeholder="e.g. 2350" required>
                </div>
                
                <div class="form-row">
                    <label>Target price</label>
                    <input type="number" name="target_price" step="0.0001" id="target_price" placeholder="e.g. 2500" required>
                </div>
                
                <div class="form-row" style="grid-column:1/-1">
                    <label>Analysis link (optional)</label>
                    <input type="url" name="analysis_link" placeholder="https://...">
                </div>
                
                <div class="form-row" style="grid-column:1/-1">
                    <label>Notes (Reason for taking this trade)</label>
                    <textarea name="notes" placeholder="Your reasoning..."></textarea>
                </div>
                
                <!-- Trade Calculations Display -->
                <div class="form-row" style="grid-column:1/-1; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px;">
                    <h3 style="margin:0 0 12px; color:#1f2937; font-size:16px;">📊 Trade Calculations</h3>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                        <div>
                            <label>Total Capital</label>
                            <input type="text" id="total_capital" readonly style="background:#fff;">
                        </div>
                        <div>
                            <label>Amount Invested per Trade</label>
                            <input type="text" id="amount_invested" readonly style="background:#fff;">
                        </div>
                        <div>
                            <label>Risk Amount</label>
                            <input type="text" id="risk_amount" readonly style="background:#fff;">
                        </div>
                        <div>
                            <label>Risk per Trade (RPT) %</label>
                            <input type="text" id="risk_per_trade" readonly style="background:#fff;">
                        </div>
                        <div>
                            <label>Number of Quantity</label>
                            <input type="text" id="quantity" readonly style="background:#fff;">
                        </div>
                        <div>
                            <label>Available Funds</label>
                            <input type="text" id="available_funds" readonly style="background:#fff; color:#059669; font-weight:600;">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="actions">
                <button type="submit" class="btn">💾 Save trade</button>
                <a href="dashboard.php" class="btn btn-ghost">← Back to Dashboard</a>
            </div>
            
            <div style="margin-top:20px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;color:#666;">
                <strong>ℹ️ Information:</strong> This form automatically adapts to your database structure and creates trades with all available columns.
            </div>
        </form>
    </div>
</div>

<script>
// Real trade calculations using actual balance data
function calculateTradeMetrics() {
    const posPct = parseFloat(document.getElementById('position_percent').value) || 0;
    const entry = parseFloat(document.getElementById('entry_price').value) || 0;
    const sl = parseFloat(document.getElementById('stop_loss').value) || 0;
    
    // Real capital values from database (matching dashboard)
    const totalCapital = <?php echo json_encode($js_total_capital); ?>;
    const availableFunds = <?php echo json_encode($js_available_funds); ?>;
    
    // Calculate Amount Invested per Trade
    const amountInvested = (totalCapital * posPct) / 100;
    
    // Calculate Risk Amount
    let riskAmount = 0;
    if (entry > 0 && sl > 0) {
        riskAmount = Math.abs((entry - sl) * (amountInvested / entry));
    }
    
    // Calculate Risk per Trade (RPT) %
    const riskPerTrade = totalCapital > 0 ? (riskAmount / totalCapital) * 100 : 0;
    
    // Calculate Number of Quantity (rounded to whole numbers)
    let quantity = 0;
    if (entry > 0) {
        quantity = Math.round(amountInvested / entry);
    }
    
    // Update form fields
    document.getElementById('total_capital').value = '₹' + totalCapital.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('available_funds').value = '₹' + availableFunds.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('amount_invested').value = '₹' + amountInvested.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('risk_amount').value = '₹' + riskAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('risk_per_trade').value = riskPerTrade.toFixed(2) + '%';
    document.getElementById('quantity').value = quantity.toLocaleString('en-IN');
}

// Add event listeners for real-time calculation
document.addEventListener('DOMContentLoaded', function() {
    const posField = document.getElementById('position_percent');
    const entryField = document.getElementById('entry_price');
    const slField = document.getElementById('stop_loss');
    
    if (posField) posField.addEventListener('input', calculateTradeMetrics);
    if (entryField) entryField.addEventListener('input', calculateTradeMetrics);
    if (slField) slField.addEventListener('input', calculateTradeMetrics);
    
    // Initial calculation with real balance
    calculateTradeMetrics();
});
</script>

<?php include __DIR__ . '/footer.php'; ?>