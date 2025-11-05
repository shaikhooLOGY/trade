-- Migration: TMS-MTM Module Tables
-- Created: 2025-11-04
-- Description: Core tables for Shaikhoology TMS-MTM module

-- Table: mtm_models
CREATE TABLE IF NOT EXISTS mtm_models (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(190) NOT NULL,
    tiering TEXT NOT NULL, -- JSON as TEXT for portability
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: mtm_tasks
CREATE TABLE IF NOT EXISTS mtm_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_id BIGINT UNSIGNED NOT NULL,
    tier ENUM('basic','intermediate','advanced') NOT NULL,
    level ENUM('basic','medium','hard') NOT NULL,
    rule_config TEXT NOT NULL, -- JSON as TEXT
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_model_tier_level (model_id, tier, level, sort_order),
    FOREIGN KEY (model_id) REFERENCES mtm_models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: mtm_enrollments
CREATE TABLE IF NOT EXISTS mtm_enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trader_id BIGINT UNSIGNED NOT NULL,
    model_id BIGINT UNSIGNED NOT NULL,
    tier ENUM('basic','intermediate','advanced') NOT NULL,
    status ENUM('active','paused','completed','cancelled') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trader_model (trader_id, model_id),
    INDEX idx_trader_status (trader_id, status),
    FOREIGN KEY (trader_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES mtm_models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: trades
CREATE TABLE IF NOT EXISTS trades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trader_id BIGINT UNSIGNED NOT NULL,
    symbol VARCHAR(32) NOT NULL,
    side ENUM('buy','sell') NOT NULL,
    quantity DECIMAL(16,4) NOT NULL,
    price DECIMAL(16,4) NOT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trader_symbol_date (trader_id, symbol, opened_at),
    FOREIGN KEY (trader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample data for testing
INSERT IGNORE INTO mtm_models (code, name, tiering) VALUES 
('BASIC_TMS', 'Basic Trading Management System', '{"basic": {"max_trades": 10, "max_volume": 10000}, "intermediate": {"max_trades": 20, "max_volume": 25000}, "advanced": {"max_trades": 50, "max_volume": 100000}}'),
('MTM_STD', 'Standard Market-to-Market Model', '{"basic": {"max_trades": 15, "max_volume": 15000}, "intermediate": {"max_trades": 30, "max_volume": 50000}, "advanced": {"max_trades": 100, "max_volume": 200000}}');

-- Insert sample tasks for the Basic TMS model
INSERT IGNORE INTO mtm_tasks (model_id, tier, level, rule_config, sort_order) VALUES 
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'basic', 'basic', '{"min_trades": 1, "min_volume": 1000, "min_success_rate": 0.5}', 1),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'basic', 'medium', '{"min_trades": 3, "min_volume": 3000, "min_success_rate": 0.6}', 2),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'basic', 'hard', '{"min_trades": 5, "min_volume": 5000, "min_success_rate": 0.7}', 3),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'intermediate', 'basic', '{"min_trades": 8, "min_volume": 8000, "min_success_rate": 0.6}', 4),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'intermediate', 'medium', '{"min_trades": 12, "min_volume": 12000, "min_success_rate": 0.65}', 5),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'intermediate', 'hard', '{"min_trades": 16, "min_volume": 16000, "min_success_rate": 0.7}', 6),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'advanced', 'basic', '{"min_trades": 25, "min_volume": 25000, "min_success_rate": 0.7}', 7),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'advanced', 'medium', '{"min_trades": 35, "min_volume": 35000, "min_success_rate": 0.75}', 8),
((SELECT id FROM mtm_models WHERE code = 'BASIC_TMS'), 'advanced', 'hard', '{"min_trades": 50, "min_volume": 50000, "min_success_rate": 0.8}', 9);