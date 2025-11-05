<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_login();

$user = current_user();
$uid = (int)$user['id'];

// Fetch user trades with calculation data
$stmt = $mysqli->prepare("
    SELECT id, entry_date, close_date, symbol, marketcap, position_percent,
           entry_price, stop_loss, target_price, exit_price, outcome,
           pl_percent, rr, allocation_amount, points, analysis_link, notes, created_at
    FROM trades
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();

// Calculate user's total capital and reserved amounts
$funds_sql = $mysqli->query("SELECT COALESCE(funds_available,0) as fa, COALESCE(trading_capital,0) as tc FROM users WHERE id = {$uid}");
$default_capital = 100000.0;
$total_capital = $default_capital;
if ($funds_sql && $row = $funds_sql->fetch_assoc()) {
    $total_capital = ($row['tc'] ?? 0) > 0 ? floatval($row['tc']) : (($row['fa'] ?? 0) > 0 ? floatval($row['fa']) : $default_capital);
}

// Calculate reserved amounts from existing open trades
$reserved_amt = 0.0;
$alloc_column = null;
foreach (['allocation_amount', 'allocated_amount', 'capital_allocated', 'risk_amount'] as $candidate) {
    if ($mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades' AND COLUMN_NAME = '{$candidate}'")->fetch_assoc()['COUNT(*)'] > 0) {
        $alloc_column = $candidate;
        break;
    }
}
if ($alloc_column) {
    $sql = "SELECT COALESCE(SUM(`{$alloc_column}`),0) as res
            FROM trades
            WHERE user_id=?
              AND (closed_at IS NULL OR closed_at='')
              AND (deleted_at IS NULL OR deleted_at='')";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $reserved_amt = isset($res_data['res']) ? floatval($res_data['res']) : 0.0;
    }
} elseif ($mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades' AND COLUMN_NAME IN ('position_percent', 'risk_pct')")->fetch_assoc()['COUNT(*)'] > 0) {
    $percent_col = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades' AND COLUMN_NAME = 'position_percent'")->fetch_assoc()['COUNT(*)'] > 0 ? 'position_percent' : 'risk_pct';
    $conditions = ["user_id=?", "UPPER(COALESCE(outcome,'OPEN'))='OPEN'"];
    if ($mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades' AND COLUMN_NAME = 'closed_at'")->fetch_assoc()['COUNT(*)'] > 0) {
        $conditions[] = "closed_at IS NULL";
    }
    if ($mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades' AND COLUMN_NAME = 'deleted_at'")->fetch_assoc()['COUNT(*)'] > 0) {
        $conditions[] = "deleted_at IS NULL";
    }
    $sql = "SELECT COALESCE(SUM(`{$percent_col}`),0) as pct FROM trades WHERE " . implode(' AND ', $conditions);
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $pct = isset($res_data['pct']) ? floatval($res_data['pct']) : 0.0;
        $reserved_amt = ($total_capital * $pct) / 100.0;
    }
}

// Totals
$totStmt = $mysqli->prepare("SELECT COALESCE(SUM(points),0) AS total_points, COALESCE(SUM(allocation_amount),0) AS total_alloc FROM trades WHERE user_id = ?");
$totStmt->bind_param('i', $uid);
$totStmt->execute();
$totRow = $totStmt->get_result()->fetch_assoc();
$total_points = (int)$totRow['total_points'];
$total_alloc = (float)$totRow['total_alloc'];

function esc($s){ return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>My Trades — <?= esc($user['name'] ?? $user['email']) ?></title>
  <style>
    body{font-family:Arial,Helvetica,sans-serif;padding:18px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #ddd;padding:8px;text-align:left;font-size:14px}
    th{background:#f7f7f7}
    .summary{margin-bottom:12px}
    .small{font-size:13px;color:#666}
    .notes{white-space:pre-wrap}
    a.btn{display:inline-block;padding:6px 10px;background:#0b5cff;color:#fff;text-decoration:none;border-radius:4px}
  </style>
</head>
<body>
  <h2>My Trades</h2>
  <p class="small">Logged in as <strong><?= esc($user['name'] ?? $user['email']) ?></strong> — <a href="dashboard.php">Dashboard</a></p>

  <div class="summary">
    <strong>Total points:</strong> <?= $total_points ?> &nbsp;&nbsp;
    <strong>Total allocation reserved:</strong> <?= number_format($total_alloc,2) ?> &nbsp;&nbsp;
    <a class="btn" href="trade_new.php">New Trade</a>
  </div>

  <?php if ($res->num_rows === 0): ?>
    <p>No trades yet. <a href="trade_new.php">Create your first trade</a>.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Entry</th>
          <th>Close</th>
          <th>Symbol</th>
          <th>Size%</th>
          <th>Entry</th>
          <th>SL</th>
          <th>Target</th>
          <th>Exit</th>
          <th>Outcome</th>
          <th>P/L%</th>
          <th>R:R</th>
          <th>Amount Invested</th>
          <th>Risk Amount</th>
          <th>Risk per Trade %</th>
          <th>Quantity</th>
          <th>Alloc (₹)</th>
          <th>Points</th>
          <th>Analysis</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
      <?php while ($row = $res->fetch_assoc()): ?>
        <?php
          // Calculate new metrics
          $position_pct = $row['position_percent'] ?? 0;
          $entry_price = $row['entry_price'] ?? 0;
          $stop_loss = $row['stop_loss'] ?? 0;
          
          // Amount Invested per Trade
          $amount_invested = ($total_capital * $position_pct) / 100;
          
          // Risk Amount
          $risk_amount = 0;
          if ($entry_price > 0 && $stop_loss > 0) {
            $risk_amount = abs($entry_price - $stop_loss) * ($amount_invested / $entry_price);
          }
          
          // Risk per Trade (RPT) %
          $risk_per_trade = $total_capital > 0 ? ($risk_amount / $total_capital) * 100 : 0;
          
          // Number of Quantity (rounded to whole numbers)
          $quantity = 0;
          if ($entry_price > 0) {
            $quantity = round($amount_invested / $entry_price);
          }
        ?>
        <tr>
          <td><?= (int)$row['id'] ?></td>
          <td><?= esc($row['entry_date']) ?></td>
          <td><?= esc($row['close_date']) ?></td>
          <td><?= esc($row['symbol']) ?></td>
          <td><?= $row['position_percent'] !== null ? esc($row['position_percent']) : '' ?></td>
          <td><?= $row['entry_price'] !== null ? esc($row['entry_price']) : '' ?></td>
          <td><?= $row['stop_loss'] !== null ? esc($row['stop_loss']) : '' ?></td>
          <td><?= $row['target_price'] !== null ? esc($row['target_price']) : '' ?></td>
          <td><?= $row['exit_price'] !== null ? esc($row['exit_price']) : '' ?></td>
          <td><?= esc($row['outcome']) ?></td>
          <td><?= $row['pl_percent'] !== null ? esc($row['pl_percent']) : '' ?></td>
          <td><?= $row['rr'] !== null ? esc($row['rr']) : '' ?></td>
          <td><?= number_format($amount_invested, 2) ?></td>
          <td><?= number_format($risk_amount, 2) ?></td>
          <td><?= number_format($risk_per_trade, 2) ?>%</td>
          <td><?= number_format($quantity, 0) ?></td>
          <td><?= $row['allocation_amount'] !== null ? number_format($row['allocation_amount'],2) : '0.00' ?></td>
          <td><?= (int)$row['points'] ?></td>
          <td>
            <?php if (!empty($row['analysis_link'])): ?>
              <a href="<?= esc($row['analysis_link']) ?>" target="_blank">View</a>
            <?php endif; ?>
          </td>
          <td class="notes"><?= esc($row['notes']) ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  <?php endif; ?>

</body>
</html>