-- Academic Thesis Evaluation — module identity reconciliation.
--
-- The legacy manifest id 'academic_thesis_evaluation' (underscore) fails the
-- kernel module-id format check (lowercase alphanumeric + hyphens). This
-- additive, idempotent migration reconciles tenant enablement/settings rows
-- to the validated id 'academic-thesis-evaluation' and removes the legacy
-- rows. Capability IDs (academic_thesis_evaluation.*@1), ate_* tables,
-- snapshots, and reports are intentionally preserved.
--
-- @mysql57-compat: no window functions, no CTEs; guarded dynamic SQL pattern.

SELECT IF(
    EXISTS(
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tenant_module_settings'
    ),
    'INSERT INTO tenant_module_settings (tenant_id, module_id, setting_key, setting_value, created_at, updated_at)
     SELECT tenant_id, ''academic-thesis-evaluation'', setting_key, setting_value, created_at, updated_at
     FROM tenant_module_settings
     WHERE module_id = ''academic_thesis_evaluation''
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
    'SELECT 1'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tenant_module_settings'
    ),
    'DELETE FROM tenant_module_settings WHERE module_id = ''academic_thesis_evaluation''',
    'SELECT 1'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
