-- Moto Inventory — Import Mapping Templates (tenant-scoped custom templates).
-- Built-in per-brand presets are bundled in ImportTemplateService; this table
-- stores user-created custom templates for brands/supplier pricelists that are
-- not covered by a preset. Same layout fields as a preset: a column mapping,
-- preferred sheet name, header/data rows, code semantics, and part-number
-- synthesis. MySQL 5.7 compatible (InnoDB, no window functions/CTEs).
--
-- NOTE: tenant-scoped tables in the tenant DB do not FK to the control-plane
-- kernel_tenants table (it lives in a different database); tenant_id is an
-- application-enforced scope like the rest of the moto_* schema.

CREATE TABLE IF NOT EXISTS moto_import_templates (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    name               VARCHAR(191) NOT NULL,
    sheet              VARCHAR(191) NULL,
    header_row         INT UNSIGNED NOT NULL DEFAULT 1,
    data_start_row     INT UNSIGNED NOT NULL DEFAULT 2,
    mapping            JSON NOT NULL,
    code_mode          VARCHAR(20)  NOT NULL DEFAULT 'attribute' COMMENT 'attribute (store raw code) or decode (MICHAELSON coded price)',
    part_number_source VARCHAR(20)  NOT NULL DEFAULT 'column' COMMENT 'column, description, composite',
    part_number_cols   JSON NULL,
    part_number_sep    VARCHAR(10)  NOT NULL DEFAULT ' ',
    created_by         INT UNSIGNED NULL,
    created_by_name    VARCHAR(191) NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_moto_import_template_tenant (tenant_id),
    INDEX idx_moto_import_template_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
