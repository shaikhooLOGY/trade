<?php
// includes/schema_manager.php - Complete Website-wide Schema Management System
// Hybrid Detection + Manual Trigger approach

class SchemaManager {
    private $mysqli;
    private $admin_mode;
    
    public function __construct($mysqli, $admin_mode = false) {
        $this->mysqli = $mysqli;
        $this->admin_mode = $admin_mode;
    }
    
    /**
     * Main detection method - call this from any page
     */
    public function detectIssues($show_admin_panel = false) {
        $issues = [];
        
        // Check trades table
        $trades_issues = $this->checkTradesTable();
        if (!empty($trades_issues)) {
            $issues['trades'] = $trades_issues;
        }
        
        // Check users table  
        $users_issues = $this->checkUsersTable();
        if (!empty($users_issues)) {
            $issues['users'] = $users_issues;
        }
        
        // Check other critical tables
        $other_issues = $this->checkOtherTables();
        if (!empty($other_issues)) {
            $issues['other'] = $other_issues;
        }
        
        // Show admin panel if requested and issues exist
        if ($show_admin_panel && !empty($issues)) {
            $this->showAdminPanel($issues);
        }
        
        return $issues;
    }
    
    /**
     * Check trades table for missing columns
     */
    private function checkTradesTable() {
        $issues = [];
        $required_columns = [
            'entry_price' => 'DECIMAL(10,2) DEFAULT 0.00 COMMENT "Entry price of the trade"',
            'exit_price' => 'DECIMAL(10,2) DEFAULT 0.00 COMMENT "Exit price of the trade"',
            'pl_percent' => 'DECIMAL(5,2) DEFAULT 0.00 COMMENT "Profit/Loss percentage"',
            'outcome' => 'VARCHAR(50) DEFAULT "OPEN" COMMENT "Trade outcome (OPEN/CLOSED/WIN/LOSS)"',
            'entry_date' => 'DATE NULL COMMENT "Date when trade was entered"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Record creation time"'
        ];
        
        foreach ($required_columns as $col => $definition) {
            if (!$this->columnExists('trades', $col)) {
                $issues[] = [
                    'type' => 'missing_column',
                    'table' => 'trades',
                    'column' => $col,
                    'definition' => $definition,
                    'sql' => "ALTER TABLE trades ADD COLUMN {$col} {$definition};"
                ];
            }
        }
        
        // Check for required indexes
        if (!$this->indexExists('trades', 'idx_user_id')) {
            $issues[] = [
                'type' => 'missing_index',
                'table' => 'trades', 
                'index' => 'idx_user_id',
                'sql' => "CREATE INDEX idx_user_id ON trades(user_id);"
            ];
        }
        
        return $issues;
    }
    
    /**
     * Check users table for missing columns
     */
    private function checkUsersTable() {
        $issues = [];
        $required_columns = [
            'trading_capital' => 'DECIMAL(12,2) DEFAULT 100000.00 COMMENT "Available trading capital"',
            'status' => 'ENUM("pending","active","approved","suspended") DEFAULT "pending" COMMENT "Account status"',
            'email_verified' => 'TINYINT(1) DEFAULT 0 COMMENT "Whether email is verified"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Account creation time"',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT "Last update time"'
        ];
        
        foreach ($required_columns as $col => $definition) {
            if (!$this->columnExists('users', $col)) {
                $issues[] = [
                    'type' => 'missing_column',
                    'table' => 'users',
                    'column' => $col,
                    'definition' => $definition,
                    'sql' => "ALTER TABLE users ADD COLUMN {$col} {$definition};"
                ];
            }
        }
        
        return $issues;
    }
    
    /**
     * Check other critical tables
     */
    private function checkOtherTables() {
        $issues = [];
        
        // Check if deploy_notes table exists and has right structure
        if (!$this->tableExists('deploy_notes')) {
            $issues[] = [
                'type' => 'missing_table',
                'table' => 'deploy_notes',
                'sql' => $this->getDeployNotesTableSQL()
            ];
        }
        
        return $issues;
    }
    
    /**
     * Generate SQL for deploy_notes table
     */
    private function getDeployNotesTableSQL() {
        return "CREATE TABLE IF NOT EXISTS deploy_notes (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    }
    
    /**
     * Execute fixes - this modifies your phpMyAdmin database directly
     */
    public function executeFixes($issues) {
        $results = [];
        
        foreach ($issues as $table => $table_issues) {
            foreach ($table_issues as $issue) {
                $result = [
                    'issue' => $issue,
                    'success' => false,
                    'error' => null
                ];
                
                try {
                    if ($this->mysqli->query($issue['sql'])) {
                        $result['success'] = true;
                    } else {
                        $result['error'] = $this->mysqli->error;
                    }
                } catch (Exception $e) {
                    $result['error'] = $e->getMessage();
                }
                
                $results[] = $result;
            }
        }
        
        return $results;
    }
    
    /**
     * Check if column exists
     */
    private function columnExists($table, $column) {
        try {
            $stmt = $this->mysqli->prepare("
                SELECT COUNT(*) as cnt FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            return ($row['cnt'] ?? 0) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if index exists
     */
    private function indexExists($table, $index) {
        try {
            $stmt = $this->mysqli->prepare("
                SELECT COUNT(*) as cnt FROM information_schema.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
            ");
            $stmt->bind_param('ss', $table, $index);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            return ($row['cnt'] ?? 0) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if table exists
     */
    private function tableExists($table) {
        try {
            $stmt = $this->mysqli->prepare("
                SELECT COUNT(*) as cnt FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ");
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            return ($row['cnt'] ?? 0) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Show admin panel with issues and fix options
     */
    private function showAdminPanel($issues) {
        echo '<div style="background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white; padding: 20px; margin: 20px 0; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">';
        echo '<h3 style="margin: 0 0 15px 0; display: flex; align-items: center; gap: 10px;">';
        echo '<span style="font-size: 24px;">🔧</span> Database Schema Health Check';
        echo '</h3>';
        
        echo '<p style="margin: 0 0 15px 0; opacity: 0.9;">The following database issues were detected. Click "Fix All Issues" to automatically update your phpMyAdmin database.</p>';
        
        $total_issues = 0;
        foreach ($issues as $table => $table_issues) {
            $total_issues += count($table_issues);
        }
        
        echo '<div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin: 15px 0;">';
        echo '<strong>Summary:</strong> ' . $total_issues . ' issue(s) found across ' . count($issues) . ' table(s)';
        echo '</div>';
        
        // Show detailed issues
        foreach ($issues as $table => $table_issues) {
            echo '<div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin: 10px 0;">';
            echo '<h4 style="margin: 0 0 10px 0; color: #fff;">📋 ' . ucfirst($table) . ' Table Issues</h4>';
            
            foreach ($table_issues as $issue) {
                $icon = $issue['type'] === 'missing_column' ? '📝' : ($issue['type'] === 'missing_table' ? '📦' : '🔍');
                echo '<div style="background: rgba(255,255,255,0.05); padding: 8px; border-radius: 5px; margin: 5px 0; font-family: monospace; font-size: 12px;">';
                echo $icon . ' ' . htmlspecialchars($issue['sql']);
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '<div style="text-align: center; margin-top: 20px;">';
        echo '<button onclick="fixSchemaIssues()" style="background: #4CAF50; color: white; border: none; padding: 12px 24px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; margin-right: 10px;">✅ Fix All Issues</button>';
        echo '<button onclick="copySQLCommands()" style="background: #2196F3; color: white; border: none; padding: 12px 24px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer;">📋 Copy SQL</button>';
        echo '</div>';
        
        echo '</div>';
        
        // Add JavaScript for fix functionality
        echo '<script>
        function fixSchemaIssues() {
            if (confirm("This will execute SQL commands on your phpMyAdmin database. Continue?")) {
                fetch(window.location.href, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: "schema_fix=1&confirm=1"
                })
                .then(response => response.text())
                .then(data => {
                    alert("Schema fixes applied! Page will refresh.");
                    location.reload();
                })
                .catch(error => {
                    alert("Error applying fixes: " + error);
                });
            }
        }
        
        function copySQLCommands() {
            let sqlCommands = "";
            ' . $this->generateSQLCommands($issues) . '
            navigator.clipboard.writeText(sqlCommands).then(() => {
                alert("SQL commands copied to clipboard!");
            });
        }
        </script>';
    }
    
    /**
     * Generate SQL commands for JavaScript
     */
    private function generateSQLCommands($issues) {
        $sql = "sqlCommands = `\\n";
        foreach ($issues as $table => $table_issues) {
            foreach ($table_issues as $issue) {
                $sql .= $issue['sql'] . ";\\n";
            }
        }
        $sql .= "`;\\n";
        return $sql;
    }
}

/**
 * Helper function to include in any page
 */
function checkSchemaHealth($admin_mode = false) {
    global $mysqli;
    if (!$mysqli) return;
    
    $schema = new SchemaManager($mysqli, $admin_mode);
    
    // Handle fix requests
    if (isset($_POST['schema_fix']) && $_POST['schema_fix'] == '1') {
        $issues = $schema->detectIssues(false);
        $results = $schema->executeFixes($issues);
        
        // Return results as JSON for AJAX
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
    
    // Show issues (only for admin or if explicitly requested)
    $show_panel = $admin_mode || (isset($_GET['schema_check']) && $_GET['schema_check'] == '1');
    $schema->detectIssues($show_panel);
}