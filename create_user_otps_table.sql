-- Create user_otps table for OTP verification system
-- This table stores OTP codes for email verification

CREATE TABLE IF NOT EXISTS user_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL COMMENT 'Hashed OTP code for security',
    expires_at DATETIME NOT NULL COMMENT 'When this OTP expires',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME DEFAULT NULL COMMENT 'When user successfully verified this OTP',
    attempts INT NOT NULL DEFAULT 0 COMMENT 'Number of verification attempts',
    max_attempts INT NOT NULL DEFAULT 3 COMMENT 'Maximum allowed attempts before lockout',
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this OTP is still valid',
    email_sent_at DATETIME DEFAULT NULL COMMENT 'When OTP email was sent',
    ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address that requested OTP',
    
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_email_sent (email_sent_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OTP codes for email verification';

-- Add indexes for performance optimization
CREATE INDEX idx_otp_cleanup ON user_otps (expires_at, is_active) WHERE is_active = TRUE;

-- Insert sample data (optional, for testing)
-- This will be empty initially and populated during registration