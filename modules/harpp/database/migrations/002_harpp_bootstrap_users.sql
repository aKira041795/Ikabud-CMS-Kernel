INSERT INTO `harpp_users` (`email`, `password_hash`, `full_name`, `role`, `is_active`)
SELECT
    `seed`.`email`,
    `seed`.`password_hash`,
    `seed`.`full_name`,
    `seed`.`role`,
    1
FROM (
    SELECT
        'owner@harpp.local' AS `email`,
        '$2y$12$mq2QCTxGTbJ4eUTYQ1.Kn.0Ek/Dc2eah/AbwkckZyzSDnFYHFWV/S' AS `password_hash`,
        'HARPP Owner' AS `full_name`,
        'owner' AS `role`
    UNION ALL
    SELECT
        'admin@harpp.local',
        '$2y$12$eAn.t5dP1Y5GX1bZ21L6GOajsBhSYLKDw6yLygEVXzU6pYoOObOcW',
        'HARPP Admin',
        'admin'
    UNION ALL
    SELECT
        'member@harpp.local',
        '$2y$12$tJz4g/pPDmaZbMK0gLqpBuNrfblxB5aSEtWnbCEbBleXT/YQxh/y.',
        'HARPP Member',
        'member'
) AS `seed`
WHERE NOT EXISTS (
    SELECT 1
    FROM `harpp_users` `existing`
    WHERE `existing`.`email` = `seed`.`email`
);
