-- Adds a payout audit trail to okr_cards, mirroring ATEM's atems table
-- (payout_remark / who-and-when locked or unlocked), instead of the plain
-- incentive_locked boolean having no history behind it.
-- Run once against the `odb` database.
ALTER TABLE okr_cards
    ADD COLUMN payout_remark TEXT NULL AFTER incentive_locked,
    ADD COLUMN locked_by INT NULL AFTER payout_remark,
    ADD COLUMN locked_at DATETIME NULL AFTER locked_by,
    ADD COLUMN unlocked_by INT NULL AFTER locked_at,
    ADD COLUMN unlocked_at DATETIME NULL AFTER unlocked_by;
