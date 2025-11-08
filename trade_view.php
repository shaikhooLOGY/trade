<?php
// trade_view.php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$is_admin = !empty($_SESSION['is_admin']);
$trade_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($trade_id <= 0) { echo "Invalid trade id."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n1($v){ return ($v === null || $v === '') ? '—' : number_format((float)$v, 1, '.', ''); }
function n2($v){ return ($v === null || $v === '') ? '—' : number_format((float)$v, 2, '.', ''); }
function pct($v){ return ($v === null || $v === '') ? '—' : n2($v) . '%'; }
function money($v){ return ($v === null || $v === '') ? '—' : '₹ ' . number_format((float)$v, 2); }
function has_col(mysqli $db, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($st = $db->prepare($sql)) {
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $res = $st->get_result();
        $ok  = $res && ($row = $res->fetch_assoc()) && (int)$row['c'] > 0;
        $st->close();
        return $ok;
    }
    return false;
}

// ---- Fetch trade safely ----
$trade = null;
if ($st = $mysqli->prepare("SELECT * FROM trades WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $trade_id);
    $st->execute();
    $res = $st->get_result();
    if ($res) { $trade = $res->fetch_assoc(); }
    $st->close();
}
if (!$trade) { echo "Trade not found."; exit; }

// ---- Authorization ----
if (!$is_admin && (int)$trade['user_id'] !== $user_id) {
    header('HTTP/1.1 403 Forbidden'); echo "Access denied."; exit;
}

// ---- Optional trading capital ----
$trading_capital = null;
if (has_col($mysqli, 'users', 'trading_capital')) {
    if ($st = $mysqli->prepare("SELECT trading_capital FROM users WHERE id = ? LIMIT 1")) {
        $st->bind_param('i', $trade['user_id']);
        $st->execute();
        if ($r = $st->get_result()) {
            $row = $r->fetch_assoc();
            $trading_capital = $row['trading_capital'] ?? null;
        }
        $st->close();
    }
}

// ---- Core values ----
$symbol   = strtoupper(trim((string)($trade['symbol'] ?? '')));
$entry    = isset($trade['entry_price'])  ? (float)$trade['entry_price']  : null;
$sl       = isset($trade['stop_loss'])    ? (float)$trade['stop_loss']    : null;
$target   = isset($trade['target_price']) ? (float)$trade['target_price'] : null;
$exit     = isset($trade['exit_price'])   ? (float)$trade['exit_price']   : null;
$pospct   = isset($trade['position_percent']) ? (float)$trade['position_percent'] : null;
$outcome  = strtoupper((string)($trade['outcome'] ?? ''));
$points   = isset($trade['points']) ? (int)$trade['points'] : 0;
$notes    = trim((string)($trade['notes'] ?? ''));
$analysis_link = trim((string)($trade['analysis_link'] ?? ''));

// ---- Derived metrics ----
$riskPct    = ($entry && $sl)     ? (($entry - $sl)     / $entry) * 100.0 : null;  // % drop to SL
$rewardPct  = ($entry && $target) ? (($target - $entry) / $entry) * 100.0 : null;  // % rise to Target
$rr         = ($riskPct !== null && $riskPct != 0 && $rewardPct !== null) ? ($rewardPct / $riskPct) : null;
$plPct      = ($exit !== null && $entry) ? (($exit - $entry) / $entry) * 100.0 : null;

// Money amounts (only if we know trading_capital & position%)
$invested = null;        // position sizing * capital
$rpt_amt  = null;        // RPT amount
$absPL    = null;        // absolute P/L amount
$current_value = null;   // invested + absPL (when closed)
if ($trading_capital !== null && $pospct !== null) {
    $invested = ((float)$trading_capital) * ($pospct/100.0);
    if ($riskPct !== null) $rpt_amt = $invested * ($riskPct/100.0);
    if ($plPct !== null) {
        $absPL = $invested * ($plPct/100.0);
        if ($exit !== null) $current_value = $invested + $absPL;
    }
}

// ---- Status helpers ----
$concern_status = strtolower((string)($trade['concern_status'] ?? ''));
$is_under_review = in_array($concern_status, ['raised','pending'], true);
$is_closed  = ($exit !== null);
$is_active  = (!$is_closed && !$is_under_review);

// ---- Learnings (for closed only) ----
$learning_msg = '';
if ($is_closed) {
    if ($plPct !== null && $plPct > 0 && $rr !== null && $rr >= 2) {
        $learning_msg = "🚀 Excellent: healthy Risk–Reward and profit captured.";
    } elseif ($plPct !== null && $plPct > 0 && $sl !== null && $rr === null) {
        $learning_msg = "✅ Profit with SL respected; define explicit R:R for better planning.";
    } elseif ($plPct !== null && $plPct <= 0 && $sl === null) {
        $learning_msg = "⚠️ No Stop Loss — high risk. Always define SL before entering.";
    } elseif ($rr !== null && $rr < 1) {
        $learning_msg = "📉 Poor R:R. Improve entry timing or refine SL/Target placement.";
    }
}

// External links
$tvLink       = "https://www.tradingview.com/symbols/NSE-" . rawurlencode($symbol) . "/";
$screenerLink = "https://www.screener.in/company/" . rawurlencode($symbol) . "/";

// ---- UI starts ----
include __DIR__ . '/header.php';
?>
<style>
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:700;font-size:12px}
.badge-green{background:#e8fff0;color:#067647;border:1px solid #86efac}
.badge-amber{background:#fff7ed;color:#b45309;border:1px solid #fdba74}
.badge-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}
.badge-red{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.card{background:#fff;border-radius:12px;box-shadow:0 6px 20px rgba(15,20,40,0.06);padding:16px}
.card h3{margin:0 0 10px;font-size:16px}
.kv{display:grid;grid-template-columns:160px 1fr;row-gap:8px}
.kv div:nth-child(odd){color:#6b7280}
.kv .val{font-weight:700;color:#111827}
.backbtn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,#111827,#374151);color:#fff;text-decoration:none;padding:8px 12px;border-radius:999px;font-weight:700}
.editbtn{background:#4f46e5;border:0;color:#fff;padding:8px 12px;border-radius:10px;font-weight:700;text-decoration:none}
.btn-row{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-top:16px}
.bigbtn{display:inline-flex;align-items:center;gap:10px;padding:16px 20px;border-radius:12px;font-weight:800;text-decoration:none}
.bigbtn.tech{background:#eef2ff;border:1px solid #c7d2fe;color:#1e3a8a}
.bigbtn.funda{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.learning{margin-top:16px;padding:12px;border-left:4px solid #4f46e5;background:#f9fafb;font-weight:500}
.val.pl-pos{color:#047857}
.val.pl-neg{color:#b91c1c}
</style>

<main class="container" style="padding:20px 0">
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <?php if ($is_active): ?>
      <span class="badge badge-green">ACTIVE</span>
    <?php elseif ($is_under_review): ?>
      <span class="badge badge-amber">UNDER REVIEW</span>
    <?php else: ?>
      <?php
        $closedBadgeClass = 'badge-gray';
        if ($plPct !== null) {
          if ($plPct > 0) $closedBadgeClass = 'badge-green';
          elseif ($plPct < 0) $closedBadgeClass = 'badge-red';
        }
      ?>
      <span class="badge <?= $closedBadgeClass ?>">CLOSED</span>
    <?php endif; ?>

    <a href="/dashboard.php" class="backbtn">← Back</a>
    <?php if ($is_active): ?>
      <a class="editbtn" href="/trade_edit.php?id=<?= (int)$trade_id ?>">✏️ Edit</a>
    <?php endif; ?>
  </div>

  <h2 style="margin:14px 0 6px">Trade — <?= h($symbol) ?></h2>
  <div style="color:#6b7280;margin-bottom:16px">by <?= h($_SESSION['username'] ?? 'User') ?> · <?= h($trade['entry_date'] ?? '') ?></div>

  <div class="grid">
    <!-- Prices -->
    <div class="card">
      <h3>Prices</h3>
      <div class="kv">
        <div>- Entry</div><div class="val"><?= n1($entry) ?></div>
        <div>- Stop Loss</div><div class="val"><?= n1($sl) ?></div>
        <div>- Target</div><div class="val"><?= n1($target) ?></div>
        <div>- Exit</div><div class="val"><?= n1($exit) ?></div>
      </div>
    </div>

    <!-- Performance -->
    <div class="card">
      <h3>Performance</h3>
      <div class="kv">
        <div>P/L %</div>
        <?php
          $plClass = 'val';
          if ($plPct !== null) {
            if ($plPct > 0) $plClass .= ' pl-pos';
            elseif ($plPct < 0) $plClass .= ' pl-neg';
          }
        ?>
        <div class="<?= $plClass ?>"><?= pct($plPct) ?></div>
        <div>Absolute P/L</div><div class="val"><?= money($absPL) ?></div>
        <div>Points earned</div>
        <div class="val"><?= $is_active ? '— (points update after closing)' : (int)$points ?></div>
      </div>
    </div>

    <!-- Analysis -->
    <div class="card">
      <h3>Analysis</h3>
      <div class="kv">
        <div>Analysis link</div>
        <div class="val"><?= $analysis_link ? "<a href='".h($analysis_link)."' target='_blank' rel='noopener'>Open</a>" : '—' ?></div>
        <div>Notes</div><div class="val"><?= ($notes !== '') ? nl2br(h($notes)) : '—' ?></div>
      </div>
    </div>

    <!-- Risk Management -->
    <div class="card">
      <h3>Risk Management</h3>
      <div class="kv">
        <div>Risk %</div><div class="val"><?= pct($riskPct) ?></div>
        <div>Reward %</div><div class="val"><?= pct($rewardPct) ?></div>
        <div>R : R</div><div class="val"><?= ($rr !== null) ? n2($rr) : '—' ?></div>
        <div>Risk per trade (RPT)</div><div class="val"><?= money($rpt_amt) ?></div>
      </div>
    </div>

    <!-- Money Management -->
    <div class="card">
      <h3>Money Management</h3>
      <div class="kv">
        <div>Asset allocation</div><div class="val"><?= pct($pospct) ?></div>
        <div>Invested</div><div class="val"><?= money($invested) ?></div>
        <div>Current value</div><div class="val"><?= money($current_value) ?></div>
      </div>
    </div>
  </div>

  <!-- Learnings -->
  <div class="card" style="margin-top:16px">
    <h3>Learnings from this trade</h3>
    <div style="color:#111827;font-weight:600">
      <?php if ($is_closed): ?>
        <?= $learning_msg ? h($learning_msg) : '—' ?>
      <?php else: ?>
        Close trade to see learning.
      <?php endif; ?>
    </div>
  </div>

  <!-- External research buttons -->
  <div class="btn-row">
    <a class="bigbtn tech" href="<?= h($tvLink) ?>" target="_blank" rel="noopener">📈 Technical Analysis</a>
    <a class="bigbtn funda" href="<?= h($screenerLink) ?>" target="_blank" rel="noopener">📊 Fundamental Analysis</a>
  </div>

  <div style="text-align:center;color:#6b7280;margin-top:10px">
    Symbol: <strong>NSE:<?= h($symbol) ?></strong>
  </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>