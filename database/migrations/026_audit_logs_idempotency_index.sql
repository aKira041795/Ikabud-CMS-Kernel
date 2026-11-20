-- Migration 026: index the audit idempotency lookup — (module, action, entity_id).
--
-- MEASURED 2026-11-20 (tenant 207, /daily-ledger/admin/dashboard, KERNEL_DB_PROFILE on):
--   SELECT COUNT(*) FROM audit_logs WHERE module = ? AND action = ? AND entity_id = ?
--   17x, 2,574 ms of a 3,286 ms DB budget — one query shape, ~151 ms each, on 37,749 audit rows.
--
-- The shape exists because module code uses it as an IDEMPOTENCY GUARD ("has this exact event
-- already been audited?"), e.g. daily-ledger's per-day auto-close notification, which runs once per
-- branch per pass. Existing indexes are module, created_at, branch_id and actor_user_id, so the
-- lookup filtered on module (a low-cardinality prefix matching most rows) and then read action and
-- entity_id from each row. The guard is correct; the table just had no index for it.
--
-- Composite order matches the equality predicates in that order: high selectivity last, so MySQL can
-- range-scan on module+action and touch only the candidate rows. module is VARCHAR(50) and
-- entity_id VARCHAR(50) — the whole columns fit, so no prefix lengths are needed and the index can
-- answer the query index-only for the existence form (`SELECT 1 ... LIMIT 1`).
--
-- Idempotent by construction, not by contract: MySQL 5.7 has no CREATE INDEX IF NOT EXISTS, so the
-- information_schema check below is the guard. The migration runner also tolerates error 1061
-- (duplicate key name), which covers a host that somehow runs this twice inside one statement batch.
SET @_m026_idx := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'audit_logs'
       AND index_name = 'idx_al_module_action_entity'
);
SET @_m026_sql := IF(
    @_m026_idx = 0,
    'ALTER TABLE `audit_logs` ADD INDEX `idx_al_module_action_entity` (`module`, `action`, `entity_id`)',
    'DO 0'
);
PREPARE _stmt FROM @_m026_sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;
