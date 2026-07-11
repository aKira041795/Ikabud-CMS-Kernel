SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE pal_projects
    ADD COLUMN `jo_type` ENUM('items','contract') NOT NULL DEFAULT 'items' AFTER job_order_number,
    ADD INDEX idx_pal_proj_jo_type (`jo_type`);

SET FOREIGN_KEY_CHECKS = 1;
