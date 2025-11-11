<?php
// includes/schema_manager.php - Complete Website-wide Schema Management System
// Dynamic configuration-based schema validation

class SchemaManager {
    private $mysqli;
    private $admin_mode;
    private $schema_config;
    
    public function __construct($mysqli, $admin_mode = false) {
        $this->mysqli = $mysqli;
        $this->admin_mode = $admin_mode;
        
        // Load schema configuration
        $config_file = __DIR__ . '/schema_config.php';
        if (file_exists($config_file)) {
            $this->schema_config = include $config_file;
        } else {
            $this->schema_config = [];
        }
    }
    
    /**
     * Main detection method - checks all tables from configuration
     */
    public function detectIssues($show_admin_panel = false) {
        $issues = [];
        
        // Check each table defined in schema_config.php
        foreach ($this->schema_config as $table_name => $table_config) {
            $table_issues = $this->checkTable($table_name, $table_config);
            if (!empty($table_issues)) {
                $issues[$table_name] = $table_issues;
            }
        }
        
        // Show admin panel if requested and issues exist
        if ($show_admin_panel && !empty($issues)) {
            $this->showAdminPanel($issues);
        }
        
        return $issues;
    }
    
    /**
     * Check a single table against its configuration
     */
    private function checkTable($table_name, $table_config) {
        $issues = [];
        
        // Check if table exists
        if (!$this->tableExists($table_name)) {
            $issues[] = [
                'type' => 'missing_table',
                'table' => $table_name,
                'sql' => $this->generateCreateTableSQL($table_name, $table_config)
            ];
            return $issues; // If table doesn't exist, no point checking columns
        }
        
        // Check each required column
        if (isset($table_config['columns'])) {
            foreach ($table_config['columns'] as $column_name => $column_definition) {
                if (!$this->columnExists($table_name, $column_name)) {
                    $issues[] = [
                        'type' => 'missing_column',
                        'table' => $table_name,
                        'column' => $column_name,
                        'definition' => $column_definition,
                        'sql' => "ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` {$column_definition}"
                    ];
                }
            }
        }
        
        // Check indexes
        if (isset($table_config['indexes'])) {
            foreach ($table_config['indexes'] as $index_name => $index_columns) {
                if (!$this->indexExists($table_name, $index_name)) {
                    $issues[] = [
                        'type' => 'missing_index',
                        'table' => $table_name,
                        'index' => $index_name,
                        'sql' => "CREATE INDEX `{$index_name}` ON `{$table_name}`({$index_columns})"
                    ];
                }
            }
        }
        
        return $issues;
    }
    
    /**
     * Generate CREATE TABLE SQL from configuration
     */
    private function generateCreateTableSQL($table_name, $table_config) {
        $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (\n";
        
        $column_defs = [];
        if (isset($table_config['columns'])) {
            foreach ($table_config['columns'] as $column_name => $column_definition) {
                $column_defs[] = "  `{$column_name}` {$column_definition}";
            }
        }
        
        $sql .= implode(",\n", $column_defs);
        $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        return $sql;
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