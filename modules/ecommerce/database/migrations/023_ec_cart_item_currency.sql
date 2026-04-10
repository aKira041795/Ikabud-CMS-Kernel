SET @ec_cart_item_currency_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ec_cart_items'
      AND COLUMN_NAME = 'currency'
);

SET @ec_cart_item_currency_sql := IF(
    @ec_cart_item_currency_exists = 0,
    'ALTER TABLE ec_cart_items ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT ''USD'' AFTER price_snapshot',
    'SELECT 1'
);

PREPARE ec_cart_item_currency_stmt FROM @ec_cart_item_currency_sql;
EXECUTE ec_cart_item_currency_stmt;
DEALLOCATE PREPARE ec_cart_item_currency_stmt;

UPDATE ec_cart_items
SET currency = 'USD'
WHERE currency IS NULL OR currency = '';
