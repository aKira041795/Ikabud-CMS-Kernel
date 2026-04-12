-- Migration 016: Add order_token_expires_at to ec_orders
-- Order confirmation tokens used by the /ecommerce/order/{token} route should
-- expire after 90 days to limit their exposure window.

SET @ec_orders_token_exp_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ec_orders'
      AND COLUMN_NAME = 'token_expires_at'
);
SET @ec_orders_token_exp_sql := IF(
    @ec_orders_token_exp_exists = 0,
    'ALTER TABLE ec_orders ADD COLUMN token_expires_at TIMESTAMP NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE ec_orders_token_exp_stmt FROM @ec_orders_token_exp_sql;
EXECUTE ec_orders_token_exp_stmt;
DEALLOCATE PREPARE ec_orders_token_exp_stmt;

-- Back-fill expiry for existing orders (90 days from creation)
UPDATE ec_orders
SET token_expires_at = DATE_ADD(created_at, INTERVAL 90 DAY)
WHERE token_expires_at IS NULL AND confirmation_token IS NOT NULL AND confirmation_token != '';
