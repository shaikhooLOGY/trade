<?php
/**
 * config.local.php - Local Development Configuration
 * Provides SQLite fallback for local development when MySQL is not accessible
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use SQLite for local development if MySQL connection fails
if (!function_exists('setupLocalDatabase')) {
    function setupLocalDatabase(): bool {
        $dbPath = __DIR__ . '/data/local_dev.sqlite';
        $dataDir = __DIR__ . '/data';
        
        // Create data directory if it doesn't exist
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        // Create SQLite database
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create basic users table structure
            $createTableSQL = "
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT UNIQUE NOT NULL,
                    username TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    status TEXT DEFAULT 'pending',
                    email_verified INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ";
            $pdo->exec($createTableSQL);
            
            // Create a sample admin user for testing
            $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (name, email, username, password_hash, status, email_verified) VALUES (?, ?, ?, ?, 'active', 1)");
            $stmt->execute(['Admin', 'admin@example.com', 'admin', $adminPassword]);
            
            return true;
        } catch (Exception $e) {
            error_log("Local database setup failed: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize local database
setupLocalDatabase();

return true;