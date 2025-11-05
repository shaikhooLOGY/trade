-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 04, 2025 at 08:52 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u613260542_tcmtm`
--

-- --------------------------------------------------------

--
-- Table structure for table `deploy_notes`
--

CREATE TABLE `deploy_notes` (
  `id` int(11) NOT NULL,
  `deploy_date` date NOT NULL,
  `environment` enum('prod','staging','local') NOT NULL DEFAULT 'prod',
  `version_tag` varchar(50) NOT NULL,
  `git_commit` varchar(80) DEFAULT NULL,
  `summary` varchar(255) NOT NULL,
  `files_updated` text DEFAULT NULL,
  `sql_migration` text DEFAULT NULL,
  `checklist_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checklist_json`)),
  `backups` text DEFAULT NULL,
  `known_issues` text DEFAULT NULL,
  `verified_by` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leaderboard`
--

CREATE TABLE `leaderboard` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_trades` int(11) DEFAULT 0,
  `total_pnl` decimal(14,2) DEFAULT 0.00,
  `average_rr` decimal(6,2) DEFAULT 0.00,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mtm_enrollments`
--

CREATE TABLE `mtm_enrollments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `model_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected','dropped','completed') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `joined_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `mtm_enrollments`
--

INSERT INTO `mtm_enrollments` (`id`, `user_id`, `model_id`, `status`, `requested_at`, `approved_at`, `joined_at`, `completed_at`) VALUES
(1, 2, 1, 'pending', '2025-11-03 05:31:56', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `mtm_models`
--

CREATE TABLE `mtm_models` (
  `id` int(11) NOT NULL,
  `title` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','active','paused','archived') NOT NULL DEFAULT 'active',
  `difficulty` enum('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `estimated_days` int(11) NOT NULL DEFAULT 0,
  `cover_image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `mtm_models`
--

INSERT INTO `mtm_models` (`id`, `title`, `description`, `status`, `difficulty`, `display_order`, `estimated_days`, `cover_image_path`, `created_at`) VALUES
(1, 'Disciplined Trader Program', 'Structured journey: Basic → Intermediate → Advanced', 'active', 'easy', 0, 0, 'uploads/mtm/cover_1_1761994343.jpg', '2025-10-29 13:42:33');

-- --------------------------------------------------------

--
-- Table structure for table `mtm_tasks`
--

CREATE TABLE `mtm_tasks` (
  `id` int(11) NOT NULL,
  `model_id` int(11) NOT NULL,
  `title` varchar(190) NOT NULL,
  `level` enum('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `min_trades` int(11) NOT NULL DEFAULT 0,
  `time_window_days` int(11) NOT NULL DEFAULT 0,
  `require_sl` tinyint(1) NOT NULL DEFAULT 0,
  `max_risk_pct` decimal(5,2) DEFAULT NULL,
  `max_position_pct` decimal(5,2) DEFAULT NULL,
  `min_rr` decimal(5,2) DEFAULT NULL,
  `require_analysis_link` tinyint(1) NOT NULL DEFAULT 0,
  `weekly_min_trades` int(11) NOT NULL DEFAULT 0,
  `weeks_consistency` int(11) NOT NULL DEFAULT 0,
  `rule_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rule_json`)),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `mtm_tasks`
--

INSERT INTO `mtm_tasks` (`id`, `model_id`, `title`, `level`, `sort_order`, `min_trades`, `time_window_days`, `require_sl`, `max_risk_pct`, `max_position_pct`, `min_rr`, `require_analysis_link`, `weekly_min_trades`, `weeks_consistency`, `rule_json`, `created_at`) VALUES
(1, 1, 'First trade with SL', 'easy', 0, 1, 2, 1, 2.00, NULL, 1.00, 0, 0, 0, '{\"allowed_outcomes\": [\"TARGET HIT\", \"BE\"]}', '2025-10-29 13:42:33'),
(2, 1, 'Trade with 5% risk', 'easy', 1, 1, 3, 1, 5.00, NULL, 1.00, 0, 0, 0, NULL, '2025-10-29 13:42:33');

-- --------------------------------------------------------

--
-- Table structure for table `mtm_task_progress`
--

CREATE TABLE `mtm_task_progress` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `status` enum('locked','in_progress','passed','failed') NOT NULL DEFAULT 'locked',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_update` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `passed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mtm_tier_labels`
--

CREATE TABLE `mtm_tier_labels` (
  `tier_key` enum('easy','moderate','hard') NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `mtm_tier_labels`
--

INSERT INTO `mtm_tier_labels` (`tier_key`, `display_name`, `updated_at`) VALUES
('easy', 'Tier 1', '2025-11-03 05:32:15'),
('moderate', 'Tier 2', '2025-11-03 05:32:15'),
('hard', 'Tier 3', '2025-11-03 05:32:15');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error') DEFAULT 'info',
  `status` enum('unread','read') DEFAULT 'unread',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trades`
--

CREATE TABLE `trades` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `entry_date` datetime DEFAULT NULL,
  `exit_price` decimal(10,2) DEFAULT NULL,
  `pnl` decimal(12,2) DEFAULT NULL,
  `symbol` varchar(50) NOT NULL,
  `risk_pct` decimal(5,2) DEFAULT NULL,
  `rr` decimal(5,2) DEFAULT NULL,
  `analysis_link` varchar(255) DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  `compliance_status` enum('unknown','pass','fail','override') NOT NULL DEFAULT 'unknown',
  `violation_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`violation_json`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `entry_price` decimal(10,2) DEFAULT 0.00 COMMENT 'Entry price of the trade',
  `pl_percent` decimal(5,2) DEFAULT 0.00 COMMENT 'Profit/Loss percentage',
  `outcome` varchar(50) DEFAULT 'OPEN' COMMENT 'Trade outcome (OPEN/CLOSED/WIN/LOSS)',
  `position_percent` decimal(5,2) DEFAULT NULL COMMENT 'Position size as percentage of total capital',
  `stop_loss` decimal(10,2) DEFAULT NULL COMMENT 'Stop loss price for the trade',
  `target_price` decimal(10,2) DEFAULT NULL COMMENT 'Target price for the trade',
  `allocation_amount` decimal(12,2) DEFAULT NULL COMMENT 'Actual amount allocated for this trade'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `trades`
--

INSERT INTO `trades` (`id`, `user_id`, `entry_date`, `exit_price`, `pnl`, `symbol`, `risk_pct`, `rr`, `analysis_link`, `opened_at`, `closed_at`, `enrollment_id`, `task_id`, `compliance_status`, `violation_json`, `created_at`, `entry_price`, `pl_percent`, `outcome`, `position_percent`, `stop_loss`, `target_price`, `allocation_amount`) VALUES
(3, 2, '2025-10-30 00:00:00', 120.00, 2000.00, 'TTML', NULL, 2.00, NULL, NULL, '2025-11-03 04:12:55', NULL, NULL, 'unknown', NULL, '2025-10-29 19:47:33', 100.00, 20.00, 'TARGET HIT', 10.00, 90.00, 120.00, NULL),
(4, 2, '2025-10-30 00:00:00', 1250.00, 2500.00, 'DOJI', NULL, 2.00, NULL, NULL, '2025-11-03 04:13:30', NULL, NULL, 'unknown', NULL, '2025-10-30 14:18:21', 1000.00, 25.00, 'TARGET HIT', 10.00, 900.00, 1200.00, NULL),
(5, 2, '2025-11-03 00:00:00', 190.00, -1397.73, 'BBOX', NULL, 1.50, NULL, NULL, '2025-11-03 05:29:24', NULL, NULL, 'unknown', NULL, '2025-11-03 04:53:27', 220.00, -13.64, 'SL HIT', 10.00, 200.00, 250.00, NULL),
(6, 2, '2025-11-03 00:00:00', 130.00, 3033.07, 'TEST1', NULL, 10.00, NULL, NULL, '2025-11-03 06:49:10', NULL, NULL, 'unknown', NULL, '2025-11-03 05:30:52', 100.00, 30.00, 'MANUAL CLOSE', 10.00, 95.00, 150.00, NULL),
(8, 2, '2025-11-03 00:00:00', 105.00, 520.68, 'TEST2', NULL, 3.00, NULL, NULL, '2025-11-03 07:20:08', NULL, NULL, 'unknown', NULL, '2025-11-03 07:19:43', 100.00, 5.00, 'TARGET HIT', 10.00, 99.00, 103.00, NULL),
(9, 2, '2025-11-03 00:00:00', NULL, NULL, 'TEST3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'unknown', NULL, '2025-11-03 07:20:56', 100.00, 0.00, 'OPEN', 10.00, 97.00, 110.00, NULL),
(10, 2, '2025-11-03 00:00:00', NULL, NULL, 'JAICORP', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'unknown', NULL, '2025-11-03 07:29:11', 160.00, 0.00, 'OPEN', 25.00, 150.00, 300.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `trade_concerns`
--

CREATE TABLE `trade_concerns` (
  `id` int(11) NOT NULL,
  `trade_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `concern_type` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `username` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `funds_available` decimal(14,2) NOT NULL DEFAULT 100000.00,
  `promoted_by` int(11) DEFAULT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `password_hash` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `profile_status` enum('pending','approved','rejected') DEFAULT 'approved',
  `status` enum('pending','approved','rejected','blocked') NOT NULL DEFAULT 'approved',
  `email_verified` tinyint(1) NOT NULL DEFAULT 1,
  `trading_capital` decimal(12,2) DEFAULT 100000.00 COMMENT 'Available trading capital',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'Last update time'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password`, `funds_available`, `promoted_by`, `role`, `password_hash`, `is_admin`, `created_at`, `profile_status`, `status`, `email_verified`, `trading_capital`, `updated_at`) VALUES
(1, 'Shaikhoology', 'Shaikh Saab', 'shaikhoology@gmail.com', '11223344', 100000.00, NULL, 'admin', '$2y$10$HfXG3l3F2Yq3gk3s6xq9tu3yQ1Y1zA5vYJ1l6f7bq2m7H7k9n2n5e', 1, '2025-10-29 13:42:33', 'approved', 'approved', 1, 100000.00, NULL),
(2, 'Shaikhoo555', '', 'shaikhoo555@gmail.com', '11223344', 68026.41, NULL, 'user', '$2y$10$vccUh70APOXk9tnvEW8qverauKB3zFkKc76uIy2a1XnqbGgSq9VRK', 1, '2025-10-29 17:34:51', 'approved', 'approved', 1, 104656.02, '2025-11-03 07:29:11');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `deploy_notes`
--
ALTER TABLE `deploy_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`deploy_date`),
  ADD KEY `idx_env` (`environment`),
  ADD KEY `idx_version` (`version_tag`);

--
-- Indexes for table `leaderboard`
--
ALTER TABLE `leaderboard`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `mtm_enrollments`
--
ALTER TABLE `mtm_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_model` (`user_id`,`model_id`),
  ADD KEY `fk_en_model` (`model_id`);

--
-- Indexes for table `mtm_models`
--
ALTER TABLE `mtm_models`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mtm_tasks`
--
ALTER TABLE `mtm_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_task_model` (`model_id`);

--
-- Indexes for table `mtm_task_progress`
--
ALTER TABLE `mtm_task_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_en_task` (`enrollment_id`,`task_id`),
  ADD KEY `fk_prog_task` (`task_id`);

--
-- Indexes for table `mtm_tier_labels`
--
ALTER TABLE `mtm_tier_labels`
  ADD PRIMARY KEY (`tier_key`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `trades`
--
ALTER TABLE `trades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_user_outcome` (`user_id`,`outcome`),
  ADD KEY `idx_user_closed` (`user_id`,`closed_at`);

--
-- Indexes for table `trade_concerns`
--
ALTER TABLE `trade_concerns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trade_id` (`trade_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_promoted_by` (`promoted_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `deploy_notes`
--
ALTER TABLE `deploy_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leaderboard`
--
ALTER TABLE `leaderboard`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mtm_enrollments`
--
ALTER TABLE `mtm_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `mtm_models`
--
ALTER TABLE `mtm_models`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `mtm_tasks`
--
ALTER TABLE `mtm_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `mtm_task_progress`
--
ALTER TABLE `mtm_task_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trades`
--
ALTER TABLE `trades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `trade_concerns`
--
ALTER TABLE `trade_concerns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `mtm_enrollments`
--
ALTER TABLE `mtm_enrollments`
  ADD CONSTRAINT `fk_en_model` FOREIGN KEY (`model_id`) REFERENCES `mtm_models` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_en_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mtm_tasks`
--
ALTER TABLE `mtm_tasks`
  ADD CONSTRAINT `fk_task_model` FOREIGN KEY (`model_id`) REFERENCES `mtm_models` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mtm_task_progress`
--
ALTER TABLE `mtm_task_progress`
  ADD CONSTRAINT `fk_prog_en` FOREIGN KEY (`enrollment_id`) REFERENCES `mtm_enrollments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_prog_task` FOREIGN KEY (`task_id`) REFERENCES `mtm_tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trades`
--
ALTER TABLE `trades`
  ADD CONSTRAINT `fk_tr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
