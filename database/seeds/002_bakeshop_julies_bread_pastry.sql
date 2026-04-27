-- Julie's Bakeshop filtered bread/pastry seed for the bakeshop module
-- Source host: 127.0.0.1
-- Source port: 3306
-- Source database: jbakeshop_live
-- Generated at: 2026-04-27T09:29:56+00:00
-- Filter rule: Products must be in the bakery category allow-list and must not be explicitly included for DC stores.
-- Bakery categories: Asian Bread, Bread, Pastry
-- Excluded DC store codes: DC001, DCM01, DCBLU01
-- Julie's branch rule: Active legacy stores with type branch/outlet, name like "Julies %", and non-DC store codes.
-- Positive recipe rows only: yes
-- Counts: branches=10, products=81, ingredients=30, recipes=271

START TRANSACTION;

CREATE TEMPORARY TABLE tmp_julies_seed_branches (
    `legacy_store_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`legacy_store_id`),
    UNIQUE KEY `uq_tmp_julies_seed_branches_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE tmp_julies_seed_units (
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `dimension` ENUM('mass', 'volume', 'count') NOT NULL,
    `base_unit_code` VARCHAR(20) NULL,
    `factor_to_base` DECIMAL(14,6) NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE tmp_julies_seed_ingredients (
    `legacy_ingredient_id` INT UNSIGNED NOT NULL,
    `sku` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`legacy_ingredient_id`),
    UNIQUE KEY `uq_tmp_julies_seed_ingredients_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE tmp_julies_seed_products (
    `legacy_product_id` INT UNSIGNED NOT NULL,
    `sku` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `category` VARCHAR(100) NULL,
    `default_yield_qty` DECIMAL(14,4) NOT NULL,
    `default_yield_unit_code` VARCHAR(20) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `legacy_source_sku` VARCHAR(100) NULL,
    PRIMARY KEY (`legacy_product_id`),
    UNIQUE KEY `uq_tmp_julies_seed_products_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE tmp_julies_seed_recipes (
    `legacy_product_id` INT UNSIGNED NOT NULL,
    `legacy_ingredient_id` INT UNSIGNED NOT NULL,
    `qty` DECIMAL(14,4) NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL,
    `notes` VARCHAR(255) NULL,
    PRIMARY KEY (`legacy_product_id`, `legacy_ingredient_id`, `unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_julies_seed_branches (`legacy_store_id`, `code`, `name`, `address`, `is_active`) VALUES
    (38, 'JB01', 'Julies Bagting', 'Bagting', 1),
    (2, 'JES01', 'Julies Estaka', 'General Luna st.,', 1),
    (37, 'JL01', 'Julies Lawa-an', 'Lawa-an', 1),
    (43, 'JMA01', 'Julies Mapang', 'Poblacion', 1),
    (3, 'JMIP01', 'Julies Miputak', 'Quezon Avenue', 1),
    (31, 'JMN01', 'Julies Minaog', 'Minaog', 1),
    (36, 'JP01', 'Julies Polo', 'Polo', 1),
    (42, 'JPI01', 'Julies Pinan', 'Poblacion', 1),
    (41, 'JPO01', 'Julies Polanco', 'Poblacion', 1),
    (1, 'JTUR01', 'Julies Turno', 'Hi-way Turno,', 1);

INSERT INTO tmp_julies_seed_units (`code`, `name`, `dimension`, `base_unit_code`, `factor_to_base`, `sort_order`) VALUES
    ('BOT', 'Bottle', 'volume', 'L', '1.000000', 100),
    ('GAL', 'Gallon', 'volume', 'L', '3.790000', 100),
    ('SACK', 'Sack', 'mass', 'kg', '50.000000', 100);

INSERT INTO tmp_julies_seed_ingredients (`legacy_ingredient_id`, `sku`, `name`, `unit_code`, `is_active`) VALUES
    (13, 'JBS-ING-13', 'ALL PURPOSE FLOUR', 'SACK', 1),
    (17, 'JBS-ING-17', 'BAKING POWDER', 'GAL', 1),
    (21, 'JBS-ING-21', 'BROWN SUGAR', 'SACK', 1),
    (24, 'JBS-ING-24', 'BUTTER OIL', 'GAL', 1),
    (25, 'JBS-ING-25', 'CAKE FLOUR', 'SACK', 1),
    (27, 'JBS-ING-27', 'CHEESE (PCS)', 'BOT', 1),
    (60, 'JBS-ING-60', 'CHEESE BREAD', 'SACK', 1),
    (82, 'JBS-ING-82', 'CHEESE OIL', 'GAL', 1),
    (31, 'JBS-ING-31', 'COCOA POWDER', 'GAL', 1),
    (32, 'JBS-ING-32', 'CORNSTARCH', 'SACK', 1),
    (68, 'JBS-ING-68', 'EGG PCS', 'BOT', 1),
    (35, 'JBS-ING-35', 'EL POPA (POWDERED SUGAR)', 'SACK', 1),
    (33, 'JBS-ING-33', 'FRANCIS ORIG', 'SACK', 1),
    (91, 'JBS-ING-91', 'HARD FLOUR (SACK)', 'SACK', 1),
    (38, 'JBS-ING-38', 'JULIE\'S BLEND', 'SACK', 1),
    (39, 'JBS-ING-39', 'LOAF ADDITIVE', 'GAL', 1),
    (94, 'JBS-ING-94', 'MARGARINE (20KG)', 'SACK', 1),
    (41, 'JBS-ING-41', 'MARGARINE TUBS', 'SACK', 1),
    (42, 'JBS-ING-42', 'OIL', 'SACK', 1),
    (97, 'JBS-ING-97', 'PANDESAL', 'SACK', 1),
    (61, 'JBS-ING-61', 'PINOY', 'SACK', 1),
    (44, 'JBS-ING-44', 'POWDERED MILK', 'SACK', 1),
    (46, 'JBS-ING-46', 'REFINED SUGAR', 'SACK', 1),
    (48, 'JBS-ING-48', 'SALT', 'SACK', 1),
    (49, 'JBS-ING-49', 'SBRP', 'SACK', 1),
    (98, 'JBS-ING-98', 'SHORTENING (20KG)', 'SACK', 1),
    (55, 'JBS-ING-55', 'SOFT FLOUR', 'SACK', 1),
    (58, 'JBS-ING-58', 'VARIETY (FLOUR)', 'SACK', 1),
    (106, 'JBS-ING-106', 'VIOLET UBE', 'GAL', 1),
    (59, 'JBS-ING-59', 'YEAST', 'GAL', 1);

INSERT INTO tmp_julies_seed_products (`legacy_product_id`, `sku`, `name`, `category`, `default_yield_qty`, `default_yield_unit_code`, `is_active`, `legacy_source_sku`) VALUES
    (179, 'JBS-PRD-179', 'CHEESE LOAF', 'Asian Bread', '1.0000', 'pc', 1, 'ASICHE7972'),
    (114, 'JBS-PRD-114', 'AMERICAN LOAF', 'Bread', '4.0000', 'pc', 1, 'BREAME6862'),
    (102, 'JBS-PRD-102', 'BAHUG-BAHUG', 'Bread', '100.0000', 'pc', 1, 'BREBAH4351'),
    (121, 'JBS-PRD-121', 'BUKO PANDAN', 'Bread', '55.0000', 'pc', 1, 'BREBUK5243'),
    (319, 'JBS-PRD-319', 'BURGER', 'Bread', '1.0000', 'pc', 1, 'BREBUR9664'),
    (6, 'JBS-PRD-6', 'BUTTER BREAD', 'Bread', '12.0000', 'pc', 1, 'BTBR-001'),
    (35, 'JBS-PRD-35', 'BUTTER BUN', 'Bread', '12.0000', 'pc', 0, 'BTBN-001'),
    (18, 'JBS-PRD-18', 'BUTTER ROLL', 'Bread', '12.0000', 'pc', 1, 'BTRL-001'),
    (117, 'JBS-PRD-117', 'CHEESE BREAD ', 'Bread', '48.0000', 'pc', 1, 'BRECHE5349'),
    (42, 'JBS-PRD-42', 'CHEESE PANDESAL', 'Bread', '64.0000', 'pc', 1, 'CHBR-001'),
    (3, 'JBS-PRD-3', 'CHEESE STRUESSEL', 'Bread', '69.0000', 'pc', 1, 'CHST-001'),
    (104, 'JBS-PRD-104', 'CHOCO FUDGE', 'Bread', '60.0000', 'pc', 1, 'BRECHO3033'),
    (36, 'JBS-PRD-36', 'CHOCO GERMAN', 'Bread', '82.0000', 'pc', 1, 'CHGR-001'),
    (293, 'JBS-PRD-293', 'CHOCO HALF MOON', 'Bread', '76.0000', 'pc', 1, 'BRECHO2960'),
    (122, 'JBS-PRD-122', 'CHOCO LOAF', 'Bread', '9.0000', 'pc', 1, 'BRECHO2827'),
    (23, 'JBS-PRD-23', 'COCO BUN', 'Bread', '60.0000', 'pc', 1, 'PDCC-001'),
    (16, 'JBS-PRD-16', 'CORN BREAD', 'Bread', '12.0000', 'pc', 1, 'CRNB-001'),
    (31, 'JBS-PRD-31', 'CREAM LOAF', 'Bread', '7.0000', 'pc', 1, 'CRLF-001'),
    (294, 'JBS-PRD-294', 'CRUSTY ONION', 'Bread', '52.0000', 'pc', 1, 'BRECRU4597'),
    (76, 'JBS-PRD-76', 'ENSAYMADA', 'Bread', '56.0000', 'pc', 1, 'BREENS9427'),
    (7, 'JBS-PRD-7', 'EVERLASTING', 'Bread', '47.0000', 'pc', 1, 'EVLT-001'),
    (323, 'JBS-PRD-323', 'EVERLASTING 4', 'Bread', '47.0000', 'pc', 1, 'BREEVE2796'),
    (27, 'JBS-PRD-27', 'FANCY BREAD', 'Bread', '12.0000', 'pc', 0, 'FCBR-001'),
    (20, 'JBS-PRD-20', 'FINGER ROLL', 'Bread', '12.0000', 'pc', 0, 'FGRL-001'),
    (33, 'JBS-PRD-33', 'FRANCIS', 'Bread', '45.0000', 'pc', 1, 'FRNC-001'),
    (120, 'JBS-PRD-120', 'FRANCIS (ORIG)', 'Bread', '45.0000', 'pc', 1, 'BREFRA5636'),
    (30, 'JBS-PRD-30', 'GRACIOSA LOAF', 'Bread', '10.0000', 'pc', 1, 'GRLF-001'),
    (321, 'JBS-PRD-321', 'GRANADA', 'Bread', '1.0000', 'pc', 1, 'BREGRA1986'),
    (119, 'JBS-PRD-119', 'HAWAIIAN', 'Bread', '55.0000', 'pc', 1, 'BREHAW4019'),
    (299, 'JBS-PRD-299', 'HOTDOG', 'Bread', '1.0000', 'pc', 1, 'SNAHOT6418'),
    (78, 'JBS-PRD-78', 'HOTDOG BUN', 'Bread', '39.0000', 'pc', 1, 'BREHOT3731'),
    (5, 'JBS-PRD-5', 'KING ROLL', 'Bread', '41.0000', 'pc', 1, 'KGRL-001'),
    (307, 'JBS-PRD-307', 'MARBLE LOAF', 'Bread', '5.0000', 'pc', 1, 'BREMAR3803'),
    (295, 'JBS-PRD-295', 'MARBLE RING', 'Bread', '45.0000', 'pc', 1, 'BREMAR5212'),
    (37, 'JBS-PRD-37', 'MINI BUN', 'Bread', '8.0000', 'pc', 1, 'MNBN-001'),
    (38, 'JBS-PRD-38', 'MONAY BIG', 'Bread', '8.0000', 'pc', 1, 'MNBG-001'),
    (21, 'JBS-PRD-21', 'MONAY PUTOK', 'Bread', '12.0000', 'pc', 0, 'MNPT-001'),
    (8, 'JBS-PRD-8', 'MONAY SMALL', 'Bread', '50.0000', 'pc', 1, 'MNSM-001'),
    (322, 'JBS-PRD-322', 'MUSHROOM', 'Bread', '1.0000', 'pc', 1, 'BREMUS5567'),
    (75, 'JBS-PRD-75', 'PAN DE JULIA', 'Bread', '37.0000', 'pc', 1, 'BREPAN7921'),
    (32, 'JBS-PRD-32', 'PAN DE SAL', 'Bread', '55.0000', 'pc', 1, 'PDSL-001'),
    (26, 'JBS-PRD-26', 'SB BAYAN', 'Bread', '12.0000', 'pc', 0, 'SBBY-001'),
    (25, 'JBS-PRD-25', 'SB CLASSIC', 'Bread', '33.0000', 'pc', 1, 'SBSP-001'),
    (24, 'JBS-PRD-24', 'SB SPECIAL', 'Bread', '33.0000', 'pc', 1, 'SBRG-001'),
    (10, 'JBS-PRD-10', 'SIAKOY', 'Bread', '12.0000', 'pc', 0, 'SIAK-001'),
    (22, 'JBS-PRD-22', 'SIOPAO', 'Bread', '25.0000', 'pc', 1, 'SIOP-001'),
    (1, 'JBS-PRD-1', 'SPANISH BREAD', 'Bread', '48.0000', 'pc', 1, 'SPAN-001'),
    (4, 'JBS-PRD-4', 'SWEET MONAY', 'Bread', '41.0000', 'pc', 1, 'SWMN-001'),
    (115, 'JBS-PRD-115', 'SWEET ROLL', 'Bread', '38.0000', 'pc', 1, 'BRESWE9193'),
    (17, 'JBS-PRD-17', 'TIRE BREAD', 'Bread', '12.0000', 'pc', 1, 'TIRB-001'),
    (14, 'JBS-PRD-14', 'UBE CHIZ DE SAL', 'Bread', '55.0000', 'pc', 1, 'UBCS-001'),
    (15, 'JBS-PRD-15', 'UBE STRUSSEL', 'Bread', '55.0000', 'pc', 1, 'UBST-001'),
    (28, 'JBS-PRD-28', 'V-CREAM', 'Bread', '10.0000', 'pc', 1, 'VCRM-001'),
    (202, 'JBS-PRD-202', 'APPLE CRUMBLE', 'Pastry', '1.0000', 'pc', 0, 'PASAPP9724'),
    (236, 'JBS-PRD-236', 'BLACK ZAMBO', 'Pastry', '1.0000', 'pc', 1, 'PASBLA4761'),
    (200, 'JBS-PRD-200', 'BLUEBERRY CHEESECAKE', 'Pastry', '1.0000', 'pc', 1, 'PASBLU9860'),
    (2, 'JBS-PRD-2', 'CHEESE MILK', 'Pastry', '59.0000', 'pc', 1, 'CHMK-001'),
    (41, 'JBS-PRD-41', 'CHEESE STRUSSEL', 'Pastry', '12.0000', 'pc', 0, 'CHST-002'),
    (55, 'JBS-PRD-55', 'CHIFFON', 'Pastry', '4.0000', 'pc', 1, 'CHFF-001'),
    (59, 'JBS-PRD-59', 'CHOCO CHIPS', 'Pastry', '12.0000', 'pc', 1, 'CHCP-001'),
    (49, 'JBS-PRD-49', 'CHOCO CRINKLES', 'Pastry', '112.0000', 'pc', 1, 'CRNK-001'),
    (304, 'JBS-PRD-304', 'CHOCO CUPCAKE', 'Pastry', '70.0000', 'pc', 1, 'PASCHO5812'),
    (196, 'JBS-PRD-196', 'CHOCO MUFFINS', 'Pastry', '1.0000', 'pc', 1, 'PASCHO3475'),
    (54, 'JBS-PRD-54', 'FIG PIE', 'Pastry', '95.0000', 'pc', 1, 'PGPI-001'),
    (60, 'JBS-PRD-60', 'FRUIT CAKE', 'Pastry', '69.0000', 'pc', 1, 'FRTC-001'),
    (39, 'JBS-PRD-39', 'HAWAIIAN', 'Pastry', '12.0000', 'pc', 0, 'HWII-001'),
    (53, 'JBS-PRD-53', 'HOPIA', 'Pastry', '85.0000', 'pc', 1, 'MOPI-001'),
    (235, 'JBS-PRD-235', 'MANGO FLOAF', 'Pastry', '1.0000', 'pc', 1, 'PASMAN9020'),
    (77, 'JBS-PRD-77', 'MARBLE CUPCAKE', 'Pastry', '64.0000', 'pc', 1, 'PASMAR5681'),
    (204, 'JBS-PRD-204', 'MINI CAKE', 'Pastry', '1.0000', 'pc', 1, 'PASMIN7825'),
    (34, 'JBS-PRD-34', 'PINEAPPLE', 'Pastry', '12.0000', 'pc', 1, 'PNPL-001'),
    (43, 'JBS-PRD-43', 'PINEAPPLE PIE', 'Pastry', '80.0000', 'pc', 1, 'PNPP-001'),
    (50, 'JBS-PRD-50', 'POLVORON', 'Pastry', '83.0000', 'pc', 1, 'PLVR-001'),
    (317, 'JBS-PRD-317', 'ROUND CAKE', 'Pastry', '1.0000', 'pc', 1, 'PASROU9168'),
    (233, 'JBS-PRD-233', 'SPINACH LASAGNA', 'Pastry', '1.0000', 'pc', 1, 'PASSPI5030'),
    (201, 'JBS-PRD-201', 'STRAWBERRY CHEESECAKE', 'Pastry', '1.0000', 'pc', 1, 'PASSTR4342'),
    (52, 'JBS-PRD-52', 'SUNFLOWER', 'Pastry', '52.0000', 'pc', 1, 'SNFL-001'),
    (46, 'JBS-PRD-46', 'SWEET HEART', 'Pastry', '50.0000', 'pc', 1, 'SWHT-001'),
    (83, 'JBS-PRD-83', 'TEST PRODUCT', 'Pastry', '45.0000', 'pc', 0, 'PASTES8358'),
    (48, 'JBS-PRD-48', 'UBE BAR', 'Pastry', '35.0000', 'pc', 1, 'UBBR-001'),
    (19, 'JBS-PRD-19', 'YEMA ROLL', 'Pastry', '12.0000', 'pc', 0, 'YMRL-001');

INSERT INTO tmp_julies_seed_recipes (`legacy_product_id`, `legacy_ingredient_id`, `qty`, `unit_code`, `notes`) VALUES
    (1, 13, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=1, legacy_ingredient_id=13'),
    (1, 21, '0.3400', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=1, legacy_ingredient_id=21'),
    (1, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=1, legacy_ingredient_id=59'),
    (1, 94, '0.1700', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=1, legacy_ingredient_id=94'),
    (1, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=1, legacy_ingredient_id=98'),
    (2, 44, '0.0400', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=44'),
    (2, 46, '0.2450', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=46'),
    (2, 55, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=55'),
    (2, 58, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=58'),
    (2, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=59'),
    (2, 82, '0.0500', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=82'),
    (2, 94, '0.0800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=94'),
    (2, 98, '0.0800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=2, legacy_ingredient_id=98'),
    (3, 24, '0.0200', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=24'),
    (3, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=38'),
    (3, 46, '0.1300', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=46'),
    (3, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=48'),
    (3, 55, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=55'),
    (3, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=59'),
    (3, 82, '0.0600', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=82'),
    (3, 91, '0.8000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=91'),
    (3, 98, '0.0300', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=3, legacy_ingredient_id=98'),
    (4, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=38'),
    (4, 44, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=44'),
    (4, 46, '0.1500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=46'),
    (4, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=48'),
    (4, 55, '0.4000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=55'),
    (4, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=59'),
    (4, 91, '0.6000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=91'),
    (4, 98, '0.7000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=4, legacy_ingredient_id=98'),
    (5, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=5, legacy_ingredient_id=38'),
    (5, 46, '0.1600', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=5, legacy_ingredient_id=46'),
    (5, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=5, legacy_ingredient_id=48'),
    (5, 55, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=5, legacy_ingredient_id=55'),
    (5, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=5, legacy_ingredient_id=59'),
    (5, 91, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=5, legacy_ingredient_id=91'),
    (5, 98, '0.0700', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=5, legacy_ingredient_id=98'),
    (6, 21, '0.1500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=6, legacy_ingredient_id=21'),
    (6, 46, '0.2880', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=6, legacy_ingredient_id=46'),
    (6, 55, '0.0220', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=6, legacy_ingredient_id=55'),
    (6, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=6, legacy_ingredient_id=59'),
    (6, 91, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=6, legacy_ingredient_id=91'),
    (6, 98, '0.0800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=6, legacy_ingredient_id=98'),
    (7, 46, '0.1800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=7, legacy_ingredient_id=46'),
    (7, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=7, legacy_ingredient_id=59'),
    (7, 91, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=7, legacy_ingredient_id=91'),
    (7, 98, '7.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=7, legacy_ingredient_id=98'),
    (8, 46, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=8, legacy_ingredient_id=46'),
    (8, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=8, legacy_ingredient_id=59'),
    (8, 91, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=8, legacy_ingredient_id=91'),
    (8, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=8, legacy_ingredient_id=94'),
    (8, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=8, legacy_ingredient_id=98'),
    (14, 21, '0.0400', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=21'),
    (14, 48, '7.4000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=48'),
    (14, 55, '9.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=55'),
    (14, 59, '0.0120', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=59'),
    (14, 60, '0.2250', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=60'),
    (14, 94, '0.0220', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=94'),
    (14, 97, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=97'),
    (14, 98, '0.0400', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=14, legacy_ingredient_id=98'),
    (15, 46, '0.7900', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=15, legacy_ingredient_id=46'),
    (15, 48, '0.0150', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=15, legacy_ingredient_id=48'),
    (15, 55, '0.4000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=15, legacy_ingredient_id=55'),
    (15, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=15, legacy_ingredient_id=59'),
    (15, 91, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=15, legacy_ingredient_id=91'),
    (15, 94, '0.4800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=15, legacy_ingredient_id=94'),
    (15, 98, '0.2200', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=15, legacy_ingredient_id=98'),
    (16, 41, '0.0220', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=16, legacy_ingredient_id=41'),
    (16, 46, '0.1900', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=16, legacy_ingredient_id=46'),
    (16, 59, '0.0120', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=16, legacy_ingredient_id=59'),
    (16, 91, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=16, legacy_ingredient_id=91'),
    (16, 98, '0.1200', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=16, legacy_ingredient_id=98'),
    (17, 46, '0.1800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=17, legacy_ingredient_id=46'),
    (17, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=17, legacy_ingredient_id=59'),
    (17, 91, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=17, legacy_ingredient_id=91'),
    (17, 94, '0.0900', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=17, legacy_ingredient_id=94'),
    (22, 13, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=13'),
    (22, 17, '0.0130', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=17'),
    (22, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=38'),
    (22, 46, '0.1500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=46'),
    (22, 48, '0.0050', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=48'),
    (22, 55, '2.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=55'),
    (22, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=59'),
    (22, 98, '0.0800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=22, legacy_ingredient_id=98'),
    (23, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=23, legacy_ingredient_id=38'),
    (23, 46, '0.3500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=23, legacy_ingredient_id=46'),
    (23, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=23, legacy_ingredient_id=48'),
    (23, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=23, legacy_ingredient_id=59'),
    (23, 91, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=23, legacy_ingredient_id=91'),
    (23, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=23, legacy_ingredient_id=94'),
    (23, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=23, legacy_ingredient_id=98'),
    (24, 24, '0.4000', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=24'),
    (24, 38, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=38'),
    (24, 39, '0.0200', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=39'),
    (24, 44, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=44'),
    (24, 46, '1.7000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=46'),
    (24, 48, '0.1300', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=48'),
    (24, 59, '0.1000', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=59'),
    (24, 91, '10.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=91'),
    (24, 98, '0.4000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=24, legacy_ingredient_id=98'),
    (25, 49, '12.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=25, legacy_ingredient_id=49'),
    (25, 59, '0.1000', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=25, legacy_ingredient_id=59'),
    (25, 98, '0.7500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=25, legacy_ingredient_id=98'),
    (28, 35, '0.3750', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=28, legacy_ingredient_id=35'),
    (28, 46, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=28, legacy_ingredient_id=46'),
    (28, 58, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=28, legacy_ingredient_id=58'),
    (28, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=28, legacy_ingredient_id=59'),
    (28, 82, '0.0600', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=28, legacy_ingredient_id=82'),
    (28, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=28, legacy_ingredient_id=94'),
    (28, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=28, legacy_ingredient_id=98'),
    (30, 35, '0.3750', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=30, legacy_ingredient_id=35'),
    (30, 46, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=30, legacy_ingredient_id=46'),
    (30, 58, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=30, legacy_ingredient_id=58'),
    (30, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=30, legacy_ingredient_id=59'),
    (30, 82, '0.0600', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=30, legacy_ingredient_id=82'),
    (30, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=30, legacy_ingredient_id=94'),
    (30, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=30, legacy_ingredient_id=98'),
    (31, 21, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=21'),
    (31, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=38'),
    (31, 44, '0.0200', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=44'),
    (31, 46, '0.1500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=46'),
    (31, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=48'),
    (31, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=59'),
    (31, 82, '0.0600', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=82'),
    (31, 91, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=91'),
    (31, 94, '0.1000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=94'),
    (31, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=31, legacy_ingredient_id=98'),
    (32, 42, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=32, legacy_ingredient_id=42'),
    (32, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=32, legacy_ingredient_id=59'),
    (32, 61, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=32, legacy_ingredient_id=61'),
    (32, 94, '0.0250', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=32, legacy_ingredient_id=94'),
    (33, 33, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=33, legacy_ingredient_id=33'),
    (34, 32, '2.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=34, legacy_ingredient_id=32'),
    (34, 46, '0.9500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=34, legacy_ingredient_id=46'),
    (34, 48, '0.0050', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=34, legacy_ingredient_id=48'),
    (34, 55, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=34, legacy_ingredient_id=55'),
    (34, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=34, legacy_ingredient_id=94'),
    (34, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=34, legacy_ingredient_id=98'),
    (35, 13, '6.3000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=35, legacy_ingredient_id=13'),
    (36, 21, '0.1880', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=21'),
    (36, 31, '0.0150', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=31'),
    (36, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=38'),
    (36, 44, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=44'),
    (36, 46, '0.1500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=46'),
    (36, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=48'),
    (36, 55, '0.4000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=55'),
    (36, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=59'),
    (36, 91, '0.8500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=91'),
    (36, 94, '0.1450', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=94'),
    (36, 98, '0.0450', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=36, legacy_ingredient_id=98'),
    (37, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=37, legacy_ingredient_id=38'),
    (37, 44, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=37, legacy_ingredient_id=44'),
    (37, 46, '0.1400', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=37, legacy_ingredient_id=46'),
    (37, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=37, legacy_ingredient_id=48'),
    (37, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=37, legacy_ingredient_id=59'),
    (37, 91, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=37, legacy_ingredient_id=91'),
    (37, 98, '0.1000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=37, legacy_ingredient_id=98'),
    (38, 46, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=38, legacy_ingredient_id=46'),
    (38, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=38, legacy_ingredient_id=59'),
    (38, 91, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=38, legacy_ingredient_id=91'),
    (38, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=38, legacy_ingredient_id=94'),
    (38, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=38, legacy_ingredient_id=98'),
    (41, 27, '1.2000', 'BOT', 'Imported from Julie\'s live bakery seed. legacy_product_id=41, legacy_ingredient_id=27'),
    (42, 42, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=42, legacy_ingredient_id=42'),
    (42, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=42, legacy_ingredient_id=59'),
    (42, 61, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=42, legacy_ingredient_id=61'),
    (42, 94, '0.0250', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=42, legacy_ingredient_id=94'),
    (43, 32, '2.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=43, legacy_ingredient_id=32'),
    (43, 46, '0.9500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=43, legacy_ingredient_id=46'),
    (43, 48, '0.0050', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=43, legacy_ingredient_id=48'),
    (43, 55, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=43, legacy_ingredient_id=55'),
    (43, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=43, legacy_ingredient_id=94'),
    (43, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=43, legacy_ingredient_id=98'),
    (46, 17, '0.0500', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=17'),
    (46, 35, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=35'),
    (46, 44, '0.0750', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=44'),
    (46, 46, '0.6000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=46'),
    (46, 55, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=55'),
    (46, 91, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=91'),
    (46, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=94'),
    (46, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=98'),
    (46, 106, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=46, legacy_ingredient_id=106'),
    (48, 17, '0.0700', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=48, legacy_ingredient_id=17'),
    (48, 42, '0.1000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=48, legacy_ingredient_id=42'),
    (48, 44, '0.0700', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=48, legacy_ingredient_id=44'),
    (48, 46, '0.6000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=48, legacy_ingredient_id=46'),
    (48, 91, '0.7500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=48, legacy_ingredient_id=91'),
    (48, 106, '0.0030', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=48, legacy_ingredient_id=106'),
    (49, 13, '0.6000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=49, legacy_ingredient_id=13'),
    (49, 17, '0.0200', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=49, legacy_ingredient_id=17'),
    (49, 21, '0.8000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=49, legacy_ingredient_id=21'),
    (49, 24, '0.0600', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=49, legacy_ingredient_id=24'),
    (49, 35, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=49, legacy_ingredient_id=35'),
    (49, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=49, legacy_ingredient_id=48'),
    (49, 68, '8.0000', 'BOT', 'Imported from Julie\'s live bakery seed. legacy_product_id=49, legacy_ingredient_id=68'),
    (50, 46, '0.7500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=50, legacy_ingredient_id=46'),
    (50, 55, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=50, legacy_ingredient_id=55'),
    (50, 68, '2.0000', 'BOT', 'Imported from Julie\'s live bakery seed. legacy_product_id=50, legacy_ingredient_id=68'),
    (50, 98, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=50, legacy_ingredient_id=98'),
    (52, 17, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=52, legacy_ingredient_id=17'),
    (52, 44, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=52, legacy_ingredient_id=44'),
    (52, 46, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=52, legacy_ingredient_id=46'),
    (52, 55, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=52, legacy_ingredient_id=55'),
    (52, 94, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=52, legacy_ingredient_id=94'),
    (53, 42, '0.5600', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=53, legacy_ingredient_id=42'),
    (53, 46, '0.8150', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=53, legacy_ingredient_id=46'),
    (53, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=53, legacy_ingredient_id=48'),
    (53, 55, '1.7000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=53, legacy_ingredient_id=55'),
    (53, 68, '2.0000', 'BOT', 'Imported from Julie\'s live bakery seed. legacy_product_id=53, legacy_ingredient_id=68'),
    (53, 91, '0.3000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=53, legacy_ingredient_id=91'),
    (54, 17, '0.0200', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=54, legacy_ingredient_id=17'),
    (54, 42, '0.2400', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=54, legacy_ingredient_id=42'),
    (54, 46, '1.1500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=54, legacy_ingredient_id=46'),
    (54, 48, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=54, legacy_ingredient_id=48'),
    (54, 55, '2.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=54, legacy_ingredient_id=55'),
    (54, 98, '0.1000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=54, legacy_ingredient_id=98'),
    (55, 17, '0.0200', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=55, legacy_ingredient_id=17'),
    (55, 25, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=55, legacy_ingredient_id=25'),
    (55, 46, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=55, legacy_ingredient_id=46'),
    (55, 68, '20.0000', 'BOT', 'Imported from Julie\'s live bakery seed. legacy_product_id=55, legacy_ingredient_id=68'),
    (60, 17, '0.0680', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=60, legacy_ingredient_id=17'),
    (60, 44, '0.1000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=60, legacy_ingredient_id=44'),
    (60, 46, '0.8000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=60, legacy_ingredient_id=46'),
    (60, 55, '0.7500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=60, legacy_ingredient_id=55'),
    (60, 91, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=60, legacy_ingredient_id=91'),
    (60, 94, '0.1300', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=60, legacy_ingredient_id=94'),
    (75, 21, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=75, legacy_ingredient_id=21'),
    (75, 42, '0.0800', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=75, legacy_ingredient_id=42'),
    (75, 59, '0.0120', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=75, legacy_ingredient_id=59'),
    (75, 61, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=75, legacy_ingredient_id=61'),
    (75, 94, '0.0200', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=75, legacy_ingredient_id=94'),
    (115, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=38'),
    (115, 44, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=44'),
    (115, 46, '0.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=46'),
    (115, 48, '1.0000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=48'),
    (115, 55, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=55'),
    (115, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=59'),
    (115, 91, '0.7500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=91'),
    (115, 94, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=94'),
    (115, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=115, legacy_ingredient_id=98'),
    (117, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=117, legacy_ingredient_id=59'),
    (117, 60, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=117, legacy_ingredient_id=60'),
    (117, 82, '0.1400', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=117, legacy_ingredient_id=82'),
    (117, 94, '0.0200', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=117, legacy_ingredient_id=94'),
    (119, 32, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=32'),
    (119, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=38'),
    (119, 46, '0.4000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=46'),
    (119, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=48'),
    (119, 55, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=55'),
    (119, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=59'),
    (119, 91, '0.7500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=91'),
    (119, 94, '0.0350', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=94'),
    (119, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=119, legacy_ingredient_id=98'),
    (120, 33, '1.2000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=120, legacy_ingredient_id=33'),
    (121, 32, '0.1850', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=32'),
    (121, 38, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=38'),
    (121, 46, '0.4250', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=46'),
    (121, 48, '0.0100', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=48'),
    (121, 55, '0.2500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=55'),
    (121, 59, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=59'),
    (121, 91, '0.7500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=91'),
    (121, 94, '0.0250', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=94'),
    (121, 98, '0.0500', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=121, legacy_ingredient_id=98'),
    (293, 17, '0.0100', 'GAL', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=17'),
    (293, 44, '0.0650', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=44'),
    (293, 46, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=46'),
    (293, 55, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=55'),
    (293, 68, '1.0000', 'BOT', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=68'),
    (293, 91, '0.5000', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=91'),
    (293, 94, '0.1250', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=94'),
    (293, 98, '0.2650', 'SACK', 'Imported from Julie\'s live bakery seed. legacy_product_id=293, legacy_ingredient_id=98');

DELETE recipe_rows
FROM `bakeshop_product_recipe` recipe_rows
INNER JOIN `bakeshop_products` products ON products.`id` = recipe_rows.`product_id`
LEFT JOIN tmp_julies_seed_products seed ON seed.`sku` = products.`sku`
WHERE products.`sku` LIKE 'JBS-PRD-%'
  AND seed.`sku` IS NULL;

DELETE FROM `bakeshop_products`
WHERE `sku` LIKE 'JBS-PRD-%'
  AND `sku` NOT IN (SELECT seed.`sku` FROM tmp_julies_seed_products seed);

DELETE FROM `bakeshop_ingredients`
WHERE `sku` LIKE 'JBS-ING-%'
  AND `sku` NOT IN (SELECT seed.`sku` FROM tmp_julies_seed_ingredients seed);

DELETE FROM `bakeshop_branches`
WHERE `external_store_id` IS NOT NULL
  AND `name` LIKE 'Julies %'
  AND `code` NOT IN (SELECT seed.`code` FROM tmp_julies_seed_branches seed);

INSERT INTO `bakeshop_branches` (`code`, `name`, `address`, `external_store_id`, `external_warehouse_id`, `is_active`)
SELECT seed.`code`, seed.`name`, seed.`address`, seed.`legacy_store_id`, NULL, seed.`is_active`
FROM tmp_julies_seed_branches seed
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `address` = VALUES(`address`),
    `external_store_id` = VALUES(`external_store_id`),
    `external_warehouse_id` = VALUES(`external_warehouse_id`),
    `is_active` = VALUES(`is_active`);

INSERT INTO `bakeshop_units` (`code`, `name`, `dimension`, `base_unit_id`, `factor_to_base`, `sort_order`)
SELECT seed.`code`, seed.`name`, seed.`dimension`, base_units.`id`, seed.`factor_to_base`, seed.`sort_order`
FROM tmp_julies_seed_units seed
LEFT JOIN bakeshop_units base_units ON base_units.`code` = seed.`base_unit_code`
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `dimension` = VALUES(`dimension`),
    `base_unit_id` = VALUES(`base_unit_id`),
    `factor_to_base` = VALUES(`factor_to_base`),
    `sort_order` = VALUES(`sort_order`);

INSERT INTO `bakeshop_ingredients` (`sku`, `name`, `default_unit_id`, `is_active`)
SELECT seed.`sku`, seed.`name`, units.`id`, seed.`is_active`
FROM tmp_julies_seed_ingredients seed
INNER JOIN bakeshop_units units ON units.`code` = seed.`unit_code`
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `default_unit_id` = VALUES(`default_unit_id`),
    `is_active` = VALUES(`is_active`);

INSERT INTO `bakeshop_products` (`sku`, `name`, `category`, `default_yield_qty`, `default_yield_unit_id`, `is_active`)
SELECT seed.`sku`, seed.`name`, seed.`category`, seed.`default_yield_qty`, yield_units.`id`, seed.`is_active`
FROM tmp_julies_seed_products seed
LEFT JOIN bakeshop_units yield_units ON yield_units.`code` = seed.`default_yield_unit_code`
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `category` = VALUES(`category`),
    `default_yield_qty` = VALUES(`default_yield_qty`),
    `default_yield_unit_id` = VALUES(`default_yield_unit_id`),
    `is_active` = VALUES(`is_active`);

INSERT INTO `bakeshop_product_recipe` (`product_id`, `ingredient_id`, `qty`, `unit_id`, `notes`)
SELECT products.`id`, ingredients.`id`, seed.`qty`, units.`id`, seed.`notes`
FROM tmp_julies_seed_recipes seed
INNER JOIN tmp_julies_seed_products seed_products ON seed_products.`legacy_product_id` = seed.`legacy_product_id`
INNER JOIN tmp_julies_seed_ingredients seed_ingredients ON seed_ingredients.`legacy_ingredient_id` = seed.`legacy_ingredient_id`
INNER JOIN bakeshop_products products ON products.`sku` = seed_products.`sku`
INNER JOIN bakeshop_ingredients ingredients ON ingredients.`sku` = seed_ingredients.`sku`
INNER JOIN bakeshop_units units ON units.`code` = seed.`unit_code`
ON DUPLICATE KEY UPDATE
    `qty` = VALUES(`qty`),
    `notes` = VALUES(`notes`);

DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_recipes;
DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_products;
DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_ingredients;
DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_units;
DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_branches;

COMMIT;
