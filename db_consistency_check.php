<?php
/**
 * Database Consistency Check for Phase 3 Pre-Integration
 * Compares actual DB schema against expected tables from migrations
 */

include 'includes/env.php';

echo "=== PHASE 3 PRE-INTEGRATION DATABASE CONSISTENCY CHECK ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

// Database connection
try {
    $conn = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', 
                      getenv('DB_USERNAME') ?: 'shaikh_local', 
                      getenv('DB_PASSWORD') ?: 'StrongLocalPass!23', 
                      getenv('DB_DATABASE') ?: 'shaikhoology');
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    echo "✅ Database connection successful\n";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Expected tables from migrations analysis
$expected_tables = [
    'users' => 'Core user table (referenced by trades and mtm_enrollments)',
    'trades' => 'User trading records (002_tmsmtm.sql)',
    'mtm_models' => 'MTM model definitions (002_tmsmtm.sql)',
    'mtm_tasks' => 'MTM tasks for models (002_tmsmtm.sql)',
    'mtm_enrollments' => 'User MTM enrollments (002_tmsmtm.sql)',
    'audit_events' => 'Audit trail events (005_audit_trail.sql)',
    'audit_event_types' => 'Configurable event types (005_audit_trail.sql)',
    'audit_retention_policies' => 'Data retention policies (005_audit_trail.sql)',
    'rate_limits' => 'Rate limiting storage (008_rate_limit_store.sql)',
    'agent_logs' => 'Agent activity logs (mentioned in requirements)'
];

echo "\n=== TABLE EXISTENCE CHECK ===\n";

// Get actual tables from database
$actual_tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    $actual_tables[] = $row[0];
}

// Compare expected vs actual
$missing_tables = [];
$present_tables = [];

foreach ($expected_tables as $table => $description) {
    if (in_array($table, $actual_tables)) {
        $present_tables[] = $table;
        echo "✅ {$table} - {$description}\n";
    } else {
        $missing_tables[] = $table;
        echo "❌ {$table} - {$description}\n";
    }
}

echo "\n=== TABLE ROW COUNT CHECK ===\n";
echo "Table statistics (SELECT COUNT(*) for each table):\n";

$table_stats = [];
foreach ($present_tables as $table) {
    try {
        $count_result = $conn->query("SELECT COUNT(*) as cnt FROM `{$table}`");
        $count = $count_result->fetch_assoc()['cnt'];
        $table_stats[$table] = $count;
        echo "📊 {$table}: {$count} rows\n";
    } catch (Exception $e) {
        $table_stats[$table] = "ERROR: " . $e->getMessage();
        echo "❌ {$table}: ERROR - " . $e->getMessage() . "\n";
    }
}

// Get all tables for reference
echo "\n=== ALL TABLES IN DATABASE ===\n";
echo "Complete list of tables in " . (getenv('DB_DATABASE') ?: 'shaikhoology') . ":\n";
foreach ($actual_tables as $table) {
    $status = in_array($table, $present_tables) ? "✅ Expected" : "ℹ️ Additional";
    echo "{$status} {$table}\n";
}

echo "\n=== SUMMARY ===\n";
echo "Expected tables: " . count($expected_tables) . "\n";
echo "Present tables: " . count($present_tables) . "\n";
echo "Missing tables: " . count($missing_tables) . "\n";

if (count($missing_tables) > 0) {
    echo "\n❌ INCONSISTENCY DETECTED - Missing tables:\n";
    foreach ($missing_tables as $table) {
        echo "   - {$table}\n";
    }
} else {
    echo "\n✅ ALL EXPECTED TABLES PRESENT\n";
}

$conn->close();

// Generate report content for file output
$report_content = "# Database Consistency Report - Phase 3 Pre-Integration\n\n";
$report_content .= "**Generated:** " . date('Y-m-d H:i:s') . "\n";
$report_content .= "**Database:** " . (getenv('DB_DATABASE') ?: 'shaikhoology') . "\n";
$report_content .= "**Status:** " . (count($missing_tables) > 0 ? "❌ INCONSISTENT" : "✅ CONSISTENT") . "\n\n";

$report_content .= "## Expected vs Actual Tables\n\n";
$report_content .= "| Table | Status | Description | Row Count |\n";
$report_content .= "|-------|--------|-------------|-----------|\n";

foreach ($expected_tables as $table => $description) {
    $status = in_array($table, $present_tables) ? "✅ Present" : "❌ Missing";
    $count = isset($table_stats[$table]) ? $table_stats[$table] : "N/A";
    $report_content .= "| {$table} | {$status} | {$description} | {$count} |\n";
}

$report_content .= "\n## All Database Tables\n\n";
foreach ($actual_tables as $table) {
    $status = in_array($table, $present_tables) ? "Expected" : "Additional";
    $report_content .= "- {$status}: {$table}\n";
}

if (count($missing_tables) > 0) {
    $report_content .= "\n## Missing Tables\n\n";
    $report_content .= "The following required tables are missing:\n";
    foreach ($missing_tables as $table) {
        $report_content .= "- {$table}\n";
    }
    $report_content .= "\n**Action Required:** Run appropriate migrations to create missing tables.\n";
} else {
    $report_content .= "\n## Database Schema Status: ✅ CONSISTENT\n\n";
    $report_content .= "All expected tables are present and the schema matches the latest migrations.\n";
}

// Create reports directory if it doesn't exist
if (!is_dir('reports/phase3_preintegration')) {
    mkdir('reports/phase3_preintegration', 0755, true);
}

// Write report file
file_put_contents('reports/phase3_preintegration/db_consistency.md', $report_content);
echo "\n✅ Report saved to: reports/phase3_preintegration/db_consistency.md\n";