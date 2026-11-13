-- 100: Correct cake lines that were stamped as Bodega stock.
--
-- Bodega supplies what is NEITHER bread NOR cake. dl_product_categories.grouping
-- is only ('bread','non_bread') and the Cake category is grouped non_bread
-- (a cake is not bread), so every Bodega decision keyed off grouping alone and
-- stamped cake lines 'bodega' from migration 097 until the code exclusion in
-- dl_supplyOriginForProduct() / apiGetIncomingDeliveries().
--
-- supply_origin is a source LABEL, not a quantity or a money value: no balance
-- depends on it. It records a fact that was false at the time, so past rows are
-- corrected rather than left labelled with a source that never supplied them.
-- The predicate is exact and rerun-safe: after this runs no cake row matches.
-- MySQL 5.7 compatible (plain multi-table UPDATE, no CTE/window function).

UPDATE dl_branch_receiving_items ri
INNER JOIN dl_products p
        ON p.id = ri.product_id
LEFT JOIN dl_product_categories c
       ON c.id = p.category_id
SET ri.supply_origin = 'commissary'
WHERE ri.supply_origin = 'bodega'
  AND (c.code = 'cake' OR p.product_category = 'cake');
