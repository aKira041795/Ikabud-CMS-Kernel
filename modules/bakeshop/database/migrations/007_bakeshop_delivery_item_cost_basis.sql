SET @_bakeshop_delivery_item_cost_basis := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_delivery_items'
      AND column_name = 'cost_basis'
);
SET @_bakeshop_delivery_item_cost_basis_sql := IF(
    @_bakeshop_delivery_item_cost_basis = 0,
    "ALTER TABLE bakeshop_delivery_items ADD COLUMN cost_basis ENUM('receipt','price_list','manual') NULL AFTER unit_cost",
    'SELECT 1'
);
PREPARE _bakeshop_delivery_item_cost_basis_stmt FROM @_bakeshop_delivery_item_cost_basis_sql;
EXECUTE _bakeshop_delivery_item_cost_basis_stmt;
DEALLOCATE PREPARE _bakeshop_delivery_item_cost_basis_stmt;
