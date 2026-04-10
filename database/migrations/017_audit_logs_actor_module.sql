-- F22: Add actor_module_user_id and actor_source columns to audit_logs.
-- actor_module_user_id records the module-level user ID (e.g. CMS user ID)
-- actor_source identifies which auth source produced the actor identity.
ALTER TABLE audit_logs
    ADD COLUMN IF NOT EXISTS actor_module_user_id INT NULL DEFAULT NULL AFTER actor_user_id,
    ADD COLUMN IF NOT EXISTS actor_source VARCHAR(50) NULL DEFAULT NULL AFTER actor_module_user_id;
