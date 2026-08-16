-- Moto Inventory — Per-tenant module roles for kernel users
-- Tenant-scoped. MySQL 5.7 compatible.
--
-- Kernel auth is the identity authority (login/password live in the kernel
-- users table), but the module needs its own role axis (admin/manager/
-- cashier/owner) so a tenant admin can differentiate what each kernel user
-- may do inside Moto Inventory without changing the kernel role. A user with
-- no row here falls back to their kernel role for permission resolution.

CREATE TABLE IF NOT EXISTS moto_user_roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        VARCHAR(20)  NOT NULL COMMENT 'admin, manager, cashier, owner',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_user_role (tenant_id, user_id),
    INDEX idx_moto_user_roles_role (tenant_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
