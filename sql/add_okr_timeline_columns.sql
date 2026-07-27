-- Adds Timeline-card support (mirrors ATEM's extension/closure/remarks
-- fields) to okr_cards. One-time migration, run once against the `odb` DB.
ALTER TABLE okr_cards
    ADD COLUMN extended TINYINT(1) NOT NULL DEFAULT 0 AFTER end_date,
    ADD COLUMN extended_date DATE NULL AFTER extended,
    ADD COLUMN remarks TEXT NULL AFTER incentivised_owner_staff_id;
