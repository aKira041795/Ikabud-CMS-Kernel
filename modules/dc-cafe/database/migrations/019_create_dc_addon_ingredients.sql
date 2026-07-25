-- 019_create_dc_addon_ingredients.sql
-- Maps addon items to ingredients for automated stock deduction.
-- When a soft-serve order includes an addon (e.g., Extra Caramel Sauce),
-- the corresponding ingredient (e.g., Caramel Sauce) is deducted from inventory.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_addon_ingredients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `addon_id` INT NOT NULL,
  `ingredient_id` INT NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dc_addon_ingredient` (`addon_id`, `ingredient_id`),
  KEY `idx_dc_ai_addon` (`addon_id`),
  KEY `idx_dc_ai_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_dc_ai_addon` FOREIGN KEY (`addon_id`) REFERENCES `dc_soft_serve_addons` (`addon_id`),
  CONSTRAINT `fk_dc_ai_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `dc_ingredients` (`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: map addons to ingredients based on legacy usage
-- Extra sauces → same sauce ingredient (0.050L per addon)
INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.050
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra Caramel Sauce' AND i.name = 'Caramel Sauce';

INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.050
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra Cappucino' AND i.name = 'Caramel Sauce';

INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.050
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra Original Choco Sauce' AND i.name = 'Choco Fudge Sauce';

INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.050
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra Tiramisu' AND i.name = 'Choco Fudge Sauce';

INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.050
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra W Choco Glaze' AND i.name = 'Choco Fudge Sauce';

-- Extra toppings → 0.020kg per addon
INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.020
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra Chocolate Kisses' AND i.name = 'Chocolate Kisses';

INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.020
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra Crushed Cookies' AND i.name = 'Biscoff Crumble';

INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.020
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra CRUSHED Graham' AND i.name = 'Crushed Grahams';

INSERT INTO `dc_addon_ingredients` (`addon_id`, `ingredient_id`, `quantity`)
SELECT a.addon_id, i.ingredient_id, 0.020
FROM dc_soft_serve_addons a, dc_ingredients i
WHERE a.name = 'Extra Mango' AND i.name = 'Mango Glaze';
