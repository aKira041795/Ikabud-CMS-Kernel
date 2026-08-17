-- Project Audit Ledger — Users table
--
-- NOTE on the bootstrap admin: the admin account is NOT seeded from this
-- migration. PAL is an auth_owned module with `requires_named_admin_on_provision`,
-- so `php ikabud tenant:provision <id> --admin-user=... --admin-pass=...` seeds
-- the real admin through the kernel TenantProvisioner using the provisioned
-- tenant's actual id (see auth_owned.tenant_id_column = "tenant_id").
--
-- A legacy version of this file seeded a placeholder admin with a hardcoded
-- `tenant_id = 1`, which left fresh tenant databases with an admin scoped to
-- the wrong tenant (auth lookups use username + tenant_id and could never
-- match). That seed was removed; do not re-add a hardcoded tenant_id here.

CREATE TABLE IF NOT EXISTS pal_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin','supervisor','encoder') NOT NULL DEFAULT 'encoder',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    token_version INT UNSIGNED NOT NULL DEFAULT 0,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pal_user_username (tenant_id, username),
    INDEX idx_pal_user_tenant (tenant_id),
    INDEX idx_pal_user_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
