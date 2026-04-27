UPDATE `bakeshop_users`
SET
    `username` = 'bakeshopadmin',
    `email` = 'admin@bakeshop.local',
    `full_name` = 'Bakeshop Admin',
    `updated_at` = NOW()
WHERE `username` = 'cmsadmin'
  AND `email` = 'admin@cms.local'
  AND `role` = 'admin'
  AND `is_active` = 1
  AND (
      SELECT COUNT(*)
      FROM (
          SELECT `id`
          FROM `bakeshop_users`
          WHERE `username` = 'bakeshopadmin'
      ) AS `existing_bootstrap`
  ) = 0;

UPDATE `bakeshop_users`
SET
    `email` = 'admin@bakeshop.local',
    `full_name` = 'Bakeshop Admin',
    `updated_at` = NOW()
WHERE `username` = 'bakeshopadmin'
  AND `role` = 'admin'
  AND `email` = 'admin@cms.local';