-- Minimal tables for local MTM sandbox
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mtm_models (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  description TEXT,
  status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'active',
  difficulty ENUM('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  display_order INT NOT NULL DEFAULT 0,
  estimated_days INT NOT NULL DEFAULT 0,
  cover_image_path VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mtm_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  model_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  level ENUM('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  sort_order INT NOT NULL DEFAULT 0,
  min_trades INT NOT NULL DEFAULT 0,
  time_window_days INT NOT NULL DEFAULT 0,
  require_sl TINYINT(1) NOT NULL DEFAULT 0,
  max_risk_pct DECIMAL(5,2) DEFAULT NULL,
  max_position_pct DECIMAL(5,2) DEFAULT NULL,
  min_rr DECIMAL(5,2) DEFAULT NULL,
  require_analysis_link TINYINT(1) NOT NULL DEFAULT 0,
  weekly_min_trades INT NOT NULL DEFAULT 0,
  weeks_consistency INT NOT NULL DEFAULT 0,
  rule_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_task_model FOREIGN KEY (model_id) REFERENCES mtm_models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mtm_enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  model_id INT NOT NULL,
  status ENUM('pending','approved','rejected','dropped','completed') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  approved_at TIMESTAMP NULL,
  UNIQUE KEY uq_user_model (user_id, model_id),
  CONSTRAINT fk_en_user  FOREIGN KEY (user_id)  REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_en_model FOREIGN KEY (model_id) REFERENCES mtm_models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mtm_task_progress (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  task_id INT NOT NULL,
  status ENUM('locked','in_progress','passed','failed') NOT NULL DEFAULT 'locked',
  attempts INT NOT NULL DEFAULT 0,
  last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_en_task (enrollment_id, task_id),
  CONSTRAINT fk_prog_en FOREIGN KEY (enrollment_id) REFERENCES mtm_enrollments(id) ON DELETE CASCADE,
  CONSTRAINT fk_prog_task FOREIGN KEY (task_id) REFERENCES mtm_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- (Optional) bare-minimum trades table (for later)
CREATE TABLE IF NOT EXISTS trades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  symbol VARCHAR(50) NOT NULL,
  risk_pct DECIMAL(5,2) DEFAULT NULL,
  rr DECIMAL(5,2) DEFAULT NULL,
  analysis_link VARCHAR(255) DEFAULT NULL,
  opened_at DATETIME DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  enrollment_id INT NULL,
  task_id INT NULL,
  compliance_status ENUM('unknown','pass','fail','override') NOT NULL DEFAULT 'unknown',
  violation_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: demo admin + 1 model + 2 tasks
INSERT IGNORE INTO users (id, username, email, password_hash, is_admin)
VALUES (1, 'admin', 'admin@example.com', '$2y$10$HfXG3l3F2Yq3gk3s6xq9tu3yQ1Y1zA5vYJ1l6f7bq2m7H7k9n2n5e', 1);
/* password = admin123  (sirf local ke liye) */

INSERT IGNORE INTO mtm_models (id, title, description, status, difficulty, display_order, estimated_days, cover_image_path)
VALUES (1, 'Disciplined Trader Program',
        'Structured journey: Basic → Intermediate → Advanced',
        'active','easy',0,0,NULL);

INSERT IGNORE INTO mtm_tasks
(model_id, title, level, sort_order, min_trades, time_window_days, require_sl, max_risk_pct, min_rr, rule_json)
VALUES
(1,'First trade with SL','easy',0, 1, 2, 1, 2.00, 1.00, JSON_OBJECT('allowed_outcomes', JSON_ARRAY('TARGET HIT','BE'))),
(1,'Trade with 5% risk','easy',1, 1, 3, 1, 5.00, 1.00, NULL);
