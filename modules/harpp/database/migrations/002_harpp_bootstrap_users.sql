-- Explicit primary keys are intentional: tenant provisioning updates HARPP user id=1
-- with the named tenant administrator's email/password after module migration.
INSERT INTO `harpp_users` (`id`, `email`, `password_hash`, `full_name`, `role`, `is_active`)
VALUES
    (1, 'owner@harpp.local', '$2y$12$mq2QCTxGTbJ4eUTYQ1.Kn.0Ek/Dc2eah/AbwkckZyzSDnFYHFWV/S', 'HARPP Owner', 'owner', 1),
    (2, 'admin@harpp.local', '$2y$12$eAn.t5dP1Y5GX1bZ21L6GOajsBhSYLKDw6yLygEVXzU6pYoOObOcW', 'HARPP Admin', 'admin', 1),
    (3, 'member@harpp.local', '$2y$12$tJz4g/pPDmaZbMK0gLqpBuNrfblxB5aSEtWnbCEbBleXT/YQxh/y.', 'HARPP Member', 'member', 1);

-- Keep subsequent AUTO_INCREMENT allocation beyond all deterministic fixtures.
ALTER TABLE `harpp_users` AUTO_INCREMENT = 4;
