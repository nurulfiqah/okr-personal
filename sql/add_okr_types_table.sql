-- Adds a proper lookup table for OKR Type (previously a hardcoded ENUM +
-- PHP array) and a `recycle` flag on it and the other lookup tables, mirroring
-- the recycle-flag convention used elsewhere in ODB (e.g. staff.recycle).
-- Run once against the `odb` database.
CREATE TABLE IF NOT EXISTS okr_types (
    id TINYINT NOT NULL AUTO_INCREMENT,
    value VARCHAR(50) NOT NULL,
    recycle TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_okr_types_value (value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO okr_types (id, value) VALUES
    (1, 'Committed'),
    (2, 'Aspiration'),
    (3, 'Learning')
ON DUPLICATE KEY UPDATE value = VALUES(value);

ALTER TABLE okr_levels          ADD COLUMN recycle TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE okr_statuses        ADD COLUMN recycle TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE okr_incentive_rules ADD COLUMN recycle TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE okr_cards
    MODIFY COLUMN okr_type VARCHAR(50) NOT NULL,
    ADD CONSTRAINT fk_okr_cards_type FOREIGN KEY (okr_type) REFERENCES okr_types(value) ON UPDATE CASCADE;
