-- ============================================================
-- Migration 054: Box/pack-unit support for cashier withdrawals
--
-- Adds OPTIONAL pack metadata so cashier Stock Adjustments can
-- withdraw goods sold per BOX (e.g. crinkles: sold per pc and per
-- box). Behavior-neutral when unused:
--   * dl_products.pcs_per_pack       NULL = product is not sold by
--     box -> existing Pcs-only behavior is unchanged.
--   * dl_cashier_withdrawals.unit    'pcs' (default) | 'box'.
--   * dl_cashier_withdrawals.pack_qty  number of boxes (NULL for pcs).
--
-- quantity on dl_cashier_withdrawals stays the PIECE-equivalent for
-- full backward compatibility with ledger/variance math:
--   unit='pcs'  -> quantity = entered pcs,  pack_qty = NULL
--   unit='box'  -> quantity = boxes * pcs_per_pack, pack_qty = boxes
--
-- The dedup fingerprint (dl_withdrawalDedupHash, migration 052)
-- now also includes `unit` so "2 boxes (24 pcs)" and "24 pcs" are
-- distinct rows. Existing rows keep their hashes (no backfill needed:
-- hashes are only recomputed on the row's own update).
--
-- Idempotent: guarded ADD COLUMN via the SET @sql=IF(...) + PREPARE
-- pattern (MySQL 5.7-safe, no window functions / CTEs).
-- ============================================================

ALTER TABLE dl_products
    ADD COLUMN pcs_per_pack INT UNSIGNED NULL DEFAULT NULL AFTER output_unit_label;

ALTER TABLE dl_cashier_withdrawals
    ADD COLUMN unit VARCHAR(10) NOT NULL DEFAULT 'pcs' AFTER quantity;

ALTER TABLE dl_cashier_withdrawals
    ADD COLUMN pack_qty INT UNSIGNED NULL DEFAULT NULL AFTER unit;
