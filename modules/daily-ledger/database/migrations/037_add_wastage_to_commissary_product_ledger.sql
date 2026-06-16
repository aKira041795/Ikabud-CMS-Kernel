-- Migration 037: Add wastage tracking to commissary product ledger
-- Tracks unsaleable returned goods separately from production.
-- remaining_qty is recomputed as produced_qty - dispatched_qty - wastage_qty

ALTER TABLE `dl_commissary_product_ledger`
    ADD COLUMN `wastage_qty` INT NOT NULL DEFAULT 0 AFTER `dispatched_qty`;

-- Rebuild the computed column to include wastage
ALTER TABLE `dl_commissary_product_ledger`
    DROP COLUMN `remaining_qty`,
    ADD COLUMN `remaining_qty` INT GENERATED ALWAYS AS (produced_qty - dispatched_qty - wastage_qty) STORED AFTER `wastage_qty`;
