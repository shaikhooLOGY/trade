<?php
// Debug script to test trade creation with different values
require_once 'config.php';

echo "MySQL connection charset: " . $mysqli->character_set_name() . "\n";

$traderId = 42;
$symbol = 'TCS';
$quantity = 10.0;
$entryPrice = 400.0;
$positionPercent = 0.0;
$stopLoss = null;
$targetPrice = null;
$allocationAmount = null;
$notes = 'Test trade';

try {
    // Test with 'win' first
    echo "Testing with outcome: 'win'\n";
    $outcome = 'win';
    
    $stmt = $mysqli->prepare("
        INSERT INTO trades (
            trader_id, symbol, side, quantity, position_percent, entry_price, stop_loss, target_price, pnl,
            outcome, allocation_amount, analysis_link, price, opened_at, closed_at, close_price, notes,
            created_at, updated_at, deleted_at
        ) VALUES (?, ?, 'buy', ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), NULL, NULL, ?, NOW(), NOW(), NULL)
    ");
    
    $stmt->bind_param('isdddddsddss', $traderId, $symbol, $quantity, $positionPercent, $entryPrice, $stopLoss, $targetPrice, 0.0, $outcome, $allocationAmount, $entryPrice, $notes);
    $success = $stmt->execute();
    
    if (!$success) {
        echo "Error with 'win': " . $stmt->error . "\n";
    } else {
        echo "Success with 'win'! Trade ID: " . $mysqli->insert_id . "\n";
    }
    $stmt->close();
    
    // Test with 'open' with explicit UTF-8 conversion
    echo "Testing with outcome: 'open' (UTF-8 safe)\n";
    $outcome = 'open';
    $outcome = mb_convert_encoding($outcome, 'UTF-8', 'auto');
    
    echo "Outcome hex after conversion: " . bin2hex($outcome) . "\n";
    
    $stmt = $mysqli->prepare("
        INSERT INTO trades (
            trader_id, symbol, side, quantity, position_percent, entry_price, stop_loss, target_price, pnl,
            outcome, allocation_amount, analysis_link, price, opened_at, closed_at, close_price, notes,
            created_at, updated_at, deleted_at
        ) VALUES (?, ?, 'buy', ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), NULL, NULL, ?, NOW(), NOW(), NULL)
    ");
    
    $stmt->bind_param('isdddddsddss', $traderId, $symbol, $quantity, $positionPercent, $entryPrice, $stopLoss, $targetPrice, 0.0, $outcome, $allocationAmount, $entryPrice, $notes);
    $success = $stmt->execute();
    
    if (!$success) {
        echo "Error with 'open' (UTF-8): " . $stmt->error . "\n";
    } else {
        echo "Success with 'open' (UTF-8)! Trade ID: " . $mysqli->insert_id . "\n";
    }
    $stmt->close();
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>