// 🔁 API Integration: my_trades.php now uses /api/trades/list.php for data
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_login();

$user = current_user();
$uid = (int)$user['id'];

// 🔁 API Integration: Direct database queries replaced with API calls
// All trade data is now loaded via JavaScript fetch to /api/trades/list.php
// This provides better separation of concerns and consistency with the API design

function esc($s){ return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// Default values for initialization (will be updated via API)
$default_capital = 100000.0;
$total_capital = $default_capital;
$reserved_amt = 0.0;
$total_points = 0;
$total_alloc = 0.0;
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

  <!-- 🔁 API Integration: Summary now loaded via JavaScript -->
  <div class="summary">
    <strong>Total points:</strong> <span id="totalPoints">0</span> &nbsp;&nbsp;
    <strong>Total allocation reserved:</strong> <span id="totalAlloc">0.00</span> &nbsp;&nbsp;
    <a class="btn" href="trade_new.php">New Trade</a>
  </div>

  <!-- Loading indicator -->
  <div id="loadingIndicator" style="padding: 20px; text-align: center; color: #666;">
    Loading trades...
  </div>

  <!-- Error message (hidden by default) -->
  <div id="errorMessage" style="display: none; padding: 10px; background: #fee2e2; border: 1px solid #dc2626; color: #991b1b; border-radius: 4px; margin-bottom: 12px;">
    <strong>Error:</strong> <span id="errorText"></span>
  </div>

  <!-- 🔁 API Integration: Trade table now loaded via JavaScript -->
  <table id="tradesTable" style="display: none;">
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
    <tbody id="tradesTableBody">
    </tbody>
  </table>

  <!-- No trades message (hidden by default) -->
  <div id="noTradesMessage" style="display: none; padding: 20px; text-align: center; color: #666;">
    No trades yet. <a href="trade_new.php">Create your first trade</a>.
  </div>

</body>
</html>

<script>
// 🔁 API Integration: Load trades from /api/trades/list.php
document.addEventListener('DOMContentLoaded', function() {
    // Load trades from API
    fetch('/api/trades/list.php')
        .then(response => response.json())
        .then(data => {
            // Hide loading indicator
            document.getElementById('loadingIndicator').style.display = 'none';
            
            if (data.success && data.data && data.data.rows) {
                const trades = data.data.rows;
                
                if (trades.length === 0) {
                    // Show no trades message
                    document.getElementById('noTradesMessage').style.display = 'block';
                } else {
                    // Show table and populate it
                    document.getElementById('tradesTable').style.display = 'table';
                    populateTradesTable(trades);
                    updateSummary(trades);
                }
            } else {
                // Show error message
                showError(data.message || 'Failed to load trades');
            }
        })
        .catch(error => {
            console.error('Error loading trades:', error);
            document.getElementById('loadingIndicator').style.display = 'none';
            showError('Network error: Failed to load trades');
        });
    
    function showError(message) {
        document.getElementById('errorText').textContent = message;
        document.getElementById('errorMessage').style.display = 'block';
    }
    
    function updateSummary(trades) {
        // Calculate totals from API data
        let totalPoints = 0;
        let totalAlloc = 0;
        
        trades.forEach(trade => {
            totalPoints += trade.points || 0;
            totalAlloc += trade.allocation_amount || 0;
        });
        
        // Update summary display
        document.getElementById('totalPoints').textContent = totalPoints;
        document.getElementById('totalAlloc').textContent = totalAlloc.toFixed(2);
    }
    
    function populateTradesTable(trades) {
        const tableBody = document.getElementById('tradesTableBody');
        tableBody.innerHTML = '';
        
        trades.forEach(trade => {
            const row = document.createElement('tr');
            
            // Calculate metrics (client-side for consistency)
            const positionPct = trade.position_percent || 0;
            const entryPrice = trade.entry_price || 0;
            const stopLoss = trade.stop_loss || 0;
            const totalCapital = 100000; // Default capital
            
            // Amount Invested per Trade
            const amountInvested = (totalCapital * positionPct) / 100;
            
            // Risk Amount
            let riskAmount = 0;
            if (entryPrice > 0 && stopLoss > 0) {
                riskAmount = Math.abs(entryPrice - stopLoss) * (amountInvested / entryPrice);
            }
            
            // Risk per Trade (RPT) %
            const riskPerTrade = totalCapital > 0 ? (riskAmount / totalCapital) * 100 : 0;
            
            // Number of Quantity (rounded to whole numbers)
            const quantity = entryPrice > 0 ? Math.round(amountInvested / entryPrice) : 0;
            
            // Create table cells
            row.innerHTML = `
                <td>${trade.id}</td>
                <td>${trade.opened_at || trade.entry_date || ''}</td>
                <td>${trade.close_date || ''}</td>
                <td>${escapeHtml(trade.symbol || '')}</td>
                <td>${positionPct ? positionPct.toFixed(2) : ''}</td>
                <td>${entryPrice ? entryPrice.toFixed(2) : ''}</td>
                <td>${stopLoss ? stopLoss.toFixed(2) : ''}</td>
                <td>${trade.target_price ? trade.target_price.toFixed(2) : ''}</td>
                <td>${trade.exit_price ? trade.exit_price.toFixed(2) : ''}</td>
                <td>${escapeHtml(trade.outcome || '')}</td>
                <td>${trade.pl_percent ? trade.pl_percent.toFixed(2) : ''}</td>
                <td>${trade.rr ? trade.rr.toFixed(1) : ''}</td>
                <td>${amountInvested.toFixed(2)}</td>
                <td>${riskAmount.toFixed(2)}</td>
                <td>${riskPerTrade.toFixed(2)}%</td>
                <td>${quantity.toLocaleString()}</td>
                <td>${trade.allocation_amount ? trade.allocation_amount.toFixed(2) : '0.00'}</td>
                <td>${trade.points || 0}</td>
                <td>${trade.analysis_link ? `<a href="${escapeHtml(trade.analysis_link)}" target="_blank">View</a>` : ''}</td>
                <td class="notes" style="white-space: pre-wrap;">${escapeHtml(trade.notes || '')}</td>
            `;
            
            tableBody.appendChild(row);
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>