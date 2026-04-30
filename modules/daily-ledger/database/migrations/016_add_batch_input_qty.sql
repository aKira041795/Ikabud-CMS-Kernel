-- Migration 016: Add batch_input_qty to dl_products
-- batch_input_qty: how many kilos (or eggs) constitute 1 batch of this product.
-- Theoretical yield = FLOOR(input_qty / batch_input_qty) * output_pieces_per_batch
-- NULL means "not set" (falls back to legacy 1-unit-per-batch behaviour).
ALTER TABLE dl_products
    ADD COLUMN batch_input_qty DECIMAL(10,3) NULL DEFAULT NULL
        COMMENT 'Input units (kilo/egg) that make up one batch. NULL = not configured.'
    AFTER output_pieces_per_batch;
