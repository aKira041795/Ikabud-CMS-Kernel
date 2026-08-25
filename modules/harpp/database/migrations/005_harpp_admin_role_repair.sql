-- Upgrade repair for tenants created before deterministic HARPP bootstrap IDs/roles.
-- Preserve an existing owner (never downgrade it), but ensure the deterministic
-- administrator row and/or bootstrap administrator identity is active, undeleted,
-- and has an administrator-capable role.
UPDATE `harpp_users`
SET `role` = CASE WHEN LOWER(TRIM(COALESCE(`role`, ''))) = 'owner' THEN 'owner' ELSE 'admin' END,
    `is_active` = 1,
    `deleted_at` = NULL,
    `updated_at` = NOW()
WHERE `id` = 2 OR LOWER(`email`) = 'admin@harpp.local';
