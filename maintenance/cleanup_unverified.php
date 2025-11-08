<?php
/**
 * maintenance/cleanup_unverified.php
 *
 * Run this with a CRON job (recommended: daily).
 * It removes abandoned registrations that were never verified (unverified email users)
 * within 24 hours. It DOES NOT touch verified or active users.
 *
 * Example cron (runs every night at 02:10):
 * 10 2 * * * /usr/bin/php -d detect_unicode=0 /home/USERNAME/public_html/maintenance/cleanup_unverified.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../config.php'; // $mysqli

// Safety: only delete purely unverified accounts older than 24 hours
$sql = "
  DELETE FROM users
  WHERE status = 'unverified'
    AND (email_verified = 0 OR email_verified IS NULL)
    AND created_at < NOW() - INTERVAL 24 HOUR
";

if (!$mysqli->query($sql)) {
    error_log('[cleanup_unverified] MySQL error: ' . $mysqli->error);
} else {
    // Optional: log how many rows were deleted
    $deleted = $mysqli->affected_rows;
    error_log('[cleanup_unverified] Deleted rows: ' . $deleted);
}

echo "OK\n";