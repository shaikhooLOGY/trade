<?php
// ajax_trade_create.php
// Robust version — clears any accidental output and returns clean JSON.

// IMPORTANT: make sure config.php does NOT echo anything or start output before headers.
// We'll still guard against stray output using output buffering.

ob_start();

// disable direct display of errors in response (we still log them in server logs)
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php'; // must start session and provide $mysqli
require_once __DIR__ . '/includes/security/csrf.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        // clean any stray output
        ob_clean();
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        ob_clean();
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // CSRF Protection - validate before any DB operations
    if (!validate_csrf($_POST['csrf'] ?? '')) {
        http_response_code(403);
        ob_clean();
        echo json_encode(['error' => 'CSRF failed']);
        exit;
    }

    $uid = (int) $_SESSION['user_id'];

    // read inputs (use null/empty normalized to empty string for safe binding)
    $entry_date   = $_POST['entry_date'] ?? '';
    $close_date   = $_POST['close_date'] ?? '';
    $symbol       = trim((string)($_POST['symbol'] ?? ''));
    $position_percent = isset($_POST['position_percent']) && $_POST['position_percent'] !== '' ? (float)$_POST['position_percent'] : '';
    $entry_price  = isset($_POST['entry_price']) && $_POST['entry_price'] !== '' ? (float)$_POST['entry_price'] : '';
    $stop_loss    = isset($_POST['stop_loss']) && $_POST['stop_loss'] !== '' ? (float)$_POST['stop_loss'] : '';
    $target_price = isset($_POST['target_price']) && $_POST['target_price'] !== '' ? (float)$_POST['target_price'] : '';
    $exit_price   = isset($_POST['exit_price']) && $_POST['exit_price'] !== '' ? (float)$_POST['exit_price'] : '';
    $outcome      = strtoupper(trim((string)($_POST['outcome'] ?? 'OPEN')));
    $analysis_link= trim((string)($_POST['analysis_link'] ?? ''));
    $notes        = trim((string)($_POST['notes'] ?? ''));

    if ($symbol === '') {
        http_response_code(422);
        ob_clean();
        echo json_encode(['error' => 'Symbol is required']);
        exit;
    }

    // compute pl_percent (if entry & exit provided)
    $pl_percent = null;
    if ($entry_price !== '' && $exit_price !== '' && $entry_price != 0) {
        $pl_percent = round((($exit_price - $entry_price) / $entry_price) * 100, 4);
    }

    // compute rr
    $rr = null;
    if ($entry_price !== '' && $stop_loss !== '' && $target_price !== '') {
        $risk = abs($entry_price - $stop_loss);
        $reward = abs($target_price - $entry_price);
        if ($risk > 0) $rr = round($reward / $risk, 2);
    }

    // compute allocation & basic points (you can replace compute logic later)
    $stmt = $mysqli->prepare("SELECT COALESCE(trading_capital, funds_available, 0) AS capital FROM users WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_assoc();
    $capital = (float)($tmp['capital'] ?? 0);
    $stmt->close();

    $allocation_amount = 0.0;
    if ($position_percent !== '' && $position_percent > 0) {
        $allocation_amount = round($capital * ($position_percent / 100), 2);
    }

    // points basic (replace with your compute_points_from_trade if available)
    $points = 0;
    if ($pl_percent !== null) $points = (int) round($pl_percent);

    // Insert — bind everything as strings except user_id to avoid strict type mismatches.
    $insert = $mysqli->prepare("INSERT INTO trades
      (user_id, entry_date, close_date, symbol, position_percent, entry_price, stop_loss, target_price, exit_price, outcome, pl_percent, rr, allocation_amount, points, analysis_link, notes, created_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    if (!$insert) {
        http_response_code(500);
        ob_clean();
        echo json_encode(['error' => 'DB prepare failed: ' . $mysqli->error]);
        exit;
    }

    // normalize values to strings so bind_param works reliably
    $p_entry_date = (string)$entry_date;
    $p_close_date = (string)$close_date;
    $p_symbol = (string)$symbol;
    $p_position_percent = $position_percent !== '' ? (string)$position_percent : '';
    $p_entry_price = $entry_price !== '' ? (string)$entry_price : '';
    $p_stop_loss = $stop_loss !== '' ? (string)$stop_loss : '';
    $p_target_price = $target_price !== '' ? (string)$target_price : '';
    $p_exit_price = $exit_price !== '' ? (string)$exit_price : '';
    $p_outcome = (string)$outcome;
    $p_pl_percent = $pl_percent !== null ? (string)$pl_percent : '';
    $p_rr = $rr !== null ? (string)$rr : '';
    $p_alloc = (string)$allocation_amount;
    $p_points = (string)$points;
    $p_analysis = (string)$analysis_link;
    $p_notes = (string)$notes;

    // types: i (user_id) + 15 strings
    $insert->bind_param(
        'isssssssssssssss',
        $uid,
        $p_entry_date,
        $p_close_date,
        $p_symbol,
        $p_position_percent,
        $p_entry_price,
        $p_stop_loss,
        $p_target_price,
        $p_exit_price,
        $p_outcome,
        $p_pl_percent,
        $p_rr,
        $p_alloc,
        $p_points,
        $p_analysis,
        $p_notes
    );

    if (!$insert->execute()) {
        http_response_code(500);
        ob_clean();
        echo json_encode(['error' => 'DB insert failed: ' . $insert->error]);
        exit;
    }

    $new_id = $insert->insert_id;
    $insert->close();

    // recalc small KPIs
    $stmt = $mysqli->prepare("SELECT COALESCE(SUM(allocation_amount),0) AS reserved FROM trades WHERE user_id = ? AND (outcome IS NULL OR UPPER(outcome) = 'OPEN')");
    $stmt->bind_param('i', $uid); $stmt->execute(); $tmp = $stmt->get_result()->fetch_assoc(); $reserved = (float)($tmp['reserved'] ?? 0); $stmt->close();
    $available = $capital - $reserved; if ($available < 0) $available = 0;

    $stmt = $mysqli->prepare("SELECT COALESCE(SUM(points),0) AS month_points FROM trades WHERE user_id = ? AND YEAR(COALESCE(close_date, entry_date)) = YEAR(CURRENT_DATE()) AND MONTH(COALESCE(close_date, entry_date)) = MONTH(CURRENT_DATE())");
    $stmt->bind_param('i', $uid); $stmt->execute(); $tmp = $stmt->get_result()->fetch_assoc(); $month_points = (int)($tmp['month_points'] ?? 0); $stmt->close();

    // return clean JSON — clear buffer first
    ob_clean();
    echo json_encode([
        'success' => true,
        'trade' => [
            'id' => $new_id,
            'entry_date' => $entry_date,
            'close_date' => $close_date,
            'symbol' => $symbol,
            'position_percent' => $position_percent,
            'entry_price' => $entry_price,
            'stop_loss' => $stop_loss,
            'target_price' => $target_price,
            'exit_price' => $exit_price,
            'outcome' => $outcome,
            'pl_percent' => $pl_percent,
            'rr' => $rr,
            'points' => $points
        ],
        'kpis' => [
            'available' => $available,
            'reserved' => $reserved,
            'month_points' => $month_points
        ]
    ]);
    exit;

} catch (Exception $e) {
    // on exception, clear any stray output and return JSON error
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
    exit;
}