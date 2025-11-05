<?php
// trade_api.php - API endpoint for trade operations
// Dependencies via bootstrap.php, no session needed for API calls
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/functions.php'; // compute_points_from_trade

header('Content-Type: application/json; charset=utf-8');

// must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
    exit;
}

$input = $_POST; // form posts
// basic server-side sanitize
$uid = (int) $_SESSION['user_id'];
$entry_date = $input['entry_date'] ?? null;
$close_date = $input['close_date'] ?? null;
$symbol = trim($input['symbol'] ?? '');
$position_percent = isset($input['position_percent']) && $input['position_percent'] !== '' ? floatval($input['position_percent']) : null;
$entry_price = isset($input['entry_price']) && $input['entry_price'] !== '' ? floatval($input['entry_price']) : null;
$stop_loss = isset($input['stop_loss']) && $input['stop_loss'] !== '' ? floatval($input['stop_loss']) : null;
$target_price = isset($input['target_price']) && $input['target_price'] !== '' ? floatval($input['target_price']) : null;
$outcome = strtoupper(trim($input['outcome'] ?? 'OPEN'));
$exit_price = isset($input['exit_price']) && $input['exit_price'] !== '' ? floatval($input['exit_price']) : null;
$analysis_link = trim($input['analysis_link'] ?? '');
$notes = trim($input['notes'] ?? '');

// server validation
if ($symbol === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Symbol required']);
    exit;
}

// compute pl%
$pl_percent = null;
if ($entry_price !== null && $exit_price !== null && $entry_price != 0) {
    $pl_percent = round((($exit_price - $entry_price)/$entry_price) * 100, 4);
}

// rr calc
$rr = null;
if ($entry_price !== null && $stop_loss !== null && $stop_loss != $entry_price) {
    $risk = abs($entry_price - $stop_loss);
    $reward = $target_price !== null ? abs($target_price - $entry_price) : ($exit_price !== null ? abs($exit_price - $entry_price) : null);
    if ($risk > 0 && $reward !== null) {
        $rr = round($reward / $risk, 2);
    }
}

// allocation_amount (simple replicate): if position_percent -> use funds (from users table)
$allocation_amount = 0.0;
if ($position_percent !== null && $position_percent > 0) {
    // try to read trading_capital or funds_available
    $res = $mysqli->query("SELECT COALESCE(trading_capital, funds_available, 0) AS cap FROM users WHERE id = {$uid} LIMIT 1");
    $cap = 0;
    if ($res) {
        $r = $res->fetch_assoc();
        $cap = floatval($r['cap'] ?? 0);
    }
    $allocation_amount = round(($cap * ($position_percent/100)), 2);
}

// compute points via helper
$trade_for_points = [
    'pl_percent' => $pl_percent,
    'outcome' => $outcome,
    'stop_loss' => $stop_loss,
    'rr' => $rr,
    'analysis_link' => $analysis_link
];
$points = compute_points_from_trade($trade_for_points);

// Insert into DB (prepared)
$stmt = $mysqli->prepare("INSERT INTO trades 
    (user_id, entry_date, close_date, symbol, position_percent, entry_price, stop_loss, target_price, exit_price, outcome, pl_percent, rr, allocation_amount, points, analysis_link, notes)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB prepare failed: '.$mysqli->error]);
    exit;
}

// bind (strings and numbers) - convert NULL to empty string where needed
$entry_date_b = $entry_date ?: null;
$close_date_b = $close_date ?: null;
$symbol_b = $symbol;
$position_percent_b = $position_percent !== null ? (string)$position_percent : null;
$entry_price_b = $entry_price !== null ? (string)$entry_price : null;
$stop_loss_b = $stop_loss !== null ? (string)$stop_loss : null;
$target_price_b = $target_price !== null ? (string)$target_price : null;
$exit_price_b = $exit_price !== null ? (string)$exit_price : null;
$pl_percent_b = $pl_percent !== null ? (string)$pl_percent : null;
$rr_b = $rr !== null ? (string)$rr : null;
$alloc_b = (string)$allocation_amount;
$points_b = (string)$points;
$analysis_link_b = $analysis_link ?: null;
$notes_b = $notes ?: null;

$stmt->bind_param(
    'isssssssssssssss',
    $uid,
    $entry_date_b,
    $close_date_b,
    $symbol_b,
    $position_percent_b,
    $entry_price_b,
    $stop_loss_b,
    $target_price_b,
    $exit_price_b,
    $outcome,
    $pl_percent_b,
    $rr_b,
    $alloc_b,
    $points_b,
    $analysis_link_b,
    $notes_b
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB insert failed: '.$stmt->error]);
    exit;
}

// return the created row id + computed values
$insert_id = $stmt->insert_id;

echo json_encode([
    'ok' => true,
    'id' => $insert_id,
    'points' => $points,
    'allocation_amount' => $allocation_amount,
    'pl_percent' => $pl_percent,
    'rr' => $rr
]);
exit;