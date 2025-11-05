-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 28, 2025 at 03:27 PM
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
-- Database: `u613260542_tardersclub`
--

-- --------------------------------------------------------

--
-- Table structure for table `email_verification`
--

CREATE TABLE `email_verification` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `code_hash` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_templates`
--

CREATE TABLE `message_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `event` varchar(64) NOT NULL,
  `channel` enum('telegram','email') NOT NULL DEFAULT 'telegram',
  `subject` varchar(120) DEFAULT NULL,
  `body` text NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `message_templates`
--

INSERT INTO `message_templates` (`id`, `event`, `channel`, `subject`, `body`, `active`, `created_at`) VALUES
(1, 'welcome_new_user', 'telegram', NULL, 'Assalamu alaikum {{name}} 👋\nShaikhoology me khush aamdeed. Trading journal set? Agar help chahiye, /help likho.', 1, '2025-10-14 21:15:13');

-- --------------------------------------------------------

--
-- Table structure for table `mtm_enrollments`
--

CREATE TABLE `mtm_enrollments` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `model_id` int(10) UNSIGNED NOT NULL,
  `status` enum('active','completed','dropped') NOT NULL DEFAULT 'active',
  `enrolled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `mtm_enrollments`
--

INSERT INTO `mtm_enrollments` (`id`, `user_id`, `model_id`, `status`, `enrolled_at`, `completed_at`, `approved_at`, `rejected_at`, `requested_at`) VALUES
(1, 1, 1, '', '2025-10-24 14:17:54', NULL, NULL, NULL, '2025-10-24 18:09:32'),
(21, 29, 1, '', '2025-10-24 17:44:03', NULL, NULL, NULL, '2025-10-24 18:38:24');

-- --------------------------------------------------------

--
-- Table structure for table `mtm_models`
--

CREATE TABLE `mtm_models` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `difficulty` enum('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  `status` enum('draft','active','archived') NOT NULL DEFAULT 'draft',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `estimated_days` int(11) NOT NULL DEFAULT 0,
  `banner_color` varchar(16) DEFAULT '#7c3aed',
  `banner_image` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `cover_image_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `mtm_models`
--

INSERT INTO `mtm_models` (`id`, `title`, `description`, `difficulty`, `status`, `display_order`, `estimated_days`, `banner_color`, `banner_image`, `created_by`, `created_at`, `updated_at`, `cover_image_path`) VALUES
(1, 'Disciplined Trader Program', 'Structured journey: Basic → Intermediate → Advanced', 'easy', 'active', 0, 0, '#7c3aed', NULL, NULL, '2025-10-24 10:23:26', '2025-10-24 13:23:32', 'uploads/mtm/cover_1_1761311442.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `mtm_tasks`
--

CREATE TABLE `mtm_tasks` (
  `id` int(10) UNSIGNED NOT NULL,
  `model_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `level` enum('easy','moderate','hard') NOT NULL DEFAULT 'easy',
  `rule_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`rule_json`)),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `mtm_tasks`
--

INSERT INTO `mtm_tasks` (`id`, `model_id`, `title`, `description`, `level`, `rule_json`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'First trade with sl', 'SL Habit formation', 'easy', '{\"mode\":\"paper\",\"min_trades\":1,\"time_window_days\":0,\"require_sl\":1,\"require_analysis_link\":1}', 0, '2025-10-24 10:30:59', '2025-10-24 13:24:48'),
(2, 1, 'trade with 5% risk', '5%', 'easy', '{\"mode\":\"paper\",\"min_trades\":1,\"time_window_days\":0,\"require_sl\":1,\"require_analysis_link\":1,\"max_risk_pct\":5}', 1, '2025-10-24 11:45:02', '2025-10-24 13:24:48'),
(4, 1, 'test1', '', 'moderate', '{\"mode\":\"both\",\"min_trades\":0,\"time_window_days\":0,\"require_sl\":1,\"require_analysis_link\":1}', 3, '2025-10-24 12:33:03', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `mtm_task_progress`
--

CREATE TABLE `mtm_task_progress` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `model_id` int(10) UNSIGNED NOT NULL,
  `task_id` int(10) UNSIGNED NOT NULL,
  `status` enum('locked','in_progress','completed') NOT NULL DEFAULT 'locked',
  `verified_by` int(10) UNSIGNED DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `evidence_count` int(11) DEFAULT NULL,
  `evidence_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evidence_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token`, `token_hash`, `created_at`, `expires_at`, `used_at`) VALUES
(13, 1, '2c8868b9ffec13871360626484b16b4dbf08596bada6021f9a1d97e942ac6b73', 'a88e965823b1873e0f01c33692d7b11e881406848ce0ffca7e57d4ee59e269ac', '2025-10-02 13:36:09', '2025-10-02 20:06:09', NULL),
(14, 24, '0add708f12b2e4c667c3d58ec5b3593360d77ca48a358df791fbd8bf4b692fbd', 'b94983099b77108dde188f222d796c7214a6729f59f4c280773774c3ab38f5d5', '2025-10-04 11:28:51', '2025-10-04 17:58:51', '2025-10-04 11:52:24');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets_backup`
--

CREATE TABLE `password_resets_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets_backup`
--

INSERT INTO `password_resets_backup` (`id`, `user_id`, `token`, `created_at`, `expires_at`, `used_at`) VALUES
(6, 1, '998d5645b051038e430cd111c1879feb688ba126addf309bd1bb2fc2d425096f', '2025-09-30 18:00:16', '2025-09-30 19:00:16', NULL),
(7, 10, 'abf076f9b1b1fce06b79fa2af22729297eff0657c226b3f187dc99fc40a64fc1', '2025-09-30 18:04:59', '2025-09-30 19:04:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `rules`
--

CREATE TABLE `rules` (
  `id` int(11) NOT NULL,
  `key_name` varchar(50) DEFAULT NULL,
  `value_num` decimal(12,4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rules`
--

INSERT INTO `rules` (`id`, `key_name`, `value_num`) VALUES
(1, 'profit_points', 10.0000),
(2, 'sl_analysis_points', 5.0000),
(3, 'no_sl_penalty', -10.0000),
(4, 'min_rr', 2.0000),
(5, 'rr_bonus', 5.0000),
(6, 'consistency_points', 15.0000);

-- --------------------------------------------------------

--
-- Table structure for table `system_events`
--

CREATE TABLE `system_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `event` varchar(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `channel` varchar(32) NOT NULL,
  `payload` mediumtext DEFAULT NULL,
  `response` mediumtext DEFAULT NULL,
  `status` enum('ok','error','sent','failed') NOT NULL DEFAULT 'ok',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_rules`
--

CREATE TABLE `system_rules` (
  `id` int(10) UNSIGNED NOT NULL,
  `condition_type` enum('login_gap_days','loss_streak') NOT NULL,
  `threshold` int(11) NOT NULL DEFAULT 1,
  `message_event` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trades`
--

CREATE TABLE `trades` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `close_date` date DEFAULT NULL,
  `symbol` varchar(50) DEFAULT NULL,
  `marketcap` enum('Large','Mid','Small') DEFAULT 'Mid',
  `position_percent` decimal(7,4) DEFAULT 0.0000,
  `entry_price` decimal(14,4) DEFAULT NULL,
  `stop_loss` decimal(14,4) DEFAULT NULL,
  `target_price` decimal(14,4) DEFAULT NULL,
  `exit_price` decimal(14,4) DEFAULT NULL,
  `exit_date` date DEFAULT NULL,
  `outcome` varchar(50) DEFAULT 'OPEN',
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `rule_based` tinyint(1) NOT NULL DEFAULT 1,
  `concern` text DEFAULT NULL,
  `pl_percent` decimal(10,4) DEFAULT NULL,
  `pl_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `rr` decimal(10,4) DEFAULT NULL,
  `allocation_amount` decimal(14,2) DEFAULT NULL,
  `qty` decimal(14,4) DEFAULT NULL,
  `points` int(11) DEFAULT 0,
  `analysis_link` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `rule_flag` enum('OK','NOT_RULE_BASED') NOT NULL DEFAULT 'OK',
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `unlock_status` enum('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
  `unlock_approved_at` datetime DEFAULT NULL,
  `unlock_expires` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_reason` varchar(255) DEFAULT NULL,
  `deleted_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `restored_by` varchar(20) DEFAULT NULL,
  `unlock_requested_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `trades`
--

INSERT INTO `trades` (`id`, `user_id`, `entry_date`, `close_date`, `symbol`, `marketcap`, `position_percent`, `entry_price`, `stop_loss`, `target_price`, `exit_price`, `exit_date`, `outcome`, `locked`, `rule_based`, `concern`, `pl_percent`, `pl_amount`, `rr`, `allocation_amount`, `qty`, `points`, `analysis_link`, `notes`, `created_at`, `rule_flag`, `is_locked`, `unlock_status`, `unlock_approved_at`, `unlock_expires`, `deleted_at`, `deleted_by`, `deleted_reason`, `deleted_by_admin`, `is_deleted`, `restored_by`, `unlock_requested_by`) VALUES
(1, 1, '2025-09-28', '2025-09-26', 'RELIANCE', 'Mid', 5.0000, 2400.0000, 2350.0000, 2500.0000, 2450.0000, NULL, 'TARGET HIT', 1, 1, NULL, NULL, 0.00, NULL, 5000.00, NULL, 0, '', '', '2025-09-28 04:01:50', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:56:36', 1, '', 1, 0, NULL, NULL),
(2, 1, '2025-09-28', '2025-09-28', 'MAMATA', 'Mid', 10.0000, 1000.0000, 950.0000, 1100.0000, 1100.0000, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 1000.00, NULL, 0, '', 'testing', '2025-09-28 06:33:26', 'OK', 0, 'none', NULL, NULL, '2025-10-13 22:56:34', 1, '', 1, 0, NULL, NULL),
(3, 1, '2025-09-28', '2025-09-28', 'JWL', 'Mid', 5.0000, 100.0000, 95.0000, 120.0000, 95.0000, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 100.00, NULL, 0, '', 'TEST', '2025-09-28 06:56:07', 'OK', 0, 'pending', NULL, NULL, '2025-10-14 15:52:09', 1, '', 1, 0, NULL, 1),
(4, 1, '2025-09-28', NULL, 'BAJEL', 'Mid', 10.0000, 100.0000, 95.0000, 110.0000, 110.0300, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 100.00, NULL, 0, '', '', '2025-09-28 06:57:52', 'OK', 0, 'pending', NULL, NULL, '2025-10-13 22:56:30', 1, '', 1, 0, NULL, 1),
(5, 1, '2025-09-28', '2025-09-28', 'SSWL', 'Mid', 10.0000, 200.0000, 180.0000, 300.0000, 290.0000, NULL, 'TARGET HIT', 0, 1, NULL, 45.0000, 0.00, 5.0000, 200.00, NULL, 15, NULL, NULL, '2025-09-28 09:34:26', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:56:29', 1, '', 1, 0, NULL, NULL),
(6, 1, '2025-09-28', '2025-09-28', 'BBOX', 'Mid', 10.0000, 150.0000, 140.0000, 170.0000, NULL, NULL, 'OPEN', 0, 1, NULL, -6.6667, 0.00, 2.0000, 150.00, NULL, 20, NULL, NULL, '2025-09-28 09:54:25', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:56:25', 1, '', 1, 0, NULL, 1),
(7, 1, '2025-09-28', '2025-09-28', 'TATAELXSI', 'Mid', 10.0000, 5500.0000, 5000.0000, 7000.0000, 0.0000, NULL, 'SL HIT', 0, 1, NULL, -100.0000, 0.00, 3.0000, 5500.00, NULL, 5, NULL, NULL, '2025-09-28 12:10:15', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:56:22', 1, '', 1, 0, NULL, NULL),
(12, 1, '2025-09-29', '2025-09-29', 'TITAGARH', 'Mid', 10.0000, 830.0000, 800.0000, 1100.0000, 1045.0000, NULL, 'TARGET HIT', 0, 1, NULL, 25.9036, 0.00, 9.0000, 830.00, NULL, 30, NULL, NULL, '2025-09-29 17:40:16', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:56:07', 1, '', 1, 0, NULL, NULL),
(20, 22, '2025-10-03', '2025-10-03', 'RELIANCE', 'Mid', 10.0000, 2400.0000, 2350.0000, 2600.0000, 2600.0000, NULL, 'TARGET HIT', 0, 1, NULL, 8.3333, 0.00, 4.0000, 2400.00, NULL, 15, NULL, NULL, '2025-10-02 20:18:12', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(21, 22, '2025-10-03', '2025-10-03', 'GMDC', 'Mid', 5.0000, 200.0000, 180.0000, 240.0000, 180.0000, NULL, 'SL HIT', 0, 1, NULL, -10.0000, 0.00, 2.0000, 200.00, NULL, 5, NULL, NULL, '2025-10-02 20:37:55', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(23, 24, '2025-10-03', '2025-10-04', 'JAMNAAUTO', 'Mid', 10.0000, 102.0000, 99.0000, 109.0000, 0.0000, NULL, 'SL HIT', 0, 1, NULL, -100.0000, 0.00, 2.3333, 102.00, NULL, 10, 'https://www.tradingview.com/x/P0NwyLej', 'Inside baar breakout', '2025-10-03 14:42:46', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(24, 24, '2025-10-04', '2025-10-04', 'ATGL', 'Mid', 15.0000, 250.0000, 240.0000, 300.0000, 320.0000, NULL, 'TARGET HIT', 0, 1, NULL, 28.0000, 0.00, 5.0000, 250.00, NULL, 15, 'https://www.google.com/search?q=pinterest&rlz=1C1CHBF_enIN1134IN1135&oq=&gs_lcrp=EgZjaHJvbWUqCQgBECMYJxjqAjIJCAAQIxgnGOoCMgkIARAjGCcY6gIyCQgCECMYJxjqAjIJCAMQIxgnGOoCMgkIBBAjGCcY6gIyCQgFECMYJxjqAjIJCAYQIxgnGOoCMgkIBxAjGCcY6gLSAQkxNTIyajBqMTWoAgiwAgHxBUDw2wB-DtIa8QVA8NsAfg7SGg&sourceid=chrome&ie=UTF-8', NULL, '2025-10-04 12:06:25', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(25, 24, '2025-10-04', '2025-10-04', 'JAICORP', 'Mid', 10.0000, 20.0000, 21.0000, 18.0000, 18.0000, NULL, 'TARGET HIT', 0, 1, NULL, -10.0000, 0.00, NULL, 20.00, NULL, 5, 'https://www.google.com/search?q=pinterest&rlz=1C1CHBF_enIN1134IN1135&oq=&gs_lcrp=EgZjaHJvbWUqCQgBECMYJxjqAjIJCAAQIxgnGOoCMgkIARAjGCcY6gIyCQgCECMYJxjqAjIJCAMQIxgnGOoCMgkIBBAjGCcY6gIyCQgFECMYJxjqAjIJCAYQIxgnGOoCMgkIBxAjGCcY6gLSAQkxNTIyajBqMTWoAgiwAgHxBUDw2wB-DtIa8QVA8NsAfg7SGg&sourceid=chrome&ie=UTF-8', NULL, '2025-10-04 12:07:51', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(26, 24, '2025-10-04', '2025-10-04', 'VPRPL', 'Mid', 30.0000, 500.0000, 450.0000, 550.0000, 400.0000, NULL, 'SL HIT', 0, 1, NULL, -20.0000, 0.00, 1.0000, 500.00, NULL, 0, NULL, NULL, '2025-10-04 12:11:36', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(27, 24, '2025-10-04', '2025-10-04', 'AMARAJA', 'Mid', 50.0000, 1000.0000, 900.0000, 1500.0000, 1400.0000, NULL, 'TARGET HIT', 0, 1, NULL, 40.0000, 0.00, 5.0000, 1000.00, NULL, 15, 'https://www.google.com/search?q=pinterest&rlz=1C1CHBF_enIN1134IN1135&oq=&gs_lcrp=EgZjaHJvbWUqCQgBECMYJxjqAjIJCAAQIxgnGOoCMgkIARAjGCcY6gIyCQgCECMYJxjqAjIJCAMQIxgnGOoCMgkIBBAjGCcY6gIyCQgFECMYJxjqAjIJCAYQIxgnGOoCMgkIBxAjGCcY6gLSAQkxNTIyajBqMTWoAgiwAgHxBUDw2wB-DtIa8QVA8NsAfg7SGg&sourceid=chrome&ie=UTF-8', NULL, '2025-10-04 12:12:24', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(28, 24, '2025-10-04', '2025-10-04', 'JAMNA', 'Mid', 100.0000, 200.0000, 190.0000, 250.0000, 250.0000, NULL, 'TARGET HIT', 0, 1, NULL, 25.0000, 0.00, 5.0000, 200.00, NULL, 15, 'https://www.google.com/search?q=pinterest&rlz=1C1CHBF_enIN1134IN1135&oq=&gs_lcrp=EgZjaHJvbWUqCQgBECMYJxjqAjIJCAAQIxgnGOoCMgkIARAjGCcY6gIyCQgCECMYJxjqAjIJCAMQIxgnGOoCMgkIBBAjGCcY6gIyCQgFECMYJxjqAjIJCAYQIxgnGOoCMgkIBxAjGCcY6gLSAQkxNTIyajBqMTWoAgiwAgHxBUDw2wB-DtIa8QVA8NsAfg7SGg&sourceid=chrome&ie=UTF-8', NULL, '2025-10-04 12:14:19', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(29, 24, '2025-10-04', NULL, 'BAMS', 'Mid', 100.0000, 8879.0000, 8800.0000, 8999.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 8879.00, NULL, 0, 'https://www.google.com/search?q=pinterest&rlz=1C1CHBF_enIN1134IN1135&oq=&gs_lcrp=EgZjaHJvbWUqCQgBECMYJxjqAjIJCAAQIxgnGOoCMgkIARAjGCcY6gIyCQgCECMYJxjqAjIJCAMQIxgnGOoCMgkIBBAjGCcY6gIyCQgFECMYJxjqAjIJCAYQIxgnGOoCMgkIBxAjGCcY6gLSAQkxNTIyajBqMTWoAgiwAgHxBUDw2wB-DtIa8QVA8NsAfg7SGg&sourceid=chrome&ie=UTF-8', '', '2025-10-04 12:18:41', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(30, 24, '2025-10-04', NULL, 'GOLDIAM', 'Mid', 50.0000, 100.0000, 95.0000, 110.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 100.00, NULL, 0, 'https://www.google.com/search?q=pinterest&rlz=1C1CHBF_enIN1134IN1135&oq=&gs_lcrp=EgZjaHJvbWUqCQgBECMYJxjqAjIJCAAQIxgnGOoCMgkIARAjGCcY6gIyCQgCECMYJxjqAjIJCAMQIxgnGOoCMgkIBBAjGCcY6gIyCQgFECMYJxjqAjIJCAYQIxgnGOoCMgkIBxAjGCcY6gLSAQkxNTIyajBqMTWoAgiwAgHxBUDw2wB-DtIa8QVA8NsAfg7SGg&sourceid=chrome&ie=UTF-8', '', '2025-10-04 12:20:02', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(31, 25, '2025-10-05', '2025-10-05', 'PCJEWELLER', 'Mid', 15.0000, 12.3600, 12.2000, 12.9800, NULL, NULL, 'OPEN', 0, 1, NULL, 5.7443, 0.00, 3.8750, 12.36, NULL, 15, 'https://www.tradingview.com/x/kuAe0Gxh', 'DD7 with location support WDZ6', '2025-10-05 17:25:40', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(32, 23, '2025-10-06', '2025-10-06', 'TIINDIA', 'Mid', 20.0000, 3046.0000, 3000.0000, 3390.0000, 3392.0000, NULL, 'TARGET HIT', 0, 1, NULL, 11.3592, 0.00, 7.4783, 3046.00, NULL, 15, 'https://www.tradingview.com/x/Y5JVKsOK', 'W pattern', '2025-10-05 20:50:44', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(33, 23, '2025-10-07', NULL, 'DENTA', 'Mid', 20.0000, 457.9500, 447.1000, 600.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 457.95, NULL, 0, 'https://www.tradingview.com/x/zgojYIRy', 'Chart pattern. Ascending triangle.', '2025-10-07 12:37:29', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(34, 26, '2025-10-07', '2025-10-07', 'SIKKO', 'Mid', 3.5000, 87.0000, 82.0000, 99.0000, 99.0000, NULL, 'TARGET HIT', 0, 1, NULL, 13.7931, 0.00, 2.4000, 87.00, NULL, 15, NULL, NULL, '2025-10-07 14:09:42', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(35, 30, '2025-10-10', '2025-10-10', 'TEST 1', 'Mid', 10.0000, 100.0000, 95.0000, 110.0000, 0.0000, NULL, 'SL HIT', 0, 1, NULL, -100.0000, 0.00, 2.0000, 100.00, NULL, 10, 'WWW.GOOGLE.COM', 'DDZ-7 & WDZ-7', '2025-10-10 13:25:59', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(36, 30, '2025-10-10', '2025-10-10', 'TEST 2', 'Mid', 10.0000, 100.0000, 90.0000, 110.0000, 110.0000, NULL, 'TARGET HIT', 0, 1, NULL, 10.0000, 0.00, 1.0000, 100.00, NULL, 10, 'WWW.GOOGLE.COM', 'DDZ-7', '2025-10-10 13:27:46', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(37, 30, '2025-10-10', '2025-10-10', 'TEST 3', 'Mid', 10.0000, 100.0000, 98.0000, 115.0000, 115.0000, NULL, 'TARGET HIT', 0, 1, NULL, 15.0000, 0.00, 7.5000, 100.00, NULL, 15, 'WWW.GOOGLE.COM', 'DDZ-7', '2025-10-10 13:28:25', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(38, 30, '2025-10-10', '2025-10-10', 'TEST 4', 'Mid', 10.0000, 100.0000, 95.0000, 110.0000, 125.0000, NULL, 'TARGET HIT', 0, 1, NULL, 25.0000, 0.00, 2.0000, 100.00, NULL, 30, 'WWW.GOOGLE.COM', 'DDZ-7', '2025-10-10 13:29:10', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(39, 30, '2025-10-10', '2025-10-10', 'TEST 5', 'Mid', 10.0000, 100.0000, 95.0000, 110.0000, 95.0000, NULL, 'SL HIT', 0, 1, NULL, -5.0000, 0.00, 2.0000, 100.00, NULL, 10, 'WWW.GOOGLE.COM', 'DDZ-7', '2025-10-10 13:36:53', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(40, 30, '2025-10-10', '2025-10-10', 'TEST 7', 'Mid', 10.0000, 100.0000, 95.0000, 150.0000, 150.0000, NULL, 'TARGET HIT', 0, 1, NULL, 50.0000, 0.00, 10.0000, 100.00, NULL, 15, NULL, NULL, '2025-10-10 14:08:15', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(41, 30, '2025-10-10', '2025-10-10', 'CLEAN', 'Mid', 10.0000, 100.0000, 75.0000, 150.0000, 160.0000, NULL, 'TARGET HIT', 0, 1, NULL, 60.0000, 0.00, 2.0000, 100.00, NULL, 15, 'https://www.tradingview.com/x/YiSJOh1F/', 'Excellant volume', '2025-10-10 14:12:20', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(42, 25, '2025-10-11', '2025-10-11', 'ALKYLAMINE', 'Mid', 24.0000, 1964.6800, 1907.1000, 2142.8500, NULL, NULL, 'OPEN', 0, 1, NULL, -99.9491, 0.00, 3.0943, 1964.68, NULL, 10, 'https://www.tradingview.com/x/5eY3KgtW/', 'DDZ-07 with location WDZ-07 support', '2025-10-11 15:30:39', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(43, 25, '2025-10-11', '2025-10-11', 'JAIBALAJI', 'Mid', 10.0000, 103.1500, 99.4400, 110.0200, NULL, NULL, 'OPEN', 0, 1, NULL, -5.3320, 0.00, 1.8518, 103.15, NULL, 5, 'https://www.tradingview.com/x/20aG0315/', 'DDZ-07 WITH LOCATION WDZ-06', '2025-10-11 15:39:46', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(44, 31, '2025-10-11', NULL, 'NEULANDLAB', 'Mid', 5.0000, 13595.0000, 12900.0000, 14987.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 13595.00, NULL, 0, '', 'Bullish Stock, given breakout', '2025-10-11 16:31:48', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(45, 29, '2025-10-11', '2025-10-11', 'TTML', 'Mid', 5.0000, 100.0000, 90.0000, 110.0000, 110.0000, NULL, 'TARGET HIT', 0, 1, NULL, 10.0000, 0.00, 1.0000, 100.00, NULL, 10, NULL, NULL, '2025-10-11 18:01:10', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 23:03:33', 1, '', 1, 0, NULL, NULL),
(46, 29, '2025-10-11', '2025-10-11', 'TTML', 'Mid', 5.0000, 100.0000, 90.0000, 110.0000, 85.0000, NULL, 'SL HIT', 0, 1, NULL, -15.0000, 0.00, 1.0000, 100.00, NULL, 0, NULL, NULL, '2025-10-11 18:05:37', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 23:03:31', 1, '', 1, 0, NULL, NULL),
(47, 29, '2025-10-11', '2025-10-11', 'TTML', 'Mid', 100.0000, 100.0000, 90.0000, 110.0000, 105.0000, NULL, 'TARGET HIT', 0, 1, NULL, 5.0000, 0.00, 1.0000, 100.00, NULL, 10, NULL, NULL, '2025-10-11 18:11:08', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 23:03:30', 1, '', 1, 0, NULL, NULL),
(49, 1, '2025-10-13', NULL, 'TTT', 'Mid', 5.0000, 2.0000, 1.0000, 4.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 2.00, NULL, 0, '', '', '2025-10-12 22:11:15', 'OK', 0, 'none', NULL, NULL, '2025-10-13 22:55:30', 1, '', 1, 0, NULL, NULL),
(51, 1, '2025-10-14', '2025-10-14', 'SHANDAR', 'Mid', 10.0000, 100.0000, 99.0000, 105.0000, 105.0000, NULL, 'TARGET HIT', 0, 1, NULL, 5.0000, 0.00, 5.0000, 100.00, NULL, 15, NULL, NULL, '2025-10-13 20:56:19', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:55:27', 1, '', 1, 0, NULL, 1),
(52, 1, '2025-10-14', NULL, 'TEST5', 'Mid', 10.0000, 1000.0000, 900.0000, 1100.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, 1000.00, NULL, 0, '', '', '2025-10-13 20:59:51', 'OK', 0, 'none', NULL, NULL, '2025-10-13 21:13:41', 1, '', 1, 0, NULL, NULL),
(53, 1, '2025-10-14', '2025-10-14', 'FINAL', 'Mid', 100.0000, 260.0000, 250.0000, 300.0000, 301.0000, NULL, 'TARGET HIT', 0, 1, NULL, 15.7692, 0.00, 4.0000, 260.00, NULL, 30, NULL, NULL, '2025-10-13 21:06:20', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:55:25', 1, '', 1, 0, NULL, 1),
(54, 1, '2025-10-14', '2025-10-14', 'TCT', 'Mid', 10.0000, 1000.0000, 950.0000, 1200.0000, 900.0000, NULL, 'SL HIT', 0, 1, NULL, -10.0000, 0.00, 4.0000, 1000.00, NULL, 5, NULL, NULL, '2025-10-13 21:25:06', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:55:24', 1, '', 1, 0, NULL, 1),
(55, 1, '2025-10-14', '2025-10-14', 'R1', 'Mid', 11.0000, 111.0000, 110.0000, 115.0000, 0.0000, NULL, 'SL HIT', 0, 1, NULL, -100.0000, 0.00, 4.0000, 111.00, NULL, 5, NULL, NULL, '2025-10-13 22:53:42', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, '2025-10-13 22:55:22', 1, '', 1, 0, NULL, 1),
(56, 29, '2025-10-14', '2025-10-14', 'TRE', 'Mid', 10.0000, 100.0000, 90.0000, 120.0000, 120.0000, NULL, 'TARGET HIT', 0, 1, NULL, 20.0000, 0.00, 2.0000, 100.00, NULL, 15, NULL, NULL, '2025-10-13 23:02:48', 'OK', 0, 'approved', '2025-10-14 17:18:32', NULL, NULL, NULL, NULL, 0, 0, NULL, 29),
(57, 1, '2025-10-14', '2025-10-14', 'RELIANCE', 'Mid', 10.0000, 100.0000, 90.0000, 120.0000, 105.0000, NULL, 'MANUAL CLOSE', 0, 1, NULL, 5.0000, 0.00, 2.0000, 100.00, NULL, 15, NULL, NULL, '2025-10-14 13:51:24', 'OK', 0, 'rejected', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, 1),
(58, 1, '2025-10-14', '2025-10-14', 'RELIANCE', 'Mid', 10.0000, 100.0000, 90.0000, 120.0000, 120.0000, NULL, 'TARGET HIT', 0, 1, NULL, 20.0000, 0.00, 2.0000, 100.00, NULL, 15, NULL, NULL, '2025-10-14 13:52:16', 'OK', 0, 'approved', '2025-10-14 17:26:01', NULL, NULL, NULL, NULL, 0, 0, NULL, 1),
(59, 1, '2025-10-14', '2025-10-14', 'BBOX', 'Mid', 10.0000, 150.0000, 140.0000, 170.0000, 180.0000, NULL, 'TARGET HIT', 0, 1, NULL, 20.0000, 0.00, 2.0000, 150.00, NULL, 30, NULL, NULL, '2025-10-14 15:00:18', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, NULL, NULL, NULL, 0, 0, NULL, 1),
(60, 1, '2025-10-14', '2025-10-14', 'TTML', 'Mid', 10.0000, 150.0000, 140.0000, 170.0000, 280.0000, NULL, 'TARGET HIT', 0, 1, NULL, 86.6667, 0.00, 2.0000, 150.00, NULL, 15, NULL, NULL, '2025-10-14 15:04:58', 'OK', 0, 'approved', '2025-10-14 16:22:54', NULL, NULL, NULL, NULL, 0, 0, NULL, 1),
(61, 1, '2025-10-14', '2025-10-14', 'WAIT', 'Mid', 10.0000, 100.0000, 95.0000, 120.0000, 110.0000, NULL, 'TARGET HIT', 0, 1, NULL, 10.0000, 0.00, 4.0000, 0.00, NULL, 30, NULL, NULL, '2025-10-14 15:56:00', 'OK', 0, 'approved', '2025-10-17 05:26:53', NULL, NULL, NULL, NULL, 0, 0, NULL, 1),
(62, 1, '2025-10-15', NULL, 'MAMATA', 'Mid', 33.0000, 150.0000, 140.0000, 170.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, NULL, NULL, 0, '', '', '2025-10-14 18:31:59', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(63, 1, '2025-10-15', '2025-10-15', 'GAIL', 'Mid', 10.0000, 100.0000, 90.0000, 120.0000, 120.0000, NULL, 'TARGET HIT', 0, 1, NULL, 20.0000, 0.00, 2.0000, NULL, NULL, 15, NULL, NULL, '2025-10-14 20:47:10', 'OK', 0, 'approved', '2025-10-17 05:19:25', NULL, NULL, NULL, NULL, 0, 0, NULL, 1),
(64, 30, '2025-10-18', '2025-10-18', 'CLEAN', 'Mid', 10.0000, 100.0000, 95.0000, 110.0000, 115.0000, NULL, 'TARGET HIT', 0, 1, NULL, 15.0000, 0.00, 2.0000, NULL, NULL, 30, 'https://www.tradingview.com/x/SoQjP1jB/', 'test', '2025-10-18 08:57:41', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(65, 26, '2025-10-23', NULL, 'VOLTAS', 'Mid', 10.0000, 1406.0000, 1420.0000, 1530.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, NULL, NULL, 0, '', '', '2025-10-23 10:39:17', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(66, 26, '2025-10-23', NULL, 'UNOMINDA', 'Mid', 10.0000, 1195.0000, 1185.0000, 1380.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, NULL, NULL, 0, '', '', '2025-10-23 10:40:14', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL),
(67, 26, '2025-10-23', NULL, 'SANDHAR', 'Mid', 20.0000, 478.0000, 510.0000, 600.0000, NULL, NULL, 'OPEN', 0, 1, NULL, NULL, 0.00, NULL, NULL, NULL, 0, '', '', '2025-10-23 10:42:42', 'OK', 0, 'none', NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `trade_concerns`
--

CREATE TABLE `trade_concerns` (
  `id` int(11) NOT NULL,
  `trade_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `details` text NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `handled_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `resolved` enum('no','yes') NOT NULL DEFAULT 'no',
  `resolved_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `trade_concerns`
--

INSERT INTO `trade_concerns` (`id`, `trade_id`, `user_id`, `reason`, `details`, `message`, `status`, `handled_by`, `created_at`, `resolved_at`, `updated_at`, `resolved`, `resolved_by`) VALUES
(1, 1, 1, 'Mistyped exit/values', 'galti se', NULL, 'resolved', NULL, '2025-09-28 06:08:38', '2025-09-28 14:31:41', NULL, 'no', NULL),
(2, 1, 1, 'Mistyped exit/values', 'galti hui', NULL, 'resolved', NULL, '2025-09-28 06:09:01', '2025-09-28 14:31:46', NULL, 'no', NULL),
(3, 5, 1, 'Broker/platform issue', 'na', NULL, 'resolved', NULL, '2025-09-28 14:31:21', '2025-09-28 14:32:14', NULL, 'no', NULL),
(4, 1, 1, 'Exited by mistake', 'ok', NULL, 'resolved', NULL, '2025-09-28 14:32:36', '2025-09-28 14:32:49', NULL, 'no', NULL),
(5, 5, 1, 'Mistyped exit/values', 'mw', NULL, 'resolved', NULL, '2025-09-28 14:37:24', '2025-09-28 14:37:38', NULL, 'no', NULL),
(6, 5, 1, 'Exited by mistake', 'g', NULL, 'resolved', NULL, '2025-09-28 14:40:50', '2025-09-28 14:40:57', NULL, 'no', NULL),
(7, 7, 1, 'Exited by mistake', 'h', NULL, 'resolved', NULL, '2025-09-28 14:47:48', '2025-09-28 14:48:04', NULL, 'no', NULL),
(8, 7, 1, 'Exited by mistake', 'ji', NULL, 'resolved', NULL, '2025-09-28 14:48:17', '2025-10-13 15:40:53', NULL, 'yes', 1),
(9, 5, 1, 'Exited by mistake', 'hu', NULL, 'resolved', NULL, '2025-09-28 14:51:28', '2025-10-07 01:23:03', NULL, 'no', NULL),
(12, 31, 25, 'Other', 'Position used 30% but populated 15% by mistake.', NULL, 'resolved', NULL, '2025-10-05 17:40:18', '2025-10-07 01:22:52', NULL, 'no', NULL),
(13, 31, 25, 'Other', 'Position wrongly populated need to update', NULL, '', NULL, '2025-10-07 15:49:35', '2025-10-13 13:04:56', NULL, 'yes', 1),
(14, 35, 30, 'Exited by mistake', 'Waha par Edit option tha. Hame hamara buying remark DDZ-7 se DDZ-7 & WDZ-7 update karna tha. Hamne wo update kiye aur sumbit kiye to automatic wo trade 00 price par close ho gaya. Admin se darkhast hai ke us unlock kare.', NULL, 'open', NULL, '2025-10-10 13:42:31', '2025-10-13 05:37:27', NULL, 'yes', 1),
(15, 42, 25, 'Mistyped exit/values', 'Exit value taken as 1 by mistake.', NULL, '', NULL, '2025-10-11 16:09:49', '2025-10-13 05:36:26', NULL, 'yes', 1),
(16, 47, 29, 'Exited by mistake', 'ok', NULL, '', NULL, '2025-10-12 21:55:45', '2025-10-13 05:36:18', NULL, 'yes', 1),
(17, 12, 1, 'Mistyped exit/values', 'ok', NULL, '', NULL, '2025-10-12 22:11:46', '2025-10-13 05:36:55', NULL, 'yes', 1),
(18, 12, 1, 'Exited by mistake', 'ok', NULL, 'open', NULL, '2025-10-13 13:02:57', '2025-10-13 13:03:28', NULL, 'yes', 1),
(19, 12, 1, 'Exited by mistake', 'ok', NULL, '', NULL, '2025-10-13 13:03:50', '2025-10-13 13:04:00', NULL, 'yes', 1),
(20, 1, 1, 'Broker/platform issue', 'k', NULL, '', NULL, '2025-10-13 13:05:46', '2025-10-13 13:22:45', NULL, 'yes', 1),
(21, 5, 1, 'Exited by mistake', 'e', NULL, '', NULL, '2025-10-13 13:16:25', '2025-10-13 13:22:42', NULL, 'yes', 1),
(22, 46, 29, 'Exited by mistake', 'ok', NULL, 'open', NULL, '2025-10-13 14:46:25', '2025-10-13 14:48:09', NULL, 'yes', 1),
(23, 46, 29, 'Exited by mistake', 'ok', NULL, 'open', NULL, '2025-10-13 14:47:50', NULL, NULL, 'no', NULL),
(24, 51, 1, 'ok', '', NULL, 'open', NULL, '2025-10-14 02:34:44', '2025-10-13 21:04:54', NULL, 'yes', 1),
(25, 53, 1, 'test', '', NULL, 'open', NULL, '2025-10-14 02:37:11', '2025-10-13 21:12:00', NULL, 'yes', 1),
(26, 53, 1, 'pl', '', NULL, 'open', NULL, '2025-10-14 02:42:24', '2025-10-13 21:12:31', NULL, 'yes', 1),
(30, 4, 1, 'ok', '', NULL, 'open', NULL, '2025-10-14 03:52:59', NULL, NULL, '', NULL),
(31, 54, 1, 'e', '', NULL, 'open', NULL, '2025-10-14 03:56:58', '2025-10-13 22:27:19', NULL, 'yes', 1),
(32, 6, 1, 'ok', '', NULL, 'open', NULL, '2025-10-14 04:17:24', '2025-10-13 22:54:44', NULL, 'yes', 1),
(34, 55, 1, 'ok', '', NULL, 'open', NULL, '2025-10-14 04:24:18', '2025-10-13 22:54:30', NULL, 'yes', 1),
(35, 55, 1, 'pl', '', NULL, 'open', NULL, '2025-10-14 04:24:39', '2025-10-13 22:54:58', NULL, 'yes', 1),
(37, 56, 29, 'ghu', '', NULL, 'open', NULL, '2025-10-14 04:34:03', '2025-10-14 13:55:36', NULL, 'yes', 1),
(38, 58, 1, 'mmm', '', NULL, 'open', NULL, '2025-10-14 19:23:43', '2025-10-14 13:54:16', NULL, 'yes', 1),
(39, 57, 1, 'ok', '', NULL, 'open', NULL, '2025-10-14 19:25:51', '2025-10-14 13:55:58', NULL, 'yes', 1),
(40, 59, 1, 'ok', '', NULL, 'open', NULL, '2025-10-14 20:31:30', '2025-10-14 15:01:38', NULL, 'yes', 1),
(41, 60, 1, 'ok', '', NULL, 'open', NULL, '2025-10-14 21:13:42', '2025-10-14 15:43:52', NULL, 'yes', 1),
(42, 3, 1, 'ok', '', NULL, 'resolved', NULL, '2025-10-14 21:21:01', '2025-10-14 15:51:28', NULL, '', NULL),
(43, 58, 1, 'Ok', '', NULL, 'open', NULL, '2025-10-14 21:26:33', '2025-10-14 17:26:01', NULL, 'yes', 1),
(44, 56, 29, 'Ok', '', NULL, 'open', NULL, '2025-10-14 22:48:23', '2025-10-14 17:18:32', NULL, 'yes', 1),
(45, 63, 1, 'no', '', NULL, 'open', NULL, '2025-10-17 10:49:08', '2025-10-17 05:19:25', NULL, 'yes', 1),
(46, 61, 1, 'ok', '', NULL, 'open', NULL, '2025-10-17 10:56:47', '2025-10-17 05:26:53', NULL, 'yes', 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `email_token` varchar(64) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'unverified',
  `funds_available` decimal(14,2) DEFAULT 100000.00,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `role` varchar(50) DEFAULT 'user',
  `trading_capital` decimal(15,2) NOT NULL DEFAULT 100000.00,
  `promoted_by` int(11) DEFAULT NULL,
  `promoted_at` datetime DEFAULT NULL,
  `approval_requested_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expires` datetime DEFAULT NULL,
  `verification_attempts` int(11) NOT NULL DEFAULT 0,
  `full_name` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `trading_experience` int(11) DEFAULT NULL,
  `platform_used` varchar(255) DEFAULT NULL,
  `why_join` text DEFAULT NULL,
  `new_field_name` varchar(255) DEFAULT NULL,
  `profile_comments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profile_comments`)),
  `profile_status` varchar(20) NOT NULL DEFAULT 'pending',
  `last_reviewed_by` int(11) DEFAULT NULL,
  `last_reviewed_at` datetime DEFAULT NULL,
  `profile_field_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profile_field_status`)),
  `last_login_at` datetime DEFAULT NULL,
  `user_type` enum('human','system') NOT NULL DEFAULT 'human'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `remember_token`, `email_token`, `email_verified`, `status`, `funds_available`, `is_admin`, `created_at`, `role`, `trading_capital`, `promoted_by`, `promoted_at`, `approval_requested_at`, `rejection_reason`, `updated_at`, `otp_code`, `otp_expires`, `verification_attempts`, `full_name`, `phone`, `country`, `trading_experience`, `platform_used`, `why_join`, `new_field_name`, `profile_comments`, `profile_status`, `last_reviewed_by`, `last_reviewed_at`, `profile_field_status`, `last_login_at`, `user_type`) VALUES
(1, 'Shaikh Saab', 'shaikhoo555@gmail.com', '$2y$10$ucnUcd5OeER0A.SbD1vMPuNIeaEHTe.x1n1FoE.1SZp8J8sSGPVtO', NULL, NULL, 1, 'active', 100000.00, 1, '2025-09-27 21:58:03', 'superadmin', 100000.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, NULL, '2025-10-02 02:16:15', 'human'),
(11, 'ASIM SHAIKH', 'Asimwalton@gmail.com', '$2y$10$jaHQZcqFJvEDc.jRhPTeE.OhP6V.OAM.p5y9pnNJ25cpkXTHKfqRe', NULL, '05c0df493b01759a6572e954631fb06e', 1, 'active', 100000.00, 0, '0000-00-00 00:00:00', 'user', 100000.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, NULL, NULL, 'human'),
(22, 'Shaikhoology Meet', 'Shaikhoologymeetings@gmail.com', '$2y$10$1JzpMGyoX6CWIza3prjip.4g6HzRrdesTSNLEz//RveCjEUiE/mQ2', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-02 22:55:27', 'user', 100000.00, NULL, NULL, NULL, 'Please update the highlighted fields and resubmit.', '2025-10-02 20:04:40', NULL, NULL, 1, 'SK Trader', '918888888888', 'IN', 5, 'zerodha', 'learn and earn', NULL, '{\"full_name\":\"name sahi karo\"}', 'approved', 1, '2025-10-02 20:04:40', '{}', NULL, 'human'),
(23, 'Zaker Shaikh', 'skzkr88@gmail.com', '$2y$10$LNJ.GaGDvAW.ZFHxnrTfZOudio2RnW4dr26h5KAroDTEnbkGdLzCK', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-03 11:40:08', 'user', 100000.00, NULL, NULL, NULL, 'Filhal maine tera account approve nahi kiya just ye feature samjhane ke liye ke agar admin ko data me kuch problem dikhi toh wo users ko update karne bol sakta hai', '2025-10-04 11:40:36', NULL, NULL, 0, 'Zaker Shaikh', '9766886516', 'IN', 1, 'Zerodha', 'Khidmat', NULL, '{\"why_join\":\"Filhal maine tera account approve nahi kiya just ye feature samjhane ke liye ke agar admin ko data me kuch problem dikhi toh wo users ko update karne bol sakta hai\"}', 'approved', 1, '2025-10-04 11:40:36', '{\"full_name\":\"ok\",\"phone\":\"ok\",\"country\":\"ok\",\"trading_experience\":\"ok\",\"platform_used\":\"ok\"}', NULL, 'human'),
(24, 'Mohammad sufiyan', 'sofiyanbagwan20@gmail.com', '$2y$10$kWGxVVhYPDykQlifILRH1.dhix54VlA0zghZE5eiWbJupmO5bxloK', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-03 20:03:59', 'user', 200000.00, NULL, NULL, NULL, 'kuch dikkat aayi kya register karte waqt?', '2025-10-04 11:52:24', NULL, NULL, 0, 'Mohammed Sufiyan', '+917057953171', 'IN', 4, 'Zerodha', 'Improve trading.', NULL, '{\"why_join\":\"Sofiyan bhai kaisa laga intro? Kal msg karo personal me\"}', 'approved', 1, '2025-10-04 10:45:03', '{\"full_name\":\"ok\",\"phone\":\"ok\",\"country\":\"ok\",\"trading_experience\":\"ok\",\"platform_used\":\"ok\"}', NULL, 'human'),
(25, 'Mohd Musaib Anwaar', 'musaibanwaar@gmail.com', '$2y$10$5uhZzLjZzwx9DnvAAH.aqu/uOz6xvEL7d76W44nqslyTI8zJhp8qu', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-04 19:33:56', 'user', 50000.00, NULL, NULL, NULL, 'Mashaallah, Welcome on Board Musaib bhai, group par msg kardo ye msg dekhne ke baad to confirm your registration is done.', '2025-10-05 17:28:27', NULL, NULL, 0, 'Mohd Musaib Anwaar', '+919175599097', 'IN', 1, 'Zerodha', 'I want to join you to enhance my trading expertise and learn valuable skills that will improve my trading journey, so I can share my experience with others who need it the most', NULL, '{\"why_join\":\"Mashaallah, Welcome on Board Musaib bhai, group par msg kardo ye msg dekhne ke baad to confirm your registration is done.\"}', 'approved', 1, '2025-10-05 16:19:16', '{\"full_name\":\"ok\",\"phone\":\"ok\",\"country\":\"ok\",\"trading_experience\":\"ok\",\"platform_used\":\"ok\"}', NULL, 'human'),
(26, 'Amir', 'amiryusuf015@gmail.com', '$2y$10$mc82vVqMlROnuvWtyWji3uZJhNSe3fdK8Dmy6kJJajY8MG0vxsVbC', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-06 00:15:41', 'user', 100000.00, NULL, NULL, NULL, 'Aapka feedback mujhe personal me send kijiyega', '2025-10-07 01:25:03', NULL, NULL, 0, 'Mohammad Amir Yusuf', '8957727720', 'IN', 2, 'Angel One', 'To enhance my Trading Journey', NULL, '{\"why_join\":\"Aapka feedback mujhe personal me send kijiyega\"}', 'approved', 1, '2025-10-07 01:25:03', '{\"full_name\":\"ok\",\"phone\":\"ok\",\"country\":\"ok\",\"trading_experience\":\"ok\",\"platform_used\":\"ok\"}', NULL, 'human'),
(27, 'M.d Faiz', 'faiz313999@gmail.com', '$2y$10$0Y.FSOYwA5FhHWXQ9UpTqOFc4pzsaEZ8sFf8xh.nXtQ/pAlKGADiW', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-06 11:42:01', 'user', 100000.00, NULL, NULL, NULL, 'Please update the highlighted fields and resubmit.', '2025-10-07 01:25:15', NULL, NULL, 0, 'M.d Faiz', '7756965620', 'IN', 4, 'Upstox', '.', NULL, '{\"trading_experience\":\"Trading Ka experience pucha hai, market ka nahi, mazak me likha ye feature samjhane ke liye\"}', 'approved', 1, '2025-10-07 01:25:15', '{\"full_name\":\"ok\",\"phone\":\"ok\",\"country\":\"ok\",\"platform_used\":\"ok\",\"why_join\":\"ok\"}', NULL, 'human'),
(28, 'Shaikh Salman', 'shaikhanyime@gmail.com', '$2y$10$C/PN92RsGUSH/UX0HAfUc.3OptT13J4k7eHEm7olJ7Lcyx/ul.cYS', NULL, NULL, 0, 'rejected', 100000.00, 0, '2025-10-07 00:31:48', 'user', 100000.00, NULL, NULL, NULL, 'Insufficient / invalid details.', '2025-10-08 08:33:00', '643570', '2025-10-08 00:00:32', 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'rejected', 1, '2025-10-08 08:33:00', NULL, NULL, 'human'),
(29, 'Wasim Shaikh', 'SHAIKHOOLOGY@GMAIL.COM', '$2y$10$CnLkUNos4MeecUMA3Z95pOqkcKnmFuIpWX6.FlaqmYdsTKQN5sIyS', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-07 06:41:09', 'user', 100000.00, NULL, NULL, NULL, NULL, '2025-10-07 01:24:27', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'approved', 1, '2025-10-07 01:24:27', NULL, NULL, 'human'),
(30, 'Shaikh Salman', 'shaikhanytime@gmail.com', '$2y$10$zf4qyslSID95UFQ.z/izUeFAkhgOFilRJvMvPUa2tsClyyTik5gnC', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-07 23:53:00', 'user', 110000.00, NULL, NULL, NULL, 'Please update the highlighted fields and resubmit.', '2025-10-10 14:02:17', NULL, NULL, 0, 'Shaikh Salman Shaikh Ismail', '9730883780', 'IN', 3, 'Zerodha', 'To achieve financial freedom.', NULL, '{\"platform_used\":\"Ek bhi trading platform ka naam nahi dala hai yaha\",\"why_join\":\"Samjhme aaya nake kaisa user ko dikhega\"}', 'approved', 1, '2025-10-09 07:27:49', '{\"full_name\":\"ok\",\"phone\":\"ok\",\"country\":\"ok\",\"trading_experience\":\"ok\"}', NULL, 'human'),
(31, 'Nadeem Khan', 'khan4004@gmail.com', '$2y$10$GO5WjSA/EdpVg.hfPKSkW.Ds5JDx16OX0oLJP/J1wdgaLAqgAMQ5e', NULL, NULL, 1, 'active', 100000.00, 0, '2025-10-11 12:58:01', 'user', 100000.00, NULL, NULL, NULL, NULL, '2025-10-11 07:33:27', NULL, NULL, 0, 'Nadeem Khan', '9892291530', 'IN', 6, 'Sharekhan', 'To learn and work in market', NULL, NULL, 'approved', 1, '2025-10-11 07:33:27', NULL, NULL, 'human'),
(32, 'Shaikhoology System', 'bot@shaikhoology.com', '', NULL, NULL, 0, 'unverified', 100000.00, 0, '2025-10-14 21:15:13', 'user', 100000.00, NULL, NULL, NULL, NULL, '2025-10-14 21:15:13', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, NULL, NULL, 'system'),
(33, 'MOHAMMADANIS DAUVA', 'ANISDAUVA31@GMAIL.COM', '$2y$10$lQAm0ppvEo/arIC.uuQTved2o6QoDTshOKsXnOxzfmvqDmctinSSO', NULL, NULL, 1, 'pending', 100000.00, 0, '2025-10-22 11:32:38', 'user', 100000.00, NULL, NULL, NULL, NULL, '2025-10-22 06:06:24', NULL, NULL, 0, 'MOHAMMADANIS SIDDIK DAUVA', '9601976173', 'IN', 2024, 'ANGELONE', 'INCOM ME IZAFA', NULL, NULL, 'pending', NULL, NULL, NULL, NULL, 'human');

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `action` varchar(64) NOT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `email_verification`
--
ALTER TABLE `email_verification`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `used_at` (`used_at`);

--
-- Indexes for table `message_templates`
--
ALTER TABLE `message_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_channel` (`event`,`channel`);

--
-- Indexes for table `mtm_enrollments`
--
ALTER TABLE `mtm_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_model` (`user_id`,`model_id`),
  ADD KEY `idx_model` (`model_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `mtm_models`
--
ALTER TABLE `mtm_models`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_difficulty` (`difficulty`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `mtm_tasks`
--
ALTER TABLE `mtm_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_model` (`model_id`),
  ADD KEY `idx_level` (`level`),
  ADD KEY `idx_sort` (`model_id`,`sort_order`,`id`);

--
-- Indexes for table `mtm_task_progress`
--
ALTER TABLE `mtm_task_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_task` (`user_id`,`task_id`),
  ADD KEY `idx_user_model` (`user_id`,`model_id`),
  ADD KEY `idx_task` (`task_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_prog_model` (`model_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_password_resets_token` (`token`),
  ADD KEY `idx_token_hash` (`token_hash`);

--
-- Indexes for table `rules`
--
ALTER TABLE `rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_name` (`key_name`);

--
-- Indexes for table `system_events`
--
ALTER TABLE `system_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event` (`event_id`);

--
-- Indexes for table `system_rules`
--
ALTER TABLE `system_rules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `trades`
--
ALTER TABLE `trades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trades_user_close` (`user_id`,`close_date`),
  ADD KEY `idx_trades_user_isdel` (`user_id`,`is_deleted`),
  ADD KEY `idx_trades_unlock` (`unlock_status`),
  ADD KEY `idx_trades_closed` (`close_date`),
  ADD KEY `idx_trades_unlock_status` (`unlock_status`),
  ADD KEY `idx_trades_deleted_at` (`deleted_at`),
  ADD KEY `idx_trades_user` (`user_id`),
  ADD KEY `idx_trades_outcome` (`outcome`),
  ADD KEY `idx_trades_deleted` (`deleted_at`),
  ADD KEY `idx_trades_close` (`close_date`),
  ADD KEY `idx_trades_unlock_approved_at` (`unlock_approved_at`),
  ADD KEY `idx_trades_user_outcome_del` (`user_id`,`outcome`,`deleted_at`),
  ADD KEY `idx_trades_allocation` (`user_id`,`allocation_amount`),
  ADD KEY `idx_trades_user_open` (`user_id`,`outcome`),
  ADD KEY `idx_trades_user_outcome` (`user_id`,`outcome`);

--
-- Indexes for table `trade_concerns`
--
ALTER TABLE `trade_concerns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tc_trade` (`trade_id`),
  ADD KEY `idx_tc_user` (`user_id`),
  ADD KEY `idx_tc_resolved` (`resolved`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `ux_users_email` (`email`),
  ADD KEY `idx_email_verified` (`email_verified`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_email_verified` (`email_verified`),
  ADD KEY `idx_users_created_at` (`created_at`),
  ADD KEY `ix_users_status` (`status`),
  ADD KEY `ix_users_email_verified` (`email_verified`);

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_action` (`action`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `email_verification`
--
ALTER TABLE `email_verification`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message_templates`
--
ALTER TABLE `message_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `mtm_enrollments`
--
ALTER TABLE `mtm_enrollments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `mtm_models`
--
ALTER TABLE `mtm_models`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `mtm_tasks`
--
ALTER TABLE `mtm_tasks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `mtm_task_progress`
--
ALTER TABLE `mtm_task_progress`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `system_events`
--
ALTER TABLE `system_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_rules`
--
ALTER TABLE `system_rules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trades`
--
ALTER TABLE `trades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `trade_concerns`
--
ALTER TABLE `trade_concerns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `mtm_enrollments`
--
ALTER TABLE `mtm_enrollments`
  ADD CONSTRAINT `fk_enr_model` FOREIGN KEY (`model_id`) REFERENCES `mtm_models` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `mtm_tasks`
--
ALTER TABLE `mtm_tasks`
  ADD CONSTRAINT `fk_tasks_model` FOREIGN KEY (`model_id`) REFERENCES `mtm_models` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `mtm_task_progress`
--
ALTER TABLE `mtm_task_progress`
  ADD CONSTRAINT `fk_prog_model` FOREIGN KEY (`model_id`) REFERENCES `mtm_models` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prog_task` FOREIGN KEY (`task_id`) REFERENCES `mtm_tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trades`
--
ALTER TABLE `trades`
  ADD CONSTRAINT `trades_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_concerns`
--
ALTER TABLE `trade_concerns`
  ADD CONSTRAINT `fk_tc_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trade_concerns_ibfk_1` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trade_concerns_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
