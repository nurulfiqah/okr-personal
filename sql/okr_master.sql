-- OKR module - master schema import
-- Combines every table this module owns into a single import, in
-- foreign-key-safe order, so a fresh database can be bootstrapped in one
-- shot. The individual per-table dumps in this directory (okr_cards.sql,
-- okr_key_results.sql, etc.) are kept as-is for reference / selective
-- re-import - this file just compiles the current state of all of them.
--
-- okr_config has no standalone phpMyAdmin dump (it was never exported that
-- way); its schema is inferred from usage in lib.php's okrBackdateEnabled(),
-- admin/backend.php and admin/index.php.
--
-- Not included here: `staff`, `staff_department`, `staff_grade`,
-- `staff_struct` - those belong to the outer odb app, not this module (see
-- okr/CLAUDE.md "Important Constraints"). Every *_staff_id / issuer_staff_id
-- / owner_staff_id / created_by / actor_staff_id / sender_staff_id column
-- below is a plain int reference into staff.id, not an FK - the two schemas
-- are never joined at the database level.
--
-- Retired columns note (see okr/CLAUDE.md "Retired incentive columns"):
-- OKR previously had a full incentive/payout system. It was removed from
-- the app, but the DB was deliberately NOT altered, so okr_cards'
-- difficulty_level/incentive_rule/incentivised_owner_staff_id/
-- incentive_locked/locked_by/locked_at/payout_remark/unlocked_by/
-- unlocked_at, and the whole okr_levels/okr_incentive_rules tables, still
-- exist and still hold historical data - they're kept intact but unused by
-- design, not an oversight. Do not resurrect reads/writes of them without
-- explicit confirmation.
--
-- Order: lookup tables with no FK dependencies first (okr_config,
-- okr_types, okr_levels, okr_incentive_rules, okr_statuses), then
-- okr_cards (which FKs into four of those), then every table that FKs into
-- okr_cards (okr_audit_logs, okr_card_attachments, okr_reference_links,
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
-- Key/value settings table; currently only `backdate_enabled`, written from
-- either this module's own admin/backend.php or atem/admin/backend.php's
-- "Allow Backdated OKRs" toggle (both write the same row).
--

CREATE TABLE `okr_config` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_config`
--
INSERT INTO `okr_config` (`setting_key`, `setting_value`) VALUES
('backdate_enabled', '0');

--
-- Indexes for table `okr_config`
--
ALTER TABLE `okr_config`
  ADD PRIMARY KEY (`setting_key`);

-- --------------------------------------------------------

--
-- Table structure for table `okr_types`
--
-- Committed / Aspiration / Learning, soft-delete via `recycle`.
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
-- Indexes for table `okr_types`
--
ALTER TABLE `okr_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_types_value` (`value`);

--
-- AUTO_INCREMENT for table `okr_types`
--
ALTER TABLE `okr_types`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

-- --------------------------------------------------------

--
-- Table structure for table `okr_levels`
--
-- Retired difficulty-level lookup (see the "Retired columns" note above);
-- kept in the DB, not read/written by this module anymore except
-- createCard's hardcoded FK value of `1`.
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

--
-- Indexes for table `okr_levels`
--
ALTER TABLE `okr_levels`
  ADD PRIMARY KEY (`level`);

-- --------------------------------------------------------

--
-- Table structure for table `okr_incentive_rules`
--
-- Retired incentive-rule lookup (see the "Retired columns" note above);
-- kept in the DB, not read/written by this module anymore.
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

--
-- Indexes for table `okr_incentive_rules`
--
ALTER TABLE `okr_incentive_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_incentive_rules_code` (`code`);

--
-- AUTO_INCREMENT for table `okr_incentive_rules`
--
ALTER TABLE `okr_incentive_rules`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

-- --------------------------------------------------------

--
-- Table structure for table `okr_statuses`
--
-- Lifecycle status lookup, soft-delete via `recycle`. `pays_incentive` is a
-- leftover column from the retired incentive system, unused today.
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

-- ids and values match atem_statuses 100% (id 9 is skipped in both tables -
-- unused in atem_statuses too). Only id/value are mirrored from
-- atem_statuses; description/pays_incentive/sort_order/timestamps keep this
-- table's own convention rather than copying atem_statuses' columns (which
-- don't even exist here - system_action/incentive_treatment). 'Deleted' and
-- 'Force Terminated' are added for id/value parity even though OKR
-- implements both concepts as flag columns instead (okr_cards.deleted_at,
-- okr_cards.force_terminated - see backend.php's deleteCard/
-- forceTerminateCard) - result_status is never actually set to either value
-- by app code. okrTimelineAssignableStatuses() in lib.php excludes both from
-- the Timeline Status dropdown for that reason, same as Suspended/Completed
-- with Extension.
INSERT INTO `okr_statuses` (`id`, `value`, `description`, `pays_incentive`, `sort_order`, `created_at`, `updated_at`, `recycle`) VALUES
(1, 'Draft', 'Not yet started.', 0, 0, '2026-07-07 11:42:50', '2026-07-07 11:42:50', 0),
(2, 'Active', 'Not yet closed.', 0, 1, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(3, 'Completed', 'Delivered as expected.', 1, 2, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(4, 'Completed with Excellence', 'Delivered beyond expectation.', 1, 3, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(5, 'Extended', 'Timeline extended / still ongoing.', 0, 4, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(6, 'Failed', 'Not delivered.', 0, 6, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(7, 'Deleted', 'Soft-deleted by the issuer or admin.', 0, 7, '2026-07-29 00:00:00', '2026-07-29 00:00:00', 0),
(8, 'Suspended', 'Halted - CEO only.', 0, 5, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(10, 'Completed with Extension', 'Delivered with an extended timeline.', 1, 8, '2026-07-07 11:42:50', '2026-07-07 11:42:50', 0),
(11, 'Force Terminated', 'Suspended OKR force-closed by the CEO.', 0, 9, '2026-07-29 00:00:00', '2026-07-29 00:00:00', 0);

--
-- Indexes for table `okr_statuses`
--
ALTER TABLE `okr_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_statuses_value` (`value`);

--
-- AUTO_INCREMENT for table `okr_statuses`
--
ALTER TABLE `okr_statuses`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

-- --------------------------------------------------------

--
-- Table structure for table `okr_cards`
--
-- The core OKR record. `key_results` was removed from the UI (create/edit/
-- view) and is now always written as '' - kept NOT NULL with no default
-- since the column itself wasn't dropped, just retired from use.
-- difficulty_level/incentive_rule/incentivised_owner_staff_id/
-- incentive_locked/payout_remark/locked_by/locked_at/unlocked_by/
-- unlocked_at are the retired incentive columns (see note at the top of
-- this file). appeal_justification/appealed_at back the Appeal Suspension
-- flow; force_terminated flags a Failed card that was force-terminated by
-- the CEO rather than resolved normally; rating/rated_by/rated_at back the
-- 0-5 star Rating widget.
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
-- AUTO_INCREMENT for table `okr_cards`
--
ALTER TABLE `okr_cards`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- Constraints for table `okr_cards`
--
ALTER TABLE `okr_cards`
  ADD CONSTRAINT `fk_okr_cards_incentive_rule` FOREIGN KEY (`incentive_rule`) REFERENCES `okr_incentive_rules` (`id`),
  ADD CONSTRAINT `fk_okr_cards_level` FOREIGN KEY (`difficulty_level`) REFERENCES `okr_levels` (`level`),
  ADD CONSTRAINT `fk_okr_cards_status` FOREIGN KEY (`result_status`) REFERENCES `okr_statuses` (`id`),
  ADD CONSTRAINT `fk_okr_cards_type` FOREIGN KEY (`okr_type`) REFERENCES `okr_types` (`value`) ON UPDATE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `okr_audit_logs`
--
-- Append-only event log per card (status changes, suspend/unsuspend,
-- force-terminate, appeal, rating changes, Key Result CRUD).
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

--
-- Indexes for table `okr_audit_logs`
--
ALTER TABLE `okr_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_created` (`card_id`,`created_at`);

--
-- AUTO_INCREMENT for table `okr_audit_logs`
--
ALTER TABLE `okr_audit_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for table `okr_audit_logs`
--
ALTER TABLE `okr_audit_logs`
  ADD CONSTRAINT `fk_okr_audit_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `okr_card_attachments`
--
-- File attachments per card; `stored_name` is the NAS path (corporate
-- Synology, via nas_config.php), not a local one.
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

--
-- Indexes for table `okr_card_attachments`
--
ALTER TABLE `okr_card_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

--
-- AUTO_INCREMENT for table `okr_card_attachments`
--
ALTER TABLE `okr_card_attachments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for table `okr_card_attachments`
--
ALTER TABLE `okr_card_attachments`
  ADD CONSTRAINT `fk_okr_attachments_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `okr_reference_links`
--
-- Named URL references per card (e.g. the Trello board link) - at least one
-- is required to save a card (create.js/edit.js validation).
--

CREATE TABLE `okr_reference_links` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `url` varchar(1000) NOT NULL,
  `added_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for table `okr_reference_links`
--
ALTER TABLE `okr_reference_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

--
-- AUTO_INCREMENT for table `okr_reference_links`
--
ALTER TABLE `okr_reference_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Constraints for table `okr_reference_links`
--
ALTER TABLE `okr_reference_links`
  ADD CONSTRAINT `fk_okr_reflinks_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `okr_key_results`
--
-- "Key Result Progress" task list: self-referential `parent_id` gives one
-- level of Subtasks under a top-level Key Result. `atem_id` is a bare int
-- reference into the separate `atem` module (no FK - different service).
-- `assignee_staff_id` is retired from the UI (replaced by "Created By"),
-- not read/written by any page/action anymore.
--

CREATE TABLE `okr_key_results` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `description` text NOT NULL,
  `assignee_staff_id` int DEFAULT NULL,
  `atem_id` int DEFAULT NULL,
  `status_id` tinyint NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for table `okr_key_results`
--
ALTER TABLE `okr_key_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`),
  ADD KEY `idx_parent` (`parent_id`);

--
-- AUTO_INCREMENT for table `okr_key_results`
--
ALTER TABLE `okr_key_results`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for table `okr_key_results`
--
ALTER TABLE `okr_key_results`
  ADD CONSTRAINT `fk_okr_kr_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_okr_kr_parent` FOREIGN KEY (`parent_id`) REFERENCES `okr_key_results` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_okr_kr_status` FOREIGN KEY (`status_id`) REFERENCES `okr_statuses` (`id`);

-- --------------------------------------------------------

--
-- Table structure for table `okr_chat_messages`
--
-- Per-card discussion thread (Chat Box on view.php/edit.php). Unsend is a
-- soft delete via `deleted_at` - okrFetchChatMessages always filters
-- deleted_at IS NULL, so an unsent message simply disappears from the UI.
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

--
-- Indexes for table `okr_chat_messages`
--
ALTER TABLE `okr_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`);

--
-- AUTO_INCREMENT for table `okr_chat_messages`
--
ALTER TABLE `okr_chat_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for table `okr_chat_messages`
--
ALTER TABLE `okr_chat_messages`
  ADD CONSTRAINT `fk_okr_chat_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `okr_notifications`
--
-- Backs the notification bell in navbar.php - one row per (staff, card,
-- type), `read_at` set once the recipient has seen it.
--

CREATE TABLE `okr_notifications` (
  `id` int NOT NULL,
  `staff_id` int NOT NULL,
  `card_id` int NOT NULL,
  `type` varchar(50) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for table `okr_notifications`
--
ALTER TABLE `okr_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_staff` (`staff_id`),
  ADD KEY `idx_card` (`card_id`);

--
-- AUTO_INCREMENT for table `okr_notifications`
--
ALTER TABLE `okr_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for table `okr_notifications`
--
ALTER TABLE `okr_notifications`
  ADD CONSTRAINT `fk_okr_notif_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
