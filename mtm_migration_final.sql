-- MTM Migration Script — Production Ready
-- Safe, idempotent SQL for Shaikhoology MTM system
-- Run this on production database to add missing columns/tables

-- ===========================================
-- Extend existing users table
-- ===========================================
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS name VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS funds_available DECIMAL(14,2) DEFAULT 100000,
  ADD COLUMN IF NOT EXISTS promoted_by INT NULL,
  ADD COLUMN IF NOT EXISTS INDEX idx_promoted_by (promoted_by);

-- ===========================================
-- Extend existing trades table
-- ===========================================
ALTER TABLE trades
  ADD COLUMN IF NOT EXISTS entry_date DATE NULL,
  ADD COLUMN IF NOT EXISTS exit_price DECIMAL(14,4) NULL,
  ADD COLUMN IF NOT EXISTS pnl DECIMAL(14,4) NULL,
  ADD COLUMN IF NOT EXISTS violation_json JSON NULL,
  ADD COLUMN IF NOT EXISTS enrollment_id INT NULL,
  ADD COLUMN IF NOT EXISTS task_id INT NULL,
  ADD COLUMN IF NOT EXISTS rules_snapshot JSON NULL,
  ADD COLUMN IF NOT EXISTS compliance_status ENUM('unknown','pass','fail','override') NOT NULL DEFAULT 'unknown',
  ADD COLUMN IF NOT EXISTS INDEX idx_enrollment_id (enrollment_id),
  ADD COLUMN IF NOT EXISTS INDEX idx_task_id (task_id);

-- ===========================================
-- Extend existing mtm_models table
-- ===========================================
ALTER TABLE mtm_models
  ADD COLUMN IF NOT EXISTS cover_image_path VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS difficulty ENUM('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  ADD COLUMN IF NOT EXISTS status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
  ADD COLUMN IF NOT EXISTS display_order INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS estimated_days INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS created_by INT NULL,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS INDEX idx_status (status),
  ADD COLUMN IF NOT EXISTS INDEX idx_difficulty (difficulty);

-- ===========================================
-- Extend existing mtm_enrollments table
-- ===========================================
ALTER TABLE mtm_enrollments
  ADD COLUMN IF NOT EXISTS joined_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS INDEX idx_model_status (model_id, status);

-- ===========================================
-- Extend existing mtm_task_progress table
-- ===========================================
ALTER TABLE mtm_task_progress
  ADD COLUMN IF NOT EXISTS passed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS INDEX idx_enrollment_task (enrollment_id, task_id);

-- ===========================================
-- Create trade_concerns table if not exists
-- ===========================================
CREATE TABLE IF NOT EXISTS trade_concerns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trade_id INT NOT NULL,
  user_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  details TEXT NULL,
  status ENUM('open','resolved','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by INT NULL,
  INDEX idx_trade (trade_id),
  INDEX idx_user (user_id),
  INDEX idx_status (status),
  FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- Migration complete
-- ===========================================