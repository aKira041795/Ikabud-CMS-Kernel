-- 104: allow a delivery item to carry MORE THAN ONE ledger effect.
--
-- Why: correcting a wrongly dispatched item (wrong product -> correct product) must stay
-- append-only. The durable effect of the original dispatch is REVERSED (status flip + the
-- commissary ledger moved back), and the corrected dispatch is applied as a NEW effect row.
-- `uq_dl_delivery_ledger_effect_item (delivery_item_id)` made that second row impossible, which
-- is why the first cut of the substitution had to DELETE the reversed row - destroying the audit
-- trail of what was applied and reversed.
--
-- Replaced by a NON-UNIQUE index on the same columns, so the per-item lookup
-- (dl_applyPostedDeliveryCommissaryLedger / dl_reversePostedDeliveryCommissaryLedger) keeps its
-- covering access path while allowing history to accumulate.
--
-- Idempotent: guarded by INFORMATION_SCHEMA, the same pattern as 101. Safe to re-run.
-- MySQL 5.7 compatible: no DDL with IF EXISTS/IF NOT EXISTS, no CTEs.

-- ORDER MATTERS: the replacement index is created FIRST. InnoDB refuses to drop the unique key
-- while it is the only index serving the foreign key `fk_dl_dle_item (delivery_item_id)` -
-- measured 2026-11-17: "Cannot drop index 'uq_dl_delivery_ledger_effect_item': needed in a
-- foreign key constraint". Creating the non-unique index first gives the FK another index to use.

-- 1. Replacement index (non-unique, same leading column so the FK keeps a usable index).
SET @dl_dle_idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_delivery_ledger_effects'
       AND index_name = 'idx_dl_delivery_effect_item_status'
);
SET @dl_dle_idx_ddl := IF(
    @dl_dle_idx_exists = 0,
    'ALTER TABLE dl_delivery_ledger_effects ADD INDEX idx_dl_delivery_effect_item_status (delivery_item_id, effect_status)',
    'DO 0'
);
PREPARE dl_dle_idx_st FROM @dl_dle_idx_ddl;
EXECUTE dl_dle_idx_st;
DEALLOCATE PREPARE dl_dle_idx_st;

-- 2. Now the one-row-per-item unique key can go.
SET @dl_dle_uq_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_delivery_ledger_effects'
       AND index_name = 'uq_dl_delivery_ledger_effect_item'
);
SET @dl_dle_uq_ddl := IF(
    @dl_dle_uq_exists > 0,
    'ALTER TABLE dl_delivery_ledger_effects DROP INDEX uq_dl_delivery_ledger_effect_item',
    'DO 0'
);
PREPARE dl_dle_uq_st FROM @dl_dle_uq_ddl;
EXECUTE dl_dle_uq_st;
DEALLOCATE PREPARE dl_dle_uq_st;
