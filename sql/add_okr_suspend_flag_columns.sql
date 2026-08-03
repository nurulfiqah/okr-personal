-- Adds a standalone "currently suspended" signal to okr_cards, decoupled from
-- result_status. Previously, suspendCard overwrote result_status to the
-- 'Suspended' lookup value (and unsuspendCard always restored it to Active,
-- discarding whatever it had been). Going forward, Suspend no longer touches
-- result_status at all - the card keeps showing whatever status it already
-- had (e.g. "Completed"), and is_suspended/suspended_by/suspended_at are the
-- new source of truth for "is this card currently suspended, and by whom".
--
-- closed_by/closed_at (existing columns) are repurposed as a real,
-- independently-editable "Closure Date" field (see backend.php's updateCard)
-- rather than a pure side-effect of a status transition - Suspend now clears
-- them ("writes off" the closure date) instead of stamping them.
ALTER TABLE `okr_cards`
  ADD COLUMN `is_suspended` tinyint(1) NOT NULL DEFAULT '0' AFTER `force_terminated`,
  ADD COLUMN `suspended_by` int DEFAULT NULL AFTER `is_suspended`,
  ADD COLUMN `suspended_at` datetime DEFAULT NULL AFTER `suspended_by`;
