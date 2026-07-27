-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 14, 2026 at 03:14 AM
-- Server version: 8.4.3
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `odb`
--

-- --------------------------------------------------------

--
-- Table structure for table `okr_audit_logs`
--

CREATE TABLE `okr_audit_logs` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `event` varchar(80) NOT NULL,
  `actor_staff_id` int NOT NULL,
  `changes` text,
  `summary` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `okr_cards`
--

CREATE TABLE `okr_cards` (
  `id` int NOT NULL,
  `objective` text NOT NULL,
  `key_results` text NOT NULL,
  `okr_type` varchar(50) NOT NULL,
  `difficulty_level` tinyint NOT NULL,
  `owner_staff_id` int NOT NULL,
  `owner2_staff_id` int DEFAULT NULL,
  `owner2_purpose` varchar(255) DEFAULT NULL,
  `incentive_rule` tinyint NOT NULL DEFAULT '1',
  `incentivised_owner_staff_id` int DEFAULT NULL,
  `remarks` text,
  `issuer_staff_id` int NOT NULL,
  `dept_scope` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `extended` tinyint(1) NOT NULL DEFAULT '0',
  `extended_date` date DEFAULT NULL,
  `result_status` tinyint NOT NULL DEFAULT '1',
  `incentive_locked` tinyint(1) NOT NULL DEFAULT '0',
  `payout_remark` text,
  `locked_by` int DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `unlocked_by` int DEFAULT NULL,
  `unlocked_at` datetime DEFAULT NULL,
  `closed_by` int DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `okr_card_attachments`
--

CREATE TABLE `okr_card_attachments` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `size` int NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `okr_incentive_rules`
--

CREATE TABLE `okr_incentive_rules` (
  `id` tinyint NOT NULL,
  `code` varchar(20) NOT NULL,
  `label` varchar(255) NOT NULL,
  `payout_logic` varchar(255) NOT NULL,
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_incentive_rules`
--

INSERT INTO `okr_incentive_rules` (`id`, `code`, `label`, `payout_logic`, `recycle`) VALUES
(1, 'RULE1', 'Rule 1 - A1 100% incentivised', 'One owner is incentivised at 100%. With two owners, the issuer picks which one is incentivised.', 0),
(2, 'RULE2', 'Rule 2 - A1 50%, A2 50% incentivised', 'Both owners are incentivised, 50% each. Requires two owners.', 0);

-- --------------------------------------------------------

--
-- Table structure for table `okr_levels`
--

CREATE TABLE `okr_levels` (
  `level` tinyint NOT NULL,
  `label` varchar(50) NOT NULL,
  `rubric_text` text,
  `base_rm` decimal(10,2) NOT NULL DEFAULT '0.00',
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_levels`
--

INSERT INTO `okr_levels` (`level`, `label`, `rubric_text`, `base_rm`, `recycle`) VALUES
(1, 'Level 1 - Easiest', 'Low difficulty & complexity.', 0.00, 0),
(2, 'Level 2 - Medium-low', 'Medium-low difficulty & complexity.', 500.00, 0),
(3, 'Level 3 - Medium-high', 'Medium-high difficulty & complexity.', 1000.00, 0),
(4, 'Level 4 - Highest', 'High difficulty & complexity.', 2000.00, 0);

-- --------------------------------------------------------

--
-- Table structure for table `okr_reference_links`
--

CREATE TABLE `okr_reference_links` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `url` varchar(1000) NOT NULL,
  `added_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `okr_statuses`
--

CREATE TABLE `okr_statuses` (
  `id` tinyint NOT NULL,
  `value` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `pays_incentive` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_statuses`
--

INSERT INTO `okr_statuses` (`id`, `value`, `description`, `pays_incentive`, `sort_order`, `created_at`, `updated_at`, `recycle`) VALUES
(1, 'Active', 'Not yet closed.', 0, 1, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(2, 'Complete', 'Delivered as expected.', 1, 2, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(3, 'Complete with Excellence', 'Delivered beyond expectation.', 1, 3, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(4, 'Extend', 'Timeline extended / still ongoing.', 0, 4, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(5, 'Suspended', 'Halted - CEO only.', 0, 5, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(6, 'Fail', 'Not delivered.', 0, 6, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(7, 'Draft', 'Not yet started.', 0, 0, '2026-07-07 11:42:50', '2026-07-07 11:42:50', 0);

-- --------------------------------------------------------

--
-- Table structure for table `okr_types`
--

CREATE TABLE `okr_types` (
  `id` tinyint NOT NULL,
  `value` varchar(50) NOT NULL,
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_types`
--

INSERT INTO `okr_types` (`id`, `value`, `recycle`) VALUES
(1, 'Committed', 0),
(2, 'Aspiration', 0),
(3, 'Learning', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `okr_audit_logs`
--
ALTER TABLE `okr_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_created` (`card_id`,`created_at`);

--
-- Indexes for table `okr_cards`
--
ALTER TABLE `okr_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner` (`owner_staff_id`),
  ADD KEY `idx_owner2` (`owner2_staff_id`),
  ADD KEY `idx_issuer` (`issuer_staff_id`),
  ADD KEY `idx_status` (`result_status`),
  ADD KEY `fk_okr_cards_level` (`difficulty_level`),
  ADD KEY `fk_okr_cards_incentive_rule` (`incentive_rule`),
  ADD KEY `fk_okr_cards_type` (`okr_type`);

--
-- Indexes for table `okr_card_attachments`
--
ALTER TABLE `okr_card_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

--
-- Indexes for table `okr_incentive_rules`
--
ALTER TABLE `okr_incentive_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_incentive_rules_code` (`code`);

--
-- Indexes for table `okr_levels`
--
ALTER TABLE `okr_levels`
  ADD PRIMARY KEY (`level`);

--
-- Indexes for table `okr_reference_links`
--
ALTER TABLE `okr_reference_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

--
-- Indexes for table `okr_statuses`
--
ALTER TABLE `okr_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_statuses_value` (`value`);

--
-- Indexes for table `okr_types`
--
ALTER TABLE `okr_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_types_value` (`value`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `okr_audit_logs`
--
ALTER TABLE `okr_audit_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `okr_cards`
--
ALTER TABLE `okr_cards`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `okr_card_attachments`
--
ALTER TABLE `okr_card_attachments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `okr_incentive_rules`
--
ALTER TABLE `okr_incentive_rules`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `okr_reference_links`
--
ALTER TABLE `okr_reference_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `okr_statuses`
--
ALTER TABLE `okr_statuses`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `okr_types`
--
ALTER TABLE `okr_types`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `okr_audit_logs`
--
ALTER TABLE `okr_audit_logs`
  ADD CONSTRAINT `fk_okr_audit_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `okr_cards`
--
ALTER TABLE `okr_cards`
  ADD CONSTRAINT `fk_okr_cards_incentive_rule` FOREIGN KEY (`incentive_rule`) REFERENCES `okr_incentive_rules` (`id`),
  ADD CONSTRAINT `fk_okr_cards_level` FOREIGN KEY (`difficulty_level`) REFERENCES `okr_levels` (`level`),
  ADD CONSTRAINT `fk_okr_cards_status` FOREIGN KEY (`result_status`) REFERENCES `okr_statuses` (`id`),
  ADD CONSTRAINT `fk_okr_cards_type` FOREIGN KEY (`okr_type`) REFERENCES `okr_types` (`value`) ON UPDATE CASCADE;

--
-- Constraints for table `okr_card_attachments`
--
ALTER TABLE `okr_card_attachments`
  ADD CONSTRAINT `fk_okr_attachments_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `okr_reference_links`
--
ALTER TABLE `okr_reference_links`
  ADD CONSTRAINT `fk_okr_reflinks_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
