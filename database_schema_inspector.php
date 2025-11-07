<?php
/**
 * Database Schema Inspector for P3-frontend-delta Migration
 * Analyzes current schema vs OpenAPI contracts and frontend requirements
 */

// Load database configuration
require_once __DIR__ . '/config.php';

function inspect_database_schema() {
    global $mysqli;
    
    $schema_info = [
        'database_name' => $mysqli->query("SELECT DATABASE() as db")->fetch_assoc()['db'],
        'tables' => []
    ];
    
    // Get all tables (exclude views to avoid the audit_summary error)
    $tables_result = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $tables = [];
    while ($row = $tables_result->fetch_array()) {
        $tables[] = $row[0];
    }
    
    // Inspect each table
    foreach ($tables as $table) {
        $table_info = [
            'table_name' => $table,
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => []
        ];
        
        // Get columns
        $columns_result = $mysqli->query("SHOW COLUMNS FROM `$table`");
        while ($col = $columns_result->fetch_assoc()) {
            $table_info['columns'][] = [
                'field' => $col['Field'],
                'type' => $col['Type'],
                'null' => $col['Null'],
                'key' => $col['Key'],
                'default' => $col['Default'],
                'extra' => $col['Extra']
            ];
        }
        
        // Get indexes
        $indexes_result = $mysqli->query("SHOW INDEXES FROM `$table`");
        while ($idx = $indexes_result->fetch_assoc()) {
            $table_info['indexes'][] = [
                'key_name' => $idx['Key_name'],
                'column_name' => $idx['Column_name'],
                'non_unique' => $idx['Non_unique'],
                'seq_in_index' => $idx['Seq_in_index']
            ];
        }
        
        $schema_info['tables'][$table] = $table_info;
    }
    
    return $schema_info;
}

function get_expected_schema_from_contracts() {
    // Based on OpenAPI documentation and unified schema expectations
    return [
        'users' => [
            'required_columns' => [
                'id', 'email', 'name', 'status', 'role', 'email_verified',
                'trading_capital', 'funds_available', 'created_at', 'updated_at'
            ],
            'optional_columns' => [
                'display_name', 'bio', 'location', 'timezone', 'preferences'
            ],
            'indexes_needed' => ['idx_email', 'idx_status', 'idx_role']
        ],
        'trades' => [
            'required_columns' => [
                'id', 'user_id', 'symbol', 'side', 'quantity', 'price', 
                'opened_at', 'created_at', 'updated_at'
            ],
            'frontend_columns' => [
                'position_percent', 'entry_price', 'stop_loss', 'target_price', 
                'pnl', 'allocation_amount', 'outcome', 'analysis_link', 'notes'
            ],
            'indexes_needed' => [
                'idx_user_symbol_date', 'idx_user_status', 'idx_symbol_date', 'idx_deleted'
            ]
        ],
        'mtm_enrollments' => [
            'required_columns' => [
                'id', 'trader_id', 'model_id', 'tier', 'status', 
                'started_at', 'created_at', 'updated_at'
            ],
            'indexes_needed' => ['uq_trader_model', 'idx_trader_status', 'idx_model_status']
        ],
        'mtm_models' => [
            'required_columns' => [
                'id', 'code', 'name', 'tiering', 'is_active', 
                'created_at', 'updated_at'
            ],
            'indexes_needed' => ['idx_code', 'idx_active']
        ],
        'audit_events' => [
            'required_columns' => [
                'id', 'actor_id', 'action', 'entity', 'entity_id', 
                'summary', 'ip_address', 'created_at'
            ],
            'indexes_needed' => [
                'idx_actor_id', 'idx_action', 'idx_entity', 'idx_created_at'
            ]
        ]
    ];
}

function compute_schema_delta($current_schema, $expected_schema) {
    $delta = [
        'missing_tables' => [],
        'missing_columns' => [],
        'missing_indexes' => [],
        'inconsistencies' => []
    ];
    
    // Check for missing tables
    foreach ($expected_schema as $table_name => $table_requirements) {
        if (!isset($current_schema['tables'][$table_name])) {
            $delta['missing_tables'][] = $table_name;
            continue;
        }
        
        $current_table = $current_schema['tables'][$table_name];
        $current_columns = array_column($current_table['columns'], 'field');
        $current_indexes = array_column($current_table['indexes'], 'key_name');
        
        // Check for missing required columns
        foreach ($table_requirements['required_columns'] as $required_col) {
            if (!in_array($required_col, $current_columns)) {
                $delta['missing_columns'][] = [
                    'table' => $table_name,
                    'column' => $required_col,
                    'type' => 'required'
                ];
            }
        }
        
        // Check for missing optional columns that are needed for frontend
        if (isset($table_requirements['frontend_columns'])) {
            foreach ($table_requirements['frontend_columns'] as $frontend_col) {
                if (!in_array($frontend_col, $current_columns)) {
                    $delta['missing_columns'][] = [
                        'table' => $table_name,
                        'column' => $frontend_col,
                        'type' => 'frontend_required'
                    ];
                }
            }
        }
        
        // Check for missing indexes
        if (isset($table_requirements['indexes_needed'])) {
            foreach ($table_requirements['indexes_needed'] as $index_name) {
                if (!in_array($index_name, $current_indexes)) {
                    $delta['missing_indexes'][] = [
                        'table' => $table_name,
                        'index' => $index_name
                    ];
                }
            }
        }
        
        // Check for user_id vs trader_id inconsistencies in trades table
        if ($table_name === 'trades') {
            $has_user_id = in_array('user_id', $current_columns);
            $has_trader_id = in_array('trader_id', $current_columns);
            
            if ($has_user_id && $has_trader_id) {
                $delta['inconsistencies'][] = [
                    'table' => 'trades',
                    'issue' => 'both_user_id_and_trader_id_exist',
                    'description' => 'Table has both user_id and trader_id columns - need to standardize'
                ];
            } elseif (!$has_user_id && !$has_trader_id) {
                $delta['inconsistencies'][] = [
                    'table' => 'trades',
                    'issue' => 'missing_user_reference',
                    'description' => 'Table missing user reference column (user_id or trader_id)'
                ];
            }
        }
    }
    
    return $delta;
}

function generate_migration_sql($delta) {
    $sql = "-- Schema Delta Migration for P3-frontend-delta\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Purpose: Add missing columns/indexes for frontend API integration\n\n";
    
    // Add missing columns
    foreach ($delta['missing_columns'] as $missing) {
        $table = $missing['table'];
        $column = $missing['column'];
        $type = $missing['type'];
        
        // Determine column type based on name and context
        $column_def = null;
        switch ($column) {
            case 'position_percent':
                $column_def = "DECIMAL(5,2) NULL COMMENT 'Position percentage allocation'";
                break;
            case 'entry_price':
                $column_def = "DECIMAL(16,4) NULL COMMENT 'Entry price per share'";
                break;
            case 'stop_loss':
                $column_def = "DECIMAL(16,4) NULL COMMENT 'Stop loss price'";
                break;
            case 'target_price':
                $column_def = "DECIMAL(16,4) NULL COMMENT 'Target price'";
                break;
            case 'pnl':
                $column_def = "DECIMAL(16,2) NULL COMMENT 'Profit and loss'";
                break;
            case 'allocation_amount':
                $column_def = "DECIMAL(16,2) NULL COMMENT 'Allocated amount for trade'";
                break;
            case 'outcome':
                $column_def = "ENUM('WIN', 'LOSS', 'OPEN', 'PENDING') NULL COMMENT 'Trade outcome'";
                break;
            case 'analysis_link':
                $column_def = "VARCHAR(500) NULL COMMENT 'Link to analysis document'";
                break;
            case 'notes':
                $column_def = "TEXT NULL COMMENT 'Trade notes and analysis'";
                break;
            case 'display_name':
                $column_def = "VARCHAR(100) NULL COMMENT 'User display name'";
                break;
            case 'bio':
                $column_def = "TEXT NULL COMMENT 'User bio'";
                break;
            case 'location':
                $column_def = "VARCHAR(100) NULL COMMENT 'User location'";
                break;
            case 'timezone':
                $column_def = "VARCHAR(50) NULL COMMENT 'User timezone'";
                break;
            case 'preferences':
                $column_def = "JSON NULL COMMENT 'User preferences as JSON'";
                break;
            case 'enrollment_id':
                $column_def = "BIGINT UNSIGNED NULL COMMENT 'Link to MTM enrollment'";
                break;
        }
        
        if ($column_def) {
            $sql .= "-- Add missing column: {$table}.{$column} ({$type})\n";
            $sql .= "ALTER TABLE `{$table}` ADD COLUMN {$column} {$column_def};\n\n";
        }
    }
    
    // Add missing indexes
    foreach ($delta['missing_indexes'] as $missing) {
        $table = $missing['table'];
        $index = $missing['index'];
        
        $index_def = null;
        switch ($index) {
            case 'idx_user_symbol_date':
                $index_def = "ADD INDEX idx_user_symbol_date (user_id, symbol, opened_at)";
                break;
            case 'idx_user_status':
                $index_def = "ADD INDEX idx_user_status (user_id, outcome)";
                break;
            case 'idx_symbol_date':
                $index_def = "ADD INDEX idx_symbol_date (symbol, opened_at)";
                break;
            case 'idx_deleted':
                $index_def = "ADD INDEX idx_deleted (deleted_at)";
                break;
            case 'uq_trader_model':
                $index_def = "ADD CONSTRAINT uq_trader_model UNIQUE (trader_id, model_id)";
                break;
            case 'idx_trader_status':
                $index_def = "ADD INDEX idx_trader_status (trader_id, status)";
                break;
            case 'idx_model_status':
                $index_def = "ADD INDEX idx_model_status (model_id, status)";
                break;
            case 'idx_code':
                $index_def = "ADD INDEX idx_code (code)";
                break;
            case 'idx_active':
                $index_def = "ADD INDEX idx_active (is_active)";
                break;
        }
        
        if ($index_def) {
            $sql .= "-- Add missing index: {$table}.{$index}\n";
            $sql .= "ALTER TABLE `{$table}` {$index_def};\n\n";
        }
    }
    
    // Handle inconsistencies
    foreach ($delta['inconsistencies'] as $inconsistency) {
        if ($inconsistency['issue'] === 'both_user_id_and_trader_id_exist') {
            $sql .= "-- Fix inconsistency: standardize on user_id in trades table\n";
            $sql .= "-- Note: Code should be updated to use user_id consistently\n";
            $sql .= "-- No schema change needed - just code alignment\n\n";
        }
    }
    
    return $sql;
}

// Main execution
try {
    echo "=== Database Schema Inspection Started ===\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Inspect current schema
    echo "1. Inspecting current database schema...\n";
    $current_schema = inspect_database_schema();
    echo "   Found " . count($current_schema['tables']) . " tables\n\n";
    
    // Get expected schema
    echo "2. Loading expected schema from contracts...\n";
    $expected_schema = get_expected_schema_from_contracts();
    echo "   Expected " . count($expected_schema) . " core tables\n\n";
    
    // Compute delta
    echo "3. Computing schema delta...\n";
    $delta = compute_schema_delta($current_schema, $expected_schema);
    
    echo "   Missing tables: " . count($delta['missing_tables']) . "\n";
    echo "   Missing columns: " . count($delta['missing_columns']) . "\n";
    echo "   Missing indexes: " . count($delta['missing_indexes']) . "\n";
    echo "   Inconsistencies: " . count($delta['inconsistencies']) . "\n\n";
    
    // Save results
    file_put_contents('current_schema.json', json_encode($current_schema, JSON_PRETTY_PRINT));
    file_put_contents('schema_delta.json', json_encode($delta, JSON_PRETTY_PRINT));
    
    // Generate migration SQL
    echo "4. Generating migration SQL...\n";
    $migration_sql = generate_migration_sql($delta);
    file_put_contents('schema_delta_migration.sql', $migration_sql);
    
    echo "5. Analysis complete!\n";
    echo "   Files generated:\n";
    echo "   - current_schema.json (current state)\n";
    echo "   - schema_delta.json (delta analysis)\n";
    echo "   - schema_delta_migration.sql (migration script)\n\n";
    
    // Show summary
    if (!empty($delta['missing_columns'])) {
        echo "MISSING COLUMNS NEEDED FOR FRONTEND:\n";
        foreach ($delta['missing_columns'] as $missing) {
            echo "   - {$missing['table']}.{$missing['column']} ({$missing['type']})\n";
        }
        echo "\n";
    }
    
    if (!empty($delta['inconsistencies'])) {
        echo "INCONSISTENCIES TO RESOLVE:\n";
        foreach ($delta['inconsistencies'] as $inconsistency) {
            echo "   - {$inconsistency['table']}: {$inconsistency['description']}\n";
        }
        echo "\n";
    }
    
    echo "=== Inspection Complete ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>