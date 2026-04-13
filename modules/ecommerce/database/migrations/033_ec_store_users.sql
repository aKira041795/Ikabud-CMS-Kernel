-- ============================================================
-- Ecommerce Module — Store Users
-- Adds ec_store_users table for assigning users (owners/managers)
-- to specific stores.  Safe to re-run (idempotent).
-- ============================================================

CREATE TABLE IF NOT EXISTS ec_store_users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        VARCHAR(50)  NOT NULL DEFAULT 'manager'     COMMENT 'owner | manager',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY  uq_ec_store_users (store_id, user_id),
    KEY         idx_ec_store_users_store (store_id),
    KEY         idx_ec_store_users_user  (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
