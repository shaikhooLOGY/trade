<?php
/**
 * Authentication Matrix Test Bootstrap
 * Identifies existing test accounts and sets up test environment
 */

require_once __DIR__ . '/config.php';

echo "=== AUTH MATRIX TEST BOOTSTRAP ===\n";
echo "Time: " . date('c') . "\n\n";

// Connect to database
try {
    // Use the $mysqli object from config.php
    if (!$mysqli || $mysqli->connect_error) {
        throw new Exception("Database connection failed: " . ($mysqli ? $mysqli->connect_error : "Unknown error"));
    }
    echo "✅ Database connection successful\n\n";
} catch (Exception $e) {
    die("❌ Database error: " . $e->getMessage() . "\n");
}

// Get existing users
try {
    $stmt = $mysqli->prepare("
        SELECT 
            id, 
            name, 
            email, 
            is_admin, 
            status, 
            email_verified,
            role
        FROM users 
        WHERE status IN ('active', 'approved') 
        AND email_verified = 1
        ORDER BY is_admin DESC, id ASC
        LIMIT 10
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "=== AVAILABLE TEST ACCOUNTS ===\n";
    $users = [];
    $admins = [];
    $regular_users = [];
    
    while ($row = $result->fetch_assoc()) {
        // Check both is_admin flag and role field for admin detection
        $user_type = ($row['is_admin'] == 1 || $row['role'] === 'admin') ? 'ADMIN' : 'USER';
        echo sprintf(
            "ID: %d | %s | %s | Email: %s | Role: %s | Status: %s\n",
            $row['id'],
            $user_type,
            $row['name'],
            $row['email'],
            $row['role'],
            $row['status']
        );
        
        $users[] = $row;
        if ($row['is_admin'] == 1 || $row['role'] === 'admin') {
            $admins[] = $row;
        } else {
            $regular_users[] = $row;
        }
    }
    $stmt->close();
    
    echo "\n=== SUMMARY ===\n";
    echo "Total users found: " . count($users) . "\n";
    echo "Admin accounts: " . count($admins) . "\n";
    echo "Regular users: " . count($regular_users) . "\n\n";
    
    if (count($admins) == 0) {
        echo "⚠️  WARNING: No admin accounts found!\n\n";
    }
    
    if (count($regular_users) == 0) {
        echo "⚠️  WARNING: No regular user accounts found!\n\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error querying users: " . $e->getMessage() . "\n";
}

// Check if we have test trades
try {
    $stmt = $mysqli->prepare("
        SELECT
            t.id,
            t.symbol,
            t.trader_id,
            u1.name as trader_name
        FROM trades t
        LEFT JOIN users u1 ON u1.id = t.trader_id
        LIMIT 5
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "=== SAMPLE TRADES ===\n";
    $trades = [];
    while ($row = $result->fetch_assoc()) {
        echo sprintf(
            "Trade ID: %d | Symbol: %s | Trader: %s (ID: %d) | User: %s (ID: %d)\n",
            $row['id'],
            $row['symbol'],
            $row['trader_name'],
            $row['trader_id'],
            $row['user_name'],
            $row['user_id']
        );
        $trades[] = $row;
    }
    $stmt->close();
    
    if (count($trades) == 0) {
        echo "No trades found in database\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error querying trades: " . $e->getMessage() . "\n";
}

echo "\n=== BOOTSTRAP COMPLETE ===\n";
echo "You can now use these accounts for authentication matrix testing.\n";

// Provide test credentials
if (count($admins) > 0) {
    echo "\n🔑 ADMIN TEST CREDENTIALS NEEDED:\n";
    echo "Note: You will need to determine passwords manually or create test accounts.\n";
}

if (count($regular_users) > 0) {
    echo "\n👤 REGULAR USER TEST CREDENTIALS NEEDED:\n";  
    echo "Note: You will need to determine passwords manually or create test accounts.\n";
}

$mysqli->close();
?>