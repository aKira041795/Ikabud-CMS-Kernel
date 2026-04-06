-- ── Migration 011: Digital License Delivery Enhancements ────────────────────
-- Adds customer_id, product_id, download_token, and downloaded_at columns
-- to ec_order_licenses to support customer dashboard access, token-based
-- download end-point, and user-scoped license queries.

ALTER TABLE ec_order_licenses
    ADD COLUMN customer_id   INT UNSIGNED  DEFAULT NULL  AFTER customer_email,
    ADD COLUMN product_id    INT UNSIGNED  DEFAULT NULL  AFTER customer_id,
    ADD COLUMN download_token VARCHAR(64)  DEFAULT NULL,
    ADD COLUMN downloaded_at  DATETIME     DEFAULT NULL;

CREATE INDEX idx_eol_customer_id    ON ec_order_licenses (customer_id);
CREATE INDEX idx_eol_download_token ON ec_order_licenses (download_token);
CREATE INDEX idx_eol_product_id     ON ec_order_licenses (product_id);
