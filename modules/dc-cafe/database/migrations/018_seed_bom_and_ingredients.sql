-- 018_seed_bom_and_ingredients.sql
-- Seed basic ingredients and BOM (bill of materials) for core soft-serve products.
-- This enables real-time inventory deduction when orders are completed.
-- Ingredients are approximations — actual recipes should be calibrated by management.

-- @mysql57-compat: InnoDB, utf8mb4.

-- ── Ingredients ─────────────────────────────────────────────────────────
-- Core ingredients used across all soft-serve sizes.
INSERT INTO `dc_ingredients` (`name`, `unit`, `cost_per_unit`, `current_stock`, `reorder_level`) VALUES
('FroYo Mix',         'L',   120.00, 50.00, 10.00),
('Soft Serve Mix',    'L',   100.00, 50.00, 10.00),
('Sugar Cone',        'pc',   8.00,  200.00, 50.00),
('Choco Fudge Sauce', 'L',   200.00, 20.00,  5.00),
('Caramel Sauce',     'L',   180.00, 20.00,  5.00),
('Mango Glaze',       'L',   150.00, 15.00,  5.00),
('Biscoff Crumble',   'kg',  350.00, 10.00,  3.00),
('Crushed Grahams',   'kg',  120.00, 15.00,  5.00),
('Sliced Almonds',    'kg',  400.00,  8.00,  2.00),
('Chocolate Kisses',  'kg',  280.00, 10.00,  3.00);

-- ── BOM: Product → Ingredient mappings ──────────────────────────────
-- Each entry defines how much of an ingredient is used per unit of product.
-- Product names must match those seeded in 017_seed_dc_cafe_data.sql.
--
-- Soft-serve sizes use base mix + cone/cup.
-- PETITE (FroYo):    0.170L FroYo Mix
-- TINY (Soft Serve): 0.170L Soft Serve Mix
-- CUDDLY (FroYo):    0.200L FroYo Mix
-- HUGGABLE (Soft Serve): 0.220L Soft Serve Mix
-- SNUGGLY (FroYo):   0.270L FroYo Mix
-- CONE:              1pc Sugar Cone (no base mix — pre-made)

INSERT INTO `dc_product_ingredients` (`product_id`, `ingredient_id`, `quantity`)
SELECT p.product_id, i.ingredient_id, 0.170
FROM dc_products p, dc_ingredients i
WHERE p.name = 'PETITE' AND i.name = 'FroYo Mix';

INSERT INTO `dc_product_ingredients` (`product_id`, `ingredient_id`, `quantity`)
SELECT p.product_id, i.ingredient_id, 0.170
FROM dc_products p, dc_ingredients i
WHERE p.name = 'TINY' AND i.name = 'Soft Serve Mix';

INSERT INTO `dc_product_ingredients` (`product_id`, `ingredient_id`, `quantity`)
SELECT p.product_id, i.ingredient_id, 0.200
FROM dc_products p, dc_ingredients i
WHERE p.name = 'CUDDLY' AND i.name = 'FroYo Mix';

INSERT INTO `dc_product_ingredients` (`product_id`, `ingredient_id`, `quantity`)
SELECT p.product_id, i.ingredient_id, 0.220
FROM dc_products p, dc_ingredients i
WHERE p.name = 'HUGGABLE' AND i.name = 'Soft Serve Mix';

INSERT INTO `dc_product_ingredients` (`product_id`, `ingredient_id`, `quantity`)
SELECT p.product_id, i.ingredient_id, 0.270
FROM dc_products p, dc_ingredients i
WHERE p.name = 'SNUGGLY' AND i.name = 'FroYo Mix';

INSERT INTO `dc_product_ingredients` (`product_id`, `ingredient_id`, `quantity`)
SELECT p.product_id, i.ingredient_id, 1.000
FROM dc_products p, dc_ingredients i
WHERE p.name = 'CONE' AND i.name = 'Sugar Cone';
