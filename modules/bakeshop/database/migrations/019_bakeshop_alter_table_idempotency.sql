-- Bakeshop — ALTER TABLE idempotency hardening
--
-- Wraps the 017 migration's ALTER TABLE ADD COLUMN statements with
-- column-existence checks so the migration can be safely re-run if
-- interrupted mid-file.
--
-- Uses SELECT IF(...) with prepared statements — no stored procedures,
-- no DELIMITER tricks. MySQL 5.7 compatible.

-- bakeshop_deliveries
SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_deliveries' AND COLUMN_NAME='status'),
    'SELECT 1',
    'ALTER TABLE bakeshop_deliveries ADD COLUMN status ENUM(\'draft\',\'posted\',\'voided\') NOT NULL DEFAULT \'draft\' AFTER notes'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_deliveries' AND COLUMN_NAME='version'),
    'SELECT 1',
    'ALTER TABLE bakeshop_deliveries ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_deliveries' AND COLUMN_NAME='document_no'),
    'SELECT 1',
    'ALTER TABLE bakeshop_deliveries ADD COLUMN document_no VARCHAR(50) DEFAULT NULL AFTER version'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_deliveries' AND COLUMN_NAME='voided_at'),
    'SELECT 1',
    'ALTER TABLE bakeshop_deliveries ADD COLUMN voided_at DATETIME DEFAULT NULL AFTER document_no'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_deliveries' AND COLUMN_NAME='voided_by'),
    'SELECT 1',
    'ALTER TABLE bakeshop_deliveries ADD COLUMN voided_by INT UNSIGNED DEFAULT NULL AFTER voided_at'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_deliveries' AND COLUMN_NAME='void_reason'),
    'SELECT 1',
    'ALTER TABLE bakeshop_deliveries ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL AFTER voided_by'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- bakeshop_delivery_items
SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_delivery_items' AND COLUMN_NAME='version'),
    'SELECT 1',
    'ALTER TABLE bakeshop_delivery_items ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER unit_cost'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- bakeshop_production_runs
SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_production_runs' AND COLUMN_NAME='status'),
    'SELECT 1',
    'ALTER TABLE bakeshop_production_runs ADD COLUMN status ENUM(\'draft\',\'released\',\'in_progress\',\'completed\',\'voided\') NOT NULL DEFAULT \'draft\' AFTER notes'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_production_runs' AND COLUMN_NAME='version'),
    'SELECT 1',
    'ALTER TABLE bakeshop_production_runs ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_production_runs' AND COLUMN_NAME='document_no'),
    'SELECT 1',
    'ALTER TABLE bakeshop_production_runs ADD COLUMN document_no VARCHAR(50) DEFAULT NULL AFTER version'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- bakeshop_production_items
SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_production_items' AND COLUMN_NAME='version'),
    'SELECT 1',
    'ALTER TABLE bakeshop_production_items ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER unit_id'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- bakeshop_inventory_adjustments
SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_inventory_adjustments' AND COLUMN_NAME='status'),
    'SELECT 1',
    'ALTER TABLE bakeshop_inventory_adjustments ADD COLUMN status ENUM(\'draft\',\'posted\',\'voided\') NOT NULL DEFAULT \'draft\' AFTER notes'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_inventory_adjustments' AND COLUMN_NAME='version'),
    'SELECT 1',
    'ALTER TABLE bakeshop_inventory_adjustments ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_inventory_adjustments' AND COLUMN_NAME='document_no'),
    'SELECT 1',
    'ALTER TABLE bakeshop_inventory_adjustments ADD COLUMN document_no VARCHAR(50) DEFAULT NULL AFTER version'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_inventory_adjustments' AND COLUMN_NAME='voided_at'),
    'SELECT 1',
    'ALTER TABLE bakeshop_inventory_adjustments ADD COLUMN voided_at DATETIME DEFAULT NULL AFTER document_no'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_inventory_adjustments' AND COLUMN_NAME='voided_by'),
    'SELECT 1',
    'ALTER TABLE bakeshop_inventory_adjustments ADD COLUMN voided_by INT UNSIGNED DEFAULT NULL AFTER voided_at'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_inventory_adjustments' AND COLUMN_NAME='void_reason'),
    'SELECT 1',
    'ALTER TABLE bakeshop_inventory_adjustments ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL AFTER voided_by'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- bakeshop_product_allocations
SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_product_allocations' AND COLUMN_NAME='version'),
    'SELECT 1',
    'ALTER TABLE bakeshop_product_allocations ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER allocated_date'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_product_allocations' AND COLUMN_NAME='voided_at'),
    'SELECT 1',
    'ALTER TABLE bakeshop_product_allocations ADD COLUMN voided_at DATETIME DEFAULT NULL AFTER version'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_product_allocations' AND COLUMN_NAME='voided_by'),
    'SELECT 1',
    'ALTER TABLE bakeshop_product_allocations ADD COLUMN voided_by INT UNSIGNED DEFAULT NULL AFTER voided_at'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bakeshop_product_allocations' AND COLUMN_NAME='void_reason'),
    'SELECT 1',
    'ALTER TABLE bakeshop_product_allocations ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL AFTER voided_by'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill existing deliveries as 'posted'
UPDATE bakeshop_deliveries SET status = 'posted' WHERE status = 'draft';

-- Backfill existing non-voided runs as 'completed'
UPDATE bakeshop_production_runs SET status = 'completed' WHERE status = 'draft' AND voided_at IS NULL;
-- Backfill existing voided runs
UPDATE bakeshop_production_runs SET status = 'voided' WHERE voided_at IS NOT NULL AND status = 'draft';

-- Backfill existing adjustments as 'posted'
UPDATE bakeshop_inventory_adjustments SET status = 'posted' WHERE status = 'draft';
