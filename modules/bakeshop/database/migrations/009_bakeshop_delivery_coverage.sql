ALTER TABLE `bakeshop_deliveries`
    ADD COLUMN `coverage_days` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `reference`;
