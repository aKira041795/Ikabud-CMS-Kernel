-- 039_add_viewer_role.sql
-- Read-only viewer role, for the analytics dashboard and the reports.
--
-- A viewer exists so branch numbers can be shared with someone who has no
-- business at the till: they may read the dashboard and the reports, and nothing
-- else. The branch decides whether the role is available at all
-- (pos_viewer_dashboard_enabled, off by default), so a site with nobody to read
-- the numbers does not expose a read-only login for the sake of it.
--
-- @mysql57-compat: ALTER guarded via information_schema, no data rewrite.

SET @role_has_viewer := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_users'
      AND column_name = 'role'
      AND column_type LIKE '%viewer%'
);

SET @sql := IF(@role_has_viewer = 0,
    'ALTER TABLE `dc_users`
     MODIFY COLUMN `role` ENUM(''admin'',''supervisor'',''auditor'',''cashier'',''viewer'')
     NOT NULL DEFAULT ''cashier''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
