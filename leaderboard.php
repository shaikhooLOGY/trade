<?php
// leaderboard.php — compact awards + responsive, scrollable stats
// Session and security handling centralized via bootstrap.php
require_once __DIR__ . '/includes/bootstrap.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function name_fallback($name, $email){
  $name = trim((string)$name);
  if ($name !== '') return $name;
  $local = trim((string)@explode('@', (string)$email)[0]);
  return $local !== '' ? $local : 'Trader';
}

/* ----------- SAFE PATCH: Column Detection Helpers ----------- */
// Helper to detect if a column exists in a table (cached to avoid repeated queries)
function db_has_col(mysqli $m, string $table, string $col): bool {
    static $cache = [];
    $cache_key = $table . '.' . $col;
    
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    try {
        $stmt = $m->prepare("
            SELECT COUNT(*) as cnt 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $col);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        $exists = ($row['cnt'] ?? 0) > 0;
        $cache[$cache_key] = $exists;
        return $exists;
    } catch (Throwable $e) {
        // If schema query fails, assume column doesn't exist
        $cache[$cache_key] = false;
        return false;
    }
}

/* ----------- SAFE PATCH: Dynamic Display Name Building ----------- */
// Build safe display name SQL that only uses columns that actually exist
function build_display_name_sql(mysqli $m, string $table_alias = 'u'): array {
    // Priority order: name > full_name > username > email
    $candidate_columns = ['name', 'full_name', 'username', 'email'];
    $existing_columns = [];
    
    foreach ($candidate_columns as $col) {
        if (db_has_col($m, 'users', $col)) {
            $existing_columns[] = $col;
        }
    }
    
    // Build COALESCE SQL with only existing columns
    if (empty($existing_columns)) {
        // Fallback if no user name columns exist
        return [
            'sql' => "'Member'",
            'columns_used' => ['literal_Member'],
            'debug_info' => 'No name columns found, using literal "Member"'
        ];
    }
    
    $coalesce_parts = [];
    foreach ($existing_columns as $col) {
        $coalesce_parts[] = "{$table_alias}.{$col}";
    }
    
    $sql = 'COALESCE(' . implode(', ', $coalesce_parts) . ')';
    
    return [
        'sql' => $sql,
        'columns_used' => $existing_columns,
        'debug_info' => 'Using columns: ' . implode(', ', $existing_columns)
    ];
}

/* ----------- SAFE PATCH: Optional Trade Column Detection ----------- */
// Detect which trade columns exist to build safe queries
function get_trade_columns(mysqli $m): array {
    $trade_columns = [
        'entry_date', 'close_date', 'position_percent', 'entry_price', 
        'stop_loss', 'target_price', 'exit_price', 'outcome', 
        'pl_percent', 'rr', 'allocation_amount', 'points'
    ];
    
    $existing_columns = [];
    foreach ($trade_columns as $col) {
        if (db_has_col($m, 'trades', $col)) {
            $existing_columns[] = $col;
        }
    }
    
    return $existing_columns;
}

/* ----------- SAFE PATCH: Main Data Loading with Error Handling ----------- */
try {
    // Build safe display name SQL
    $name_sql_info = build_display_name_sql($mysqli);
    
    // === BEGIN SAFE PATCH: Users Query ===
    // Load current user label (safe query)
    $me = null;
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $name_sql = $name_sql_info['sql'];
        
        // Build safe query - always include id and email, plus available name columns
        $user_cols = ['id', 'email'];
        foreach ($name_sql_info['columns_used'] as $col) {
            if (!in_array($col, $user_cols)) {
                $user_cols[] = $col;
            }
        }
        
        $select_parts = [];
        foreach ($user_cols as $col) {
            if ($col === 'literal_Member') {
                $select_parts[] = "'Member'";
            } else {
                $select_parts[] = "u.{$col}";
            }
        }
        
        $st = $mysqli->prepare("SELECT " . implode(', ', $select_parts) . " FROM users u WHERE u.id=? LIMIT 1");
        $st->bind_param('i', $uid); 
        $st->execute();
        $me = $st->get_result()->fetch_assoc();
        $st->close();
    }
    $meLabel = $me ? name_fallback($me['name'] ?? '', $me['email'] ?? '') : null;

    // Load all users with safe query
    $users_query = "SELECT u.id, {$name_sql_info['sql']} AS name, u.email FROM users u ORDER BY u.name";
    $res = $mysqli->query($users_query);
    // === END SAFE PATCH: Users Query ===
    
    if(!$res) throw new Exception("DB error fetching users: " . $mysqli->error);
    $users = [];
    while($r = $res->fetch_assoc()){
        $uid = (int)$r['id'];
        $users[$uid] = [
            'id'=>$uid, 'name'=>name_fallback($r['name'] ?? '', $r['email'] ?? ''),
            'trades'=>0, 'points'=>0, 'best_return'=>null, 'avg_rr'=>null,
            'weighted_return'=>null, 'win_pct'=>null, 'no_sl'=>0, 'consistency'=>0,
            'closed_trades'=>0, 'allocated_sum'=>0.0,
        ];
    }
    $res->close();
    if(count($users) === 0){ throw new Exception("No users found in DB."); }

    // === BEGIN SAFE PATCH: Trades Query ===
    // Get available trade columns and build safe query
    $trade_cols = get_trade_columns($mysqli);
    
    // Always include required columns
    $required_cols = ['id', 'user_id'];
    $select_cols = array_merge($required_cols, $trade_cols);
    
    // Build SELECT clause
    $select_parts = [];
    foreach ($select_cols as $col) {
        $select_parts[] = "t.{$col}";
    }
    
    // === BUILD SAFE ORDER BY CLAUSE ===
    $order_by_parts = ['t.user_id'];
    
    // Check if we have date columns for ordering
    $has_close_date = in_array('close_date', $trade_cols);
    $has_entry_date = in_array('entry_date', $trade_cols);
    
    if ($has_close_date && $has_entry_date) {
        $order_by_parts[] = "COALESCE(t.close_date, t.entry_date)";
    } elseif ($has_close_date) {
        $order_by_parts[] = "t.close_date";
    } elseif ($has_entry_date) {
        $order_by_parts[] = "t.entry_date";
    } else {
        // Fallback to created_at if available, otherwise use id
        if (in_array('created_at', $trade_cols)) {
            $order_by_parts[] = "t.created_at";
        } else {
            $order_by_parts[] = "t.id";
        }
    }
    
    $order_by_parts[] = "t.id ASC";
    // === END BUILD SAFE ORDER BY CLAUSE ===
    
    $q = "SELECT " . implode(', ', $select_parts) . " FROM trades t WHERE t.enrollment_id IS NULL OR t.enrollment_id = '' ORDER BY " . implode(', ', $order_by_parts);
    $trade_q = $mysqli->query($q);
    // === END SAFE PATCH: Trades Query ===
    
    if(!$trade_q) throw new Exception("DB error fetching trades: " . $mysqli->error);

    $profit_seq_by_user = [];
    while($t = $trade_q->fetch_assoc()){
      $uid = (int)$t['user_id'];
      if(!isset($users[$uid])) continue;

      // === BEGIN SAFE PATCH: Safe Field Access ===
      // Access trade fields safely - they may not exist in all schemas
      $pl     = isset($t['pl_percent']) && $t['pl_percent'] !== null ? (float)$t['pl_percent'] : null;
      $rr     = isset($t['rr']) && $t['rr'] !== null ? (float)$t['rr'] : null;
      $pospct = isset($t['position_percent']) && $t['position_percent'] !== null ? (float)$t['position_percent'] : 0.0;
      $alloc  = isset($t['allocation_amount']) && $t['allocation_amount'] !== null ? (float)$t['allocation_amount'] : 0.0;
      // === END SAFE PATCH: Safe Field Access ===

      $users[$uid]['trades'] += 1;
      $users[$uid]['allocated_sum'] += $alloc;
      $users[$uid]['points'] += (int)($t['points'] ?? 0);

      if($pl !== null){
        if($users[$uid]['best_return'] === null || $pl > $users[$uid]['best_return']){
          $users[$uid]['best_return'] = $pl;
        }
      }
      if(!isset($users[$uid]['_rr_sum']))   $users[$uid]['_rr_sum']   = 0.0;
      if(!isset($users[$uid]['_rr_count'])) $users[$uid]['_rr_count'] = 0;
      if($rr !== null){ $users[$uid]['_rr_sum'] += $rr; $users[$uid]['_rr_count'] += 1; }

      if(!isset($users[$uid]['_wret_num'])) $users[$uid]['_wret_num'] = 0.0;
      if(!isset($users[$uid]['_wret_den'])) $users[$uid]['_wret_den'] = 0.0;
      if($pl !== null){ $users[$uid]['_wret_num'] += $pospct * $pl; $users[$uid]['_wret_den'] += $pospct; }

      // === BEGIN SAFE PATCH: Safe Date/Outcome Access ===
      $is_closed = false;
      if (isset($t['close_date']) && $t['close_date'] !== null && $t['close_date'] !== '') {
          $is_closed = true;
      } elseif (isset($t['outcome']) && strtoupper((string)$t['outcome']) !== 'OPEN') {
          $is_closed = true;
      }
      // === END SAFE PATCH: Safe Date/Outcome Access ===
      
      if($is_closed){
        $users[$uid]['closed_trades'] += 1;
        if($pl !== null && $pl > 0){
          if(!isset($users[$uid]['_wins'])) $users[$uid]['_wins'] = 0;
          $users[$uid]['_wins'] += 1;
        }
      }
      
      // === BEGIN SAFE PATCH: Safe Stop Loss Check ===
      $stop_loss_value = $t['stop_loss'] ?? null;
      if(is_null($stop_loss_value) || $stop_loss_value==='' || (float)$stop_loss_value==0.0){ 
          $users[$uid]['no_sl'] += 1; 
      }
      // === END SAFE PATCH: Safe Stop Loss Check ===

      if($is_closed){
        if(!isset($profit_seq_by_user[$uid])) $profit_seq_by_user[$uid] = [];
        $profit_seq_by_user[$uid][] = ($pl !== null && $pl > 0) ? 1 : 0;
      }
    }
    $trade_q->close();

    /* ----------- finalize metrics ----------- */
    foreach($users as $uid => &$u){
      $u['avg_rr'] = (!empty($u['_rr_count'])) ? ($u['_rr_sum'] / $u['_rr_count']) : null;
      $u['weighted_return'] = (!empty($u['_wret_den'])) ? ($u['_wret_num'] / $u['_wret_den']) : null;
      if($u['closed_trades'] > 0){
        $wins = isset($u['_wins']) ? $u['_wins'] : 0;
        $u['win_pct'] = ($wins / $u['closed_trades']) * 100.0;
      } else { $u['win_pct'] = null; }
      // longest winning streak
      $u['consistency'] = 0;
      if(isset($profit_seq_by_user[$uid])){
        $max=0; $cur=0;
        foreach($profit_seq_by_user[$uid] as $p){ if($p==1){ $cur++; $max=max($max,$cur);} else {$cur=0;} }
        $u['consistency'] = $max;
      }
      unset($u['_rr_sum'],$u['_rr_count'],$u['_wret_num'],$u['_wret_den'],$u['_wins']);
    }
    unset($u);

    /* ----------- awards ----------- */
    $awards = [
      'Top Scorer' => ['user'=>null, 'metric'=>0,   'icon'=>'🏆', 'unit'=>'pts'],
      'Most Active Trader' => ['user'=>null, 'metric'=>0, 'icon'=>'🔥', 'unit'=>'trades'],
      'Best Single Trade %' => ['user'=>null, 'metric'=>null, 'icon'=>'📈', 'unit'=>'%'],
      'Best Avg R:R' => ['user'=>null, 'metric'=>null, 'icon'=>'⚖️', 'unit'=>''],
      'Risk Manager (Fewest No-SL)' => ['user'=>null, 'metric'=>null, 'icon'=>'🛡️', 'unit'=>'no-SL'],
      'Consistency King' => ['user'=>null, 'metric'=>0, 'icon'=>'🚀', 'unit'=>'streak'],
      'Best Weighted Return' => ['user'=>null, 'metric'=>null, 'icon'=>'✨', 'unit'=>'%'],
    ];
    foreach($users as $u){
      if($u['points'] > $awards['Top Scorer']['metric']) $awards['Top Scorer'] = ['user'=>$u,'metric'=>$u['points'],'icon'=>'🏆','unit'=>'pts'];
      if($u['trades'] > $awards['Most Active Trader']['metric']) $awards['Most Active Trader'] = ['user'=>$u,'metric'=>$u['trades'],'icon'=>'🔥','unit'=>'trades'];
      if($u['best_return'] !== null){
        if($awards['Best Single Trade %']['metric'] === null || $u['best_return'] > $awards['Best Single Trade %']['metric']){
          $awards['Best Single Trade %'] = ['user'=>$u,'metric'=>$u['best_return'],'icon'=>'📈','unit'=>'%'];
        }
      }
      if($u['avg_rr'] !== null){
        if($awards['Best Avg R:R']['metric'] === null || $u['avg_rr'] > $awards['Best Avg R:R']['metric']){
          $awards['Best Avg R:R'] = ['user'=>$u,'metric'=>$u['avg_rr'],'icon'=>'⚖️','unit'=>''];
        }
      }
      if($awards['Risk Manager (Fewest No-SL)']['metric'] === null
         || $u['no_sl'] < $awards['Risk Manager (Fewest No-SL)']['metric']
         || ($u['no_sl'] == $awards['Risk Manager (Fewest No-SL)']['metric'] && $u['points'] > ($awards['Risk Manager (Fewest No-SL)']['user']['points'] ?? -1))){
        $awards['Risk Manager (Fewest No-SL)'] = ['user'=>$u,'metric'=>$u['no_sl'],'icon'=>'🛡️','unit'=>'no-SL'];
      }
      if($u['consistency'] > $awards['Consistency King']['metric']) $awards['Consistency King'] = ['user'=>$u,'metric'=>$u['consistency'],'icon'=>'🚀','unit'=>'streak'];
      if($u['weighted_return'] !== null){
        if($awards['Best Weighted Return']['metric'] === null || $u['weighted_return'] > $awards['Best Weighted Return']['metric']){
          $awards['Best Weighted Return'] = ['user'=>$u,'metric'=>$u['weighted_return'],'icon'=>'✨','unit'=>'%'];
        }
      }
    }

    /* ----------- sort table ----------- */
    usort($users, function($a,$b){
      if($a['points'] !== $b['points']) return $b['points'] - $a['points'];
      return $b['trades'] - $a['trades'];
    });

} catch (Throwable $e) {
    // === BEGIN SAFE PATCH: Friendly Error Handling ===
    $error_msg = $e->getMessage();
    if (defined('APP_ENV') && APP_ENV !== 'prod') {
        // Show detailed error in development
        $friendly_error = htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8');
    } else {
        // Generic error message in production
        $friendly_error = "We couldn't load the leaderboard right now. Please try again later.";
    }
    // === END SAFE PATCH: Friendly Error Handling ===
    
    // Show error and exit safely
    include __DIR__ . '/header.php';
    echo '<div style="max-width:1150px;margin:20px auto;padding:0 16px;">';
    echo '<div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:20px;">';
    echo '<strong>Database Error</strong><br>' . $friendly_error;
    echo '</div>';
    echo '<p><a href="dashboard.php" style="color:#5a2bd9;">← Back to Dashboard</a></p>';
    echo '</div>';
    include __DIR__ . '/footer.php';
    exit;
}

include __DIR__ . '/header.php';
?>
<style>
  :root{ --accent:#5a2bd9; --muted:#6b7280; --card:#fff; --shadow:0 6px 18px rgba(30,30,60,.06); }
  body{ background:#fff; }
  .wrap{ max-width:1150px; margin:20px auto; padding:0 16px; }

  /* top row actions */
  .toprow{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px}
  .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;border:1px solid #e6e9ef;
       background:#fff;font-weight:800;text-decoration:none;color:#1f2937;box-shadow:var(--shadow)}
  .btn:hover{background:#f8fafc}
  .btn.purple{background:linear-gradient(90deg,#6a3af7,#2d1fb7);color:#fff;border-color:transparent}

  /* Awards */
  .section-title{display:flex;align-items:center;gap:10px;margin:8px 0 10px 0}
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:18px}
  .card{background:var(--card);border-radius:12px;padding:12px;box-shadow:var(--shadow);border:1px solid #f1f1f4}
  .card h4{margin:6px 0 0;font-size:15px;color:#1f2937}
  .gloss{background:linear-gradient(180deg,#faf5ff,#ffffff);border:1px solid #efe9ff}
  .award-name{font-weight:800;margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .award-metric{font-size:12px;color:#6b7280}

  /* Table responsive wrapper */
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:12px;border:1px solid #eef0f4}
  .table{width:100%;border-collapse:collapse;min-width:900px;background:#fff}
  .table th,.table td{padding:12px;border-bottom:1px solid #eef0f4;text-align:left}
  .table thead th{background:#f3f4f6;font-weight:800}
  .row-highlight{background:#f7fff7}
  .small{font-size:12px;color:#6b7280}
</style>

<div class="wrap">
  <div class="toprow">
    <h2 style="margin:0">🏆 Leaderboard</h2>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn" href="dashboard.php">← Back to Dashboard</a>
    </div>
  </div>

  <div class="section-title">
    <span style="font-size:22px">🥇</span><h3 style="margin:0">Top Awards</h3>
  </div>

  <div class="cards">
    <?php foreach($awards as $title => $data):
      $u = $data['user']; $icon = $data['icon']; $unit = $data['unit'];
    ?>
      <div class="card gloss">
        <div style="font-size:20px"><?= $icon ?></div>
        <h4><?= h($title) ?></h4>
        <?php if($u): ?>
          <div class="award-name" title="<?= h($u['name']) ?>"><?= h($u['name']) ?></div>
          <div class="award-metric small">
            <?php
              $m = $data['metric'];
              if($title==='Best Avg R:R')        echo ($m===null?'N/A':number_format($m,2));
              elseif($unit==='%')                 echo ($m===null?'N/A':number_format($m,4).'%');
              else                                echo ($m===null?'N/A':(int)$m.' '.$unit);
            ?>
          </div>
        <?php else: ?>
          <div class="small">N/A</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="section-title" style="margin-top:6px">
    <span style="font-size:20px">📊</span><h3 style="margin:0">Detailed Trader Stats</h3>
  </div>

  <!-- Horizontal scrollable wrapper for mobile -->
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Rank</th>
          <th>Trader</th>
          <th>Trades</th>
          <th>Points</th>
          <th>Best Return %</th>
          <th>Avg R:R</th>
          <th>Weighted Return %</th>
          <th>Win %</th>
          <th>No-SL</th>
          <th>Consistency</th>
        </tr>
      </thead>
      <tbody>
        <?php $rank=0; foreach($users as $u): $rank++;
          $highlight = (($awards['Top Scorer']['user']['id'] ?? -1) === $u['id']) ? 'row-highlight' : '';
        ?>
          <tr class="<?= $highlight ?>">
            <td><?= $rank ?></td>
            <td><?= h($u['name']) ?></td>
            <td><?= (int)$u['trades'] ?></td>
            <td><?= (int)$u['points'] ?></td>
            <td><?= $u['best_return']===null ? 'N/A' : number_format($u['best_return'],4).'%' ?></td>
            <td><?= $u['avg_rr']===null ? 'N/A' : number_format($u['avg_rr'],2) ?></td>
            <td><?= $u['weighted_return']===null ? 'N/A' : number_format($u['weighted_return'],4).'%' ?></td>
            <td><?= $u['win_pct']===null ? 'N/A' : number_format($u['win_pct'],2).'%' ?></td>
            <td><?= (int)$u['no_sl'] ?></td>
            <td><?= (int)$u['consistency'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="small" style="margin-top:10px">Tip: Awards calculate from recorded trades; keep logging consistently.</p>
</div>

<?php include __DIR__ . '/footer.php'; ?>