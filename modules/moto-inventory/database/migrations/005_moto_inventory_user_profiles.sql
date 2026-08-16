-- Moto Inventory — per-tenant user profile (first/last name split)
-- Tenant-scoped. MySQL 5.7 compatible.
--
-- Kernel auth is the identity authority (login/password/email live in the
-- kernel `users` table; `full_name` holds "First Last"). This table stores the
-- structured first/last name split per tenant so the module's Users view can
-- edit names independently. A user with no row here falls back to full_name.

CREATE TABLE IF NOT EXISTS moto_user_profiles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    first_name  VARCHAR(100) NOT NULL DEFAULT '',
    last_name   VARCHAR(100) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_user_profile (tenant_id, user_id),
    INDEX idx_moto_user_profiles_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
