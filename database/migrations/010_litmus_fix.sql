-- Migration: Phase-3 Litmus Guarded Schema Fix
-- Created: 2025-11-07
-- Description: Creates missing audit_logs table with safe indexes (CREATE IF NOT EXISTS)

-- Table: audit_logs
-- Handles all audit events in the system
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL, -- NULL for system events
    actor_type ENUM('user','system','agent') NOT NULL DEFAULT 'user',
    entity_type VARCHAR(64) NULL, -- e.g., 'user', 'trade', 'mtm_enrollment'
    entity_id BIGINT UNSIGNED NULL,
    event_data TEXT NULL, -- JSON data for the event
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actor_type_id (actor_type, actor_id),
    INDEX idx_entity_type_id (entity_type, entity_id),
    INDEX idx_event_type_id (event_type_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify table creation by checking if it exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'SUCCESS: audit_logs table created/verified'
        ELSE 'ERROR: audit_logs table not found'
    END AS status
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'audit_logs';