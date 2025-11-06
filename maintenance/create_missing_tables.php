<?php
/**
 * Missing Tables Creator
 * Creates the missing audit tables that weren't properly created
 */

require_once __DIR__ . '/../core/bootstrap.php';

function create_missing_audit_tables(): array {
    global $mysqli;
    
    $results = [];
    
    // Create audit_event_types table
    $sql1 = "
        CREATE TABLE IF NOT EXISTS audit_event_types (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    try {
        if ($mysqli->query($sql1)) {
            $results['audit_event_types'] = 'success';
            
            // Populate base audit event types
            $insertSql = "
                INSERT IGNORE INTO audit_event_types (event_type, description, severity) VALUES
                ('login_success', 'User successful login', 'low'),
                ('login_failure', 'User failed login attempt', 'medium'),
                ('logout', 'User logout', 'low'),
                ('password_change', 'User password changed', 'medium'),
                ('profile_update', 'User profile updated', 'low'),
                ('trade_create', 'New trade created', 'low'),
                ('trade_update', 'Trade updated', 'low'),
                ('trade_delete', 'Trade deleted', 'medium'),
                ('mtm_enroll', 'User enrolled in MTM model', 'low'),
                ('mtm_unenroll', 'User unenrolled from MTM model', 'low'),
                ('admin_action', 'Admin performed action', 'medium'),
                ('security_violation', 'Security violation detected', 'high'),
                ('rate_limit_exceeded', 'Rate limit exceeded', 'medium'),
                ('api_access', 'API endpoint accessed', 'low'),
                ('system_error', 'System error occurred', 'high')
            ";
            $mysqli->query($insertSql);
        } else {
            $results['audit_event_types'] = 'error: ' . $mysqli->error;
        }
    } catch (Exception $e) {
        $results['audit_event_types'] = 'error: ' . $e->getMessage();
    }
    
    // Create audit_retention_policies table
    $sql2 = "
        CREATE TABLE IF NOT EXISTS audit_retention_policies (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            event_types JSON, -- Array of event types this policy applies to
            retention_days INT NOT NULL DEFAULT 365,
            auto_delete TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    try {
        if ($mysqli->query($sql2)) {
            $results['audit_retention_policies'] = 'success';
            
            // Populate base audit retention policies
            $insertSql2 = "
                INSERT IGNORE INTO audit_retention_policies (name, event_types, retention_days, auto_delete) VALUES
                ('default_retention', JSON_ARRAY('login_success', 'logout', 'profile_update', 'mtm_enroll', 'mtm_unenroll', 'api_access'), 365, 1),
                ('security_events', JSON_ARRAY('login_failure', 'security_violation', 'rate_limit_exceeded'), 2555, 1),
                ('admin_actions', JSON_ARRAY('admin_action', 'password_change'), 1826, 1),
                ('trade_activities', JSON_ARRAY('trade_create', 'trade_update', 'trade_delete'), 1095, 1),
                ('system_events', JSON_ARRAY('system_error'), 90, 1)
            ";
            $mysqli->query($insertSql2);
        } else {
            $results['audit_retention_policies'] = 'error: ' . $mysqli->error;
        }
    } catch (Exception $e) {
        $results['audit_retention_policies'] = 'error: ' . $e->getMessage();
    }
    
    return $results;
}

function verify_final_schema(): array {
    global $mysqli;
    
    $expectedTables = [
        'users', 'mtm_models', 'mtm_tasks', 'mtm_enrollments', 'trades',
        'rate_limits', 'idempotency_keys', 'audit_events', 'audit_event_types',
        'audit_retention_policies', 'agent_logs', 'leagues', 'schema_migrations'
    ];
    
    $verification = [
        'status' => 'unknown',
        'tables_found' => [],
        'tables_missing' => []
    ];
    
    try {
        $result = $mysqli->query("SHOW TABLES");
        $existingTables = [];
        
        while ($row = $result->fetch_array()) {
            $existingTables[] = $row[0];
        }
        
        foreach ($expectedTables as $table) {
            if (in_array($table, $existingTables, true)) {
                $verification['tables_found'][] = $table;
            } else {
                $verification['tables_missing'][] = $table;
            }
        }
        
        $verification['status'] = empty($verification['tables_missing']) ? 'complete' : 'incomplete';
        
    } catch (Exception $e) {
        $verification['status'] = 'error';
        $verification['error'] = $e->getMessage();
    }
    
    return $verification;
}

// Main execution
echo "🔧 Creating missing audit tables...\n";

$results = create_missing_audit_tables();

foreach ($results as $table => $status) {
    if ($status === 'success') {
        echo "  ✅ $table: Created successfully\n";
    } else {
        echo "  ❌ $table: $status\n";
    }
}

echo "\nVerifying final schema...\n";
$verification = verify_final_schema();

echo "Schema Status: " . $verification['status'] . "\n";
echo "Tables Found: " . count($verification['tables_found']) . "\n";
echo "Tables Missing: " . count($verification['tables_missing']) . "\n";

if (!empty($verification['tables_missing'])) {
    echo "Missing Tables:\n";
    foreach ($verification['tables_missing'] as $table) {
        echo "  - $table\n";
    }
}

if ($verification['status'] === 'complete') {
    echo "\n🎉 Database schema synchronization COMPLETE!\n";
    exit(0);
} else {
    echo "\n⚠️ Database schema still incomplete\n";
    exit(1);
}