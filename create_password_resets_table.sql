-- ============================================================================
-- PASSWORD_RESETS TABLE CREATION SCRIPT
-- ============================================================================
-- Purpose: Create missing password_resets table for password reset functionality
-- Compatible with forgot_password.php and reset_password.php
-- Created: 2025-11-08
-- Priority: URGENT - Fixes fatal error

-- CREATE TABLE Statement
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) DEFAULT NULL COMMENT 'Plain token (legacy, for backward compatibility)',
  `token_hash` varchar(255) NOT NULL COMMENT 'SHA-256 hash of token for security',
  `expires_at` datetime NOT NULL COMMENT 'When this reset token expires',
  `used_at` datetime DEFAULT NULL COMMENT 'When this token was used (NULL = not used)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this token was created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_user_expires` (`user_id`,`expires_at`),
  CONSTRAINT `fk_password_resets_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Password reset tokens for user authentication';

-- ADDITIONAL INDEXES FOR PERFORMANCE
-- These indexes help optimize common queries used by forgot_password.php and reset_password.php

-- Index for cleanup queries (DELETE FROM password_resets WHERE user_id = ?)
CREATE INDEX `idx_user_id_clean` ON `password_resets` (`user_id`);

-- Index for checking token validity
CREATE INDEX `idx_token_hash_valid` ON `password_resets` (`token_hash`, `expires_at`);

-- Index for marking used tokens
CREATE INDEX `idx_used_at` ON `password_resets` (`used_at`);

-- COMMENTS FOR UNDERSTANDING:
-- 1. id: Primary key, auto-increment
-- 2. user_id: Foreign key to users table, ON DELETE CASCADE removes tokens when user is deleted
-- 3. token: Kept for backward compatibility, but should not be used in production
-- 4. token_hash: The secure SHA-256 hash of the actual token (used in production)
-- 5. expires_at: When the token becomes invalid
-- 6. used_at: When the token was used (NULL means not used yet)
-- 7. created_at: When the token was generated

-- SECURITY NOTES:
-- 1. token_hash is unique to prevent duplicate tokens
-- 2. Foreign key constraint ensures referential integrity
-- 3. CASCADE DELETE removes tokens when user is deleted
-- 4. Token lifetime is controlled by expires_at field

-- MAINTENANCE QUERIES (Optional - run periodically)
-- Delete expired tokens (older than 30 days)
-- DELETE FROM password_resets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Clean up used tokens older than 7 days
-- DELETE FROM password_resets WHERE used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY);