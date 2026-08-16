-- Moto Inventory — Staged Imports, Append-Only Audit, Idempotency, Preferences, Backups
-- Tenant-scoped. MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS moto_imports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    branch_id       INT UNSIGNED NOT NULL,
    brand_id        INT UNSIGNED NOT NULL,
    filename        VARCHAR(255) NOT NULL,
    file_size       INT UNSIGNED NOT NULL DEFAULT 0,
    mime            VARCHAR(120) NOT NULL DEFAULT '',
    status          VARCHAR(20)  NOT NULL DEFAULT 'staged' COMMENT 'staged, committed, rejected',
    row_count       INT UNSIGNED NOT NULL DEFAULT 0,
    new_count       INT UNSIGNED NOT NULL DEFAULT 0,
    existing_count  INT UNSIGNED NOT NULL DEFAULT 0,
    overwrite_qty   TINYINT(1)   NOT NULL DEFAULT 0,
    error_report    JSON NULL,
    idempotency_key VARCHAR(191) NULL,
    created_by      INT UNSIGNED NULL,
    created_by_name VARCHAR(191) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_at    DATETIME     NULL,
    UNIQUE KEY uq_moto_import_idem (tenant_id, branch_id, idempotency_key),
    INDEX idx_moto_import_branch (tenant_id, branch_id),
    INDEX idx_moto_import_status (tenant_id, status),
    CONSTRAINT fk_moto_import_brand FOREIGN KEY (brand_id) REFERENCES moto_brands (id) ON DELETE CASCADE,
    CONSTRAINT fk_moto_import_branch FOREIGN KEY (branch_id) REFERENCES moto_branches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_import_rows (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    import_id          INT UNSIGNED NOT NULL,
    row_index          INT UNSIGNED NOT NULL,
    part_number        VARCHAR(191) NOT NULL,
    description        VARCHAR(191) NOT NULL DEFAULT '',
    cost               DECIMAL(14,2) NULL,
    price              DECIMAL(14,2) NULL,
    qty                DECIMAL(14,4) NULL,
    code               VARCHAR(64)  NOT NULL DEFAULT '',
    extra              JSON NULL,
    validation_status  VARCHAR(20)  NOT NULL DEFAULT 'ok' COMMENT 'ok, error',
    validation_errors  JSON NULL,
    INDEX idx_moto_import_row_import (tenant_id, import_id),
    CONSTRAINT fk_moto_import_row_import FOREIGN KEY (import_id) REFERENCES moto_imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    branch_id       INT UNSIGNED NULL,
    actor_user_id   INT UNSIGNED NULL,
    actor_name      VARCHAR(191) NULL,
    action          VARCHAR(80)  NOT NULL,
    target_type     VARCHAR(60)  NULL,
    target_id       VARCHAR(191) NULL,
    request_id      VARCHAR(80)  NULL,
    idempotency_key VARCHAR(191) NULL,
    before_data     JSON NULL,
    after_data      JSON NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_moto_audit_tenant (tenant_id),
    INDEX idx_moto_audit_branch (tenant_id, branch_id),
    INDEX idx_moto_audit_action (tenant_id, action),
    INDEX idx_moto_audit_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_idempotency_keys (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    branch_id        INT UNSIGNED NOT NULL,
    idempotency_key  VARCHAR(191) NOT NULL,
    operation        VARCHAR(80)  NOT NULL,
    request_hash     CHAR(64)     NOT NULL,
    request_payload  JSON NULL,
    response_payload JSON NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_idem (tenant_id, branch_id, idempotency_key),
    INDEX idx_moto_idem_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_preferences (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    branch_id   INT UNSIGNED NULL,
    pref_key    VARCHAR(80)  NOT NULL,
    pref_value  TEXT NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_moto_pref (tenant_id, branch_id, pref_key),
    INDEX idx_moto_pref_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moto_backups (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    branch_id       INT UNSIGNED NULL,
    backup_version  VARCHAR(20)  NOT NULL DEFAULT '1',
    filename        VARCHAR(255) NOT NULL,
    scope           VARCHAR(20)  NOT NULL DEFAULT 'full',
    row_counts      JSON NULL,
    created_by      INT UNSIGNED NULL,
    created_by_name VARCHAR(191) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_moto_backup_tenant (tenant_id),
    INDEX idx_moto_backup_branch (tenant_id, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
