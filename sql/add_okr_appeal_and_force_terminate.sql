-- Adds the Suspend > Appeal > Active/Force Terminate workflow:
-- - okr_cards gets a single-pending-appeal pair (appeal_justification,
--   appealed_at), cleared whenever the CEO/admin resolves it via unsuspend
--   or force-terminate. Full history of past appeals stays visible via
--   okr_audit_logs even after these are cleared for the next cycle.
-- - Force Terminate does NOT introduce a separate status - it sets
--   result_status to the existing 'Failed' status (a force-terminated OKR
--   is, semantically, a failed one) and flips force_terminated = 1 so the
--   dashboard ranking (and anything else) can still tell a force-terminated
--   Failed apart from an ordinarily-failed one.
-- Run once against the `odb` database.
ALTER TABLE `okr_cards` ADD COLUMN `appeal_justification` TEXT NULL AFTER `remarks`;
ALTER TABLE `okr_cards` ADD COLUMN `appealed_at` DATETIME NULL AFTER `appeal_justification`;
ALTER TABLE `okr_cards` ADD COLUMN `force_terminated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `appealed_at`;
