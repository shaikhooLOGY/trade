<?php
/**
 * maintenance/verify_schema_master.php
 * 
 * Schema Master Verification Script
 * 
 * Purpose: Verify database schema matches the canonical master schema
 * Usage: php maintenance/verify_schema_master.php
 * Exit Code: 0 = perfect match, non-zero = schema differences found
 * 
 * This script connects via existing includes/bootstrap.php and verifies
 * presence + types of all columns, indexes, FKs, printing machine-readable
 * JSON diff to stdout.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Try to load unified bootstrap first, fallback to direct database connection
$bootstrapLoaded = false;
$db = null;

try {
    if (file_exists(__DIR__ . '/../core/bootstrap.php')) {
        require_once __DIR__ . '/../core/bootstrap.php';
        $bootstrapLoaded = true;
        $db = $GLOBALS['mysqli'] ?? null;
    }
} catch (Exception $e) {
    // Bootstrap failed, will fallback to direct connection
}

if (!$bootstrapLoaded || !$db instanceof mysqli) {
    // Fallback: direct database connection using environment variables
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: '';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_name = getenv('DB_NAME') ?: '';
    
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        fwrite(STDERR, "ERROR: Database connection failed: " . $db->connect_error . "\n");
        exit(1);
    }
}

// Expected schema definition
$expectedSchema = [
    'users' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'name' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'email' => ['type' => 'varchar', 'null' => 'NO', 'key' => 'UNI'],
            'password_hash' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'role' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'status' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'email_verified' => ['type' => 'tinyint', 'null' => 'NO', 'key' => ''],
            'trading_capital' => ['type' => 'decimal', 'null' => 'NO', 'key' => ''],
            'funds_available' => ['type' => 'decimal', 'null' => 'NO', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_users_email', 'idx_users_role', 'idx_users_status', 'idx_users_created_at']
    ],
    'trades' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'user_id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'MUL'],
            'symbol' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'side' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'quantity' => ['type' => 'decimal', 'null' => 'NO', 'key' => ''],
            'price' => ['type' => 'decimal', 'null' => 'NO', 'key' => ''],
            'entry_price' => ['type' => 'decimal', 'null' => 'YES', 'key' => ''],
            'stop_loss' => ['type' => 'decimal', 'null' => 'YES', 'key' => ''],
            'target_price' => ['type' => 'decimal', 'null' => 'YES', 'key' => ''],
            'position_percent' => ['type' => 'decimal', 'null' => 'YES', 'key' => ''],
            'allocation_amount' => ['type' => 'decimal', 'null' => 'YES', 'key' => ''],
            'pnl' => ['type' => 'decimal', 'null' => 'YES', 'key' => ''],
            'outcome' => ['type' => 'enum', 'null' => 'YES', 'key' => ''],
            'opened_at' => ['type' => 'datetime', 'null' => 'NO', 'key' => ''],
            'closed_at' => ['type' => 'datetime', 'null' => 'YES', 'key' => ''],
            'notes' => ['type' => 'text', 'null' => 'YES', 'key' => ''],
            'analysis_link' => ['type' => 'varchar', 'null' => 'YES', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_trades_user_opened_at', 'idx_trades_user_symbol_opened_at', 'idx_trades_advanced', 'idx_trades_symbol']
    ],
    'mtm_models' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'name' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'tier' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'status' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_mtm_models_tier', 'idx_mtm_models_status']
    ],
    'mtm_tasks' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'model_id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'MUL'],
            'level' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'name' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'rules_json' => ['type' => 'json', 'null' => 'YES', 'key' => ''],
            'sort_order' => ['type' => 'int', 'null' => 'NO', 'key' => ''],
            'status' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_mtm_tasks_model_level', 'idx_mtm_tasks_model_status']
    ],
    'mtm_enrollments' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'user_id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'MUL'],
            'model_id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'MUL'],
            'status' => ['type' => 'enum', 'null' => 'NO', 'key' => ''],
            'started_at' => ['type' => 'datetime', 'null' => 'NO', 'key' => ''],
            'completed_at' => ['type' => 'datetime', 'null' => 'YES', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['uq_mtm_enrollments_user_model', 'idx_mtm_enrollments_user_status', 'idx_mtm_enrollments_model_status']
    ],
    'audit_logs' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'event_time' => ['type' => 'datetime', 'null' => 'NO', 'key' => ''],
            'user_id' => ['type' => 'bigint', 'null' => 'YES', 'key' => 'MUL'],
            'admin_id' => ['type' => 'bigint', 'null' => 'YES', 'key' => 'MUL'],
            'event_type' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'category' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'entity' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'entity_id' => ['type' => 'bigint', 'null' => 'YES', 'key' => ''],
            'details' => ['type' => 'json', 'null' => 'YES', 'key' => ''],
            'ip' => ['type' => 'varchar', 'null' => 'YES', 'key' => ''],
            'ua' => ['type' => 'varchar', 'null' => 'YES', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_audit_logs_time', 'idx_audit_logs_entity', 'idx_audit_logs_user_time', 'idx_audit_logs_admin_time', 'idx_audit_logs_category']
    ],
    'audit_event_types' => [
        'columns' => [
            'code' => ['type' => 'varchar', 'null' => 'NO', 'key' => 'PRI'],
            'description' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'retention_days' => ['type' => 'int', 'null' => 'NO', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_audit_event_types_retention']
    ],
    'audit_retention_policies' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'category' => ['type' => 'varchar', 'null' => 'NO', 'key' => 'UNI'],
            'retention_days' => ['type' => 'int', 'null' => 'NO', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['uq_audit_retention_category']
    ],
    'rate_limits' => [
        'columns' => [
            'key_hash' => ['type' => 'varbinary', 'null' => 'NO', 'key' => 'PRI'],
            'window_start' => ['type' => 'int', 'null' => 'NO', 'key' => 'PRI'],
            'count' => ['type' => 'int', 'null' => 'NO', 'key' => ''],
            'limit' => ['type' => 'int', 'null' => 'NO', 'key' => ''],
            'actor' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'scope' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_rate_limits_scope_actor', 'idx_rate_limits_window']
    ],
    'idempotency_keys' => [
        'columns' => [
            'key_hash' => ['type' => 'varbinary', 'null' => 'NO', 'key' => 'PRI'],
            'created_at' => ['type' => 'int', 'null' => 'NO', 'key' => ''],
            'status' => ['type' => 'smallint', 'null' => 'NO', 'key' => ''],
            'last_response_hash' => ['type' => 'varbinary', 'null' => 'YES', 'key' => ''],
            'created_at_ts' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_idempotency_created_at', 'idx_idempotency_status']
    ],
    'agent_logs' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'created_at' => ['type' => 'datetime', 'null' => 'NO', 'key' => ''],
            'user_id' => ['type' => 'bigint', 'null' => 'YES', 'key' => 'MUL'],
            'agent' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'action' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'payload' => ['type' => 'json', 'null' => 'YES', 'key' => ''],
            'meta' => ['type' => 'json', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_agent_logs_user_time', 'idx_agent_logs_agent', 'idx_agent_logs_action', 'idx_agent_logs_time']
    ],
    'leagues' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'name' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'season' => ['type' => 'varchar', 'null' => 'YES', 'key' => ''],
            'status' => ['type' => 'varchar', 'null' => 'NO', 'key' => ''],
            'created_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['idx_leagues_season', 'idx_leagues_status']
    ],
    'guard_trade_limits' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'user_id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'UNI'],
            'daily_count' => ['type' => 'int', 'null' => 'NO', 'key' => ''],
            'daily_notional' => ['type' => 'decimal', 'null' => 'NO', 'key' => ''],
            'updated_at' => ['type' => 'timestamp', 'null' => 'YES', 'key' => '']
        ],
        'indexes' => ['uq_guard_trade_limits_user', 'idx_guard_trade_limits_updated']
    ],
    'schema_migrations' => [
        'columns' => [
            'id' => ['type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
            'filename' => ['type' => 'varchar', 'null' => 'NO', 'key' => 'UNI'],
            'checksum' => ['type' => 'varbinary', 'null' => 'NO', 'key' => ''],
            'applied_at' => ['type' => 'datetime', 'null' => 'NO', 'key' => '']
        ],
        'indexes' => ['uq_schema_migrations_filename']
    ]
];

/**
 * Get current database schema information
 */
function getCurrentSchema(mysqli $db): array {
    $schema = [];
    
    // Get all tables
    $tablesResult = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    while ($row = $tablesResult->fetch_assoc()) {
        $tableName = $row['TABLE_NAME'];
        $schema[$tableName] = ['columns' => [], 'indexes' => []];
        
        // Get columns
        $columnsResult = $db->query("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA 
                                   FROM information_schema.COLUMNS 
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableName'
                                   ORDER BY ORDINAL_POSITION");
        while ($col = $columnsResult->fetch_assoc()) {
            $schema[$tableName]['columns'][$col['COLUMN_NAME']] = [
                'type' => $col['DATA_TYPE'],
                'null' => $col['IS_NULLABLE'],
                'key' => $col['COLUMN_KEY'],
                'extra' => $col['EXTRA']
            ];
        }
        
        // Get indexes
        $indexesResult = $db->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS 
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableName' AND INDEX_NAME != 'PRIMARY'");
        while ($idx = $indexesResult->fetch_assoc()) {
            $schema[$tableName]['indexes'][] = $idx['INDEX_NAME'];
        }
    }
    
    return $schema;
}

/**
 * Compare schema and generate diff
 */
function compareSchema(array $expected, array $actual): array {
    $diff = [
        'missing_tables' => [],
        'extra_tables' => [],
        'table_diffs' => [],
        'missing_indexes' => [],
        'extra_indexes' => [],
        'column_diffs' => []
    ];
    
    // Check for missing/extra tables
    $expectedTables = array_keys($expected);
    $actualTables = array_keys($actual);
    
    $diff['missing_tables'] = array_diff($expectedTables, $actualTables);
    $diff['extra_tables'] = array_diff($actualTables, $expectedTables);
    
    // Compare table structures
    foreach ($expectedTables as $table) {
        if (!isset($actual[$table])) continue;
        
        $tableDiff = [
            'missing_columns' => [],
            'extra_columns' => [],
            'column_type_diffs' => []
        ];
        
        $expectedCols = $expected[$table]['columns'];
        $actualCols = $actual[$table]['columns'];
        
        // Check columns
        $expectedColNames = array_keys($expectedCols);
        $actualColNames = array_keys($actualCols);
        
        $tableDiff['missing_columns'] = array_diff($expectedColNames, $actualColNames);
        $tableDiff['extra_columns'] = array_diff($actualColNames, $expectedColNames);
        
        // Check column types
        foreach ($expectedColNames as $colName) {
            if (isset($actualCols[$colName])) {
                $expectedCol = $expectedCols[$colName];
                $actualCol = $actualCols[$colName];
                
                // Compare key properties (type, nullability, key type)
                $expectedKey = $expectedCol['type'] . '_' . $expectedCol['null'] . '_' . $expectedCol['key'];
                $actualKey = $actualCol['type'] . '_' . $actualCol['null'] . '_' . $actualCol['key'];
                
                if ($expectedKey !== $actualKey) {
                    $tableDiff['column_type_diffs'][$colName] = [
                        'expected' => $expectedCol,
                        'actual' => $actualCol
                    ];
                }
            }
        }
        
        if (!empty($tableDiff['missing_columns']) || !empty($tableDiff['extra_columns']) || !empty($tableDiff['column_type_diffs'])) {
            $diff['table_diffs'][$table] = $tableDiff;
        }
        
        // Check indexes
        $expectedIndexes = $expected[$table]['indexes'] ?? [];
        $actualIndexes = $actual[$table]['indexes'] ?? [];
        
        $missingIndexes = array_diff($expectedIndexes, $actualIndexes);
        $extraIndexes = array_diff($actualIndexes, $expectedIndexes);
        
        if (!empty($missingIndexes) || !empty($extraIndexes)) {
            $diff['missing_indexes'][$table] = $missingIndexes;
            $diff['extra_indexes'][$table] = $extraIndexes;
        }
    }
    
    return $diff;
}

// Get current schema and compare
$currentSchema = getCurrentSchema($db);
$schemaDiff = compareSchema($expectedSchema, $currentSchema);

// Build response
$response = [
    'timestamp' => gmdate('c'),
    'database' => $db->server_info,
    'schema_version' => 'master_schema_2025',
    'expected_tables' => count($expectedSchema),
    'actual_tables' => count($currentSchema),
    'perfect_match' => empty(array_filter($schemaDiff, function($v) { return !empty($v); })),
    'diff' => $schemaDiff,
    'summary' => [
        'missing_tables' => count($schemaDiff['missing_tables']),
        'extra_tables' => count($schemaDiff['extra_tables']),
        'tables_with_diffs' => count($schemaDiff['table_diffs']),
        'missing_indexes' => array_sum(array_map('count', $schemaDiff['missing_indexes'])),
        'extra_indexes' => array_sum(array_map('count', $schemaDiff['extra_indexes']))
    ]
];

// Output JSON result
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// Exit with appropriate code
$hasDifferences = !$response['perfect_match'];
exit($hasDifferences ? 1 : 0);