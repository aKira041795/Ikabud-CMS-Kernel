-- Example Notes Module — Initial Schema
-- Table prefix: en_ (example-notes)
--
-- This is a plain SQL migration file.
-- Run: php ikabud migrate example-notes

CREATE TABLE IF NOT EXISTS en_notes (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255)  NOT NULL,
    body        TEXT          NOT NULL DEFAULT '',
    created_by  VARCHAR(100)  NOT NULL DEFAULT '',
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_en_notes_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
