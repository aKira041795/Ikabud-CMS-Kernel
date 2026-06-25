-- Add product_id to delivery items so commissary can tag ingredients per product.
-- This bridges the product coverage ledger with actual ingredient quantities.

SET @_bakeshop_delivery_items_product_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_delivery_items'
      AND column_name = 'product_id'
);
SET @_bakeshop_delivery_items_product_id_sql := IF(
    @_bakeshop_delivery_items_product_id = 0,
    'ALTER TABLE bakeshop_delivery_items
        ADD COLUMN product_id INT UNSIGNED NULL AFTER ingredient_id,
        ADD KEY idx_bakeshop_delivery_items_product (product_id),
        ADD CONSTRAINT fk_bakeshop_delivery_items_product
            FOREIGN KEY (product_id) REFERENCES bakeshop_products (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @_bakeshop_delivery_items_product_id_sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;
