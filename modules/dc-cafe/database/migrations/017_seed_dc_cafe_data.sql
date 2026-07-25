-- 017_seed_dc_cafe_data.sql
-- Seed real DC Cafe product data harvested from legacy CodeIgniter jbakeshop_live DB.
-- Products are DC Cafe's own menu items sold at DC Blu (store_id=39).
-- Julies Bakeshop products (Bread, Pastry, Frying, Cake categories) are excluded —
-- those are bakery counter sales, not DC Cafe menu items.
-- Sauces, toppings, and addons extracted from customization_data JSON across 6,756 sales.

-- @mysql57-compat: InnoDB, utf8mb4.

-- ── Additional Categories ──────────────────────────────────────────────
-- Doughnuts and Hot Meals are DC Cafe's own food menu, not Julies bakery.
INSERT INTO `dc_categories` (`name`, `sort_order`) VALUES
('Doughnuts', 6),
('Hot Meals', 7);

-- ── Products ────────────────────────────────────────────────────────────
-- Category mapping: 1=Soft Serve, 2=FroYo, 3=Beverages, 4=Pastries,
--                   5=Add-ons, 6=Doughnuts, 7=Hot Meals
--
-- Core soft-serve sizes (is_variable=1 → customizable with base/sauce/toppings).
-- PETITE/CUDDLY/SNUGGLY use FroYo base → FroYo category.
-- TINY/HUGGABLE use Soft Serve base → Soft Serve category.
INSERT INTO `dc_products` (`store_id`, `category_id`, `name`, `base_price`, `is_variable`, `is_active`) VALUES
-- Soft-serve sizes (by base type)
(1, 1, 'CONE',         45.00,  0, 1),
(1, 2, 'PETITE',       55.00,  1, 1),
(1, 1, 'TINY',         75.00,  1, 1),
(1, 2, 'CUDDLY',       95.00,  1, 1),
(1, 1, 'HUGGABLE',    125.00,  1, 1),
(1, 2, 'SNUGGLY',     150.00,  1, 1),
-- Specialty dessert items
(1, 1, 'OREO KITKAT',           190.00, 0, 1),
(1, 1, 'MANGO CARAMEL PARFAIT', 150.00, 0, 1),
(1, 1, 'BANANA SPLIT SUNDAE',   150.00, 0, 1),
(1, 1, 'Choco Pistachio Almond', 190.00, 0, 1),
(1, 1, 'PINT',                  300.00, 0, 1),
-- Classic Doughnuts (₱10-35)
(1, 6, 'Butternut Bites',   10.00, 0, 1),
(1, 6, 'MINI DOUGHNUTS',    25.00, 0, 1),
(1, 6, 'GLAZED',            30.00, 0, 1),
(1, 6, 'DC Glaze',          30.00, 0, 1),
(1, 6, 'GLAZED DRIZZLE',    35.00, 0, 1),
-- Elite Doughnuts (₱45)
(1, 6, 'DARK CHOCO CHIPS', 45.00, 0, 1),
(1, 6, 'CHOCO FEATHER',    45.00, 0, 1),
(1, 6, 'CHOCO MM',         45.00, 0, 1),
(1, 6, 'CHOCO CARAMEL',    45.00, 0, 1),
(1, 6, 'BUTTERNUT RING',   45.00, 0, 1),
-- Supreme Doughnuts (₱50)
(1, 6, 'CAPPUCINO RING',   50.00, 0, 1),
(1, 6, 'RED VELVET',       50.00, 0, 1),
(1, 6, 'OREOPHILE RING',   50.00, 0, 1),
(1, 6, 'MATCHA TEA RING',  50.00, 0, 1),
(1, 6, 'UBE RING',         50.00, 0, 1),
-- Premium Doughnuts (₱55)
(1, 6, 'BOSTON CREME',         55.00, 0, 1),
(1, 6, 'S''MORES',             55.00, 0, 1),
(1, 6, 'NUTELLA BOMB',         55.00, 0, 1),
(1, 6, 'BLUEBERRY CHEESECAKE', 55.00, 0, 1),
(1, 6, 'MANGO GRAHAM',         55.00, 0, 1),
(1, 6, 'UBE SUPREME',          55.00, 0, 1),
(1, 6, 'CHOCO KITKAT',         55.00, 0, 1),
(1, 6, 'WHITE OREO KITKAT',    55.00, 0, 1),
(1, 6, 'OREOPHILE CHEESECAKE', 55.00, 0, 1),
-- Donut Box combos
(1, 6, 'YOU DRIVE ME GLAZY (6pcs)',         165.00, 0, 1),
(1, 6, 'DC MINI DOZEN BOX',                 180.00, 0, 1),
(1, 6, 'DC DELIGHTS COMBO (6pcs)',           255.00, 0, 1),
(1, 6, 'ELITE BEST (6pcs)',                  250.00, 0, 1),
(1, 6, 'PREMIUM BUNDLE (6pcs)',              310.00, 0, 1),
(1, 6, 'SUPREME BUNDLE (6pcs)',              280.00, 0, 1),
(1, 6, 'HAPPY BUNDLE (6pcs)',                420.00, 0, 1),
(1, 6, 'ULTIMATE FEAST COMBO (6pcs)',        570.00, 0, 1),
-- Hot Meals
(1, 7, 'BANGUS SOLO',                  195.00, 0, 1),
(1, 7, 'CHICKEN TERYAKI',              195.00, 0, 1),
(1, 7, 'BREADED PORK CHOP',            195.00, 0, 1),
(1, 7, 'BEEF SALISBURY MUSHROOM STEAK',195.00, 0, 1),
(1, 7, 'BREAKFAST PANCAKE',            189.00, 0, 1),
(1, 7, 'PORK STEAK',                   230.00, 0, 1),
(1, 7, 'BANGUS SHARING',               230.00, 0, 1),
(1, 7, 'CHICKEN FAJITA',               260.00, 0, 1),
(1, 7, 'Bacon Lettuce Tomato Panini',  195.00, 0, 1),
(1, 7, 'Beef Mozzarella Melt Burger',  250.00, 0, 1),
(1, 7, 'Bolognese Solo',               209.00, 0, 1),
(1, 7, 'Carbonara Solo',               229.00, 0, 1),
(1, 7, '3 Cheese Pizza',               345.00, 0, 1),
(1, 7, 'BABY BACK RIBS COMBO',         359.00, 0, 1),
(1, 7, 'FRIED CHICKEN COMBO',          359.00, 0, 1),
(1, 7, 'CLUBMOJO',                     399.00, 0, 1),
(1, 7, 'ALL-OUT MUNCH',                699.00, 0, 1),
(1, 7, 'BBQ BESHIES',                  699.00, 0, 1),
(1, 7, 'CHURROS 6PCS',                 120.00, 0, 1),
(1, 7, 'CHURROS 12 PCS',               240.00, 0, 1);

-- ── Sauces (harvested from legacy customization_data JSON) ──────────────
-- Replaces the initial seed with the actual sauces used in production.
DELETE FROM `dc_soft_serve_sauces`;
INSERT INTO `dc_soft_serve_sauces` (`name`, `is_active`) VALUES
('CARAMEL SAUCE',           1),
('CHOCO FUDGE',             1),
('MANGO GLAZE SAUCE',       1),
('STRAWBERRY GLAZE SAUCE',  1),
('CAPPUCINO',               1),
('TIRAMISU SAUCE',          1),
('W CHOCOLATE GLAZE SAUCE', 1);

-- ── Toppings (harvested from legacy customization_data JSON) ────────────
-- Replaces the initial seed with all 24 toppings actually used in production.
DELETE FROM `dc_soft_serve_toppings`;
INSERT INTO `dc_soft_serve_toppings` (`name`, `is_active`) VALUES
('BANANA',              1),
('BISCOFF',             1),
('CARAMEL CRUMBLE',     1),
('CHOCO FLAKES',        1),
('CHOCOLATE KISSES',    1),
('CHOCOLATE SPRINKLES', 1),
('CORN FLAKES',         1),
('CRUSHED COOKIES',     1),
('CRUSHED GRAHAMS',     1),
('FRUIT LOOPS',         1),
('FRUIT PEBBLES',       1),
('GRANOLA',             1),
('KOKO KRUNCH',         1),
('LANGKA',              1),
('M&M',                 1),
('MACAPUNO',            1),
('MANGO',               1),
('MILO BALLS',          1),
('NATA',                1),
('ORIGINAL CRUMBLE',    1),
('RED BEAN',            1),
('RED VELVET CRUMBLE',  1),
('SLICED ALMONDS',      1),
('SMALL MALLOWS',       1);

-- ── Addons table ────────────────────────────────────────────────────────
-- Addon items sold alongside soft-serve (extra sauce, extra spoon, etc.).
-- Referenced by dc_order_items.customizations JSON and parent_item_id.
CREATE TABLE IF NOT EXISTS `dc_soft_serve_addons` (
  `addon_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  `type` ENUM('sauce','topping','spoon') NOT NULL DEFAULT 'topping',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`addon_id`),
  UNIQUE KEY `uk_dc_ss_addons_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed addons from legacy production data
INSERT INTO `dc_soft_serve_addons` (`name`, `price`, `type`) VALUES
('Extra Caramel Sauce',        20, 'sauce'),
('Extra Cappucino',            20, 'sauce'),
('Extra Original Choco Sauce', 20, 'sauce'),
('Extra Tiramisu',             20, 'sauce'),
('Extra W Choco Glaze',        20, 'sauce'),
('Extra Chocolate Kisses',     20, 'topping'),
('Extra Choco Flakes',         20, 'topping'),
('Extra Choco Sprinkles',      20, 'topping'),
('Extra Crushed Cookies',      20, 'topping'),
('Extra CRUSHED Graham',       20, 'topping'),
('Extra Fruit Loops New',      20, 'topping'),
('Extra Granola',              20, 'topping'),
('Extra Mango',                20, 'topping'),
('Extra Milo Balls',           20, 'topping'),
('Extra Small Mallows',        20, 'topping'),
('Extra Spoon',                 5, 'spoon');

-- ── Update module.json owns_tables list to include dc_soft_serve_addons ──
-- NOTE: If this migration is applied via the migration runner, the module.json
-- must be updated to declare `dc_soft_serve_addons` in owns_tables.
-- Run: manually add "dc_soft_serve_addons" to module.json owns_tables array.
