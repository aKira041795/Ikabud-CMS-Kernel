-- 114 — Idempotency and per-return identity for the returned-items inventory.
--
-- A returned item is NOT a stock movement, so it cannot borrow dl_production_movements.client_op_id
-- for retry safety the way a dispatch does: writing a movement row for something that never moved
-- stock would put a quantity into stock-movement reporting that no balance contains. The holding
-- inventory therefore carries its own retry key.
--
-- Without it, a double-tapped form or a retried request would put the same pullout into the rework
-- pile twice, and the PIC would "rework" stock that was never returned twice.
--
-- @mysql57-compat: plain ALTER + unique index; NULLs are allowed (a manual return with no client
-- key), and MySQL permits many NULLs in a unique index, which is what we want.

SET @has_coid := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_returned_items'
       AND column_name = 'client_op_id'
);
SET @sql := IF(@has_coid = 0,
    'ALTER TABLE dl_commissary_returned_items
        ADD COLUMN client_op_id VARCHAR(120) NULL AFTER reference',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_returned_items'
       AND index_name = 'uq_dlri_client_op'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE dl_commissary_returned_items
        ADD UNIQUE KEY `uq_dlri_client_op` (`client_op_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
