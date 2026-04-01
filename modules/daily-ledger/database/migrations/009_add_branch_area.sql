-- Migration 009: Add area field to dl_branches
-- Allows grouping branches by geographic area (e.g. Dipolog, Rizal, Pagadian)

ALTER TABLE dl_branches
    ADD COLUMN area VARCHAR(100) NULL DEFAULT NULL AFTER address;
