<?php
// includes/schema_version_manager.php - Advanced Schema Version Management
// Adds version tracking, rollback, and history logging

class SchemaVersionManager extends SchemaManager {
    private $version_table = 'schema_migrations';
    private $db; // Database connection
    
    public function __construct($mysqli, $admin_mode = false) {
        $this->db = $mysqli; // Store reference to mysqli
        parent::__construct($mysqli, $admin_mode);
        $this->ensureVersionTable();
    }
    
    /**
     * Create version tracking table
     */
    private function ensureVersionTable() {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->version_table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(50) NOT NULL UNIQUE,
            description TEXT NOT NULL,
            sql_commands TEXT NOT NULL,
            executed_by INT NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            rollback_sql TEXT NULL,
            status ENUM('applied','rolled_back','failed') DEFAULT 'applied',
            error_message TEXT NULL,
            INDEX idx_version (version),
            INDEX idx_status (status),
            INDEX idx_executed_at (executed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->db->query($sql);
    }
    
    /**
     * Get current schema version
     */
    public function getCurrentVersion() {
        $stmt = $this->db->prepare("
            SELECT version FROM {$this->version_table} 
            WHERE status = 'applied' 
            ORDER BY executed_at DESC LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row ? $row['version'] : '0.0.0';
    }
    
    /**
     * Get migration history
     */
    public function getMigrationHistory($limit = 50) {
        $stmt = $this->db->prepare("
            SELECT m.*, u.username as executed_by_name
            FROM {$this->version_table} m
            LEFT JOIN users u ON m.executed_by = u.id
            ORDER BY m.executed_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Execute versioned migration with full tracking
     */
    public function executeVersionedMigration($version, $description, $sql_commands, $rollback_sql = null) {
        $user_id = $_SESSION['user_id'] ?? 1;
        
        // Start transaction
        $this->db->begin_transaction();
        
        try {
            // Record migration attempt
            $stmt = $this->db->prepare("
                INSERT INTO {$this->version_table} 
                (version, description, sql_commands, rollback_sql, executed_by, status) 
                VALUES (?, ?, ?, ?, ?, 'applied')
            ");
            $stmt->bind_param('ssssi', $version, $description, $sql_commands, $rollback_sql, $user_id);
            $stmt->execute();
            
            // Execute SQL commands
            $sql_statements = explode(';', $sql_commands);
            foreach ($sql_statements as $sql) {
                $sql = trim($sql);
                if (!empty($sql)) {
                    if (!$this->db->query($sql)) {
                        throw new Exception("SQL Error: " . $this->db->error . " in statement: " . $sql);
                    }
                }
            }
            
            // Commit transaction
            $this->db->commit();
            return ['success' => true, 'message' => "Migration {$version} applied successfully"];
            
        } catch (Exception $e) {
            // Rollback transaction
            $this->db->rollback();
            
            // Update status to failed
            $stmt = $this->db->prepare("
                UPDATE {$this->version_table} 
                SET status = 'failed', error_message = ? 
                WHERE version = ? AND status = 'applied'
            ");
            $error_msg = $e->getMessage();
            $stmt->bind_param('ss', $error_msg, $version);
            $stmt->execute();
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Rollback to specific version
     */
    public function rollbackToVersion($target_version) {
        $user_id = $_SESSION['user_id'] ?? 1;
        
        // Get migration record
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->version_table} 
            WHERE version = ? AND status = 'applied'
        ");
        $stmt->bind_param('s', $target_version);
        $stmt->execute();
        $migration = $stmt->get_result()->fetch_assoc();
        
        if (!$migration || !$migration['rollback_sql']) {
            return ['success' => false, 'error' => 'No rollback SQL available for version ' . $target_version];
        }
        
        // Start transaction
        $this->db->begin_transaction();
        
        try {
            // Execute rollback SQL
            $rollback_statements = explode(';', $migration['rollback_sql']);
            foreach ($rollback_statements as $sql) {
                $sql = trim($sql);
                if (!empty($sql)) {
                    if (!$this->db->query($sql)) {
                        throw new Exception("Rollback SQL Error: " . $this->db->error);
                    }
                }
            }
            
            // Mark as rolled back
            $stmt = $this->db->prepare("
                UPDATE {$this->version_table} 
                SET status = 'rolled_back' 
                WHERE version = ? AND status = 'applied'
            ");
            $stmt->bind_param('s', $target_version);
            $stmt->execute();
            
            $this->db->commit();
            return ['success' => true, 'message' => "Successfully rolled back to version {$target_version}"];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => 'Rollback failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Execute standard fixes with version tracking
     */
    public function executeTrackedFixes() {
        // Define migrations with version numbers
        $migrations = [
            '1.0.0' => [
                'description' => 'Add missing trades table columns',
                'sql' => "
                    ALTER TABLE trades 
                    ADD COLUMN entry_price DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Entry price',
                    ADD COLUMN exit_price DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Exit price',
                    ADD COLUMN pl_percent DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Profit/Loss %',
                    ADD COLUMN outcome VARCHAR(50) DEFAULT 'OPEN' COMMENT 'Trade outcome',
                    ADD COLUMN entry_date DATE NULL COMMENT 'Entry date',
                    ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Created time';
                    
                    CREATE INDEX idx_trades_user_id ON trades(user_id);
                    CREATE INDEX idx_trades_outcome ON trades(outcome);
                ",
                'rollback' => "
                    ALTER TABLE trades 
                    DROP COLUMN entry_price,
                    DROP COLUMN exit_price,
                    DROP COLUMN pl_percent,
                    DROP COLUMN outcome,
                    DROP COLUMN entry_date,
                    DROP COLUMN created_at;
                    
                    DROP INDEX idx_trades_user_id ON trades;
                    DROP INDEX idx_trades_outcome ON trades;
                "
            ],
            '1.1.0' => [
                'description' => 'Add users table columns',
                'sql' => "
                    ALTER TABLE users 
                    ADD COLUMN trading_capital DECIMAL(12,2) DEFAULT 100000.00 COMMENT 'Trading capital',
                    ADD COLUMN status ENUM('pending','active','approved','suspended') DEFAULT 'pending' COMMENT 'Account status',
                    ADD COLUMN email_verified TINYINT(1) DEFAULT 0 COMMENT 'Email verified',
                    ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Created time',
                    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated time';
                ",
                'rollback' => "
                    ALTER TABLE users 
                    DROP COLUMN trading_capital,
                    DROP COLUMN status,
                    DROP COLUMN email_verified,
                    DROP COLUMN created_at,
                    DROP COLUMN updated_at;
                "
            ],
            '1.2.0' => [
                'description' => 'Create deploy_notes table',
                'sql' => "CREATE TABLE IF NOT EXISTS deploy_notes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    env ENUM('local','staging','prod') NOT NULL DEFAULT 'prod',
                    title VARCHAR(255) NOT NULL,
                    body TEXT NULL,
                    note_type ENUM('feature','hotfix','migration','maintenance') DEFAULT 'feature',
                    impact ENUM('low','medium','high','critical') DEFAULT 'low',
                    status ENUM('planned','in_progress','deployed','rolled_back') DEFAULT 'planned',
                    sql_up MEDIUMTEXT NULL,
                    sql_down MEDIUMTEXT NULL,
                    files_json JSON NULL,
                    links_json JSON NULL,
                    tags VARCHAR(255) NULL,
                    created_by INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    deployed_at DATETIME NULL,
                    INDEX idx_env (env),
                    INDEX idx_status (status),
                    INDEX idx_type (note_type),
                    INDEX idx_created_by (created_by),
                    INDEX idx_created_at (created_at),
                    INDEX idx_deployed_at (deployed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                'rollback' => "DROP TABLE IF EXISTS deploy_notes;"
            ]
        ];
        
        $current_version = $this->getCurrentVersion();
        $results = [];
        
        foreach ($migrations as $version => $migration) {
            // Skip if version already applied
            if (version_compare($version, $current_version, '<=')) {
                continue;
            }
            
            $result = $this->executeVersionedMigration(
                $version,
                $migration['description'],
                $migration['sql'],
                $migration['rollback'] ?? null
            );
            
            $results[] = array_merge($result, ['version' => $version]);
            
            if (!$result['success']) {
                break; // Stop on first failure
            }
        }
        
        return $results;
    }
    
    /**
     * Get available rollback versions
     */
    public function getRollbackOptions() {
        $stmt = $this->db->prepare("
            SELECT version, description, executed_at 
            FROM {$this->version_table} 
            WHERE status = 'applied' AND rollback_sql IS NOT NULL
            ORDER BY executed_at DESC
        ");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}