-- Migration 017: Add batch_egg_qty to dl_products
-- batch_egg_qty: how many eggs make up one batch (for egg-based products like cakes).
-- batch_input_qty (from migration 016) remains as the kilo-per-batch field.
ALTER TABLE dl_products
    ADD COLUMN batch_egg_qty DECIMAL(10,3) NULL DEFAULT NULL
        COMMENT 'Number of eggs that make up one batch. NULL = not configured.'
    AFTER batch_input_qty;
