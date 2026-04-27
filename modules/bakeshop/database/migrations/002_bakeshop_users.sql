CREATE TABLE IF NOT EXISTS `bakeshop_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(50) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'supervisor') NOT NULL DEFAULT 'supervisor',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bakeshop_users_username` (`username`),
    UNIQUE KEY `uq_bakeshop_users_email` (`email`),
    KEY `idx_bakeshop_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @bakeshop002_has_cms_users := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'cms_users'
);

SET @bakeshop002_seed_sql := IF(
    @bakeshop002_has_cms_users > 0,
    'INSERT INTO `bakeshop_users` (`username`, `email`, `phone`, `password_hash`, `full_name`, `role`, `is_active`, `created_at`, `updated_at`) SELECT * FROM ( SELECT CASE WHEN `username` = ''cmsadmin'' AND COALESCE(NULLIF(`email`, ''''), ''admin@cms.local'') = ''admin@cms.local'' AND `role` IN (''superadmin'', ''administrator'') THEN ''bakeshopadmin'' ELSE `username` END AS `username`, CASE WHEN `username` = ''cmsadmin'' AND COALESCE(NULLIF(`email`, ''''), ''admin@cms.local'') = ''admin@cms.local'' AND `role` IN (''superadmin'', ''administrator'') THEN ''admin@bakeshop.local'' ELSE COALESCE(NULLIF(`email`, ''''), CONCAT(`username`, ''@bakeshop.local'')) END AS `email`, NULL AS `phone`, `password_hash`, CASE WHEN `username` = ''cmsadmin'' AND COALESCE(NULLIF(`email`, ''''), ''admin@cms.local'') = ''admin@cms.local'' AND `role` IN (''superadmin'', ''administrator'') THEN ''Bakeshop Admin'' ELSE COALESCE(NULLIF(`display_name`, ''''), `username`) END AS `full_name`, CASE WHEN `role` IN (''superadmin'', ''administrator'') THEN ''admin'' ELSE ''supervisor'' END AS `role`, COALESCE(`is_active`, 1) AS `is_active`, COALESCE(`created_at`, NOW()) AS `created_at`, COALESCE(`updated_at`, NOW()) AS `updated_at` FROM `cms_users` ) AS `seed` WHERE NOT EXISTS ( SELECT 1 FROM `bakeshop_users` `existing` WHERE `existing`.`username` = `seed`.`username` )',
    'DO 0'
);

PREPARE bakeshop002_seed_stmt FROM @bakeshop002_seed_sql;
EXECUTE bakeshop002_seed_stmt;
DEALLOCATE PREPARE bakeshop002_seed_stmt;

INSERT INTO `bakeshop_users` (`username`, `email`, `phone`, `password_hash`, `full_name`, `role`, `is_active`)
SELECT * FROM (
    SELECT
        'bakeshopadmin' AS `username`,
        'admin@bakeshop.local' AS `email`,
        NULL AS `phone`,
        '!bakeshop-bootstrap-password-reset-required!' AS `password_hash`,
        'Bakeshop Admin' AS `full_name`,
        'admin' AS `role`,
        1 AS `is_active`
) AS `default_seed`
WHERE NOT EXISTS (SELECT 1 FROM `bakeshop_users` LIMIT 1);