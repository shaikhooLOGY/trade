<?php
/**
 * schema_config.php - Complete Database Schema Configuration
 * Single Source of Truth for all database tables and columns
 */

return [
    'users' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'name' => 'VARCHAR(255) NOT NULL COMMENT "User full name"',
            'email' => 'VARCHAR(255) UNIQUE NOT NULL COMMENT "User email address"',
            'username' => 'VARCHAR(100) UNIQUE NOT NULL COMMENT "Unique username"',
            'password_hash' => 'VARCHAR(255) NOT NULL COMMENT "Hashed password"',
            'status' => 'ENUM("pending","active","approved","suspended","rejected") DEFAULT "pending" COMMENT "Account status"',
            'email_verified' => 'TINYINT(1) DEFAULT 0 COMMENT "Email verification status"',
            'is_admin' => 'TINYINT(1) DEFAULT 0 COMMENT "Admin flag"',
            'role' => 'VARCHAR(50) DEFAULT "user" COMMENT "User role"',
            'trading_capital' => 'DECIMAL(12,2) DEFAULT 100000.00 COMMENT "Available trading capital"',
            'funds_available' => 'DECIMAL(12,2) DEFAULT 100000.00 COMMENT "Available funds"',
            'reserved_capital' => 'DECIMAL(12,2) DEFAULT 0.00 COMMENT "Reserved capital"',
            'otp_code' => 'VARCHAR(6) NULL COMMENT "OTP verification code"',
            'otp_expires_at' => 'DATETIME NULL COMMENT "OTP expiration time"',
            'otp_attempts' => 'INT DEFAULT 0 COMMENT "OTP verification attempts"',
            'profile_status' => 'VARCHAR(50) DEFAULT "pending" COMMENT "Profile completion status"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Account creation time"',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT "Last update time"',
        ],
        'indexes' => [
            'idx_email' => 'email',
            'idx_username' => 'username',
            'idx_status' => 'status',
        ]
    ],
    
    'trades' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'user_id' => 'INT NOT NULL COMMENT "User ID reference"',
            'symbol' => 'VARCHAR(20) NOT NULL COMMENT "Trading symbol"',
            'entry_price' => 'DECIMAL(10,2) DEFAULT 0.00 COMMENT "Entry price"',
            'exit_price' => 'DECIMAL(10,2) DEFAULT 0.00 COMMENT "Exit price"',
            'quantity' => 'INT DEFAULT 0 COMMENT "Trade quantity"',
            'pl_percent' => 'DECIMAL(5,2) DEFAULT 0.00 COMMENT "Profit/Loss percentage"',
            'outcome' => 'VARCHAR(50) DEFAULT "OPEN" COMMENT "Trade outcome"',
            'entry_date' => 'DATE NULL COMMENT "Entry date"',
            'exit_date' => 'DATE NULL COMMENT "Exit date"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Record creation time"',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT "Last update time"',
        ],
        'indexes' => [
            'idx_user_id' => 'user_id',
            'idx_symbol' => 'symbol',
            'idx_outcome' => 'outcome',
        ]
    ],
    
    'system_logs' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'log_type' => 'VARCHAR(50) NOT NULL COMMENT "Type of log entry"',
            'message' => 'TEXT NOT NULL COMMENT "Log message"',
            'user_id' => 'INT NULL COMMENT "Associated user ID"',
            'ip_address' => 'VARCHAR(45) NULL COMMENT "IP address"',
            'user_agent' => 'TEXT NULL COMMENT "User agent string"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Log creation time"',
        ],
        'indexes' => [
            'idx_log_type' => 'log_type',
            'idx_user_id' => 'user_id',
            'idx_created_at' => 'created_at',
        ]
    ],
    
    'password_resets' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'user_id' => 'INT NOT NULL COMMENT "User ID"',
            'token' => 'VARCHAR(255) NOT NULL COMMENT "Reset token"',
            'expires_at' => 'DATETIME NOT NULL COMMENT "Token expiration"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Request time"',
        ],
        'indexes' => [
            'idx_token' => 'token',
            'idx_user_id' => 'user_id',
        ]
    ],
    
    'user_otps' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'user_id' => 'INT NOT NULL COMMENT "User ID"',
            'otp_code' => 'VARCHAR(6) NOT NULL COMMENT "OTP code"',
            'expires_at' => 'DATETIME NOT NULL COMMENT "Expiration time"',
            'verified' => 'TINYINT(1) DEFAULT 0 COMMENT "Verification status"',
            'attempts' => 'INT DEFAULT 0 COMMENT "Verification attempts"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Creation time"',
        ],
        'indexes' => [
            'idx_user_id' => 'user_id',
            'idx_otp_code' => 'otp_code',
        ]
    ],
    
    'deploy_notes' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'env' => 'ENUM("local","staging","prod") NOT NULL DEFAULT "prod" COMMENT "Environment"',
            'title' => 'VARCHAR(255) NOT NULL COMMENT "Deployment title"',
            'body' => 'TEXT NULL COMMENT "Deployment description"',
            'note_type' => 'ENUM("feature","hotfix","migration","maintenance") DEFAULT "feature" COMMENT "Type of deployment"',
            'status' => 'ENUM("planned","in_progress","deployed","rolled_back") DEFAULT "planned" COMMENT "Deployment status"',
            'created_by' => 'INT NOT NULL COMMENT "Creator user ID"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Creation time"',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT "Update time"',
            'deployed_at' => 'DATETIME NULL COMMENT "Deployment time"',
        ],
        'indexes' => [
            'idx_env' => 'env',
            'idx_status' => 'status',
            'idx_created_by' => 'created_by',
        ]
    ],
    
    // MTM (Mental Trading Models) Tables
    'mtm_models' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'title' => 'VARCHAR(255) NOT NULL COMMENT "Model title"',
            'description' => 'TEXT NULL COMMENT "Model description"',
            'status' => 'ENUM("draft","active","archived") DEFAULT "draft" COMMENT "Model status"',
            'difficulty' => 'ENUM("easy","moderate","hard") DEFAULT "easy" COMMENT "Difficulty level"',
            'display_order' => 'INT DEFAULT 0 COMMENT "Display order"',
            'estimated_days' => 'INT DEFAULT 0 COMMENT "Estimated completion days"',
            'cover_image_path' => 'VARCHAR(255) NULL COMMENT "Cover image path"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Creation time"',
        ],
        'indexes' => [
            'idx_status' => 'status',
            'idx_difficulty' => 'difficulty',
        ]
    ],
    
    'mtm_tasks' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'model_id' => 'INT NOT NULL COMMENT "MTM model ID"',
            'title' => 'VARCHAR(255) NOT NULL COMMENT "Task title"',
            'level' => 'ENUM("easy","moderate","hard") DEFAULT "easy" COMMENT "Task difficulty"',
            'sort_order' => 'INT DEFAULT 0 COMMENT "Sort order"',
            'min_trades' => 'INT DEFAULT 1 COMMENT "Minimum trades required"',
            'time_window_days' => 'INT DEFAULT 0 COMMENT "Time window in days"',
            'require_sl' => 'TINYINT(1) DEFAULT 0 COMMENT "Require stop loss"',
            'max_risk_pct' => 'DECIMAL(5,2) NULL COMMENT "Maximum risk percentage"',
            'max_position_pct' => 'DECIMAL(5,2) NULL COMMENT "Maximum position percentage"',
            'min_rr' => 'DECIMAL(5,2) NULL COMMENT "Minimum risk-reward ratio"',
            'require_analysis_link' => 'TINYINT(1) DEFAULT 0 COMMENT "Require analysis link"',
            'weekly_min_trades' => 'INT DEFAULT 0 COMMENT "Weekly minimum trades"',
            'weeks_consistency' => 'INT DEFAULT 0 COMMENT "Weeks consistency required"',
            'rule_json' => 'TEXT NULL COMMENT "Additional rules in JSON"',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Creation time"',
        ],
        'indexes' => [
            'idx_model_id' => 'model_id',
            'idx_level' => 'level',
        ]
    ],
    
    'mtm_enrollments' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'user_id' => 'INT NOT NULL COMMENT "User ID"',
            'model_id' => 'INT NOT NULL COMMENT "MTM model ID"',
            'status' => 'ENUM("pending","approved","rejected","dropped") DEFAULT "pending" COMMENT "Enrollment status"',
            'requested_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Request time"',
            'approved_at' => 'DATETIME NULL COMMENT "Approval time"',
            'joined_at' => 'DATETIME NULL COMMENT "Join time"',
            'completed_at' => 'DATETIME NULL COMMENT "Completion time"',
        ],
        'indexes' => [
            'idx_user_id' => 'user_id',
            'idx_model_id' => 'model_id',
            'idx_status' => 'status',
        ]
    ],
    
    'mtm_task_progress' => [
        'columns' => [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'enrollment_id' => 'INT NOT NULL COMMENT "Enrollment ID"',
            'task_id' => 'INT NOT NULL COMMENT "Task ID"',
            'status' => 'ENUM("locked","unlocked","in_progress","passed","failed") DEFAULT "locked" COMMENT "Progress status"',
            'attempts' => 'INT DEFAULT 0 COMMENT "Number of attempts"',
            'unlocked_at' => 'DATETIME NULL COMMENT "Unlock time"',
            'passed_at' => 'DATETIME NULL COMMENT "Pass time"',
            'last_evaluated_at' => 'DATETIME NULL COMMENT "Last evaluation time"',
        ],
        'indexes' => [
            'idx_enrollment_id' => 'enrollment_id',
            'idx_task_id' => 'task_id',
            'idx_status' => 'status',
        ]
    ],
    
    'mtm_tier_labels' => [
        'columns' => [
            'tier_key' => 'ENUM("easy","moderate","hard") NOT NULL PRIMARY KEY',
            'display_name' => 'VARCHAR(50) NOT NULL COMMENT "Display name for tier"',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT "Last update time"',
        ],
        'indexes' => []
    ],
];