-- MTM Migration Script
-- Idempotent SQL for Shaikhoology MTM (Mental Trading Models) system
-- Safe to run multiple times; checks for existing columns/tables before creating

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
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Add indexes if not exist
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'mtm_models'
   AND INDEX_NAME = 'idx_status') = 0,
  'ALTER TABLE mtm_models ADD INDEX idx_status (status)',
  'SELECT "Index idx_status already exists"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'mtm_models'
   AND INDEX_NAME = 'idx_difficulty') = 0,
  'ALTER TABLE mtm_models ADD INDEX idx_difficulty (difficulty)',
  'SELECT "Index idx_difficulty already exists"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ===========================================
-- Create mtm_tasks table
-- ===========================================
CREATE TABLE IF NOT EXISTS mtm_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  model_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  level ENUM('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  sort_order INT NOT NULL DEFAULT 0,
  min_trades INT NOT NULL DEFAULT 0,
  time_window_days INT NOT NULL DEFAULT 0,
  require_sl TINYINT(1) NOT NULL DEFAULT 0,
  max_risk_pct DECIMAL(5,2) NULL,
  max_position_pct DECIMAL(5,2) NULL,
  min_rr DECIMAL(5,2) NULL,
  require_analysis_link TINYINT(1) NOT NULL DEFAULT 0,
  weekly_min_trades INT NOT NULL DEFAULT 0,
  weeks_consistency INT NOT NULL DEFAULT 0,
  rule_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_model (model_id),
  FOREIGN KEY (model_id) REFERENCES mtm_models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- Create mtm_enrollments table
-- ===========================================
CREATE TABLE IF NOT EXISTS mtm_enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  model_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('pending','approved','rejected','dropped','completed') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  rejected_at DATETIME NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_user_model (user_id, model_id),
  INDEX idx_model_status (model_id, status),
  FOREIGN KEY (model_id) REFERENCES mtm_models(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- Create mtm_task_progress table
-- ===========================================
CREATE TABLE IF NOT EXISTS mtm_task_progress (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  task_id INT NOT NULL,
  status ENUM('locked','unlocked','in_progress','passed','failed') NOT NULL DEFAULT 'locked',
  attempts INT NOT NULL DEFAULT 0,
  unlocked_at DATETIME NULL,
  last_evaluated_at DATETIME NULL,
  passed_at DATETIME NULL,
  failed_reason VARCHAR(255) NULL,
  UNIQUE KEY uq_enr_task (enrollment_id, task_id),
  FOREIGN KEY (enrollment_id) REFERENCES mtm_enrollments(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id) REFERENCES mtm_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- Extend trades table for MTM integration
-- ===========================================
ALTER TABLE trades
  ADD COLUMN IF NOT EXISTS enrollment_id INT NULL,
  ADD COLUMN IF NOT EXISTS task_id INT NULL,
  ADD COLUMN IF NOT EXISTS rules_snapshot JSON NULL,
  ADD COLUMN IF NOT EXISTS compliance_status ENUM('unknown','pass','fail','override') NOT NULL DEFAULT 'unknown',
  ADD COLUMN IF NOT EXISTS violation_json JSON NULL;

-- Add indexes for MTM columns if not exist
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'trades'
   AND INDEX_NAME = 'idx_enrollment_id') = 0,
  'ALTER TABLE trades ADD INDEX idx_enrollment_id (enrollment_id)',
  'SELECT "Index idx_enrollment_id already exists"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'trades'
   AND INDEX_NAME = 'idx_task_id') = 0,
  'ALTER TABLE trades ADD INDEX idx_task_id (task_id)',
  'SELECT "Index idx_task_id already exists"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ===========================================
-- Migration complete
-- ===========================================