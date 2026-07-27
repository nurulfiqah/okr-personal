-- OKR Record & Incentive System schema
-- Normalized to mirror ATEM's DB design (lookup tables, child tables per
-- relationship, JSON-validated audit log) instead of ENUM/JSON-blob columns.
-- Run once against the `odb` database.

CREATE TABLE IF NOT EXISTS okr_levels (
    level TINYINT NOT NULL PRIMARY KEY,
    label VARCHAR(50) NOT NULL,
    rubric_text TEXT NULL,
    base_rm DECIMAL(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO okr_levels (level, label, rubric_text, base_rm) VALUES
    (1, 'Level 1 - Easiest', 'Low difficulty & complexity.', 0.00),
    (2, 'Level 2 - Medium-low', 'Medium-low difficulty & complexity.', 500.00),
    (3, 'Level 3 - Medium-high', 'Medium-high difficulty & complexity.', 1000.00),
    (4, 'Level 4 - Highest', 'High difficulty & complexity.', 2000.00)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Lookup table of the 6 lifecycle statuses (mirrors ATEM's atem_statuses).
-- Only pays_incentive=1 rows are eligible for payout, per framework doc §6/§8.
CREATE TABLE IF NOT EXISTS okr_statuses (
    id TINYINT NOT NULL AUTO_INCREMENT,
    value VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    pays_incentive TINYINT(1) NOT NULL DEFAULT 0,
    sort_order TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_okr_statuses_value (value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO okr_statuses (id, value, description, pays_incentive, sort_order) VALUES
    (1, 'Active',                   'Not yet closed.',           0, 1),
    (2, 'Complete',                 'Delivered as expected.',    1, 2),
    (3, 'Complete with Excellence', 'Delivered beyond expectation.', 1, 3),
    (4, 'Extend',                   'Timeline extended / still ongoing.', 0, 4),
    (5, 'Suspended',                'Halted - CEO only.',        0, 5),
    (6, 'Fail',                     'Not delivered.',            0, 6),
    (7, 'Draft',                    'Not yet started.',          0, 0)
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- Lookup table for the two-owner payout split (framework doc §10, "to
-- confirm" — this is the team's decision: single owner gets 100%, or an even
-- 50/50 split). Mirrors ATEM's incentive_rules table.
CREATE TABLE IF NOT EXISTS okr_incentive_rules (
    id TINYINT NOT NULL AUTO_INCREMENT,
    code VARCHAR(20) NOT NULL,
    label VARCHAR(255) NOT NULL,
    payout_logic VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_okr_incentive_rules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO okr_incentive_rules (id, code, label, payout_logic) VALUES
    (1, 'RULE1', 'Rule 1 - A1 100% incentivised', 'One owner is incentivised at 100%. With two owners, the issuer picks which one is incentivised.'),
    (2, 'RULE2', 'Rule 2 - A1 50%, A2 50% incentivised', 'Both owners are incentivised, 50% each. Requires two owners.')
ON DUPLICATE KEY UPDATE label = VALUES(label);

CREATE TABLE IF NOT EXISTS okr_cards (
    id INT NOT NULL AUTO_INCREMENT,
    objective TEXT NOT NULL,
    key_results TEXT NOT NULL,
    okr_type ENUM('Aspiration','Learning','Committed') NOT NULL,
    difficulty_level TINYINT NOT NULL,
    owner_staff_id INT NOT NULL,
    owner2_staff_id INT NULL,
    owner2_purpose VARCHAR(255) NULL,
    incentive_rule TINYINT NOT NULL DEFAULT 1,
    incentivised_owner_staff_id INT NULL,
    issuer_staff_id INT NOT NULL,
    dept_scope VARCHAR(255) NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    result_status TINYINT NOT NULL DEFAULT 1,
    incentive_locked TINYINT(1) NOT NULL DEFAULT 0,
    closed_by INT NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_owner (owner_staff_id),
    KEY idx_owner2 (owner2_staff_id),
    KEY idx_issuer (issuer_staff_id),
    KEY idx_status (result_status),
    CONSTRAINT fk_okr_cards_level FOREIGN KEY (difficulty_level) REFERENCES okr_levels(level),
    CONSTRAINT fk_okr_cards_status FOREIGN KEY (result_status) REFERENCES okr_statuses(id),
    CONSTRAINT fk_okr_cards_incentive_rule FOREIGN KEY (incentive_rule) REFERENCES okr_incentive_rules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Child table, one row per reference link (mirrors atem_reference_links).
-- At least one row is required per card (Trello link is compulsory, §4).
CREATE TABLE IF NOT EXISTS okr_reference_links (
    id INT NOT NULL AUTO_INCREMENT,
    card_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    added_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card (card_id),
    CONSTRAINT fk_okr_reflinks_card FOREIGN KEY (card_id) REFERENCES okr_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS okr_card_attachments (
    id INT NOT NULL AUTO_INCREMENT,
    card_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    size INT NOT NULL,
    mime_type VARCHAR(100) NULL,
    uploaded_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card (card_id),
    CONSTRAINT fk_okr_attachments_card FOREIGN KEY (card_id) REFERENCES okr_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Immutable audit trail (mirrors atem_audit_logs). `changes` is validated
-- JSON but kept as TEXT (not the native JSON type) for the same reason ATEM
-- does it: flexible payload shape, still guaranteed well-formed.
CREATE TABLE IF NOT EXISTS okr_audit_logs (
    id INT NOT NULL AUTO_INCREMENT,
    card_id INT NOT NULL,
    event VARCHAR(80) NOT NULL,
    actor_staff_id INT NOT NULL,
    changes TEXT NULL,
    summary VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card_created (card_id, created_at),
    CONSTRAINT fk_okr_audit_card FOREIGN KEY (card_id) REFERENCES okr_cards(id) ON DELETE CASCADE,
    CONSTRAINT chk_okr_audit_changes_json CHECK (changes IS NULL OR json_valid(changes))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
