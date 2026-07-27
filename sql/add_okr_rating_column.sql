-- Adds a CEO/admin-only quality rating to okr_cards: 0-5 stars in half-star
-- steps, plus who rated it and when (mirrors the who-and-when pattern used
-- elsewhere in this table, e.g. closed_by/closed_at).
-- Run once against the `odb` database.
ALTER TABLE okr_cards
    ADD COLUMN rating DECIMAL(2,1) NULL AFTER remarks,
    ADD COLUMN rated_by INT NULL AFTER rating,
    ADD COLUMN rated_at DATETIME NULL AFTER rated_by;
