-- Moto Inventory — Core Schema (branches, user assignments, brands, products)
-- Tenant-scoped. MySQL 5.7 compatible (InnoDB, no window functions/CTEs).

CREATE TABLE IF NOT EXISTS moto_branches (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    branch_key  VARCHAR(64)  NOT NULL,
    name        VARCHAR(191) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_branch_tenant_key (tenant_id, branch_key),
    INDEX idx_moto_branch_tenant (tenant_id),
    INDEX idx_moto_branch_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_user_branches (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    branch_id   INT UNSIGNED NOT NULL,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_user_branch (tenant_id, user_id, branch_id),
    INDEX idx_moto_user_branch_user (tenant_id, user_id),
    INDEX idx_moto_user_branch_branch (tenant_id, branch_id),
    CONSTRAINT fk_moto_user_branch_branch FOREIGN KEY (branch_id) REFERENCES moto_branches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_brands (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(191) NOT NULL,
    archived    TINYINT(1)   NOT NULL DEFAULT 0,
    trashed     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_brand_tenant_name (tenant_id, name),
    INDEX idx_moto_brand_tenant (tenant_id),
    INDEX idx_moto_brand_state (tenant_id, archived, trashed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_products (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    branch_id    INT UNSIGNED NOT NULL,
    brand_id     INT UNSIGNED NOT NULL,
    part_number  VARCHAR(191) NOT NULL,
    description  VARCHAR(191) NOT NULL DEFAULT '',
    code         VARCHAR(64)  NOT NULL DEFAULT '',
    cost         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    price        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    qty_on_hand  DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    extra        JSON NULL,
    archived     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_product (tenant_id, branch_id, brand_id, part_number),
    INDEX idx_moto_product_tenant_branch (tenant_id, branch_id),
    INDEX idx_moto_product_brand (tenant_id, brand_id),
    INDEX idx_moto_product_search (tenant_id, branch_id, part_number),
    INDEX idx_moto_product_state (tenant_id, archived),
    CONSTRAINT fk_moto_product_branch FOREIGN KEY (branch_id) REFERENCES moto_branches (id) ON DELETE CASCADE,
    CONSTRAINT fk_moto_product_brand FOREIGN KEY (brand_id) REFERENCES moto_brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
