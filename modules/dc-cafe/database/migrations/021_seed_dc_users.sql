-- 021_seed_dc_users.sql
-- Seed default DC Cafe users with all four roles.
-- Default passwords (change on first login):
--   admin      / admin123
--   supervisor / super123
--   auditor    / audit123
--   cashier    / cash123
-- @mysql57-compat: InnoDB, utf8mb4.

INSERT INTO `dc_users` (`username`, `password_hash`, `email`, `full_name`, `role`, `store_id`, `is_active`) VALUES
('admin',      '$2y$10$oapmSjihtUzNrf/VX3kkLegT7Ad4Z3qe8XO1B/9OEEsIRdyORRVEu', 'admin@dccafe.test',      'DC Cafe Admin',      'admin',      1, 1),
('supervisor', '$2y$10$8bCr0Wq37vK1IIhvxTU3ouQvLc0gzoFbG56ngAnBs/409vmL1mD5u', 'supervisor@dccafe.test', 'Shift Supervisor',   'supervisor', 1, 1),
('auditor',    '$2y$12$WBDeodPnqQY6DfiHYUqvuupHL/4oSsFNtEeFZIjnfVZ2KUWxMaZGy', 'auditor@dccafe.test',    'Store Auditor',      'auditor',    1, 1),
('cashier',    '$2y$10$CnQMtlo07z6mCCxBE5IPb.TdAvIlaVxbqhS.pDX0eRH4EDN2R1tyi', 'cashier@dccafe.test',    'Counter Cashier',    'cashier',    1, 1);
