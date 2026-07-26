-- 025_add_category_ledger_group.sql
-- Adds ledger_group column to dc_categories for grouping products in the inventory ledger.
-- Instead of hardcoding a PHP category map, the data itself defines the grouping.
-- Soft Serve + FroYo share ledger_group='Soft Serve' (both are ice cream products).
-- @mysql57-compat: InnoDB, utf8mb4.

ALTER TABLE `dc_categories`
  ADD COLUMN `ledger_group` VARCHAR(50) DEFAULT NULL AFTER `name`,
  ADD KEY `idx_dc_categories_ledger_group` (`ledger_group`);

-- Assign ledger groups matching DC Cafe menu structure
UPDATE `dc_categories` SET `ledger_group` = 'Soft Serve' WHERE `name` IN ('Soft Serve', 'FroYo');
UPDATE `dc_categories` SET `ledger_group` = 'Doughnuts'  WHERE `name` = 'Doughnuts';
UPDATE `dc_categories` SET `ledger_group` = 'Hot Meals'  WHERE `name` = 'Hot Meals';
-- Beverages, Pastries, Add-ons remain NULL (no products currently, or treated standalone)
