-- Migration: 011_agent_logs_guarded.sql
-- Phase-3 Litmus Auto-Fix Pack: Agent Logs Guarded Schema
-- Created: 2025-11-06
-- Description: Add missing columns to agent_logs table if not exists
-- Ensures correct schema for Agent Log APIs

-- Check if agent_logs table exists, if not create it with the required columns
CREATE TABLE IF NOT EXISTS agent_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event VARCHAR(100) NOT NULL,
    meta_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_event (event),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add missing columns if they don't exist (guarded migration)
ALTER TABLE agent_logs 
ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL AFTER id,
ADD COLUMN IF NOT EXISTS event VARCHAR(100) NOT NULL AFTER user_id,
ADD COLUMN IF NOT EXISTS meta_json JSON NULL AFTER event,
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER meta_json;

-- Add indexes if they don't exist
ALTER TABLE agent_logs 
ADD INDEX IF NOT EXISTS idx_user_id (user_id),
ADD INDEX IF NOT EXISTS idx_event (event),
ADD INDEX IF NOT EXISTS idx_created_at (created_at);

-- Migration completion log
SELECT 'Migration 011: Agent logs guarded schema completed successfully' as result, 
       'Table agent_logs created/updated with required columns: id, user_id, event, meta_json, created_at' as schema_summary,
       NOW() as applied_at;