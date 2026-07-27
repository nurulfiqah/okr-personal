-- One-time DDL: adds the OKR superadmin flag to the shared `staff` table.
-- Mirrors ATEM's `staff.atem` column (one admin flag per module, named after
-- the module itself).
-- Run once manually; do not put this in create_okr_tables.sql since it
-- alters a table owned by the rest of ODB, not an okr_* table.
--
-- `staff.ap_status` (an unrelated legacy column) has a zero-date default
-- ('0000-00-00'), which strict SQL modes reject on ANY ALTER TABLE against
-- this table, not just columns being touched. Relax sql_mode for this
-- session only so the ALTER succeeds regardless of the server's default mode.
SET @old_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = '';

ALTER TABLE staff ADD COLUMN okr TINYINT(1) NOT NULL DEFAULT 0 AFTER transferpicklist;

SET SESSION sql_mode = @old_sql_mode;
