-- 035_mark_mini_dozen_box.sql
-- Mark DC MINI DOZEN BOX as a box so it can never be selected as a *component*.
--
-- 034 deliberately skipped this product because its piece count is not stated in
-- the name. That left it unmarked, which made it eligible to be placed inside
-- another box: the component query excludes products with slot_count set, so an
-- unmarked box leaks through as a flavour.
--
-- It is unambiguously a box ("DOZEN BOX"), and marking it is correct under both
-- readings:
--   * mix-and-match -> 12 slots the cashier fills
--   * fixed pre-packed dozen -> a standard set makes it a single-tap add, and the
--     picker only opens when a standard flavour is out of stock
--
-- The price band and standard set remain DC Cafe's to configure.
--
-- @mysql57-compat: plain UPDATE ... JOIN, InnoDB.

UPDATE `dc_products` p
JOIN `dc_categories` c ON c.category_id = p.category_id
SET p.`slot_count` = 12,
    p.`has_stock` = 0,
    p.`component_category_id` = c.category_id
WHERE c.`name` = 'Doughnuts'
  AND p.`name` = 'DC MINI DOZEN BOX'
  AND p.`slot_count` IS NULL;
