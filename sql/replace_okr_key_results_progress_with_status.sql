-- Replaces the numeric progress_percent on Key Results/Subtasks with a
-- status_id FK into okr_statuses - the same lookup table the OKR card's own
-- Timeline status uses. Only Active / Completed / Completed with Excellence /
-- Failed are assignable here (see okrKeyResultAssignableStatuses in lib.php).
-- okr_statuses.id is TINYINT, so status_id must match for the FK to attach.
-- Run once against the `odb` database.
ALTER TABLE `okr_key_results` ADD COLUMN `status_id` TINYINT NULL AFTER `atem_id`;

UPDATE `okr_key_results` kr
  JOIN `okr_statuses` os ON os.value = 'Active'
  SET kr.status_id = os.id
  WHERE kr.status_id IS NULL;

ALTER TABLE `okr_key_results` MODIFY `status_id` TINYINT NOT NULL;

ALTER TABLE `okr_key_results`
  ADD CONSTRAINT `fk_okr_kr_status` FOREIGN KEY (`status_id`) REFERENCES `okr_statuses` (`id`);

ALTER TABLE `okr_key_results` DROP COLUMN `progress_percent`;
