<?php
/**
 * Database Health Analysis Script
 * Master QC Test - Module 2: DATABASE HEALTH ANALYSIS
 * 
 * Purpose: Comprehensive read-only database validation to assess schema integrity, 
 *          performance, and data consistency
 * 
 * Usage: php database_health_analysis.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

class DatabaseHealthAnalyzer
{
    private $mysqli;
    private $results = [];
    
    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
        if ($this->mysqli->connect_errno) {
            throw new Exception("Database connection failed: " . $this->mysqli->connect_error);
        }
    }
    
    public function runFullAnalysis()
    {
        echo "=== DATABASE HEALTH ANALYSIS STARTED ===\n";
        echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        echo "Database: " . $this->mysqli->server_info . "\n";
        echo str_repeat("=", 50) . "\n\n";
        
        // 1. TABLE STRUCTURE ANALYSIS
        $this->analyzeTableStructure();
        
        // 2. INDEX VALIDATION
        $this->validateIndexes();
        
        // 3. FOREIGN KEY CONSISTENCY
        $this->checkForeignKeyIntegrity();
        
        // 4. DATA INTEGRITY SAMPLING
        $this->sampleDataIntegrity();
        
        // 5. SCHEMA MIGRATION STATUS
        $this->checkMigrationStatus();
        
        // 6. GENERATE FINAL REPORT
        $this->generateFinalReport();
    }
    
    private function analyzeTableStructure()
    {
        echo "\n### 1. TABLE STRUCTURE ANALYSIS ###\n";
        
        // Get all tables
        $result = $this->mysqli->query("SHOW TABLES");
        $tables = [];
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        
        echo "Total tables found: " . count($tables) . "\n";
        echo "Tables: " . implode(", ", $tables) . "\n\n";
        
        // Check for core tables
        $coreTables = ['users', 'trades', 'mtm_models', 'mtm_tasks', 'mtm_enrollments'];
        $foundCoreTables = [];
        $missingCoreTables = [];
        
        foreach ($coreTables as $table) {
            if (in_array($table, $tables)) {
                $foundCoreTables[] = $table;
            } else {
                $missingCoreTables[] = $table;
            }
        }
        
        echo "Core Tables Analysis:\n";
        echo "- Found: " . implode(", ", $foundCoreTables) . "\n";
        echo "- Missing: " . implode(", ", $missingCoreTables) . "\n";
        echo "- Coverage: " . (count($foundCoreTables) / count($coreTables) * 100) . "%\n";
        
        $this->results['tables'] = [
            'total' => count($tables),
            'core_tables_found' => $foundCoreTables,
            'core_tables_missing' => $missingCoreTables,
            'coverage_percentage' => count($foundCoreTables) / count($coreTables) * 100
        ];
        
        // Additional table analysis
        foreach ($tables as $table) {
            $this->analyzeTableSchema($table);
        }
    }
    
    private function analyzeTableSchema($table)
    {
        $result = $this->mysqli->query("DESCRIBE $table");
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row;
        }
        
        echo "\nTable: $table (" . count($columns) . " columns)\n";
        foreach ($columns as $col) {
            echo "  - {$col['Field']}: {$col['Type']} " . 
                 ($col['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . 
                 ($col['Default'] ? " DEFAULT '{$col['Default']}'" : '') .
                 ($col['Extra'] ? " {$col['Extra']}" : '') . "\n";
        }
    }
    
    private function validateIndexes()
    {
        echo "\n### 2. INDEX VALIDATION ###\n";
        
        $coreTables = ['users', 'trades', 'mtm_models', 'mtm_tasks', 'mtm_enrollments'];
        $indexSummary = [];
        
        foreach ($coreTables as $table) {
            $result = $this->mysqli->query("SHOW INDEX FROM $table");
            $indexes = [];
            
            while ($row = $result->fetch_assoc()) {
                $indexes[] = [
                    'key_name' => $row['Key_name'],
                    'column_name' => $row['Column_name'],
                    'non_unique' => $row['Non_unique'],
                    'seq_in_index' => $row['Seq_in_index']
                ];
            }
            
            $indexSummary[$table] = $indexes;
            
            echo "\nTable: $table\n";
            echo "Index count: " . count($indexes) . "\n";
            
            // Group by key name for clarity
            $indexesByKey = [];
            foreach ($indexes as $index) {
                $indexesByKey[$index['key_name']][] = $index['column_name'];
            }
            
            foreach ($indexesByKey as $keyName => $columns) {
                $type = $keyName === 'PRIMARY' ? 'PRIMARY' : 'INDEX';
                echo "  - $type: $keyName (" . implode(', ', $columns) . ")\n";
            }
            
            // Check for primary key
            $hasPrimary = false;
            $foreignKeys = 0;
            foreach ($indexes as $index) {
                if ($index['key_name'] === 'PRIMARY') {
                    $hasPrimary = true;
                }
                if ($index['column_name'] === 'id' && $index['key_name'] !== 'PRIMARY') {
                    $foreignKeys++;
                }
            }
            
            echo "  - Primary key exists: " . ($hasPrimary ? "YES" : "NO") . "\n";
            echo "  - Foreign key candidates: $foreignKeys\n";
        }
        
        $this->results['indexes'] = $indexSummary;
    }
    
    private function checkForeignKeyIntegrity()
    {
        echo "\n### 3. FOREIGN KEY CONSISTENCY ###\n";
        
        $result = $this->mysqli->query("
            SELECT 
                TABLE_NAME,
                COLUMN_NAME,
                CONSTRAINT_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE REFERENCED_TABLE_NAME IS NOT NULL 
            AND TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, CONSTRAINT_NAME
        ");
        
        $foreignKeys = [];
        while ($row = $result->fetch_assoc()) {
            $foreignKeys[] = $row;
        }
        
        echo "Foreign key relationships found: " . count($foreignKeys) . "\n";
        foreach ($foreignKeys as $fk) {
            echo "  - {$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} -> " .
                 "{$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
        }
        
        // Check for orphaned records
        $this->checkForOrphanedRecords();
        
        $this->results['foreign_keys'] = $foreignKeys;
    }
    
    private function checkForOrphanedRecords()
    {
        echo "\nOrphaned Records Check:\n";
        
        // Check trades without valid trader_id
        $result = $this->mysqli->query("
            SELECT COUNT(*) as orphaned_trades 
            FROM trades t 
            LEFT JOIN users u ON t.trader_id = u.id 
            WHERE u.id IS NULL
        ");
        $orphanedTrades = $result->fetch_assoc()['orphaned_trades'];
        echo "  - Orphaned trades: $orphanedTrades\n";
        
        // Check mtm_enrollments without valid trader_id
        $result = $this->mysqli->query("
            SELECT COUNT(*) as orphaned_enrollments 
            FROM mtm_enrollments e 
            LEFT JOIN users u ON e.trader_id = u.id 
            WHERE u.id IS NULL
        ");
        $orphanedEnrollments = $result->fetch_assoc()['orphaned_enrollments'];
        echo "  - Orphaned enrollments: $orphanedEnrollments\n";
        
        // Check mtm_enrollments without valid model_id
        $result = $this->mysqli->query("
            SELECT COUNT(*) as orphaned_model_refs 
            FROM mtm_enrollments e 
            LEFT JOIN mtm_models m ON e.model_id = m.id 
            WHERE m.id IS NULL
        ");
        $orphanedModelRefs = $result->fetch_assoc()['orphaned_model_refs'];
        echo "  - Orphaned model references: $orphanedModelRefs\n";
        
        $this->results['orphaned_records'] = [
            'trades' => $orphanedTrades,
            'enrollments' => $orphanedEnrollments,
            'model_refs' => $orphanedModelRefs
        ];
    }
    
    private function sampleDataIntegrity()
    {
        echo "\n### 4. DATA INTEGRITY SAMPLING ###\n";
        
        $dataSamples = [
            'users' => "SELECT COUNT(*) as count FROM users",
            'trades' => "SELECT COUNT(*) as count FROM trades",
            'mtm_models' => "SELECT COUNT(*) as count FROM mtm_models",
            'mtm_tasks' => "SELECT COUNT(*) as count FROM mtm_tasks",
            'mtm_enrollments' => "SELECT COUNT(*) as count FROM mtm_enrollments"
        ];
        
        $dataCounts = [];
        foreach ($dataSamples as $table => $query) {
            $result = $this->mysqli->query($query);
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                $dataCounts[$table] = $count;
                echo "$table: $count records\n";
                
                // Sample data distribution if significant records exist
                if ($count > 0 && $count < 1000) {
                    $this->sampleTableData($table);
                }
            } else {
                $dataCounts[$table] = 0;
                echo "$table: Table not found or query failed\n";
            }
        }
        
        $this->results['data_counts'] = $dataCounts;
    }
    
    private function sampleTableData($table)
    {
        echo "\n  Sample data from $table:\n";
        
        $result = $this->mysqli->query("SELECT * FROM $table LIMIT 5");
        if ($result && $result->num_rows > 0) {
            $columns = array_keys($result->fetch_assoc());
            echo "    Columns: " . implode(", ", $columns) . "\n";
            
            $result = $this->mysqli->query("SELECT * FROM $table LIMIT 3");
            $sampleCount = 0;
            while ($row = $result->fetch_assoc()) {
                $sampleCount++;
                echo "    Row $sampleCount: ";
                $display = [];
                foreach ($row as $key => $value) {
                    if (strlen($value) > 50) {
                        $display[] = "$key=" . substr($value, 0, 47) . "...";
                    } else {
                        $display[] = "$key=$value";
                    }
                }
                echo implode(", ", $display) . "\n";
            }
        }
    }
    
    private function checkMigrationStatus()
    {
        echo "\n### 5. SCHEMA MIGRATION STATUS ###\n";
        
        // Check if schema_migrations table exists
        $result = $this->mysqli->query("SHOW TABLES LIKE 'schema_migrations'");
        $migrationTableExists = $result->num_rows > 0;
        
        if ($migrationTableExists) {
            echo "Migration tracking table exists\n";
            
            $result = $this->mysqli->query("SELECT * FROM schema_migrations ORDER BY applied_at DESC");
            $migrations = [];
            while ($row = $result->fetch_assoc()) {
                $migrations[] = $row;
            }
            
            echo "Applied migrations (" . count($migrations) . "):\n";
            foreach ($migrations as $migration) {
                echo "  - {$migration['version']}: {$migration['applied_at']}\n";
            }
            
            $this->results['migrations'] = $migrations;
        } else {
            echo "Migration tracking table not found\n";
            echo "This suggests migrations may not have been properly tracked\n";
        }
        
        // Check for migration files
        $migrationFiles = glob('database/migrations/*.sql');
        echo "\nMigration files found (" . count($migrationFiles) . "):\n";
        foreach ($migrationFiles as $file) {
            echo "  - " . basename($file) . "\n";
        }
        
        $this->results['migration_files'] = array_map('basename', $migrationFiles);
    }
    
    private function generateFinalReport()
    {
        echo "\n### 6. COMPREHENSIVE HEALTH REPORT ###\n";
        echo str_repeat("=", 50) . "\n";
        
        // Calculate overall health score
        $score = 0;
        $maxScore = 100;
        
        // Table structure score (30 points)
        $tableScore = $this->results['tables']['coverage_percentage'] * 0.3;
        $score += $tableScore;
        
        // Foreign key integrity score (25 points)  
        $orphanedRecords = $this->results['orphaned_records'];
        $orphanedTotal = $orphanedRecords['trades'] + $orphanedRecords['enrollments'] + $orphanedRecords['model_refs'];
        $fkScore = max(0, 25 - ($orphanedTotal * 2)); // Deduct 2 points per orphaned record
        $score += $fkScore;
        
        // Data integrity score (25 points)
        $dataScore = 0;
        $coreTables = ['users', 'trades', 'mtm_models', 'mtm_tasks', 'mtm_enrollments'];
        foreach ($coreTables as $table) {
            $count = $this->results['data_counts'][$table] ?? 0;
            if ($count > 0) {
                $dataScore += 5; // 5 points per table with data
            }
        }
        $score += $dataScore;
        
        // Migration tracking score (20 points)
        $migrationScore = !empty($this->results['migrations']) ? 20 : 0;
        $score += $migrationScore;
        
        // Overall Health Status
        $healthStatus = "CRITICAL";
        if ($score >= 85) $healthStatus = "EXCELLENT";
        elseif ($score >= 70) $healthStatus = "GOOD";
        elseif ($score >= 55) $healthStatus = "FAIR";
        
        echo "OVERALL DATABASE HEALTH SCORE: " . round($score, 1) . "/$maxScore\n";
        echo "HEALTH STATUS: $healthStatus\n\n";
        
        // Detailed findings
        echo "DETAILED FINDINGS:\n";
        echo "------------------\n";
        
        echo "Table Structure: ";
        if ($this->results['tables']['coverage_percentage'] >= 80) {
            echo "PASS (" . round($this->results['tables']['coverage_percentage'], 1) . "% coverage)\n";
        } else {
            echo "FAIL (" . round($this->results['tables']['coverage_percentage'], 1) . "% coverage)\n";
        }
        
        echo "Foreign Key Integrity: ";
        $orphanedTotal = $this->results['orphaned_records']['trades'] + 
                        $this->results['orphaned_records']['enrollments'] + 
                        $this->results['orphaned_records']['model_refs'];
        if ($orphanedTotal === 0) {
            echo "PASS (No orphaned records found)\n";
        } else {
            echo "FAIL ($orphanedTotal orphaned records found)\n";
        }
        
        echo "Data Distribution: ";
        $tablesWithData = 0;
        foreach (['users', 'trades', 'mtm_models', 'mtm_tasks', 'mtm_enrollments'] as $table) {
            if (($this->results['data_counts'][$table] ?? 0) > 0) {
                $tablesWithData++;
            }
        }
        if ($tablesWithData >= 3) {
            echo "PASS ($tablesWithData/5 tables have data)\n";
        } else {
            echo "FAIL ($tablesWithData/5 tables have data)\n";
        }
        
        echo "Migration Status: ";
        if (!empty($this->results['migrations'])) {
            echo "PASS (" . count($this->results['migrations']) . " migrations tracked)\n";
        } else {
            echo "FAIL (No migration tracking found)\n";
        }
        
        // Critical issues
        echo "\nCRITICAL ISSUES DETECTED:\n";
        if ($this->results['tables']['coverage_percentage'] < 80) {
            echo "1. Missing core tables: " . implode(", ", $this->results['tables']['core_tables_missing']) . "\n";
        }
        if ($orphanedTotal > 0) {
            echo "2. Orphaned records found - foreign key integrity compromised\n";
        }
        if (empty($this->results['migrations'])) {
            echo "3. No migration tracking - schema version unknown\n";
        }
        
        echo "\nRECOMMENDATIONS:\n";
        if ($this->results['tables']['coverage_percentage'] < 100) {
            echo "- Execute missing migrations to create core tables\n";
        }
        if ($orphanedTotal > 0) {
            echo "- Clean up orphaned records or fix foreign key constraints\n";
        }
        if (empty($this->results['migrations'])) {
            echo "- Implement migration tracking system\n";
        }
        
        echo "\n=== ANALYSIS COMPLETED ===\n";
    }
}

// Main execution
try {
    echo "Initializing Database Health Analysis...\n";
    $analyzer = new DatabaseHealthAnalyzer($mysqli);
    $analyzer->runFullAnalysis();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}