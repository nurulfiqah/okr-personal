-- Adds a plain local in-app notification system ("Octopus notification"),
-- scoped to chat messages only for now (Key Result Progress, ATEM linking,
-- etc. stay email-only). Unlike ATEM's aspirational Laravel-backed
-- notification bell (frontend fully wired, but no real backend endpoint
-- exists in that checkout), this is genuinely implemented since OKR has no
-- API layer to proxy through - a plain table + mysqli is simpler here.
-- Run once against the `odb` database.
CREATE TABLE `okr_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `staff_id` int NOT NULL,
  `card_id` int NOT NULL,
  `type` varchar(50) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_card` (`card_id`),
  CONSTRAINT `fk_okr_notif_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
