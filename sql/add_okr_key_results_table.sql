-- Adds the "Key Result Progress" feature: a 2-level task list per OKR card
-- (Key Result rows FK to okr_cards, Subtask rows FK to their parent Key
-- Result row via a self-referential parent_id), mirroring iidas's
-- project_detail.php Progression Task / Subtask pattern.
-- Run once against the `odb` database.
CREATE TABLE `okr_key_results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `card_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `description` text NOT NULL,
  `assignee_staff_id` int DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `progress_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_card` (`card_id`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `fk_okr_kr_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_okr_kr_parent` FOREIGN KEY (`parent_id`) REFERENCES `okr_key_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
