-- Fix Registration Schema Mismatch
-- Adds missing columns required by register.php

-- Add missing columns to users table
ALTER TABLE users 
ADD COLUMN verification_attempts INT(11) NOT NULL DEFAULT 0,
ADD COLUMN otp_code VARCHAR(6) DEFAULT NULL,
ADD COLUMN otp_expires DATETIME DEFAULT NULL;