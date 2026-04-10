-- Migration 016: Add order_token_expires_at to ec_orders
-- Order confirmation tokens used by the /ecommerce/order/{token} route should
-- expire after 90 days to limit their exposure window.

ALTER TABLE ec_orders
    ADD COLUMN token_expires_at TIMESTAMP NULL DEFAULT NULL;

-- Back-fill expiry for existing orders (90 days from creation)
UPDATE ec_orders
SET token_expires_at = DATE_ADD(created_at, INTERVAL 90 DAY)
WHERE token_expires_at IS NULL AND confirmation_token IS NOT NULL AND confirmation_token != '';
