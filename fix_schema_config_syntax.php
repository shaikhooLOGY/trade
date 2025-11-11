<?php
/**
 * Emergency Fix Script for schema_config.php Syntax Error
 * 
 * This script restores schema_config.php from the backup created by auto-management
 * Run this on the live server to fix the syntax error
 */

// Path to the corrupted file
$config_file = __DIR__ . '/includes/schema_config.php';

// Find the most recent backup
$backup_pattern = $config_file . '.backup.*';
$backups = glob($backup_pattern);

if (empty($backups)) {
    die("❌ ERROR: No backup files found!\n\nPlease manually restore schema_config.php from your version control.\n");
}

// Sort by modification time (newest first)
usort($backups, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$latest_backup = $backups[0];

echo "🔍 Found backup: " . basename($latest_backup) . "\n";
echo "📅 Created: " . date('Y-m-d H:i:s', filemtime($latest_backup)) . "\n\n";

// Create a backup of the corrupted file first
$corrupted_backup = $config_file . '.corrupted.' . date('Y-m-d_H-i-s');
if (copy($config_file, $corrupted_backup)) {
    echo "💾 Corrupted file backed up to: " . basename($corrupted_backup) . "\n";
} else {
    die("❌ ERROR: Could not backup corrupted file!\n");
}

// Restore from backup
if (copy($latest_backup, $config_file)) {
    echo "✅ SUCCESS: schema_config.php restored from backup!\n\n";
    
    // Verify the restored file
    $test = @include($config_file);
    if (is_array($test)) {
        echo "✅ VERIFIED: Restored file is valid PHP!\n";
        echo "📊 Tables in config: " . count($test) . "\n\n";
        
        echo "🎉 Recovery complete! Your site should work now.\n\n";
        echo "⚠️ IMPORTANT: Upload the fixed admin/schema_management.php to prevent this from happening again.\n";
    } else {
        echo "⚠️ WARNING: Restored file may still have issues. Check manually.\n";
    }
} else {
    die("❌ ERROR: Could not restore from backup!\n");
}

echo "\n📝 Files created:\n";
echo "  - Corrupted backup: " . basename($corrupted_backup) . "\n";
echo "  - Restored from: " . basename($latest_backup) . "\n";
?>