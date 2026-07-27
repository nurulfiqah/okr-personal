-- Adds a plain-reference link from a Key Result to an existing card in the
-- real ATEM module. ATEM lives in a separate Laravel service (atem-api), not
-- this database, so this is intentionally a bare int - not an FK - resolved
-- client-side via atem/api.php's list-atems/get-atem actions when displaying.
-- Run once against the `odb` database.
ALTER TABLE `okr_key_results`
  ADD COLUMN `atem_id` INT NULL DEFAULT NULL AFTER `assignee_staff_id`;
