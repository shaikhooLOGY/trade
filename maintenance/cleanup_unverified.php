<?php
// maintenance/cleanup_unverified.php — Automated cleanup script for expired OTPs and unverified accounts
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Set JSON response for API calls
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
}

function cleanup_log($message) {
    $log_file = __DIR__ . '/../logs/cleanup.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

try {
    $mysqli = db();
    if (!$mysqli) {
        throw new Exception("Database connection failed");
    }

    $results = [];
    $start_time = microtime(true);

    // 1. Clean up expired OTPs
    $expired_otps = otp_cleanup_expired();
    $results['expired_otps_cleaned'] = $expired_otps;
    cleanup_log("Cleaned up $expired_otps expired OTP records");

    // 2. Clean up old unverified accounts (older than 7 days)
    $cutoff_date = date('Y-m-d H:i:s', strtotime('-7 days'));
    $stmt = $mysqli->prepare("SELECT id, name, email, created_at FROM users WHERE email_verified = 0 AND status = 'pending' AND created_at < ?");
    $stmt->bind_param('s', $cutoff_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $old_unverified = [];
    while ($row = $result->fetch_assoc()) {
        $old_unverified[] = $row;
    }
    $stmt->close();
    
    $results['old_unverified_accounts'] = count($old_unverified);
    
    // Log old unverified accounts (but don't delete them automatically)
    foreach ($old_unverified as $account) {
        cleanup_log("Found old unverified account: {$account['email']} (created: {$account['created_at']})");
    }

    // 3. Clean up OTP records for verified users (they shouldn't need OTPs anymore)
    $stmt = $mysqli->prepare("UPDATE user_otps SET is_active = FALSE WHERE user_id IN (SELECT id FROM users WHERE email_verified = 1) AND is_active = TRUE");
    $stmt->execute();
    $verified_user_otps = $stmt->affected_rows;
    $stmt->close();
    
    $results['verified_user_otps_deactivated'] = $verified_user_otps;
    cleanup_log("Deactivated $verified_user_otps OTP records for verified users");

    // 4. Clean up very old OTP records (older than 30 days, regardless of status)
    $old_otp_cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $stmt = $mysqli->prepare("DELETE FROM user_otps WHERE created_at < ?");
    $stmt->bind_param('s', $old_otp_cutoff);
    $stmt->execute();
    $old_otps_deleted = $stmt->affected_rows;
    $stmt->close();
    
    $results['old_otps_deleted'] = $old_otps_deleted;
    cleanup_log("Deleted $old_otps_deleted OTP records older than 30 days");

    // 5. Get database statistics
    $stmt = $mysqli->prepare("SELECT COUNT(*) as total_users FROM users");
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $mysqli->prepare("SELECT COUNT(*) as verified_users FROM users WHERE email_verified = 1");
    $stmt->execute();
    $verified_count = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $mysqli->prepare("SELECT COUNT(*) as pending_users FROM users WHERE email_verified = 0 AND status = 'pending'");
    $stmt->execute();
    $pending_count = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $mysqli->prepare("SELECT COUNT(*) as active_otps FROM user_otps WHERE is_active = TRUE");
    $stmt->execute();
    $active_otps = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $results['database_stats'] = [
        'total_users' => (int)$stats['total_users'],
        'verified_users' => (int)$verified_count['verified_users'],
        'pending_users' => (int)$pending_count['pending_users'],
        'active_otps' => (int)$active_otps['active_otps']
    ];

    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    $results['execution_time'] = $execution_time;
    $results['timestamp'] = date('Y-m-d H:i:s');

    cleanup_log("Cleanup completed successfully in {$execution_time}s");
    cleanup_log("Database stats: {$results['database_stats']['total_users']} total users, {$results['database_stats']['verified_users']} verified, {$results['database_stats']['pending_users']} pending");

    // Return results
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        echo json_encode([
            'success' => true,
            'message' => 'Cleanup completed successfully',
            'results' => $results
        ], JSON_PRETTY_PRINT);
    } else {
        echo "OTP and Account Cleanup Report\n";
        echo "==============================\n";
        echo "Timestamp: {$results['timestamp']}\n";
        echo "Execution Time: {$execution_time}s\n\n";
        
        echo "Cleanup Results:\n";
        echo "- Expired OTPs cleaned: {$results['expired_otps_cleaned']}\n";
        echo "- Old unverified accounts found: {$results['old_unverified_accounts']}\n";
        echo "- Verified user OTPs deactivated: {$results['verified_user_otps_deactivated']}\n";
        echo "- Old OTP records deleted: {$results['old_otps_deleted']}\n\n";
        
        echo "Database Statistics:\n";
        echo "- Total users: {$results['database_stats']['total_users']}\n";
        echo "- Verified users: {$results['database_stats']['verified_users']}\n";
        echo "- Pending users: {$results['database_stats']['pending_users']}\n";
        echo "- Active OTPs: {$results['database_stats']['active_otps']}\n\n";
        
        echo "Cleanup completed successfully!\n";
    }

} catch (Exception $e) {
    $error_message = "Cleanup failed: " . $e->getMessage();
    cleanup_log($error_message);
    
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $error_message,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        echo "ERROR: $error_message\n";
        echo "Check logs/cleanup.log for details.\n";
    }
}
?>