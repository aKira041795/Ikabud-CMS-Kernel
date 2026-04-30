-- Migration: add file_category and description to gm_attachments
-- Error 1060 (Duplicate column name) is handled as idempotent success by MigrationRunner.
ALTER TABLE gm_attachments
    ADD COLUMN file_category VARCHAR(50) NOT NULL DEFAULT 'other' AFTER file_size;

ALTER TABLE gm_attachments
    ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER file_category;
