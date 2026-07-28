-- OKR module - CLEAN master schema (reference only, NOT for import against
-- the live/production database)
--
-- This is what the schema would look like if the retired incentive/payout
-- system had never existed - okr_levels and okr_incentive_rules dropped
-- entirely, and okr_cards' difficulty_level/incentive_rule/
-- incentivised_owner_staff_id/incentive_locked/payout_remark/locked_by/
-- locked_at/unlocked_by/unlocked_at columns removed.
--
-- DO NOT run this against the live database. okr/CLAUDE.md is explicit that
-- those tables/columns were deliberately kept (not an oversight) because
-- existing okr_cards rows still hold real historical incentive data from
-- when the feature was live - dropping them for real would permanently
-- destroy that history and is not reversible. See okr_master.sql for the
-- schema that actually matches the live database today.
--
-- Use this file only as a reference for what a from-scratch OKR schema
-- would look like, or as a starting point if the team later decides (with
-- explicit sign-off) to do a real cleanup migration.
--
-- Order: lookup tables with no FK dependencies first (okr_config,
-- okr_types, okr_statuses), then okr_cards (FKs into okr_types/
-- okr_statuses only now), then every table that FKs into okr_cards
-- (okr_audit_logs, okr_card_attachments, okr_reference_links,
-- okr_key_results, okr_chat_messages, okr_notifications).

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
-- Table structure for table `okr_config`
--

CREATE TABLE `okr_config` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `okr_config` (`setting_key`, `setting_value`) VALUES
('backdate_enabled', '0');

ALTER TABLE `okr_config`
  ADD PRIMARY KEY (`setting_key`);

-- --------------------------------------------------------

--
-- Table structure for table `okr_types`
--

CREATE TABLE `okr_types` (
  `id` tinyint NOT NULL,
  `value` varchar(50) NOT NULL,
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `okr_types` (`id`, `value`, `recycle`) VALUES
(1, 'Committed', 0),
(2, 'Aspiration', 0),
(3, 'Learning', 0);

ALTER TABLE `okr_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_types_value` (`value`);

ALTER TABLE `okr_types`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

-- --------------------------------------------------------

--
-- Table structure for table `okr_statuses`
--
-- `pays_incentive` also dropped here - it only ever meant anything alongside
-- the incentive system.
--

CREATE TABLE `okr_statuses` (
  `id` tinyint NOT NULL,
  `value` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `okr_statuses` (`id`, `value`, `description`, `sort_order`, `created_at`, `updated_at`, `recycle`) VALUES
(1, 'Draft', 'Not yet started.', 0, '2026-07-07 11:42:50', '2026-07-07 11:42:50', 0),
(2, 'Active', 'Not yet closed.', 1, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(3, 'Completed', 'Delivered as expected.', 2, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(4, 'Completed with Excellence', 'Delivered beyond expectation.', 3, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(5, 'Extended', 'Timeline extended / still ongoing.', 4, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(6, 'Failed', 'Not delivered.', 6, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(8, 'Suspended', 'Halted - CEO only.', 5, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(10, 'Completed with Extension', 'Delivered with an extended timeline.', 8, '2026-07-07 11:42:50', '2026-07-07 11:42:50', 0);

ALTER TABLE `okr_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_statuses_value` (`value`);

ALTER TABLE `okr_statuses`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

-- --------------------------------------------------------

--
-- Table structure for table `okr_cards`
--
-- No difficulty_level/incentive_rule/incentivised_owner_staff_id/
-- incentive_locked/payout_remark/locked_by/locked_at/unlocked_by/
-- unlocked_at. `key_results` is also dropped here - it's retired from the
-- UI the same way (always written as '' today) and never read back.
--

CREATE TABLE `okr_cards` (
  `id` int NOT NULL,
  `objective` text NOT NULL,
  `okr_type` varchar(50) NOT NULL,
  `owner_staff_id` int NOT NULL,
  `owner2_staff_id` int DEFAULT NULL,
  `owner2_purpose` varchar(255) DEFAULT NULL,
  `remarks` text,
  `appeal_justification` text,
  `appealed_at` datetime DEFAULT NULL,
  `force_terminated` tinyint(1) NOT NULL DEFAULT '0',
  `rating` decimal(2,1) DEFAULT NULL,
  `rated_by` int DEFAULT NULL,
  `rated_at` datetime DEFAULT NULL,
  `issuer_staff_id` int NOT NULL,
  `dept_scope` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `extended` tinyint(1) NOT NULL DEFAULT '0',
  `extended_date` date DEFAULT NULL,
  `result_status` tinyint NOT NULL DEFAULT '1',
  `closed_by` int DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `okr_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner` (`owner_staff_id`),
  ADD KEY `idx_owner2` (`owner2_staff_id`),
  ADD KEY `idx_issuer` (`issuer_staff_id`),
  ADD KEY `idx_status` (`result_status`),
  ADD KEY `fk_okr_cards_type` (`okr_type`);

ALTER TABLE `okr_cards`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

ALTER TABLE `okr_cards`
  ADD CONSTRAINT `fk_okr_cards_status` FOREIGN KEY (`result_status`) REFERENCES `okr_statuses` (`id`),
  ADD CONSTRAINT `fk_okr_cards_type` FOREIGN KEY (`okr_type`) REFERENCES `okr_types` (`value`) ON UPDATE CASCADE;

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
);

ALTER TABLE `okr_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_created` (`card_id`,`created_at`);

ALTER TABLE `okr_audit_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `okr_audit_logs`
  ADD CONSTRAINT `fk_okr_audit_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

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

ALTER TABLE `okr_card_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

ALTER TABLE `okr_card_attachments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

ALTER TABLE `okr_card_attachments`
  ADD CONSTRAINT `fk_okr_attachments_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

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

ALTER TABLE `okr_reference_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

ALTER TABLE `okr_reference_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

ALTER TABLE `okr_reference_links`
  ADD CONSTRAINT `fk_okr_reflinks_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `okr_key_results`
--
-- `assignee_staff_id` also dropped here - retired from the UI (replaced by
-- "Created By"), never read/written by any page/action.
--

CREATE TABLE `okr_key_results` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `description` text NOT NULL,
  `atem_id` int DEFAULT NULL,
  `status_id` tinyint NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `okr_key_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`),
  ADD KEY `idx_parent` (`parent_id`);

ALTER TABLE `okr_key_results`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `okr_key_results`
  ADD CONSTRAINT `fk_okr_kr_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_okr_kr_parent` FOREIGN KEY (`parent_id`) REFERENCES `okr_key_results` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_okr_kr_status` FOREIGN KEY (`status_id`) REFERENCES `okr_statuses` (`id`);

-- --------------------------------------------------------

--
-- Table structure for table `okr_chat_messages`
--

CREATE TABLE `okr_chat_messages` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `sender_staff_id` int NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `okr_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

ALTER TABLE `okr_chat_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `okr_chat_messages`
  ADD CONSTRAINT `fk_okr_chat_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `okr_notifications`
--

CREATE TABLE `okr_notifications` (
  `id` int NOT NULL,
  `staff_id` int NOT NULL,
  `card_id` int NOT NULL,
  `type` varchar(50) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `okr_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_staff` (`staff_id`),
  ADD KEY `idx_card` (`card_id`);

ALTER TABLE `okr_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `okr_notifications`
  ADD CONSTRAINT `fk_okr_notif_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
