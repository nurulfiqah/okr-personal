-- Key-value settings table for OKR admin toggles (mirrors atem_config).
CREATE TABLE IF NOT EXISTS okr_config (
    setting_key   VARCHAR(64)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO okr_config (setting_key, setting_value)
VALUES ('backdate_enabled', '0');
