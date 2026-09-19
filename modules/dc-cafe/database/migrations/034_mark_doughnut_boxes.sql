-- 034_mark_doughnut_boxes.sql
-- Mark the "(6pcs)" doughnut boxes as variable box products.
--
-- STRUCTURAL ONLY. This migration deliberately does NOT invent:
--   * the price band (component_min_price / component_max_price)
--   * the standard flavour set (dc_product_components)
--
-- Those are commercial rules for DC Cafe to set. Until a band is configured the
-- UI treats the box as unconstrained (any in-category flavour may be chosen), so
-- nothing is wrongly blocked. Configure bands before roll-out.
--
-- Why `has_stock = 0`:
--   A box is an OFFER, not a stocked SKU. Its components are deducted instead.
--   Leaving has_stock = 1 would deduct the box AND its six doughnuts.
--
-- Not included: `DC MINI DOZEN BOX` (PHP 180.00). Its name does not state a piece
-- count and it may be a fixed pre-packed dozen of "MINI DOUGHNUTS" rather than a
-- mix-and-match box. Confirm with DC Cafe, then set slot_count explicitly.
--
-- @mysql57-compat: plain UPDATE ... JOIN, InnoDB, no window functions.

-- Only touches products that look like a 6-piece box and are not already
-- configured, so re-running is safe.
UPDATE `dc_products` p
JOIN `dc_categories` c ON c.category_id = p.category_id
SET p.`slot_count` = 6,
    p.`has_stock` = 0,
    p.`component_category_id` = c.category_id
WHERE c.`name` = 'Doughnuts'
  AND p.`name` LIKE '%(6pcs)%'
  AND p.`slot_count` IS NULL;
