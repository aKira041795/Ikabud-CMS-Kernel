ALTER TABLE `bakeshop_ingredients`
    ADD COLUMN `pack_label` VARCHAR(40) NULL AFTER `default_unit_id`,
    ADD COLUMN `pack_qty` DECIMAL(14,4) NULL AFTER `pack_label`,
    ADD COLUMN `pack_unit_id` INT UNSIGNED NULL AFTER `pack_qty`,
    ADD CONSTRAINT `fk_bakeshop_ingredients_pack_unit` FOREIGN KEY (`pack_unit_id`) REFERENCES `bakeshop_units` (`id`) ON DELETE SET NULL;
