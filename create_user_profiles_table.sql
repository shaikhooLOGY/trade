-- create_user_profiles_table.sql
-- Database migration for profile completion system

-- Create user_profiles table for comprehensive profile data
CREATE TABLE IF NOT EXISTS user_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    
    -- Personal Information
    age INT,
    location VARCHAR(255),
    phone VARCHAR(20),
    
    -- Educational Background
    education_level ENUM('High School', 'Bachelor\'s Degree', 'Master\'s Degree', 'PhD', 'Other') DEFAULT NULL,
    institution VARCHAR(255),
    graduation_year INT,
    
    -- Trading Experience
    trading_experience_years INT DEFAULT 0,
    trading_markets TEXT, -- JSON array of markets
    trading_strategies TEXT, -- JSON array of strategies
    previous_trading_results TEXT,
    
    -- Investment Goals and Risk Tolerance
    investment_goals TEXT,
    risk_tolerance ENUM('Conservative', 'Moderate', 'Aggressive', 'Very Aggressive') DEFAULT NULL,
    investment_timeframe ENUM('Short-term (1-6 months)', 'Medium-term (6 months - 2 years)', 'Long-term (2+ years)') DEFAULT NULL,
    
    -- Trading Capital and Financial Information
    trading_capital DECIMAL(15,2),
    monthly_income DECIMAL(15,2),
    net_worth DECIMAL(15,2),
    trading_budget_percentage DECIMAL(5,2),
    
    -- Trading Psychology Assessment
    psychology_assessment JSON, -- Store assessment responses
    emotional_control_rating INT, -- 1-10 scale
    discipline_rating INT, -- 1-10 scale
    patience_rating INT, -- 1-10 scale
    
    -- Why they want to join Shaikhoology
    why_join TEXT,
    expectations TEXT,
    commitment_level ENUM('Casual', 'Serious', 'Dedicated', 'Professional') DEFAULT NULL,
    
    -- References or recommendations
    reference_name VARCHAR(255),
    reference_contact VARCHAR(255),
    reference_relationship VARCHAR(255),
    
    -- Profile completion tracking
    profile_completion_status ENUM('not_started', 'in_progress', 'completed', 'under_review', 'approved', 'rejected') DEFAULT 'not_started',
    profile_completion_date DATETIME NULL,
    profile_last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Admin review fields
    admin_review_status ENUM('pending', 'approved', 'rejected', 'needs_update') DEFAULT 'pending',
    admin_review_date DATETIME NULL,
    admin_review_comments TEXT,
    admin_reviewed_by INT NULL,
    
    -- Profile completeness score
    completeness_score INT DEFAULT 0, -- 0-100 percentage
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_user_id (user_id),
    INDEX idx_completion_status (profile_completion_status),
    INDEX idx_admin_review_status (admin_review_status),
    INDEX idx_completeness_score (completeness_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add profile-related columns to users table if they don't exist
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS username VARCHAR(50) UNIQUE AFTER email,
ADD COLUMN IF NOT EXISTS status ENUM('pending', 'profile_pending', 'admin_review', 'active', 'approved', 'rejected', 'suspended') DEFAULT 'pending' AFTER password_hash,
ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 0 AFTER status,
ADD COLUMN IF NOT EXISTS profile_status ENUM('pending', 'needs_update', 'approved', 'under_review') DEFAULT 'pending' AFTER email_verified,
ADD COLUMN IF NOT EXISTS profile_field_status JSON AFTER profile_status,
ADD COLUMN IF NOT EXISTS profile_comments TEXT AFTER profile_field_status,
ADD COLUMN IF NOT EXISTS rejection_reason TEXT AFTER profile_comments,
ADD COLUMN IF NOT EXISTS role ENUM('user', 'admin') DEFAULT 'user' AFTER rejection_reason,
ADD COLUMN IF NOT EXISTS trading_capital DECIMAL(14,2) DEFAULT 100000.00 AFTER role,
ADD COLUMN IF NOT EXISTS profile_completion_percentage INT DEFAULT 0 AFTER trading_capital;

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);
CREATE INDEX IF NOT EXISTS idx_users_email_verified ON users(email_verified);
CREATE INDEX IF NOT EXISTS idx_users_profile_status ON users(profile_status);

-- Insert sample data for testing (optional)
-- This can be removed in production
/*
INSERT INTO user_profiles (user_id, profile_completion_status, completeness_score) 
SELECT id, 'not_started', 0 FROM users 
WHERE NOT EXISTS (SELECT 1 FROM user_profiles WHERE user_profiles.user_id = users.id);
*/