-- 014_create_dc_soft_serve_tables.sql
-- Soft-serve customization component tables.
-- Bases (FroYo, Soft Serve, Mix), sauces, and toppings are referenced by
-- the customizations JSON in dc_order_items. These are DC-owned now (vs
-- legacy where they lived in the bakery products table).
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_soft_serve_bases` (
  `base_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`base_id`),
  UNIQUE KEY `uk_dc_ss_bases_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `dc_soft_serve_bases` (`name`) VALUES ('FROYO'), ('SOFT SERVE'), ('MIX');

CREATE TABLE IF NOT EXISTS `dc_soft_serve_sauces` (
  `sauce_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sauce_id`),
  UNIQUE KEY `uk_dc_ss_sauces_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `dc_soft_serve_sauces` (`name`) VALUES
('CARAMEL SAUCE'), ('MANGO GLAZE SAUCE'), ('CHOCO FUDGE'),
('STRAWBERRY'), ('WHITE CHOCOLATE');

CREATE TABLE IF NOT EXISTS `dc_soft_serve_toppings` (
  `topping_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`topping_id`),
  UNIQUE KEY `uk_dc_ss_toppings_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `dc_soft_serve_toppings` (`name`) VALUES
('CHOCOLATE KISSES'), ('CRUSHED COOKIES'), ('CARAMEL CRUMBLE'),
('BISCOFF'), ('SLICED ALMONDS'), ('CRUSHED GRAHAMS'),
('MANGO'), ('MARSHMALLOW');
