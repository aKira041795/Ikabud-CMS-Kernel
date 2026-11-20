-- 113 — Inventory of RETURNED ITEMS awaiting rework.
--
-- Owner direction 2026-11-21:
--   "a returned product (pull-out from branches to commis) does not add to the qty of stocks.
--    you cannot redeliver a returned product for reworking. therefore this is an inventory of
--    returned items for reworking which will be identified by PIC as new stocks qty available
--    once done"
--
-- So a return is its own inventory, deliberately OUTSIDE dl_commissary_product_ledger. Nothing in
-- this table is dispatchable: it has no produced/dispatched columns and never feeds the hub's
-- stock balance. It exists to answer three questions the ledger could not:
--
--   what came back, from which branch, on which paper document;
--   what is still waiting to be reworked;
--   what was reworked, into which product, and when — which is the moment new stock qty is created
--   (as produced_qty of the OUTPUT product in the main ledger, the only place stock may grow).
--
-- Status is a small closed set rather than a boolean, because "written off" and "reworked" are
-- different endings and an operator needs to see which: stock that came back and was thrown away
-- must not look like stock that became product.
--
-- @mysql57-compat: InnoDB + utf8mb4 (Bluehost defaults to MyISAM, which silently drops FKs);
-- FK column types match their parents exactly (INT UNSIGNED ↔ INT UNSIGNED is verified at runtime
-- by the module's own migration checks); no JSON, no CTE, no window functions, no CHECK.

CREATE TABLE IF NOT EXISTS `dl_commissary_returned_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `commissary_branch_id` INT UNSIGNED NOT NULL,
    `branch_id` INT UNSIGNED NULL COMMENT 'Branch the item was pulled out from, when known',
    `product_id` INT UNSIGNED NOT NULL,
    `ledger_date` DATE NOT NULL COMMENT 'Business date the item came back',
    `shift` VARCHAR(2) NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `delivery_id` INT UNSIGNED NULL COMMENT 'Inbound pullout delivery, when the return travelled as one',
    `reference` VARCHAR(120) NULL COMMENT 'Paper document: pullout DR / slip',
    `status` ENUM('awaiting_rework','reworked','written_off') NOT NULL DEFAULT 'awaiting_rework',
    `reworked_into_product_id` INT UNSIGNED NULL,
    `reworked_quantity` INT NOT NULL DEFAULT 0,
    `reworked_at` DATETIME NULL,
    `reworked_by` INT UNSIGNED NULL,
    `write_off_reason` VARCHAR(255) NULL,
    `remarks` VARCHAR(255) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dlri_hub_status` (`commissary_branch_id`, `status`, `ledger_date`),
    KEY `idx_dlri_product` (`product_id`),
    KEY `idx_dlri_delivery` (`delivery_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotency for the receive call: one row per (delivery, product) when the return arrived as a
-- delivery, so a retried request cannot double the holding inventory. NULL deliveries are allowed
-- through (a manual return with only a paper reference), hence the coalesce column.
SET @has_uniq := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_commissary_returned_items'
       AND index_name = 'uq_dlri_delivery_product'
);
SET @sql := IF(@has_uniq = 0,
    'ALTER TABLE dl_commissary_returned_items
        ADD UNIQUE KEY `uq_dlri_delivery_product` (`delivery_id`, `product_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
