<?php
// trade_score.php
// ---------------------------------------------------------------------------
// Central points calculator. Safe to include multiple times.
// Usage: calculate_and_update_points($mysqli, $trade_id, $user_id)
//
// Rules (from your sheet):
//  - +10  Profit Trade Points                (exit > entry)
//  - +5   SL Hit + Analysis Points           (exit <= sl AND analysis_link present)
//  - -10  No SL Penalty                      (stop_loss is NULL or 0)
//  - +5   RR Bonus                           (R:R >= 2)
//  - +15  Consistency Bonus                  (3 profitable CLOSED trades in a row)
//
// Writes the final integer points to trades.points and returns it.
// ---------------------------------------------------------------------------
// Updated to include MTM task progress evaluation on trade closure

if (!function_exists('calculate_and_update_points')) {

// Helper function for column existence checking (matching the one in trade_new.php)
function has_col(mysqli $db, string $table, string $col): bool {
  static $cache = [];
  $cache_key = $table . '.' . $col;
  
  if (isset($cache[$cache_key])) {
    return $cache[$cache_key];
  }
  
  try {
    $stmt = $db->prepare("
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

function calculate_and_update_points(mysqli $mysqli, int $trade_id, int $user_id): int
{
    // === BEGIN SAFE PATCH: Dynamic Column Detection ===
    // Build dynamic SELECT query based on column existence
    $select_cols = ['id', 'user_id', 'entry_price', 'exit_price', 'outcome', 'rr'];
    
    // Check for close_date column variants
    $close_date_col = null;
    if (has_col($mysqli, 'trades', 'close_date')) {
        $close_date_col = 'close_date';
        $select_cols[] = 'close_date';
    } elseif (has_col($mysqli, 'trades', 'closed_at')) {
        $close_date_col = 'closed_at';
        $select_cols[] = 'closed_at';
    } elseif (has_col($mysqli, 'trades', 'exit_date')) {
        $close_date_col = 'exit_date';
        $select_cols[] = 'exit_date';
    } elseif (has_col($mysqli, 'trades', 'close_at')) {
        $close_date_col = 'close_at';
        $select_cols[] = 'close_at';
    }
    
    // Add optional columns only if they exist
    if (has_col($mysqli, 'trades', 'analysis_link')) {
        $select_cols[] = 'analysis_link';
    }
    if (has_col($mysqli, 'trades', 'points')) {
        $select_cols[] = 'points';
    }
    if (has_col($mysqli, 'trades', 'target_price')) {
        $select_cols[] = 'target_price';
    }
    if (has_col($mysqli, 'trades', 'stop_loss')) {
        $select_cols[] = 'stop_loss';
    }
    if (has_col($mysqli, 'trades', 'enrollment_id')) {
        $select_cols[] = 'enrollment_id';
    }
    if (has_col($mysqli, 'trades', 'task_id')) {
        $select_cols[] = 'task_id';
    }
    if (has_col($mysqli, 'trades', 'compliance_status')) {
        $select_cols[] = 'compliance_status';
    }
    
    $sql = "SELECT " . implode(', ', $select_cols) . "
              FROM trades
             WHERE id = ? AND user_id = ?
             LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $trade_id, $user_id);
    $stmt->execute();
    $trade = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    // === END SAFE PATCH: Dynamic Column Detection ===

    if (!$trade) {
        return 0; // nothing to do
    }

    // If trade isn't closed yet, keep points = 0 (and do not overwrite)
    $isClosed = !is_null($trade['exit_price']) && $trade['exit_price'] !== '';
    if (!$isClosed) {
        // keep whatever is there (usually 0)
        return (int)($trade['points'] ?? 0);
    }

    $entry  = (float)$trade['entry_price'];
    // Handle missing columns gracefully with proper checks
    $sl     = has_col($mysqli, 'trades', 'stop_loss') && isset($trade['stop_loss'])
             ? (is_null($trade['stop_loss']) ? null : (float)$trade['stop_loss'])
             : null;
    $tgt    = has_col($mysqli, 'trades', 'target_price') && isset($trade['target_price'])
             ? (is_null($trade['target_price']) ? null : (float)$trade['target_price'])
             : null;
    $exit   = (float)$trade['exit_price'];
    $rrCell = isset($trade['rr']) ? $trade['rr'] : null; // might be null
    $analysisLink = has_col($mysqli, 'trades', 'analysis_link')
                   ? trim((string)($trade['analysis_link'] ?? ''))
                   : '';

    $points = 0;

    // +10 if profitable (exit > entry)
    if ($exit > $entry) $points += 10;

    // +5 if SL hit AND analysis_link present
    if (!is_null($sl) && $sl > 0 && $exit <= $sl && $analysisLink !== '') {
        $points += 5;
    }

    // -10 if no SL
    if (is_null($sl) || $sl == 0.0) {
        $points -= 10;
    }

    // +5 RR bonus for R:R >= 2
    $rr = null;
    if (!is_null($sl) && $sl != 0.0 && $entry > 0) {
        $risk = $entry - $sl;               // long assumption
        $reward = (!is_null($tgt)) ? ($tgt - $entry) : null;
        if (!is_null($reward) && $risk > 0) {
            $rr = $reward / $risk;
        }
    }
    if (is_null($rr)) {
        // fall back to stored rr if present
        $rr = !is_null($rrCell) ? (float)$rrCell : null;
    }
    if (!is_null($rr) && $rr >= 2.0) {
        $points += 5;
    }

    // +15 consistency bonus: 3 closed profitable trades in a row (including this)
    // (Sorted by close_date DESC, then id DESC for safety)
    // Check for close_date column variants and use the same detection logic
    if (has_col($mysqli, 'trades', 'close_date')) {
        $close_date_col = 'close_date';
    } elseif (has_col($mysqli, 'trades', 'closed_at')) {
        $close_date_col = 'closed_at';
    } elseif (has_col($mysqli, 'trades', 'exit_date')) {
        $close_date_col = 'exit_date';
    } elseif (has_col($mysqli, 'trades', 'close_at')) {
        $close_date_col = 'close_at';
    } else {
        // Fallback: if no close date column exists, just sort by entry or id
        $close_date_col = null;
    }
    
    $orderByClause = $close_date_col ? "ORDER BY COALESCE($close_date_col, '1970-01-01') DESC, id DESC" : "ORDER BY id DESC";
    $sql = "SELECT id, entry_price, exit_price
              FROM trades
             WHERE user_id = ?
               AND exit_price IS NOT NULL
             " . $orderByClause . "
             LIMIT 3";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rs = $stmt->get_result();
    $rowCount = 0; $allProfitable = true;
    while ($r = $rs->fetch_assoc()) {
        $rowCount++;
        if (!((float)$r['exit_price'] > (float)$r['entry_price'])) {
            $allProfitable = false;
            break;
        }
    }
    $stmt->close();
    if ($rowCount === 3 && $allProfitable) {
        $points += 15;
    }

    // === BEGIN SAFE PATCH: Dynamic Column Persistence ===
    // Persist points only if the points column exists
    if (has_col($mysqli, 'trades', 'points')) {
        $points = (int)round($points);
        $stmt = $mysqli->prepare("UPDATE trades SET points = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("iii", $points, $trade_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    // === END SAFE PATCH: Dynamic Column Persistence ===

    // MTM Task Progress Evaluation - handle missing columns gracefully
    if ($isClosed && has_col($mysqli, 'trades', 'enrollment_id') && has_col($mysqli, 'trades', 'task_id')) {
        if (isset($trade['enrollment_id']) && isset($trade['task_id']) &&
            !empty($trade['enrollment_id']) && !empty($trade['task_id'])) {
            require_once __DIR__ . '/system/mtm_verifier.php';

            // Update task progress based on this trade completion
            mtm_update_task_progress($mysqli, (int)$trade['enrollment_id'], (int)$trade['task_id']);
        }
    }

    return $points;
}

} // function_exists