ALTER TABLE `kernel_tenant_db_connections`
    ADD COLUMN `db_pass_ciphertext` TEXT DEFAULT NULL,
    ADD COLUMN `db_pass_iv` VARCHAR(64) DEFAULT NULL,
    ADD COLUMN `db_pass_tag` VARCHAR(64) DEFAULT NULL;
