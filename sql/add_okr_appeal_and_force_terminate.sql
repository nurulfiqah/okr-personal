-- Adds the Suspend > Appeal > Active/Force Terminate workflow:
-- - okr_cards gets a single-pending-appeal pair (appeal_justification,
--   appealed_at), cleared whenever the CEO/admin resolves it via unsuspend
--   or force-terminate. Full history of past appeals stays visible via
--   okr_audit_logs even after these are cleared for the next cycle.
-- - okr_statuses gets a new "Force Terminated" row, reachable only from
--   Suspended (same system-managed treatment as Suspended itself - never
--   offered in the normal Timeline status dropdown).
-- Run once against the `odb` database.
ALTER TABLE `okr_cards` ADD COLUMN `appeal_justification` TEXT NULL AFTER `remarks`;
ALTER TABLE `okr_cards` ADD COLUMN `appealed_at` DATETIME NULL AFTER `appeal_justification`;

INSERT INTO `okr_statuses` (`value`, `description`, `pays_incentive`, `sort_order`, `recycle`)
VALUES ('Force Terminated', 'OKR forcibly terminated by CEO/admin after a suspension', 0, 9, 0);
