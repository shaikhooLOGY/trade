<?php
// Trading Dashboard - matches site style with complete trade data
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/functions.php'; // canonical loader
require_once __DIR__ . '/system/mtm_verifier.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }

// Local helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return '₹' . number_format((float)$n, 0); }
function money_with_decimals($n){ return '₹' . number_format((float)$n, 2); }
function format_rr($ratio) {
    if ($ratio == 0 || $ratio === '') return '—';
    $formatted = number_format($ratio, 1);
    return '1:' . $formatted . ' (' . $formatted . 'R)';
}
function getv($k,$d=null){ return $_GET[$k] ?? $d; }
function column_exists(mysqli $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } else {
        $exists = false;
    }

    $cache[$key] = $exists;
    return $exists;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: /login.php'); exit; }

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $act = $_POST['action'] ?? '';
    $tid = (int)($_POST['trade_id'] ?? 0);

    if ($act==='soft_delete' && $tid > 0) {
        if (column_exists($mysqli, 'trades', 'deleted_at')) {
            if ($stmt = $mysqli->prepare("UPDATE trades SET deleted_at=NOW() WHERE id=? AND user_id=?")) {
                $stmt->bind_param('ii', $tid, $user_id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            if ($stmt = $mysqli->prepare("DELETE FROM trades WHERE id=? AND user_id=? LIMIT 1")) {
                $stmt->bind_param('ii', $tid, $user_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        $_SESSION['flash'] = 'Trade deleted.';
        header('Location: /dashboard.php'); exit;
    }
}

// ---------- Export functionality ----------
if (getv('export') === '1') {
    header('Location: /dashboard_export_pdf.php');
    exit;
}

// ---------- Funds Calculation (Fixed Schema) ----------
// tot_cap: prefer trading_capital if set, else funds_available
$default_capital = 100000.0;
$tot_cap = 0.0;
$funds_available_val = 0.0;
$has_user_trading_cap = column_exists($mysqli, 'users', 'trading_capital');
$has_user_funds_available = column_exists($mysqli, 'users', 'funds_available');

try {
    $fields = [];
    if ($has_user_trading_cap) $fields[] = "COALESCE(trading_capital,0) AS tc";
    if ($has_user_funds_available) $fields[] = "COALESCE(funds_available,0) AS fa";

    if (!empty($fields) && ($stmt = $mysqli->prepare("SELECT " . implode(', ', $fields) . " FROM users WHERE id = ? LIMIT 1"))) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $tc = (float)($data['tc'] ?? 0.0);
        $funds_available_val = (float)($data['fa'] ?? 0.0);

        if ($tc <= 0 && $funds_available_val <= 0) {
            $tot_cap = $default_capital;
            $funds_available_val = $default_capital;

            if ($has_user_trading_cap && ($uStmt = $mysqli->prepare("UPDATE users SET trading_capital = ? WHERE id = ?"))) {
                $uStmt->bind_param('di', $tot_cap, $user_id);
                $uStmt->execute();
                $uStmt->close();
            }
            if ($has_user_funds_available && ($fStmt = $mysqli->prepare("UPDATE users SET funds_available = ? WHERE id = ?"))) {
                $fStmt->bind_param('di', $funds_available_val, $user_id);
                $fStmt->execute();
                $fStmt->close();
            }
        } else {
            $tot_cap = $tc > 0 ? $tc : $funds_available_val;
        }
    }
} catch (Exception $e) { $tot_cap = $default_capital; $funds_available_val = $default_capital; }

if ($tot_cap <= 0) {
    $tot_cap = $default_capital;
}
if (!$has_user_funds_available) {
    $funds_available_val = $tot_cap;
}

// reserved: sum(allocation_amount) for open and not deleted trades
// --- Build robust "open" condition based on available schema columns ---
$cols_res = $mysqli->query("SHOW COLUMNS FROM trades");
$db_cols = [];
if ($cols_res) while($c = $cols_res->fetch_assoc()) $db_cols[strtolower($c['Field'])] = true;
$has_outcome = !empty($db_cols['outcome']);
$has_closed_at = !empty($db_cols['closed_at']);
$has_close_date = !empty($db_cols['close_date']);
$has_deleted_at = !empty($db_cols['deleted_at']);
$has_pnl = !empty($db_cols['pnl']);
$has_pl_percent = !empty($db_cols['pl_percent']);

$open_conditions = [];
if ($has_outcome) $open_conditions[] = "UPPER(COALESCE(outcome, 'OPEN')) = 'OPEN'";
if ($has_closed_at) $open_conditions[] = "closed_at IS NULL";
if ($has_close_date) $open_conditions[] = "close_date IS NULL";
if (empty($open_conditions)) $open_conditions[] = "1=0"; // Failsafe if no status columns found

$where_open = '('. implode(' OR ', $open_conditions) .')';
if ($has_deleted_at) $where_open .= " AND (deleted_at IS NULL OR deleted_at = '')";

$reserved = 0.0;
try {
    $allocation_column = null;
    foreach (['allocation_amount', 'allocated_amount', 'capital_allocated', 'risk_amount'] as $candidate) {
        if (!empty($db_cols[$candidate])) {
            $allocation_column = $candidate;
            break;
        }
    }

    if ($allocation_column) {
        $sql = "SELECT COALESCE(SUM(`{$allocation_column}`),0) AS reserved FROM trades WHERE user_id=? AND {$where_open}";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $reserved = isset($row['reserved']) ? (float)$row['reserved'] : 0.0;
        $stmt->close();
        
        // *** KEY FALLBACK LOGIC FOR RESERVED ***
        if ($reserved <= 0 && !empty($db_cols['position_percent'])) {
            $sql = "SELECT COALESCE(SUM(position_percent),0) AS pct FROM trades WHERE user_id=? AND {$where_open}";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $pct = isset($row['pct']) ? (float)$row['pct'] : 0.0;
            $stmt->close();
            $reserved = ($tot_cap * $pct) / 100.0;
        }
    } elseif (!empty($db_cols['position_percent']) || !empty($db_cols['risk_pct'])) {
        $percent_col = !empty($db_cols['position_percent']) ? 'position_percent' : 'risk_pct';
        $sql = "SELECT COALESCE(SUM(`{$percent_col}`),0) AS pct FROM trades WHERE user_id=? AND {$where_open}";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $pct = isset($row['pct']) ? (float)$row['pct'] : 0.0;
        $stmt->close();
        $reserved = ($tot_cap * $pct) / 100.0;
    } else {
        $sql = "SELECT COALESCE(SUM(entry_price),0) AS reserved FROM trades WHERE user_id=? AND {$where_open}";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $reserved = isset($row['reserved']) ? (float)$row['reserved'] : 0.0;
        $stmt->close();
    }
} catch (Exception $e) { $reserved = 0.0; }

// available: funds_available - reserved (can be negative)
$available = $tot_cap - $reserved;

// FIXED: Ensure funds_available matches trading_capital if funds_available is 0
if ($funds_available_val <= 0 && $tot_cap > 0) {
    // Sync funds_available with trading_capital
    if ($stmt = $mysqli->prepare("UPDATE users SET funds_available = trading_capital WHERE id = ? AND funds_available <= 0")) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        $funds_available_val = $tot_cap;
    }
} else {
    // Normal calculation
    if ($has_user_funds_available && abs($funds_available_val - $available) > 0.01) {
        if ($stmt = $mysqli->prepare("UPDATE users SET funds_available = ? WHERE id = ?")) {
            $stmt->bind_param('di', $available, $user_id);
            $stmt->execute();
            $stmt->close();
            $funds_available_val = $available;
        }
    }
}
$available = $funds_available_val; // Use actual funds_available value

// profit_loss: sum(pnl) for closed and not deleted trades
$profit_loss = 0.0;
if ($has_pnl) {
    try {
        $conditions = ["user_id = ?"];
        if ($has_deleted_at) $conditions[] = "(deleted_at IS NULL OR deleted_at='')";
        
        // Use OR to combine closed trade detection methods
        $closed_conditions = [];
        if ($has_closed_at) $closed_conditions[] = "closed_at IS NOT NULL";
        $closed_conditions[] = "UPPER(COALESCE(outcome, 'OPEN')) != 'OPEN'";
        $conditions[] = '('. implode(' OR ', $closed_conditions) .')';
        
        $where_pnl = implode(' AND ', $conditions);

        if ($stmt = $mysqli->prepare("SELECT COALESCE(SUM(pnl),0) as total_pnl FROM trades WHERE {$where_pnl}")) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $profit_data = $stmt->get_result()->fetch_assoc();
            $profit_loss = isset($profit_data['total_pnl']) ? (float)$profit_data['total_pnl'] : 0.0;
            $stmt->close();
        }
    } catch (Exception $e) { $profit_loss = 0.0; }
}

// ---------- KPIs ----------
$stats = [
    'total_trades' => 0,
    'open_positions' => 0,
    'closed_trades' => 0,
    'winning_trades' => 0
];

try {
    $conditions = ["user_id = ?"];
    if ($has_deleted_at) $conditions[] = "(deleted_at IS NULL OR deleted_at='')";
    $where_stats = implode(' AND ', $conditions);

    // Use OR to combine open trade detection methods
    $open_conditions = [];
    if ($has_closed_at) $open_conditions[] = "closed_at IS NULL";
    if ($has_outcome) $open_conditions[] = "UPPER(COALESCE(outcome,'OPEN'))='OPEN'";
    $openExpr = !empty($open_conditions) ? '('. implode(' OR ', $open_conditions) .')' : "0";
    
    // Use OR to combine closed trade detection methods
    $closed_conditions = [];
    if ($has_closed_at) $closed_conditions[] = "closed_at IS NOT NULL";
    if ($has_outcome) $closed_conditions[] = "UPPER(COALESCE(outcome, 'OPEN')) != 'OPEN'";
    $closedExpr = !empty($closed_conditions) ? '('. implode(' OR ', $closed_conditions) .')' : "0";
    
    $winExpr = ($has_pnl && $closedExpr !== "0") ? "(pnl > 0 AND {$closedExpr})" : "0";

    $sql = "
        SELECT 
            COUNT(*) AS total_trades,
            SUM(CASE WHEN {$openExpr} THEN 1 ELSE 0 END) AS open_positions,
            SUM(CASE WHEN {$closedExpr} THEN 1 ELSE 0 END) AS closed_trades,
            SUM(CASE WHEN {$winExpr} THEN 1 ELSE 0 END) AS winning_trades
        FROM trades
        WHERE {$where_stats}
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stats_data = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $stats = array_merge($stats, array_map('intval', $stats_data));
    }
} catch (Exception $e) {
    // Keep defaults
}

// ---------- Trading Data Query ----------
$rows = [];
$tab = getv('tab', 'active');
$page = max(1, (int)getv('page', 1));
$limit = 50;
$offset = ($page - 1) * $limit;

try {
    // Simple query to get all user trades
    $query = "SELECT * FROM trades WHERE user_id={$user_id} ORDER BY id DESC LIMIT {$limit}";

    $trade_q = $mysqli->query($query);
    if ($trade_q) {
        $rows = $trade_q->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $rows = [];
}

// ---------- New Matrix Calculations (after rows are defined) ----------
$avg_rr = 0.0;
$open_risk_amount = 0.0;
$open_risk_percentage = 0.0;

try {
    // Calculate Average RR across all trades
    $rr_sum = 0;
    $rr_count = 0;
    
    foreach ($rows as $r) {
        $entry_price = isset($r['entry_price']) ? (float)$r['entry_price'] : 0;
        $stop_loss = isset($r['stop_loss']) ? (float)$r['stop_loss'] : 0;
        $exit_price = isset($r['exit_price']) ? (float)$r['exit_price'] : 0;
        
        if ($entry_price > 0 && $stop_loss > 0 && $exit_price > 0) {
            $risk_price = abs($entry_price - $stop_loss);
            $reward_price = abs($exit_price - $entry_price);
            if ($risk_price > 0) {
                $rr_ratio = $reward_price / $risk_price;
                $rr_sum += $rr_ratio;
                $rr_count++;
            }
        }
    }
    
    if ($rr_count > 0) {
        $avg_rr = $rr_sum / $rr_count;
    }
    
    // Calculate Open Risk (sum of all risk amounts for open trades divided by total capital)
    foreach ($rows as $r) {
        $closed_val = $has_closed_at ? ($r['closed_at'] ?? '') : ($has_close_date ? ($r['close_date'] ?? null) : null);
        $deleted_at = $has_deleted_at ? ($r['deleted_at'] ?? '') : '';
        $outcome_val = $has_outcome ? strtoupper(trim((string)($r['outcome'] ?? 'OPEN'))) : 'OPEN';
        $is_closed = $has_closed_at ? !empty($closed_val) : ($has_close_date ? !empty($closed_val) : ($outcome_val !== 'OPEN'));
        $is_deleted = !empty($deleted_at);
        
        if (!$is_closed && !$is_deleted) {
            $entry_price = isset($r['entry_price']) ? (float)$r['entry_price'] : 0;
            $stop_loss = isset($r['stop_loss']) ? (float)$r['stop_loss'] : 0;
            $position_pct = isset($r['position_percent']) ? (float)$r['position_percent'] : 0;
            
            if ($entry_price > 0 && $stop_loss > 0) {
                $amount_invested = ($tot_cap * $position_pct) / 100;
                $risk_amount = abs($entry_price - $stop_loss) * ($amount_invested / $entry_price);
                $open_risk_amount += $risk_amount;
            }
        }
    }
    
    $open_risk_percentage = $tot_cap > 0 ? ($open_risk_amount / $tot_cap) * 100 : 0;
    
} catch (Exception $e) {
    // Keep defaults
}

$mtm_active = [];
$mtm_pending = [];
$mtm_guidance_links = [];
$mtm_locked = false;
$dashboard_default_tab = 'personal';

// Determine whether MTM task progress table exists for richer stats
$mtm_has_progress_table = false;
try {
    if ($check = $mysqli->query("SHOW TABLES LIKE 'mtm_task_progress'")) {
        $mtm_has_progress_table = $check->num_rows > 0;
        $check->close();
    }
} catch (Throwable $e) {
    $mtm_has_progress_table = false;
}

$mtm_sql = "
    SELECT 
        e.*, 
        m.title, 
        m.difficulty, 
        m.cover_image_path" .
        ($mtm_has_progress_table ? ",
        COUNT(tp.id) AS total_tasks,
        SUM(CASE WHEN tp.status = 'passed' THEN 1 END) AS completed_tasks,
        SUM(CASE WHEN tp.status IN ('unlocked', 'in_progress') THEN 1 END) AS active_tasks
    " : ",
        0 AS total_tasks,
        0 AS completed_tasks,
        0 AS active_tasks
    ") . "
    FROM mtm_enrollments e
    JOIN mtm_models m ON e.model_id = m.id" .
    ($mtm_has_progress_table ? "
    LEFT JOIN mtm_task_progress tp ON e.id = tp.enrollment_id
    " : "") . "
    WHERE e.user_id = ?
    GROUP BY e.id
    ORDER BY e.approved_at DESC, e.requested_at DESC
";

if ($stmt = $mysqli->prepare($mtm_sql)) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    if ($res = $stmt->get_result()) {
        while ($row = $res->fetch_assoc()) {
            $total_tasks = (int)($row['total_tasks'] ?? 0);
            $completed_tasks = (int)($row['completed_tasks'] ?? 0);
            $progress_pct = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100, 1) : (float)($row['progress_pct'] ?? 0);

            if ($row['status'] === 'approved') {
                $current_task = $mtm_has_progress_table ? mtm_get_current_task($mysqli, (int)$row['id']) : null;
                $row['progress_pct'] = $progress_pct;
                $row['completed_tasks'] = $completed_tasks;
                $row['total_tasks'] = $total_tasks;
                $row['current_task'] = $current_task;
                $mtm_active[] = $row;
                $mtm_guidance_links[] = [
                    'label' => $row['title'],
                    'href' => '/mtm_model_user.php?id=' . (int)$row['model_id'],
                    'task_href' => ($current_task ? '/trade_new.php?mtm_task=' . (int)$current_task['id'] : null),
                    'task_title' => $current_task['title'] ?? null
                ];
            } elseif ($row['status'] === 'pending') {
                $row['progress_pct'] = $progress_pct;
                $row['completed_tasks'] = $completed_tasks;
                $row['total_tasks'] = $total_tasks;
                $mtm_pending[] = $row;
            }
        }
        $res->close();
    }
    $stmt->close();
}

$has_mtm_data = !empty($mtm_active) || !empty($mtm_pending);
$mtm_locked = false;
$dashboard_default_tab = 'personal';

// Fix exclusive OR logic: Only one section should be active
if (!empty($mtm_active)) {
    $mtm_locked = true;
    $dashboard_default_tab = 'mtm';
} elseif (!empty($mtm_pending)) {
    $dashboard_default_tab = 'personal';
} else {
    $dashboard_default_tab = 'personal';
}

// Flash message
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Include header
include __DIR__.'/header.php';
?>

<style>
/* PARROT GREEN BUTTON STYLING */
.parrot-green-btn{
  background: linear-gradient(135deg, #22C55E 0%, #16A34A 50%, #15803d 100%) !important;
  color:#fff !important;
  border:none !important;
  position:relative !important;
  overflow:hidden !important;
  transition: all 0.3s ease !important;
  box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3) !important;
  text-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
}

.parrot-green-btn::before{
  content:'' !important;
  position:absolute !important;
  top:0 !important;
  left:-100% !important;
  width:100% !important;
  height:100% !important;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent) !important;
  transition: left 0.6s ease !important;
}

.parrot-green-btn:hover{
  transform: translateY(-2px) !important;
  box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4) !important;
  background: linear-gradient(135deg, #16A34A 0%, #15803d 50%, #166534 100%) !important;
}

.parrot-green-btn:hover::before{
  left:100% !important;
}

.parrot-green-btn:active{
  transform: translateY(0) !important;
  box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3) !important;
}

.parrot-green-btn:disabled{
  background: linear-gradient(135deg, #9ca3af 0%, #d1d5db 100%) !important;
  cursor:not-allowed !important;
  transform: none !important;
  box-shadow: none !important;
}



/* SIMPLIFIED GLOBAL CONTAINMENT */
*{
  box-sizing:border-box;
}

html, body{
  margin:0;
  padding:0;
  font-family:Inter,system-ui,Arial,sans-serif;
  background:#f6f7fb;
  color:#111;
  overflow-x:hidden;
  max-width:100vw;
}

/* MAIN CONTAINER - SIMPLIFIED AND CONSISTENT */
.wrap{
  max-width:1200px;
  margin:0 auto;
  padding:20px 16px;
  overflow-x:hidden;
  box-sizing:border-box;
  width:100%;
}

@media (min-width:1400px){
  .wrap{max-width:1400px;}
}

@media (min-width:1600px){
  .wrap{max-width:1600px;}
}

/* BASIC ELEMENTS */
.card{
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:12px;
  padding:14px;
  margin-bottom:16px;
}
input,select,textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;background:#fff}
textarea{min-height:90px}
.btn{border:0;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer;font-size:13px}
.btn-ghost{background:#fff;border:1px solid #d1d5db}

/* Enhanced New Trade Button with Modern Styling */
.btn-primary{
  background: linear-gradient(135deg, #5a2bd9 0%, #7c3aed 50%, #9333ea 100%);
  color:#fff;
  border:none;
  position:relative;
  overflow:hidden;
  transition: all 0.3s ease;
  box-shadow: 0 4px 15px rgba(90, 43, 217, 0.3);
  text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.btn-primary::before{
  content:'';
  position:absolute;
  top:0;
  left:-100%;
  width:100%;
  height:100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
  transition: left 0.6s ease;
}

.btn-primary:hover{
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(90, 43, 217, 0.4);
  background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 50%, #a855f7 100%);
}

.btn-primary:hover::before{
  left:100%;
}

.btn-primary:active{
  transform: translateY(0);
  box-shadow: 0 4px 15px rgba(90, 43, 217, 0.3);
}

.btn-primary:disabled{
  background: linear-gradient(135deg, #9ca3af 0%, #d1d5db 100%);
  cursor:not-allowed;
  transform: none;
  box-shadow: none;
}
.pill{display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;padding:2px 8px;border-radius:999px;font-size:12px}
table{width:100%;border-collapse:separate;border-spacing:0}
th,td{padding:10px;border-bottom:1px solid #eee;vertical-align:top}
th{text-align:left;color:#374151}
.success{background:#dcfce7;border:1px solid #14532d;color:#14532d;padding:10px;border-radius:8px}
.badge{display:inline-block;padding:3px 6px;border-radius:4px;font-size:10px;font-weight:600;text-transform:uppercase}
.badge-success{background:#dcfce7;color:#166534}
.badge-warning{background:#fef3c7;color:#92400e}
.badge-danger{background:#fee2e2;color:#991b1b}

/* DASHBOARD PANES */
.dashboard-panes{
  margin-top:24px;
}
.dashboard-pane{
  display:none;
}
.dashboard-pane.is-active{
  display:block;
}

/* SECTION CARDS - COMPACT DESIGN */
.section-card{
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:12px;
  padding:12px;
  margin-bottom:8px;
}
.section-card:last-child{
  margin-bottom:16px;
}

/* DASHBOARD HEADER */
.dashboard-header{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:16px;
}
.dashboard-header-title{
  display:flex;
  flex-direction:column;
  gap:10px;
  flex:1;
  min-width:200px;
}
.header-actions{
  margin-left:auto;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

/* TOGGLE SWITCH */
.view-toggle{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}
.toggle-switch{
  position:relative;
  display:inline-flex;
  background:#f1f5f9;
  border-radius:24px;
  padding:3px;
  border:1px solid #e2e8f0;
}
.toggle-option{
  position:relative;
  padding:6px 14px;
  border-radius:20px;
  font-weight:600;
  font-size:12px;
  cursor:pointer;
  transition:all 0.2s ease;
  color:#64748b;
  min-width:100px;
  text-align:center;
  white-space:nowrap;
}
.toggle-option.active{
  background:#4f46e5;
  color:#fff;
  box-shadow:0 2px 8px rgba(79,70,229,.3);
}
.toggle-option:not(.active):hover{
  background:#e2e8f0;
  color:#475569;
}

/* COMPACT TOGGLE CHIPS */
.compact-toggle{
  display:flex;
  gap:4px;
  align-items:center;
}
.toggle-chip{
  position:relative;
  padding:4px 8px;
  border-radius:12px;
  font-weight:500;
  font-size:11px;
  cursor:pointer;
  transition:all 0.2s ease;
  color:#6b7280;
  background:#f8fafc;
  border:1px solid #e2e8f0;
  text-align:center;
  white-space:nowrap;
  line-height:1.2;
}
.toggle-chip.active{
  background:#4f46e5;
  color:#fff;
  border-color:#4338ca;
  box-shadow:0 1px 3px rgba(79,70,229,.2);
}
.toggle-chip:not(.active):hover{
  background:#f1f5f9;
  color:#475569;
  border-color:#cbd5e1;
}

/* SECTION TITLES - COMPACT */
.section-title{
  margin:0 0 8px;
  font-size:13px;
  font-weight:700;
  color:#1f2937;
  display:flex;
  align-items:center;
  gap:6px;
}

/* CAPITAL SECTION - COMPACT */
.capital-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:8px;
}
.capital-row{
  display:grid;
  grid-template-columns:1fr;
  gap:6px;
}
.capital-item{
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  padding:6px 8px;
  border:1px solid #f3f4f6;
  border-radius:6px;
  background:#fafafa;
}
.capital-item .item-label{
  font-size:10px;
  color:#6b7280;
  font-weight:500;
  margin-bottom:2px;
  text-transform:uppercase;
  letter-spacing:0.5px;
}
.capital-item .item-value{
  font-size:13px;
  font-weight:700;
}

/* KPI SECTION - COMPACT */
.kpi-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:8px;
}
.kpi-row{
  display:grid;
  grid-template-columns:1fr;
  gap:6px;
}
.kpi-item{
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  padding:6px 8px;
  border:1px solid #f3f4f6;
  border-radius:6px;
  background:#fafafa;
}
.kpi-item .item-label{
  font-size:10px;
  color:#6b7280;
  font-weight:500;
  margin-bottom:2px;
  text-transform:uppercase;
  letter-spacing:0.5px;
}
.kpi-item .item-value{
  font-size:13px;
  font-weight:700;
  line-height:1.2;
}
.kpi-item .item-subvalue{
  font-size:9px;
  color:#9ca3af;
  margin-top:1px;
  line-height:1.2;
}

/* VALUE COLORS */
.card-value.positive{color:#059669}
.card-value.negative{color:#dc2626}
.card-value.warning{color:#d97706}
.card-value.primary{color:#5a2bd9}
.card-value.info{color:#0891b2}

/* MATRIX METRICS */
.matrix-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
  gap:12px;
}
.matrix-card{
  padding:14px;
  border:1px solid #f1f5f9;
  border-radius:12px;
  background:linear-gradient(135deg,#f0f9ff 0%,#e0f2fe 100%);
}
.matrix-card .card-title{
  font-size:11px;
  color:#0c4a6e;
  margin-bottom:6px;
  text-transform:uppercase;
  letter-spacing:0.5px;
}
.matrix-card .card-value{
  font-size:18px;
  font-weight:800;
}
.matrix-card .card-subtitle{
  font-size:11px;
  color:#0ea5e9;
}

/* MTM LOCK CARD */
.mtm-lock-card{
  background:#fef3c7;
  border:1px solid #f59e0b;
  border-radius:14px;
  padding:18px;
  margin-top:16px;
  display:flex;
  flex-direction:column;
  gap:12px;
}
.mtm-lock-card .lock-title{
  font-size:16px;
  font-weight:700;
  color:#b45309;
}
.mtm-lock-card p{
  margin:0;
  color:#92400e;
  line-height:1.5;
  word-wrap:break-word;
}
.mtm-lock-links{
  display:flex;
  flex-direction:column;
  gap:10px;
}
.mtm-lock-link{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.mtm-lock-links a{
  padding:8px 14px;
  border-radius:10px;
  font-weight:600;
  text-decoration:none;
}
.mtm-lock-links a.primary{
  background:#f97316;
  color:#fff;
}
.mtm-lock-links a.secondary{
  background:#fff;
  border:1px solid #f97316;
  color:#b45309;
}
.pulse{animation:pulseGlow 1.2s ease}
@keyframes pulseGlow{0%{box-shadow:0 0 0 0 rgba(249,115,22,.45);}100%{box-shadow:0 0 0 18px rgba(249,115,22,0);}}

/* MTM SECTION */
.mtm-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(290px,1fr));
  gap:16px;
}
.mtm-card{
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:14px;
  padding:18px;
  display:flex;
  flex-direction:column;
  gap:12px;
  box-shadow:0 8px 24px rgba(15,23,42,.05);
}
.mtm-card-header{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:12px;
}
.mtm-card h3{
  margin:0;
  font-size:18px;
  color:#1f2937;
  flex:1;
}
.mtm-progress{
  display:flex;
  flex-direction:column;
  gap:6px;
}
.mtm-progress-meta{
  display:flex;
  justify-content:space-between;
  font-size:13px;
  color:#4b5563;
}
.mtm-progress-meta strong{
  color:#1f2937;
}
.progress-track{
  height:10px;
  border-radius:999px;
  background:#e5e7eb;
  width:100%;
}
.progress-fill{
  height:100%;
  background:#4f46e5;
  transition:width .3s ease;
}
.mtm-nudge{
  background:#eef2ff;
  border:1px solid #c7d2fe;
  color:#3730a3;
  padding:10px;
  border-radius:10px;
  font-size:13px;
  line-height:1.4;
}
.mtm-actions{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
}
.mtm-actions a{
  padding:8px 14px;
  border-radius:10px;
  font-weight:600;
  text-decoration:none;
  border:1px solid #d1d5db;
  color:#1f2937;
  background:#fff;
}
.mtm-actions a.primary{
  background:#4f46e5;
  color:#fff;
  border-color:#4338ca;
}
.mtm-empty{
  background:#fff;
  border:1px dashed #cbd5f5;
  border-radius:12px;
  padding:32px;
  text-align:center;
  color:#6b7280;
}
.mtm-empty a{
  color:#4f46e5;
  font-weight:600;
}
.mtm-section-title{
  margin:0 0 12px;
  font-size:16px;
  font-weight:700;
  color:#1f2937;
}

/* Trading Journal Enhanced Styling */
.trading-journal-card{
  background:#ffffff;
  border:1px solid #e5e7eb;
  border-radius:16px;
  padding:0;
  overflow:hidden;
  box-shadow:0 4px 16px rgba(15, 23, 42, 0.08);
  border-left:none;
}

.trading-journal-header{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:16px 20px;
  background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  border-bottom:1px solid #e2e8f0;
}

.trading-journal-title{
  margin:0;
  font-size:16px;
  font-weight:700;
  color:#1e293b;
  display:flex;
  align-items:center;
  gap:8px;
}

.trading-journal-actions{
  display:flex;
  gap:6px;
  flex-wrap:wrap;
}

/* Button sizing */
.trading-journal-actions .btn{
  padding:6px 12px;
  font-size:11px;
  font-weight:600;
  white-space:nowrap;
  flex-shrink:0;
  min-height:32px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}

.trading-journal-actions .btn-primary{
  background:#5a2bd9;
  color:#fff;
}

.trading-journal-actions .btn-ghost{
  background:#fff;
  border:1px solid #d1d5db;
  color:#374151;
}

/* TRADING TABLE - SIMPLIFIED CONTAINMENT */
.trading-table-container{
  overflow-x:auto;
  border:1px solid #e5e7eb;
  border-radius:8px;
  background:#fff;
  margin:0;
  width:100%;
}
.trading-table{
  border-collapse:separate;
  border-spacing:0;
  width:100%;
  min-width:800px;
  background:#fff;
}
.trading-table th,
.trading-table td{
  padding:12px 10px;
  border-bottom:1px solid #e5e7eb;
  border-right:1px solid #f3f4f6;
  white-space:nowrap;
  vertical-align:middle;
}
.trading-table th:last-child,
.trading-table td:last-child{
  border-right:none;
}
.trading-table thead th{
  position:sticky;
  top:0;
  background:#f8fafc;
  z-index:10;
  font-weight:700;
  color:#475569;
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:0.75px;
  border-bottom:2px solid #e2e8f0;
}
.trading-table tbody tr:hover{
  background:#f8fafc;
}

/* ACTION BUTTONS */
.action-btn{
  display:inline-block;
  padding:6px 10px;
  border-radius:6px;
  font-size:11px;
  font-weight:600;
  text-decoration:none;
  border:none;
  cursor:pointer;
  transition:all 0.2s ease;
  margin-right:3px;
  text-align:center;
}
.edit-btn{
  background:#eef2ff;
  color:#5a2bd9;
  border:1px solid #c7d2fe;
}
.edit-btn:hover{
  background:#e0e7ff;
  color:#4c1d95;
}
.delete-btn{
  background:#fef2f2;
  color:#dc2626;
  border:1px solid #fecaca;
}
.delete-btn:hover{
  background:#fee2e2;
  color:#b91c1c;
}
.unlock-btn{
  background:#ecfeff;
  color:#0891b2;
  border:1px solid #a5f3fc;
}
.unlock-btn:hover{
  background:#cffafe;
  color:#0e7490;
}
.action-btn form{
  display:inline;
  margin:0;
  padding:0;
}

/* STOCK SYMBOL LINK */
.stock-symbol-link{
  color:#5a2bd9;
  text-decoration:none;
  font-weight:700;
  padding:2px 4px;
  border-radius:4px;
}
.stock-symbol-link:hover{
  background:#f3f4f6;
  color:#4c1d95;
}
/* ENHANCED MOBILE RESPONSIVE DESIGN */

/* Tablet and below - Reduce widget prominence */
@media (max-width:768px){
  /* Stack Capital & Performance widgets vertically */
  div[style*="grid-template-columns:1fr 1fr"] {
    grid-template-columns:1fr !important;
    gap:6px !important;
  }
  
  /* Reduce widget padding and make more compact */
  .section-card{padding:8px !important;margin-bottom:6px !important;}
  .section-title{font-size:12px !important;margin-bottom:4px !important;}
  
  /* Adjust capital and KPI items for better mobile fit */
  .capital-grid,.kpi-grid{gap:6px !important;}
  .capital-item,.kpi-item{padding:5px !important;}
  .capital-item .item-label,.kpi-item .item-label{font-size:9px !important;}
  .capital-item .item-value,.kpi-item .item-value{font-size:12px !important;}
  .capital-item .item-subvalue,.kpi-item .item-subvalue{font-size:8px !important;}
  
  /* Matrix cards more compact */
  .matrix-card{padding:10px !important;}
  .matrix-card .card-title{font-size:10px !important;}
  .matrix-card .card-value{font-size:16px !important;}
  .matrix-card .card-subtitle{font-size:10px !important;}
}

/* Mobile phones - Maximum compactness */
@media (max-width:520px){
  /* Further reduce widget prominence on small screens */
  .section-card{padding:6px !important;margin-bottom:4px !important;border-radius:8px !important;}
  .section-title{font-size:11px !important;margin-bottom:3px !important;}
  
  /* Ultra-compact capital and KPI items */
  .capital-grid,.kpi-grid{gap:4px !important;}
  .capital-item,.kpi-item{padding:3px 4px !important;border-radius:4px !important;}
  .capital-item .item-label,.kpi-item .item-label{
    font-size:8px !important;
    margin-bottom:1px !important;
    line-height:1.1 !important;
  }
  .capital-item .item-value,.kpi-item .item-value{
    font-size:11px !important;
    line-height:1.1 !important;
    word-break:break-word !important;
  }
  .capital-item .item-subvalue,.kpi-item .item-subvalue{
    font-size:7px !important;
    line-height:1.1 !important;
    margin-top:1px !important;
  }
  
  /* Ensure proper text wrapping and prevent truncation */
  .capital-item .item-value,.kpi-item .item-value,
  .capital-item .item-subvalue,.kpi-item .item-subvalue{
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
    max-width:100% !important;
  }
  
  /* Matrix cards ultra-compact */
  .matrix-card{padding:8px !important;border-radius:8px !important;}
  .matrix-card .card-title{font-size:9px !important;margin-bottom:4px !important;}
  .matrix-card .card-value{font-size:14px !important;}
  .matrix-card .card-subtitle{font-size:9px !important;}
  
  /* Keep trading table scrollable but reduce min-width */
  .trading-table{min-width:750px !important;}
  
  /* Stock links more compact */
  .stock-symbol-link{padding:3px 5px !important;font-size:13px !important;}
  
  /* Ensure trading journal remains prominent */
  .trading-journal-card{margin-top:12px !important;}
  .trading-journal-header{padding:12px 14px !important;}
  .trading-journal-title{font-size:15px !important;}
}

/* Extra small screens - Maximum compression */
@media (max-width:380px){
  /* Even more compact widgets */
  .section-card{padding:4px !important;margin-bottom:3px !important;}
  .section-title{font-size:10px !important;margin-bottom:2px !important;}
  
  /* Minimal spacing for ultra-compact display */
  .capital-grid,.kpi-grid{gap:3px !important;}
  .capital-item,.kpi-item{padding:2px 3px !important;}
  .capital-item .item-label,.kpi-item .item-label{font-size:7px !important;}
  .capital-item .item-value,.kpi-item .item-value{font-size:10px !important;}
  .capital-item .item-subvalue,.kpi-item .item-subvalue{font-size:6px !important;}
  
  /* Matrix cards minimal */
  .matrix-card{padding:6px !important;}
  .matrix-card .card-title{font-size:8px !important;}
  .matrix-card .card-value{font-size:12px !important;}
  .matrix-card .card-subtitle{font-size:8px !important;}
  
  /* Allow horizontal scrolling for trading table */
  .trading-table{min-width:700px !important;}
  
  /* Compact stock links */
  .stock-symbol-link{padding:2px 4px !important;font-size:12px !important;}
  
  /* Minimal trading journal header */
  .trading-journal-header{padding:10px 12px !important;}
  .trading-journal-title{font-size:14px !important;}
  
  /* Compact action buttons */
  .trading-journal-actions .btn{
    padding:4px 8px !important;
    font-size:10px !important;
    min-height:28px !important;
  }
}

/* Keep original responsive styles for reference */
@media (max-width:720px){
  .header-actions{width:100%;justify-content:flex-start}
  .trading-journal-header{flex-direction:column;align-items:flex-start;gap:12px;padding:16px}
  .trading-journal-actions{width:100%;justify-content:flex-start}
}

</style>

<main>
<div class="wrap">
    <?php if($flash): ?>
        <div class="success"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="card dashboard-header">
        <div class="dashboard-header-title">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <h2 style="margin:0">📊 Trading Dashboard</h2>
                <?php if ($has_mtm_data): ?>
                    <div class="compact-toggle">
                        <button type="button" class="toggle-chip <?= $dashboard_default_tab === 'personal' ? 'active' : '' ?>" data-target="personal-pane">Personal</button>
                        <button type="button" class="toggle-chip <?= $dashboard_default_tab === 'mtm' ? 'active' : '' ?>" data-target="mtm-pane">MTM</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($mtm_locked): ?>
        <div id="mtm-guidance" class="card mtm-lock-card">
            <div class="lock-title">MTM trade flow active</div>
            <p>You are currently enrolled in an MTM program. Log trades from your unlocked MTM tasks so progress and compliance stay in sync.</p>
            <?php if (!empty($mtm_guidance_links)): ?>
                <div class="mtm-lock-links">
                    <?php foreach ($mtm_guidance_links as $link): ?>
                        <div class="mtm-lock-link">
                            <a class="primary" href="<?= htmlspecialchars($link['href']) ?>">Open <?= htmlspecialchars($link['label']) ?></a>
                            <?php if (!empty($link['task_href'])): ?>
                                <a class="secondary" href="<?= htmlspecialchars($link['task_href']) ?>">
                                    <?= !empty($link['task_title']) ? 'Log trade for ' . htmlspecialchars($link['task_title']) : 'Log next MTM trade' ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p style="font-size:12px;color:#b45309;">Need a personal journal entry? Complete or archive your MTM program first.</p>
        </div>
    <?php endif; ?>

    <div class="dashboard-panes">
        <div id="personal-pane" class="dashboard-pane <?= $dashboard_default_tab === 'personal' ? 'is-active' : '' ?>">
            <!-- Compact Capital & Performance Widgets -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                <!-- Capital Overview Widget -->
                <div class="section-card" style="margin-bottom:0;padding:10px;">
                    <h3 class="section-title" style="margin-bottom:6px;">💰 Capital</h3>
                    <div class="capital-grid">
                        <div class="capital-row">
                            <div class="capital-item">
                                <span class="item-label">Total</span>
                                <span class="item-value" style="color:#5a2bd9"><?= money($tot_cap) ?></span>
                            </div>
                            <div class="capital-item">
                                <span class="item-label">Available</span>
                                <span class="item-value" style="color:#059669"><?= money($available) ?></span>
                            </div>
                        </div>
                        <div class="capital-row">
                            <div class="capital-item">
                                <span class="item-label">Reserved</span>
                                <span class="item-value" style="color:#d97706"><?= money($reserved) ?></span>
                            </div>
                            <div class="capital-item">
                                <span class="item-label">P&L</span>
                                <span class="item-value" style="color:<?= $profit_loss >= 0 ? '#059669' : '#dc2626' ?>"><?= money($profit_loss) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Matrix Widget -->
                <div class="section-card" style="margin-bottom:0;padding:10px;">
                    <h3 class="section-title" style="margin-bottom:6px;">📊 Performance</h3>
                    <div class="kpi-grid">
                        <div class="kpi-row">
                            <div class="kpi-item">
                                <span class="item-label">Total Trades</span>
                                <span class="item-value"><?= (int)$stats['total_trades'] ?><span class="item-subvalue"> (<?= (int)$stats['open_positions'] ?> open)</span></span>
                            </div>
                            <div class="kpi-item">
                                <span class="item-label">Win Rate</span>
                                <span class="item-value" style="color:#5a2bd9"><?= $stats['closed_trades'] > 0 ? round(($stats['winning_trades'] / $stats['closed_trades']) * 100) : 0 ?>%<span class="item-subvalue"> (<?= (int)$stats['winning_trades'] ?> wins)</span></span>
                            </div>
                        </div>
                        <div class="kpi-row">
                            <div class="kpi-item">
                                <span class="item-label">Avg RR</span>
                                <span class="item-value" style="color:#0891b2"><?= format_rr($avg_rr) ?></span>
                            </div>
                            <div class="kpi-item">
                                <span class="item-label">Open Risk</span>
                                <span class="item-value" style="color:#d97706"><?= money($open_risk_amount) ?><span class="item-subvalue"> (<?= number_format($open_risk_percentage, 1) ?>%)</span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="trading-journal-card">
                <div class="trading-journal-header">
                    <h3 class="trading-journal-title">📋 Trading Journal</h3>
                    <div class="trading-journal-actions">
                        <?php if ($mtm_locked): ?>
                            <button type="button" class="btn parrot-green-btn" id="mtmLockedBtn" style="font-size: 14px; font-weight: 600; text-decoration: none;">+ Add New Trade</button>
                        <?php else: ?>
                            <?php if ($available <= 0): ?>
                                <button class="btn parrot-green-btn" disabled title="Insufficient available funds" style="font-size: 14px; font-weight: 600; text-decoration: none;">+ Add New Trade</button>
                            <?php else: ?>
                                <a href="/trade_new.php" class="btn parrot-green-btn" style="font-size: 14px; font-weight: 600; text-decoration: none;">+ Add New Trade</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if(empty($rows)): ?>
                    <p style="text-align:center;color:#6b7280;margin:40px 0">
                        <?php if ($mtm_locked): ?>
                            MTM journal entries will appear once you log trades from your active program.
                        <?php else: ?>
                            No trades found. <a href="/trade_new.php" style="color:#5a2bd9">Create your first trade</a>
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <div class="trading-table-container">
                        <table class="trading-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Symbol</th>
                                    <th>Date</th>
                                    <th>Position %</th>
                                    <th>Entry</th>
                                    <th>Stop Loss</th>
                                    <th>Target</th>
                                    <th>RR</th>
                                    <th>P&L</th>
                                    <th>Amount Invested</th>
                                    <th>Risk Amount</th>
                                    <th>Risk per Trade %</th>
                                    <th>Quantity</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $snapshot_meta = [];
                                    if (!empty($r['rules_snapshot'])) {
                                        $decoded = json_decode($r['rules_snapshot'], true);
                                        if (is_array($decoded)) {
                                            if (isset($decoded['trade_inputs']) && is_array($decoded['trade_inputs'])) {
                                                $snapshot_meta = $decoded['trade_inputs'];
                                            } else {
                                                $snapshot_meta = $decoded;
                                            }
                                        }
                                    }
                                    $trade_id = (int)($r['id'] ?? 0);
                                    $symbol = h($r['symbol'] ?? 'N/A');
                                    $entry_date = isset($r['entry_date']) ? h($r['entry_date']) : h(date('Y-m-d'));
                                    $position_pct = isset($r['position_percent']) ? (float)$r['position_percent']
                                        : (isset($r['risk_pct']) ? (float)$r['risk_pct']
                                        : (isset($snapshot_meta['position_percent']) ? (float)$snapshot_meta['position_percent'] : 0));
                                    $entry_price = isset($r['entry_price']) ? (float)$r['entry_price'] : 0;
                                    $stop_loss = isset($r['stop_loss']) ? (float)$r['stop_loss']
                                        : (isset($snapshot_meta['stop_loss']) ? (float)$snapshot_meta['stop_loss'] : null);
                                    $target_price = isset($r['target_price']) ? (float)$r['target_price']
                                        : (isset($snapshot_meta['target_price']) ? (float)$snapshot_meta['target_price'] : null);
                                    $pnl = $has_pnl && isset($r['pnl']) ? (float)$r['pnl'] : null;
                                    $pl_percent = $has_pl_percent && isset($r['pl_percent']) ? (float)$r['pl_percent'] : null;
                                    $closed_val = $has_closed_at ? ($r['closed_at'] ?? '') : ($has_close_date ? ($r['close_date'] ?? null) : null);
                                    $deleted_at = $has_deleted_at ? ($r['deleted_at'] ?? '') : '';
                                    $outcome_val = $has_outcome ? strtoupper(trim((string)($r['outcome'] ?? 'OPEN'))) : 'OPEN';
                                    $is_closed = $has_closed_at ? !empty($closed_val) : ($has_close_date ? !empty($closed_val) : ($outcome_val !== 'OPEN'));
                                    $is_deleted = !empty($deleted_at);

                                    $status_class = $is_deleted ? 'badge-danger' : ($is_closed ? 'badge-success' : 'badge-warning');
                                    $status_text = $is_deleted ? 'Deleted' : ($is_closed ? 'Closed' : 'Open');

                                    // Calculate metrics with updated formatting
                                    $amount_invested = ($tot_cap * $position_pct) / 100;
                                    
                                    // Risk Amount
                                    $risk_amount = 0;
                                    if ($entry_price > 0 && $stop_loss > 0) {
                                        $risk_amount = abs($entry_price - $stop_loss) * ($amount_invested / $entry_price);
                                    }
                                    
                                    // Risk per Trade (RPT) %
                                    $risk_per_trade = $tot_cap > 0 ? ($risk_amount / $tot_cap) * 100 : 0;
                                    
                                    // Risk to Reward (RR) Ratio
                                    $rr_ratio = '';
                                    $rr_value_formatted = 0;
                                    if ($entry_price > 0 && $stop_loss > 0 && $exit_price > 0) {
                                        $risk_price = abs($entry_price - $stop_loss);
                                        $reward_price = abs($exit_price - $entry_price);
                                        if ($risk_price > 0) {
                                            $rr_ratio = $reward_price / $risk_price;
                                            $rr_value_formatted = $rr_ratio;
                                        }
                                    }
                                    
                                    // Number of Quantity (rounded to whole numbers)
                                    $quantity = 0;
                                    if ($entry_price > 0) {
                                        $quantity = round($amount_invested / $entry_price);
                                    }

                                    // Action Button Logic
                                    if ($is_deleted) {
                                        $actionBtns = '<span style="color:#94a3b8;font-size:12px;">—</span>';
                                    } elseif (!$is_closed) {
                                        // OPEN trades: Edit + Delete buttons
                                        $actionBtns = '<a href="/trade_edit.php?id=' . $trade_id . '" class="action-btn edit-btn">Edit</a>';
                                        $actionBtns .= ' <form method="POST" action="" style="display:inline" onsubmit="return confirm(\'Delete this trade?\')">';
                                        $actionBtns .= '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
                                        $actionBtns .= '<input type="hidden" name="action" value="soft_delete">';
                                        $actionBtns .= '<input type="hidden" name="trade_id" value="' . $trade_id . '">';
                                        $actionBtns .= '<button type="submit" class="action-btn delete-btn">Delete</button>';
                                        $actionBtns .= '</form>';
                                    } else {
                                        // CLOSED trades: Check unlock status
                                        $unlock_requested = false;
                                        
                                        // Check if unlock_status column exists and has pending status
                                        if (column_exists($mysqli, 'trades', 'unlock_status')) {
                                            $unlock_status = $r['unlock_status'] ?? '';
                                            if (strtolower($unlock_status) === 'pending' || strtolower($unlock_status) === 'approved') {
                                                $unlock_requested = true;
                                            }
                                        }
                                        
                                        // Check if request exists in session (fallback method)
                                        if (!$unlock_requested && isset($_SESSION['unlock_request_' . $trade_id])) {
                                            $unlock_requested = true;
                                        }
                                        
                                        if ($unlock_requested) {
                                            // Show "Request Sent" status
                                            $actionBtns = '<span style="color:#0891b2;font-size:11px;font-weight:600;">Request Sent</span>';
                                        } else {
                                            // Show "Request for Unlock" button
                                            $actionBtns = '<a href="/trade_unlock_request.php?id=' . $trade_id . '" class="action-btn unlock-btn">Request Unlock</a>';
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td style="font-weight:600">#<?= $trade_id ?></td>
                                        <td>
                                            <a href="/trade_view.php?id=<?= $trade_id ?>" class="stock-symbol-link" title="View trade details">
                                                <?= $symbol ?>
                                            </a>
                                        </td>
                                        <td><?= $entry_date ?></td>
                                        <td><?= $position_pct !== 0 ? number_format($position_pct, 2) . '%' : '—' ?></td>
                                        <td><?= $entry_price > 0 ? money($entry_price) : '—' ?></td>
                                        <td><?= ($stop_loss !== null && $stop_loss > 0) ? money($stop_loss) : '—' ?></td>
                                        <td><?= ($target_price !== null && $target_price > 0) ? money($target_price) : '—' ?></td>
                                        <td style="font-weight:700;color:#5a2bd9"><?= $rr_ratio !== '' ? format_rr($rr_value_formatted) : '—' ?></td>
                                        <td style="font-weight:700; color: <?php
                                            $positive = $pnl !== null ? ($pnl >= 0) : ($pl_percent !== null ? ($pl_percent >= 0) : true);
                                            echo $positive ? '#059669' : '#dc2626';
                                        ?>;">
                                            <?php
                                            // Display both P&L amount and percentage
                                            if ($pnl !== null && $pl_percent !== null) {
                                                // Show both amount and percentage: "₹500 (10.5%)"
                                                echo money($pnl) . ' (' . number_format($pl_percent, 1) . '%)';
                                            } elseif ($pnl !== null && $pnl != 0) {
                                                // Show only amount if percentage not available
                                                echo money($pnl);
                                            } elseif ($pl_percent !== null && $pl_percent != 0) {
                                                // Show only percentage if amount not available
                                                echo number_format($pl_percent, 2) . '%';
                                            } else {
                                                echo '—';
                                            }
                                            ?>
                                        </td>
                                        <td><?= money($amount_invested) ?></td>
                                        <td><?= money($risk_amount) ?></td>
                                        <td><?= number_format($risk_per_trade, 1) ?>%</td>
                                        <td><?= number_format($quantity, 0) ?></td>
                                        <td><span class="badge <?= $status_class ?>"><?= $status_text ?></span></td>
                                        <td><?= $actionBtns ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Export PDF Button - Below Trading Journal Table (Right Side, Unnoticeable) -->
                    <div style="margin-top: 8px; text-align: right; padding: 8px 0; border-top: 1px solid #f3f4f6;">
                        <a href="?export=1" style="font-size: 10px; color: #9ca3af; text-decoration: none; opacity: 0.6; padding: 4px 8px; border-radius: 4px; border: 1px solid #e5e7eb; background: #f9fafb;">PDF</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($has_mtm_data): ?>
            <div id="mtm-pane" class="dashboard-pane <?= $dashboard_default_tab === 'mtm' ? 'is-active' : '' ?>">
                <?php if (!empty($mtm_active)): ?>
                    <h3 class="mtm-section-title">Active MTM</h3>
                    <div class="mtm-grid">
                        <?php foreach ($mtm_active as $enrollment): 
                            $progress_pct = (float)($enrollment['progress_pct'] ?? 0);
                            $progress_pct = max(0, min(100, $progress_pct));
                            $next_task = $enrollment['current_task'] ?? null;
                            $next_summary = '';
                            if ($next_task && !empty($next_task['description'])) {
                                $next_summary = trim(strip_tags($next_task['description']));
                                if (strlen($next_summary) > 140) {
                                    $next_summary = substr($next_summary, 0, 137) . '…';
                                }
                            }
                        ?>
                            <div class="mtm-card">
                                <div class="mtm-card-header">
                                    <h3><?= htmlspecialchars($enrollment['title']) ?></h3>
                                    <span class="badge badge-success">Active</span>
                                </div>
                                <div style="font-size:13px;color:#6b7280;">
                                    Difficulty: <?= htmlspecialchars(ucfirst($enrollment['difficulty'])) ?>
                                </div>
                                <div class="mtm-progress">
                                    <div class="mtm-progress-meta">
                                        <span><strong><?= (int)$enrollment['completed_tasks'] ?></strong> / <?= (int)$enrollment['total_tasks'] ?> tasks</span>
                                        <span><?= round($progress_pct) ?>%</span>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-fill" style="width: <?= $progress_pct ?>%"></div>
                                    </div>
                                </div>
                                <?php if ($next_task): ?>
                                    <div class="mtm-nudge">
                                        Next focus: <strong><?= htmlspecialchars($next_task['title']) ?></strong>
                                        <?php if ($next_summary): ?><br><?= htmlspecialchars($next_summary) ?><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="mtm-nudge">
                                        Great progress! Log your next trade or review completed tasks to advance.
                                    </div>
                                <?php endif; ?>
                                <div class="mtm-actions">
                                    <a class="primary" href="/mtm_model_user.php?id=<?= (int)$enrollment['model_id'] ?>">Open program</a>
                                    <?php if ($next_task): ?>
                                        <a href="/trade_new.php?mtm_task=<?= (int)$next_task['id'] ?>">Log MTM trade</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="mtm-empty">
                        <p>No active MTM enrollments yet.</p>
                        <a href="/mtm.php">Explore MTM programs</a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($mtm_pending)): ?>
                    <h3 class="mtm-section-title">Pending approvals</h3>
                    <div class="mtm-grid">
                        <?php foreach ($mtm_pending as $enrollment): ?>
                            <div class="mtm-card">
                                <div class="mtm-card-header">
                                    <h3><?= htmlspecialchars($enrollment['title']) ?></h3>
                                    <span class="badge badge-warning">Pending</span>
                                </div>
                                <div style="font-size:13px;color:#6b7280;">
                                    Submitted <?= !empty($enrollment['requested_at']) ? date('M j, Y', strtotime($enrollment['requested_at'])) : 'Recently' ?>
                                </div>
                                <div class="mtm-nudge" style="background:#fff7ed;border-color:#fcd34d;color:#92400e;">
                                    We'll notify you once the admin approves this program.
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    </main>
</div>


</style>

<script>
document.addEventListener('DOMContentLoaded', function() {

const toggleButtons = document.querySelectorAll('.toggle-chip');
    const panes = document.querySelectorAll('.dashboard-pane');
    
    // Enhanced toggle function with exclusive OR logic
    function setActiveSection(targetId) {
        // Defensive check: remove active class from all toggle buttons
        toggleButtons.forEach(btn => btn.classList.remove('active'));
        
        // Defensive check: hide all panes
        panes.forEach(pane => pane.classList.remove('is-active'));
        
        // Find and activate the target toggle button
        const targetBtn = document.querySelector(`[data-target="${targetId}"]`);
        if (targetBtn) {
            targetBtn.classList.add('active');
        }
        
        // Find and show the target pane
        const targetPane = document.getElementById(targetId);
        if (targetPane) {
            targetPane.classList.add('is-active');
        }
    }
    
    // Initialize with default section (exclusive OR - only one active)
    const defaultPane = document.querySelector('.dashboard-pane.is-active');
    if (defaultPane) {
        setActiveSection(defaultPane.id);
    }
    
    // Attach click handlers to toggle buttons
    toggleButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = btn.dataset.target;
            
            // Ensure exclusive OR: only one section visible
            if (btn.classList.contains('active')) {
                // Already active, no change needed
                return;
            }
            
            setActiveSection(targetId);
        });
    });

    const mtmBtn = document.getElementById('mtmLockedBtn');
    if (mtmBtn) {
        mtmBtn.addEventListener('click', () => {
            const panel = document.getElementById('mtm-guidance');
            if (panel) {
                panel.classList.add('pulse');
                panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => panel.classList.remove('pulse'), 1200);
            }
        });
    }
});
</script>

<?php include __DIR__.'/footer.php'; ?>
