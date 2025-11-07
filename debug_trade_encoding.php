<?php
// Debug script to test trade creation with encoding
require_once 'config.php';

$traderId = 42;
$symbol = 'TCS';
$quantity = 10.0;
$entryPrice = 400.0;
$outcome = 'open';
$pnl = 0.0;
$positionPercent = 0.0;
$stopLoss = null;
$targetPrice = null;
$allocationAmount = null;
$notes = 'Test trade';

echo "Testing trade creation with outcome: '$outcome'\n";
echo "Outcome hex: " . bin2hex($outcome) . "\n";
echo "Outcome length: " . strlen($outcome) . "\n";
echo "Outcome type: " . gettype($outcome) . "\n";

try {
    $stmt = $mysqli->prepare("
        INSERT INTO trades (
            trader_id, symbol, side, quantity, position_percent, entry_price, stop_loss, target_price, pnl,
            outcome, allocation_amount, analysis_link, price, opened_at, closed_at, close_price, notes,
            created_at, updated_at, deleted_at
        ) VALUES (?, ?, 'buy', ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), NULL, NULL, ?, NOW(), NOW(), NULL)
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare trade query: ' . $mysqli->error);
    }
    
    echo "Binding parameters...\n";
    $stmt->bind_param(
        'isdddddsddss',
        $traderId,
        $symbol,
        $quantity,
        $positionPercent,
        $entryPrice,
        $stopLoss,
        $targetPrice,
        $pnl,
        $outcome,
        $allocationAmount,
        $entryPrice, // price
        $notes
    );
    
    echo "Executing...\n";
    $success = $stmt->execute();
    
    if (!$success) {
        echo "Error: " . $stmt->error . "\n";
        echo "Error number: " . $stmt->errno . "\n";
    } else {
        $tradeId = $mysqli->insert_id;
        echo "Success! Trade created with ID: $tradeId\n";
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>