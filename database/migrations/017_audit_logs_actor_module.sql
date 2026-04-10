-- F22: Add actor_module_user_id and actor_source columns to audit_logs.
-- actor_module_user_id records the module-level user ID (e.g. CMS user ID)
-- actor_source identifies which auth source produced the actor identity.
SET @_m017_im := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'audit_logs' AND column_name = 'actor_source');
SET @_m017_alt := IF(@_m017_im = 0, 'ALTER TABLE audit_logs ADD COLUMN actor_module_user_id INT NULL DEFAULT NULL AFTER actor_user_id, ADD COLUMN actor_source VARCHAR(50) NULL DEFAULT NULL AFTER actor_module_user_id', 'SELECT 1');
PREPARE _stmt FROM @_m017_alt; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
