-- Adds a per-card chat thread, modeled after ATEM's Chat Box (edit.php +
-- atem/api.php's chat-list/chat-send/chat-edit/chat-unsend, which proxy to
-- atem-api). OKR has no Laravel API layer, so this is plain local mysqli
-- instead: messages, sender identity, and soft-delete (unsend) all live in
-- this one table. Run once against the `odb` database.
CREATE TABLE `okr_chat_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `card_id` int NOT NULL,
  `sender_staff_id` int NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_card` (`card_id`),
  CONSTRAINT `fk_okr_chat_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
