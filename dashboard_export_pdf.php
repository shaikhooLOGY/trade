<?php
// Enhanced Dashboard Export to PDF
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['user_id'])) {
    header('HTTP/1.0 401 Unauthorized');
    exit('Unauthorized');
}

$user_id = (int)$_SESSION['user_id'];

// Helper functions
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return '₹' . number_format((float)$n, 0); }
function money_with_decimals($n){ return '₹' . number_format((float)$n, 2); }
function format_rr($ratio) {
    if ($ratio == 0 || $ratio === '') return '—';
    $formatted = number_format($ratio, 1);
    return '1:' . $formatted . ' (' . $formatted . 'R)';
}
function column_exists(mysqli $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
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

// Get user capital information
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

// Calculate reserved capital
$reserved = 0.0;
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
if (empty($open_conditions)) $open_conditions[] = "1=0";

$where_open = '('. implode(' OR ', $open_conditions) .')';
if ($has_deleted_at) $where_open .= " AND (deleted_at IS NULL OR deleted_at = '')";

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

// available: funds_available - reserved
$available = $tot_cap - $reserved;
if ($has_user_funds_available && abs($funds_available_val - $available) > 0.01) {
    if ($stmt = $mysqli->prepare("UPDATE users SET funds_available = ? WHERE id = ?")) {
        $stmt->bind_param('di', $available, $user_id);
        $stmt->execute();
        $stmt->close();
        $funds_available_val = $available;
    }
}

// Calculate profit/loss
$profit_loss = 0.0;
if ($has_pnl) {
    try {
        $conditions = ["user_id = ?"];
        if ($has_deleted_at) $conditions[] = "(deleted_at IS NULL OR deleted_at='')";
        
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

// Get all trades for the user
$sql = "SELECT * FROM trades WHERE user_id={$user_id} ORDER BY id DESC";
$trade_q = $mysqli->query($sql);
$rows = $trade_q ? $trade_q->fetch_all(MYSQLI_ASSOC) : [];

// Calculate statistics
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

    $open_conditions = [];
    if ($has_closed_at) $open_conditions[] = "closed_at IS NULL";
    if ($has_outcome) $open_conditions[] = "UPPER(COALESCE(outcome,'OPEN'))='OPEN'";
    $openExpr = !empty($open_conditions) ? '('. implode(' OR ', $open_conditions) .')' : "0";
    
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

// Calculate average RR and open risk
$avg_rr = 0.0;
$open_risk_amount = 0.0;
$open_risk_percentage = 0.0;

try {
    $rr_sum = 0;
    $rr_count = 0;
    
    foreach ($rows as $r) {
        $entry_price = isset($r['entry_price']) ? (float)$r['entry_price'] : 0;
        $stop_loss = isset($r['stop_loss']) ? (float)$r['stop_loss'] : 0;
        $target_price = isset($r['target_price']) ? (float)$r['target_price'] : 0;
        
        if ($entry_price > 0 && $stop_loss > 0 && $target_price > 0) {
            $risk_price = abs($entry_price - $stop_loss);
            $reward_price = abs($target_price - $entry_price);
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
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Trading Dashboard - PDF Export</title>
<style>
    @page { 
        margin: 15mm; 
        size: A4;
    }
    body { 
        font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; 
        color:#111; 
        font-size: 11px;
        line-height: 1.4;
    }
    
    /* Header with Logo */
    .pdf-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding: 15px 20px;
        background: linear-gradient(135deg, #000 0%, #1a1a1a 100%);
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .header-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .header-logo {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid rgba(255,255,255,0.2);
    }
    
    .header-title h1 {
        margin: 0;
        font-size: 20px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }
    
    /* ---- Eyes implementation from header.php (adapted for PDF) ---- */
    #pdf-header-ustaad{
        display:inline-flex;align-items:center;gap:2px;
        cursor:pointer;color:#fff;font-weight:800;
        text-shadow:0 0 8px var(--glow,#00a5b4);
        transition:text-shadow .2s ease,transform .12s ease;
    }
    #pdf-header-ustaad:hover{transform:scale(1.03);}
    #pdf-header-ustaad:active{transform:scale(.98);}

    /* Eyes wrapper – tight spacing with text */
    #pdf-header-ustaad .oo{
        position:relative;width:40px;height:26px;
        display:inline-block;margin:0 3px;
    }

    /* Eyes – larger than letters, faster default blink */
    #pdf-header-ustaad .eye{
        position:absolute;top:2px;
        width:18px;height:18px;
        border-radius:50%;background:#fff;border:2px solid #000;overflow:hidden;
        transform-origin:center;
        animation:blinkMed 3s infinite;
        box-shadow:0 0 10px var(--glow,#00a5b4);
    }
    #pdf-header-ustaad .eye.left{left:1px;transform:scale(.92);}
    #pdf-header-ustaad .eye.right{right:1px;transform:scale(1.08);animation-delay:.6s;}

    /* pupil as .dot */
    .dot{
        position:absolute;left:6px;top:6px;
        width:5px;height:5px;background:#000;border-radius:50%;
        transition:transform .4s ease;
    }

    #pdf-header-ustaad:hover .dot{transform:translateY(-1px);}

    /* Hover → even faster; Active → fastest */
    #pdf-header-ustaad:hover .eye{animation:blinkFast 1.2s infinite;}
    #pdf-header-ustaad:active .eye{animation:blinkTurbo .8s infinite;}

    @keyframes blinkMed{0%,86%,100%{transform:scaleY(1);}92%,96%{transform:scaleY(.12);}}
    @keyframes blinkFast{0%,78%,100%{transform:scaleY(1);}86%,90%{transform:scaleY(.12);}}
    @keyframes blinkTurbo{0%,70%,100%{transform:scaleY(1);}80%,84%{transform:scaleY(.12);}}
    
    .header-subtitle {
        font-size: 12px;
        opacity: 0.8;
        margin-top: 2px;
    }
    
    .header-meta {
        text-align: right;
        font-size: 10px;
        opacity: 0.9;
    }
    
    /* Summary Cards */
    .summary-section {
        margin-bottom: 20px;
    }
    
    .summary-title {
        font-size: 14px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 10px;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 5px;
    }
    
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 15px;
    }
    
    .summary-card {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 12px;
        background: #fff;
    }
    
    .summary-card h4 {
        margin: 0 0 8px 0;
        font-size: 11px;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .summary-value {
        font-size: 16px;
        font-weight: 700;
        color: #1f2937;
    }
    
    .summary-value.positive { color: #059669; }
    .summary-value.negative { color: #dc2626; }
    .summary-value.warning { color: #d97706; }
    .summary-value.primary { color: #5a2bd9; }
    
    .summary-subvalue {
        font-size: 9px;
        color: #9ca3af;
        margin-top: 2px;
    }
    
    /* Trading Table */
    .table-section {
        margin-top: 20px;
    }
    
    .table-title {
        font-size: 14px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 10px;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 5px;
    }
    
    table { 
        width: 100%; 
        border-collapse: collapse; 
        font-size: 10px;
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    th, td { 
        border: 1px solid #e5e7eb; 
        padding: 8px 6px; 
        text-align: left;
        vertical-align: middle;
    }
    
    th { 
        background: #f8fafc; 
        font-weight: 600; 
        color: #374151;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    tbody tr:nth-child(even) {
        background: #f9fafb;
    }
    
    tbody tr:hover {
        background: #f3f4f6;
    }
    
    .status-open { color: #d97706; font-weight: 600; }
    .status-closed { color: #059669; font-weight: 600; }
    .status-deleted { color: #dc2626; font-weight: 600; }
    
    .pnl-positive { color: #059669; font-weight: 600; }
    .pnl-negative { color: #dc2626; font-weight: 600; }
    
    /* Footer */
    .pdf-footer {
        margin-top: 30px;
        padding: 15px 20px;
        background: #0b0b0b;
        color: #aaa;
        text-align: center;
        border-radius: 8px;
        font-size: 12px;
    }
    
    /* Disclaimers Section */
    .disclaimers-section {
        margin-top: 30px;
        margin-bottom: 20px;
        border: 2px solid #dc2626;
        border-radius: 8px;
        background: #fff5f5;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1);
    }
    
    .disclaimer-title {
        text-align: center;
        font-size: 16px;
        font-weight: 800;
        color: #dc2626;
        margin: 0 0 20px 0;
        padding: 10px;
        background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        color: white;
        border-radius: 6px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .disclaimer-block {
        margin-bottom: 20px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 15px;
        background: white;
    }
    
    .disclaimer-subtitle {
        font-size: 12px;
        font-weight: 700;
        color: #1f2937;
        margin: 0 0 10px 0;
        padding-bottom: 5px;
        border-bottom: 2px solid #f3f4f6;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .disclaimer-list {
        margin: 0;
        padding-left: 0;
        list-style: none;
    }
    
    .disclaimer-list li {
        margin-bottom: 8px;
        padding-left: 15px;
        position: relative;
        font-size: 9px;
        line-height: 1.4;
        color: #374151;
    }
    
    .disclaimer-list li:before {
        content: "⚠";
        position: absolute;
        left: 0;
        color: #dc2626;
        font-weight: bold;
    }
    
    .disclaimer-list strong {
        color: #1f2937;
        font-weight: 600;
    }
    
    .acknowledgment-box {
        margin-top: 20px;
        padding: 15px;
        background: #fef3c7;
        border: 1px solid #f59e0b;
        border-radius: 6px;
        border-left: 4px solid #f59e0b;
    }
    
    .acknowledgment-box p {
        margin: 0;
        font-size: 10px;
        line-height: 1.4;
        color: #92400e;
        font-weight: 500;
    }
    
    .acknowledgment-box strong {
        color: #78350f;
        font-weight: 700;
    }
    
    /* Print optimizations */
    @media print {
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .pdf-header, .pdf-footer, .disclaimers-section { page-break-inside: avoid; }
        table { page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        tbody { display: table-row-group; }
    }
</style>
</head>
<body>
    <?php
    // Robust server URL detection to replace REQUEST_SCHEME
    function getServerUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
    ?>
    
    <!-- Header with Shaikhoology Logo and Eyes -->
    <div class="pdf-header">
        <div class="header-left">
            <img src="<?= getServerUrl() ?>/img/logo.png" alt="Logo" class="header-logo">
            <div class="header-title">
                <h1 id="pdf-header-ustaad" style="margin:0;">
                    <span class="brand">SHAIKH</span>
                    <span class="oo" aria-hidden="true">
                        <span class="eye left"><span class="dot"></span></span>
                        <span class="eye right"><span class="dot"></span></span>
                    </span>
                    <span class="brand">LOGY</span>
                </h1>
                <div class="header-subtitle">Trading Psychology Dashboard Report</div>
            </div>
        </div>
        <div class="header-meta">
            Generated: <?= date('Y-m-d H:i:s') ?><br>
            User ID: #<?= $user_id ?>
        </div>
    </div>

    <!-- Capital Overview Section -->
    <div class="summary-section">
        <h2 class="summary-title">💰 Capital Overview</h2>
        
        <div class="summary-grid">
            <div class="summary-card">
                <h4>Total Capital</h4>
                <div class="summary-value primary"><?= money($tot_cap) ?></div>
                <div class="summary-subvalue">Starting capital allocated</div>
            </div>
            <div class="summary-card">
                <h4>Available</h4>
                <div class="summary-value positive"><?= money($available) ?></div>
                <div class="summary-subvalue">Free for new trades</div>
            </div>
        </div>
        
        <div class="summary-grid">
            <div class="summary-card">
                <h4>Reserved</h4>
                <div class="summary-value warning"><?= money($reserved) ?></div>
                <div class="summary-subvalue">Allocated to open positions</div>
            </div>
            <div class="summary-card">
                <h4>P&L</h4>
                <div class="summary-value <?= $profit_loss >= 0 ? 'positive' : 'negative' ?>"><?= money($profit_loss) ?></div>
                <div class="summary-subvalue"><?= $profit_loss >= 0 ? 'Profit' : 'Loss' ?> from closed trades</div>
            </div>
        </div>
    </div>

    <!-- Performance Matrix Section -->
    <div class="summary-section">
        <h2 class="summary-title">📊 Performance & Risk Matrix</h2>
        
        <div class="summary-grid">
            <div class="summary-card">
                <h4>Total Trades</h4>
                <div class="summary-value"><?= (int)$stats['total_trades'] ?></div>
                <div class="summary-subvalue">Open: <?= (int)$stats['open_positions'] ?> | Closed: <?= (int)$stats['closed_trades'] ?></div>
            </div>
            <div class="summary-card">
                <h4>Win Rate</h4>
                <div class="summary-value primary">
                    <?= $stats['closed_trades'] > 0 ? round(($stats['winning_trades'] / $stats['closed_trades']) * 100) : 0 ?>%
                </div>
                <div class="summary-subvalue"><?= (int)$stats['winning_trades'] ?> wins of <?= (int)$stats['closed_trades'] ?> closed trades</div>
            </div>
        </div>
        
        <div class="summary-grid">
            <div class="summary-card">
                <h4>Average RR</h4>
                <div class="summary-value warning"><?= format_rr($avg_rr) ?></div>
                <div class="summary-subvalue">Risk:Reward Ratio</div>
            </div>
            <div class="summary-card">
                <h4>Open Risk</h4>
                <div class="summary-value warning"><?= money($open_risk_amount) ?></div>
                <div class="summary-subvalue"><?= number_format($open_risk_percentage, 1) ?>% of total capital</div>
            </div>
        </div>
    </div>

    <!-- Trading Table -->
    <div class="table-section">
        <h2 class="table-title">Trading Journal</h2>
        <table>
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
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="14" style="text-align: center; color: #6b7280;">No trades found.</td>
                    </tr>
                <?php else: foreach ($rows as $r):
                    // Enhanced trade data extraction with fallback to snapshots
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
                        
                    $entry_price = isset($r['entry_price']) ? (float)$r['entry_price']
                        : (isset($snapshot_meta['entry_price']) ? (float)$snapshot_meta['entry_price'] : 0);
                        
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

                    $status_class = $is_deleted ? 'status-deleted' : ($is_closed ? 'status-closed' : 'status-open');
                    $status_text = $is_deleted ? 'Deleted' : ($is_closed ? 'Closed' : 'Open');

                    // Calculate metrics
                    $amount_invested = ($tot_cap * $position_pct) / 100;
                    $risk_amount = 0;
                    if ($entry_price > 0 && $stop_loss > 0) {
                        $risk_amount = abs($entry_price - $stop_loss) * ($amount_invested / $entry_price);
                    }
                    
                    // Risk to Reward (RR) Ratio
                    $rr_ratio = '';
                    $rr_value_formatted = 0;
                    if ($entry_price > 0 && $stop_loss > 0 && $target_price > 0) {
                        $risk_price = abs($entry_price - $stop_loss);
                        $reward_price = abs($target_price - $entry_price);
                        if ($risk_price > 0) {
                            $rr_ratio = $reward_price / $risk_price;
                            $rr_value_formatted = $rr_ratio;
                        }
                    }
                    
                    // Risk per Trade %
                    $risk_per_trade = $tot_cap > 0 ? ($risk_amount / $tot_cap) * 100 : 0;
                    
                    // Quantity (rounded to whole numbers)
                    $quantity = 0;
                    if ($entry_price > 0) {
                        $quantity = round($amount_invested / $entry_price);
                    }
                    
                    $pnl_class = '';
                    if ($pnl !== null) {
                        $pnl_class = $pnl >= 0 ? 'pnl-positive' : 'pnl-negative';
                    }
                ?>
                    <tr>
                        <td style="font-weight: 600;">#<?= $trade_id ?></td>
                        <td style="font-weight: 600; color: #5a2bd9;"><?= $symbol ?></td>
                        <td><?= $entry_date ?></td>
                        <td><?= $position_pct !== 0 ? number_format($position_pct, 2) . '%' : '—' ?></td>
                        <td><?= $entry_price > 0 ? money($entry_price) : '—' ?></td>
                        <td><?= ($stop_loss !== null && $stop_loss > 0) ? money($stop_loss) : '—' ?></td>
                        <td><?= ($target_price !== null && $target_price > 0) ? money($target_price) : '—' ?></td>
                        <td style="font-weight: 600; color: #5a2bd9;"><?= $rr_ratio !== '' ? format_rr($rr_value_formatted) : '—' ?></td>
                        <td class="<?= $pnl_class ?>">
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
                        <td class="<?= $status_class ?>"><?= $status_text ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- SEBI Compliant Disclaimers and Legal Disclosures -->
    <div class="disclaimers-section">
        <h2 class="disclaimer-title">RISK DISCLOSURE & LEGAL DISCLAIMERS</h2>
        
        <div class="disclaimer-block">
            <h3 class="disclaimer-subtitle">1. SEBI RISK DISCLOSURE</h3>
            <ul class="disclaimer-list">
                <li><strong>Market Risk Warning:</strong> All trading and investment activities involve substantial risk of loss and may not be suitable for all investors. You should carefully consider your financial situation and risk tolerance before engaging in any trading activities.</li>
                <li><strong>No Guarantee of Returns:</strong> Past performance is not indicative of future results. There is no guarantee of profits or returns on investments made through this platform or any trading activities.</li>
                <li><strong>Volatility Risk:</strong> Financial markets are inherently volatile and unpredictable. Market conditions can change rapidly, resulting in significant gains or losses.</li>
                <li><strong>Investment at Own Risk:</strong> All investment and trading decisions are made at your own risk and discretion. You are solely responsible for the outcomes of your trading activities.</li>
                <li><strong>Loss of Capital:</strong> You may lose some or all of your invested capital. Never invest more than you can afford to lose.</li>
            </ul>
        </div>

        <div class="disclaimer-block">
            <h3 class="disclaimer-subtitle">2. EDUCATIONAL DISCLAIMER</h3>
            <ul class="disclaimer-list">
                <li><strong>Purpose - Educational/Learning Only:</strong> This platform and all associated content, reports, and data are provided solely for educational and learning purposes. It is designed to help users understand trading concepts, psychology, and market analysis.</li>
                <li><strong>Not Investment Advice:</strong> Nothing on this platform constitutes investment advice, financial advice, trading advice, or any other sort of advice. All content is for informational and educational purposes only.</li>
                <li><strong>No Recommendations:</strong> We do not provide specific buy, sell, or hold recommendations for any securities, stocks, or financial instruments.</li>
                <li><strong>Conduct Your Own Research:</strong> Users should conduct their own research and analysis before making any investment decisions. Always consult with qualified financial advisors.</li>
                <li><strong>No Financial Advice Provided:</strong> This platform does not provide personalized financial advice or investment recommendations tailored to individual circumstances.</li>
            </ul>
        </div>

        <div class="disclaimer-block">
            <h3 class="disclaimer-subtitle">3. LEGAL COMPLIANCE & REGULATORY DISCLOSURES</h3>
            <ul class="disclaimer-list">
                <li><strong>SEBI Compliance:</strong> This platform operates in compliance with Securities and Exchange Board of India (SEBI) guidelines. Users are advised to familiarize themselves with applicable securities laws and regulations.</li>
                <li><strong>Third-Party Data:</strong> Market data and information are sourced from third-party providers. While efforts are made to ensure accuracy, we cannot guarantee the completeness or reliability of such data.</li>
                <li><strong>User Responsibility:</strong> Users are solely responsible for complying with all applicable laws, regulations, and tax obligations in their jurisdiction.</li>
                <li><strong>Regulatory Risk:</strong> Changes in laws, regulations, or market conditions may affect the availability, terms, or profitability of trading activities.</li>
                <li><strong>Professional Advice:</strong> For professional financial advice, consult with SEBI-registered investment advisors, financial planners, or qualified professionals.</li>
                <li><strong>Accountability:</strong> Users acknowledge and agree that they are solely accountable for their investment and trading decisions and their consequences.</li>
            </ul>
        </div>

        <div class="disclaimer-block">
            <h3 class="disclaimer-subtitle">4. IMPORTANT ACKNOWLEDGMENTS</h3>
            <ul class="disclaimer-list">
                <li><strong>Age Restriction:</strong> Users must be at least 18 years of age and have the legal capacity to enter into financial agreements.</li>
                <li><strong>Technology Risk:</strong> Trading involves technological risks including system failures, internet connectivity issues, and electronic trading platform errors.</li>
                <li><strong>No Warranty:</strong> The platform is provided "as is" without warranties of any kind, express or implied.</li>
                <li><strong>Limitation of Liability:</strong> The platform shall not be liable for any direct, indirect, incidental, special, or consequential damages arising from trading activities.</li>
            </ul>
        </div>

        <div class="acknowledgment-box">
            <p><strong>USER ACKNOWLEDGMENT:</strong> By using this platform and downloading this report, you acknowledge that you have read, understood, and agree to be bound by all the above risk disclosures and legal disclaimers. You confirm that you are using this platform for educational purposes only and that you understand the risks associated with trading and investment activities.</p>
        </div>
    </div>

    <!-- Footer -->
    <div class="pdf-footer">
        © Shaikhoology — Trading Psychology | Since 2021.<br>
        Professional Trading Dashboard Report
    </div>

    <script>
        // rotating glow colors + eyes look around (from header.php)
        (function(){
            const hdr=document.getElementById('pdf-header-ustaad');
            const dots=document.querySelectorAll('#pdf-header-ustaad .dot');
            const colors=['#22d3ee','#a78bfa','#f472b6','#34d399','#f59e0b','#60a5fa'];
            let ci=0, timerGlow, timerLook;

            function setGlow(c){hdr.style.setProperty('--glow',c);}
            function startGlow(){
                stopGlow();
                timerGlow=setInterval(()=>{ci=(ci+1)%colors.length;setGlow(colors[ci]);},2500);
            }
            function stopGlow(){if(timerGlow)clearInterval(timerGlow);}
            setGlow(colors[0]);startGlow();
            document.addEventListener('visibilitychange',()=>{if(document.hidden)stopGlow();else startGlow();});
            hdr.addEventListener('click',()=>{ci=(ci+1)%colors.length;setGlow(colors[ci]);});

            // --- Eye motion logic ---
            const moves=[{x:0,y:0},{x:2,y:0},{x:-2,y:0},{x:0,y:-2},{x:0,y:2},{x:2,y:2},{x:-2,y:-2}];
            let mi=0;
            function moveEyes(){
                mi=(mi+1)%moves.length;
                const m=moves[mi];
                dots.forEach(d=>{d.style.transform=`translate(${m.x}px,${m.y}px)`;});
            }
            timerLook=setInterval(moveEyes,5000);
            document.addEventListener('visibilitychange',()=>{if(document.hidden)clearInterval(timerLook);else timerLook=setInterval(moveEyes,2000);});
        })();

        // Auto-print dialog for PDF generation
        window.onload = function() {
            setTimeout(function() {
                if (confirm('Would you like to save this as PDF?')) {
                    window.print();
                }
            }, 500);
        };
    </script>
</body>
</html>