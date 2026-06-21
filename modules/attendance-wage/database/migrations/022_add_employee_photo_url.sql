-- Adds photo_url column to employee_profiles for employee portrait/ID photo

ALTER TABLE `employee_profiles`
    ADD COLUMN `photo_url` VARCHAR(500) DEFAULT NULL AFTER `basic_salary`,
    ADD INDEX `idx_photo` (`photo_url`(191));
