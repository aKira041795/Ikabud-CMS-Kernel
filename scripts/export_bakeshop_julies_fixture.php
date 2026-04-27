<?php

declare(strict_types=1);

function juliesExportDefaultOutputPath(string $format): string
{
    return match ($format) {
        'sql' => __DIR__ . '/../database/seeds/002_bakeshop_julies_bread_pastry.sql',
        default => __DIR__ . '/../tests/fixtures/bakeshop-julies-bread-pastry.json',
    };
}

function juliesExportSqlValue(null|int|float|string $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . str_replace(['\\', "'", "\r", "\n"], ['\\\\', "\\'", '\\r', '\\n'], $value) . "'";
}

function juliesExportSqlRows(array $rows): string
{
    return implode(",\n", array_map(
        static fn (array $row): string => '    (' . implode(', ', array_map('juliesExportSqlValue', $row)) . ')',
        $rows
    ));
}

function juliesExportStageRows(array $branches, array $products, array $ingredients, array $recipes): string
{
    $branchRows = array_map(static function (array $branch): array {
        return [
            (int)$branch['store_id'],
            (string)$branch['store_code'],
            (string)$branch['name'],
            (string)$branch['address'],
            (int)($branch['is_active'] ?? 1),
        ];
    }, $branches);

    $unitRows = [];
    foreach ($ingredients as $ingredient) {
        $unitCode = trim((string)($ingredient['unit_code'] ?? ''));
        if ($unitCode === '' || isset($unitRows[$unitCode])) {
            continue;
        }

        $measurementType = (string)($ingredient['measurement_type'] ?? '');
        $dimension = match ($measurementType) {
            'weight' => 'mass',
            'volume' => 'volume',
            'piece' => 'count',
            default => throw new RuntimeException('Unsupported measurement type: ' . $measurementType),
        };
        $baseUnitCode = match ($measurementType) {
            'weight' => 'kg',
            'volume' => 'L',
            'piece' => 'pc',
            default => throw new RuntimeException('Unsupported measurement type: ' . $measurementType),
        };

        $unitRows[$unitCode] = [
            $unitCode,
            (string)($ingredient['unit_name'] ?? $unitCode),
            $dimension,
            $baseUnitCode,
            number_format((float)($ingredient['measurement_value'] ?? 1), 6, '.', ''),
            100,
        ];
    }

    ksort($unitRows);

    $ingredientRows = array_map(static function (array $ingredient): array {
        return [
            (int)$ingredient['ingredient_id'],
            'JBS-ING-' . (int)$ingredient['ingredient_id'],
            (string)$ingredient['name'],
            (string)$ingredient['unit_code'],
            1,
        ];
    }, $ingredients);

    $productRows = array_map(static function (array $product): array {
        return [
            (int)$product['product_id'],
            'JBS-PRD-' . (int)$product['product_id'],
            (string)$product['name'],
            (string)($product['category'] ?? ''),
            number_format(max(1, (float)($product['yield'] ?? 1)), 4, '.', ''),
            'pc',
            (int)($product['is_active'] ?? 1),
            (string)($product['sku'] ?? ''),
        ];
    }, $products);

    $recipeRows = [];
    foreach ($recipes as $recipe) {
        $legacyProductId = (int)($recipe['product_id'] ?? 0);
        $legacyIngredientId = (int)($recipe['ingredient_id'] ?? 0);
        $ingredient = null;
        foreach ($ingredients as $candidate) {
            if ((int)($candidate['ingredient_id'] ?? 0) === $legacyIngredientId) {
                $ingredient = $candidate;
                break;
            }
        }
        if (!is_array($ingredient)) {
            throw new RuntimeException('Recipe ingredient mapping missing for legacy ingredient ' . $legacyIngredientId);
        }

        $recipeRows[] = [
            $legacyProductId,
            $legacyIngredientId,
            number_format((float)($recipe['quantity'] ?? 0), 4, '.', ''),
            (string)$ingredient['unit_code'],
            'Imported from Julie\'s live bakery seed. legacy_product_id=' . $legacyProductId . ', legacy_ingredient_id=' . $legacyIngredientId,
        ];
    }

    $sections = [];
    if ($branchRows !== []) {
        $sections[] = "INSERT INTO tmp_julies_seed_branches (`legacy_store_id`, `code`, `name`, `address`, `is_active`) VALUES\n" . juliesExportSqlRows($branchRows) . ';';
    }
    if ($unitRows !== []) {
        $sections[] = "INSERT INTO tmp_julies_seed_units (`code`, `name`, `dimension`, `base_unit_code`, `factor_to_base`, `sort_order`) VALUES\n" . juliesExportSqlRows(array_values($unitRows)) . ';';
    }
    if ($ingredientRows !== []) {
        $sections[] = "INSERT INTO tmp_julies_seed_ingredients (`legacy_ingredient_id`, `sku`, `name`, `unit_code`, `is_active`) VALUES\n" . juliesExportSqlRows($ingredientRows) . ';';
    }
    if ($productRows !== []) {
        $sections[] = "INSERT INTO tmp_julies_seed_products (`legacy_product_id`, `sku`, `name`, `category`, `default_yield_qty`, `default_yield_unit_code`, `is_active`, `legacy_source_sku`) VALUES\n" . juliesExportSqlRows($productRows) . ';';
    }
    if ($recipeRows !== []) {
        $sections[] = "INSERT INTO tmp_julies_seed_recipes (`legacy_product_id`, `legacy_ingredient_id`, `qty`, `unit_code`, `notes`) VALUES\n" . juliesExportSqlRows($recipeRows) . ';';
    }

    return implode("\n\n", $sections);
}

function juliesExportBuildSqlSeed(string $host, string $port, string $database, array $bakeryCategories, array $dcStoreCodes, array $branches, array $products, array $ingredients, array $recipes): string
{
    $header = [
        '-- Julie\'s Bakeshop filtered bread/pastry seed for the bakeshop module',
        '-- Source host: ' . $host,
        '-- Source port: ' . $port,
        '-- Source database: ' . $database,
        '-- Generated at: ' . gmdate('c'),
        '-- Filter rule: Products must be in the bakery category allow-list and must not be explicitly included for DC stores.',
        '-- Bakery categories: ' . implode(', ', $bakeryCategories),
        '-- Excluded DC store codes: ' . implode(', ', $dcStoreCodes),
        '-- Julie\'s branch rule: Active legacy stores with type branch/outlet, name like "Julies %", and non-DC store codes.',
        '-- Positive recipe rows only: yes',
        '-- Counts: branches=' . count($branches) . ', products=' . count($products) . ', ingredients=' . count($ingredients) . ', recipes=' . count($recipes),
        '',
        'START TRANSACTION;',
        '',
        'CREATE TEMPORARY TABLE tmp_julies_seed_branches (',
        '    `legacy_store_id` INT UNSIGNED NOT NULL,',
        '    `code` VARCHAR(50) NOT NULL,',
        '    `name` VARCHAR(255) NOT NULL,',
        '    `address` VARCHAR(255) NULL,',
        '    `is_active` TINYINT(1) NOT NULL DEFAULT 1,',
        '    PRIMARY KEY (`legacy_store_id`),',
        '    UNIQUE KEY `uq_tmp_julies_seed_branches_code` (`code`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        '',
        'CREATE TEMPORARY TABLE tmp_julies_seed_units (',
        '    `code` VARCHAR(20) NOT NULL,',
        '    `name` VARCHAR(100) NOT NULL,',
        '    `dimension` ENUM(\'mass\', \'volume\', \'count\') NOT NULL,',
        '    `base_unit_code` VARCHAR(20) NULL,',
        '    `factor_to_base` DECIMAL(14,6) NOT NULL,',
        '    `sort_order` INT UNSIGNED NOT NULL',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        '',
        'CREATE TEMPORARY TABLE tmp_julies_seed_ingredients (',
        '    `legacy_ingredient_id` INT UNSIGNED NOT NULL,',
        '    `sku` VARCHAR(100) NOT NULL,',
        '    `name` VARCHAR(255) NOT NULL,',
        '    `unit_code` VARCHAR(20) NOT NULL,',
        '    `is_active` TINYINT(1) NOT NULL DEFAULT 1,',
        '    PRIMARY KEY (`legacy_ingredient_id`),',
        '    UNIQUE KEY `uq_tmp_julies_seed_ingredients_sku` (`sku`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        '',
        'CREATE TEMPORARY TABLE tmp_julies_seed_products (',
        '    `legacy_product_id` INT UNSIGNED NOT NULL,',
        '    `sku` VARCHAR(100) NOT NULL,',
        '    `name` VARCHAR(255) NOT NULL,',
        '    `category` VARCHAR(100) NULL,',
        '    `default_yield_qty` DECIMAL(14,4) NOT NULL,',
        '    `default_yield_unit_code` VARCHAR(20) NULL,',
        '    `is_active` TINYINT(1) NOT NULL DEFAULT 1,',
        '    `legacy_source_sku` VARCHAR(100) NULL,',
        '    PRIMARY KEY (`legacy_product_id`),',
        '    UNIQUE KEY `uq_tmp_julies_seed_products_sku` (`sku`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        '',
        'CREATE TEMPORARY TABLE tmp_julies_seed_recipes (',
        '    `legacy_product_id` INT UNSIGNED NOT NULL,',
        '    `legacy_ingredient_id` INT UNSIGNED NOT NULL,',
        '    `qty` DECIMAL(14,4) NOT NULL,',
        '    `unit_code` VARCHAR(20) NOT NULL,',
        '    `notes` VARCHAR(255) NULL,',
        '    PRIMARY KEY (`legacy_product_id`, `legacy_ingredient_id`, `unit_code`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        '',
        juliesExportStageRows($branches, $products, $ingredients, $recipes),
        '',
        'DELETE recipe_rows',
        'FROM `bakeshop_product_recipe` recipe_rows',
        'INNER JOIN `bakeshop_products` products ON products.`id` = recipe_rows.`product_id`',
        'LEFT JOIN tmp_julies_seed_products seed ON seed.`sku` = products.`sku`',
        'WHERE products.`sku` LIKE \'JBS-PRD-%\'',
        '  AND seed.`sku` IS NULL;',
        '',
        'DELETE FROM `bakeshop_products`',
        'WHERE `sku` LIKE \'JBS-PRD-%\'',
        '  AND `sku` NOT IN (SELECT seed.`sku` FROM tmp_julies_seed_products seed);',
        '',
        'DELETE FROM `bakeshop_ingredients`',
        'WHERE `sku` LIKE \'JBS-ING-%\'',
        '  AND `sku` NOT IN (SELECT seed.`sku` FROM tmp_julies_seed_ingredients seed);',
        '',
        'DELETE FROM `bakeshop_branches`',
        'WHERE `external_store_id` IS NOT NULL',
        '  AND `name` LIKE \'Julies %\'',
        '  AND `code` NOT IN (SELECT seed.`code` FROM tmp_julies_seed_branches seed);',
        '',
        'INSERT INTO `bakeshop_branches` (`code`, `name`, `address`, `external_store_id`, `external_warehouse_id`, `is_active`)',
        'SELECT seed.`code`, seed.`name`, seed.`address`, seed.`legacy_store_id`, NULL, seed.`is_active`',
        'FROM tmp_julies_seed_branches seed',
        'ON DUPLICATE KEY UPDATE',
        '    `name` = VALUES(`name`),',
        '    `address` = VALUES(`address`),',
        '    `external_store_id` = VALUES(`external_store_id`),',
        '    `external_warehouse_id` = VALUES(`external_warehouse_id`),',
        '    `is_active` = VALUES(`is_active`);',
        '',
        'INSERT INTO `bakeshop_units` (`code`, `name`, `dimension`, `base_unit_id`, `factor_to_base`, `sort_order`)',
        'SELECT seed.`code`, seed.`name`, seed.`dimension`, base_units.`id`, seed.`factor_to_base`, seed.`sort_order`',
        'FROM tmp_julies_seed_units seed',
        'LEFT JOIN bakeshop_units base_units ON base_units.`code` = seed.`base_unit_code`',
        'ON DUPLICATE KEY UPDATE',
        '    `name` = VALUES(`name`),',
        '    `dimension` = VALUES(`dimension`),',
        '    `base_unit_id` = VALUES(`base_unit_id`),',
        '    `factor_to_base` = VALUES(`factor_to_base`),',
        '    `sort_order` = VALUES(`sort_order`);',
        '',
        'INSERT INTO `bakeshop_ingredients` (`sku`, `name`, `default_unit_id`, `is_active`)',
        'SELECT seed.`sku`, seed.`name`, units.`id`, seed.`is_active`',
        'FROM tmp_julies_seed_ingredients seed',
        'INNER JOIN bakeshop_units units ON units.`code` = seed.`unit_code`',
        'ON DUPLICATE KEY UPDATE',
        '    `name` = VALUES(`name`),',
        '    `default_unit_id` = VALUES(`default_unit_id`),',
        '    `is_active` = VALUES(`is_active`);',
        '',
        'INSERT INTO `bakeshop_products` (`sku`, `name`, `category`, `default_yield_qty`, `default_yield_unit_id`, `is_active`)',
        'SELECT seed.`sku`, seed.`name`, seed.`category`, seed.`default_yield_qty`, yield_units.`id`, seed.`is_active`',
        'FROM tmp_julies_seed_products seed',
        'LEFT JOIN bakeshop_units yield_units ON yield_units.`code` = seed.`default_yield_unit_code`',
        'ON DUPLICATE KEY UPDATE',
        '    `name` = VALUES(`name`),',
        '    `category` = VALUES(`category`),',
        '    `default_yield_qty` = VALUES(`default_yield_qty`),',
        '    `default_yield_unit_id` = VALUES(`default_yield_unit_id`),',
        '    `is_active` = VALUES(`is_active`);',
        '',
        'INSERT INTO `bakeshop_product_recipe` (`product_id`, `ingredient_id`, `qty`, `unit_id`, `notes`)',
        'SELECT products.`id`, ingredients.`id`, seed.`qty`, units.`id`, seed.`notes`',
        'FROM tmp_julies_seed_recipes seed',
        'INNER JOIN tmp_julies_seed_products seed_products ON seed_products.`legacy_product_id` = seed.`legacy_product_id`',
        'INNER JOIN tmp_julies_seed_ingredients seed_ingredients ON seed_ingredients.`legacy_ingredient_id` = seed.`legacy_ingredient_id`',
        'INNER JOIN bakeshop_products products ON products.`sku` = seed_products.`sku`',
        'INNER JOIN bakeshop_ingredients ingredients ON ingredients.`sku` = seed_ingredients.`sku`',
        'INNER JOIN bakeshop_units units ON units.`code` = seed.`unit_code`',
        'ON DUPLICATE KEY UPDATE',
        '    `qty` = VALUES(`qty`),',
        '    `notes` = VALUES(`notes`);',
        '',
        'DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_recipes;',
        'DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_products;',
        'DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_ingredients;',
        'DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_units;',
        'DROP TEMPORARY TABLE IF EXISTS tmp_julies_seed_branches;',
        '',
        'COMMIT;',
        '',
    ];

    return implode("\n", $header);
}

$options = getopt('', [
    'host::',
    'port::',
    'database::',
    'username::',
    'password::',
    'output::',
    'format::',
]);

$format = strtolower(trim((string)($options['format'] ?? 'json')));
if (!in_array($format, ['json', 'sql'], true)) {
    fwrite(STDERR, "Unsupported format. Use --format=json or --format=sql.\n");
    exit(1);
}

$host = (string)($options['host'] ?? getenv('JBAKESHOP_LIVE_HOST') ?: '127.0.0.1');
$port = (string)($options['port'] ?? getenv('JBAKESHOP_LIVE_PORT') ?: '3306');
$database = (string)($options['database'] ?? getenv('JBAKESHOP_LIVE_DATABASE') ?: 'jbakeshop_live');
$username = (string)($options['username'] ?? getenv('JBAKESHOP_LIVE_USERNAME') ?: '');
$password = (string)($options['password'] ?? getenv('JBAKESHOP_LIVE_PASSWORD') ?: '');
$outputPath = (string)($options['output'] ?? juliesExportDefaultOutputPath($format));

if ($username === '') {
    fwrite(STDERR, "Missing database username. Pass --username or set JBAKESHOP_LIVE_USERNAME.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$bakeryCategories = [
    'Asian Bread',
    'Bread',
    'Pastry',
];

$dcStoreCodes = ['DC001', 'DCM01', 'DCBLU01'];

$categoryPlaceholders = implode(', ', array_fill(0, count($bakeryCategories), '?'));
$dcStorePlaceholders = implode(', ', array_fill(0, count($dcStoreCodes), '?'));

$branchSql = '
    SELECT
        s.store_id,
        s.store_code,
        s.name,
        s.address,
        s.type,
        s.is_active,
        s.created_at,
        s.updated_at
    FROM stores s
    WHERE s.deleted_at IS NULL
      AND s.is_active = 1
      AND s.type IN (\'branch\', \'outlet\')
      AND s.name LIKE \'Julies %\'
      AND s.store_code NOT IN (' . $dcStorePlaceholders . ')
    ORDER BY s.store_code ASC, s.store_id ASC
';

$branchStmt = $pdo->prepare($branchSql);
$branchStmt->execute($dcStoreCodes);
$branches = $branchStmt->fetchAll();

if ($branches === []) {
    fwrite(STDERR, "No Julie's branches matched the export filter.\n");
    exit(1);
}

$productSql = '
    SELECT
        p.product_id,
        p.sku,
        p.name,
        p.category,
        p.yield,
        p.is_active,
        p.created_at,
        p.updated_at
    FROM products p
    WHERE p.deleted_at IS NULL
      AND p.category IN (' . $categoryPlaceholders . ')
      AND p.product_id NOT IN (
          SELECT DISTINCT spi.product_id
          FROM store_product_inclusions spi
          INNER JOIN stores s ON s.store_id = spi.store_id
          WHERE s.store_code IN (' . $dcStorePlaceholders . ')
      )
    ORDER BY p.category ASC, p.name ASC, p.product_id ASC
';

$productStmt = $pdo->prepare($productSql);
$productStmt->execute(array_merge($bakeryCategories, $dcStoreCodes));
$products = $productStmt->fetchAll();

if ($products === []) {
    fwrite(STDERR, "No Julie's bakery products matched the export filter.\n");
    exit(1);
}

$productIds = array_values(array_map(static fn (array $row): int => (int)$row['product_id'], $products));
$productIdPlaceholders = implode(', ', array_fill(0, count($productIds), '?'));

$ingredientSql = '
    SELECT DISTINCT
        i.ingredient_id,
        i.name,
        i.type,
        i.unit_id,
        i.minimum_stock,
        i.cost_per_unit,
        i.created_at,
        i.updated_at,
        u.name AS unit_name,
        u.code AS unit_code,
        u.measurement_type,
        u.measurement_value
    FROM ingredients i
    INNER JOIN ingredient_units u ON u.id = i.unit_id
    INNER JOIN product_ingredients pi ON pi.ingredient_id = i.ingredient_id
    WHERE pi.product_id IN (' . $productIdPlaceholders . ')
      AND pi.quantity > 0
    ORDER BY i.name ASC, i.ingredient_id ASC
';

$ingredientStmt = $pdo->prepare($ingredientSql);
$ingredientStmt->execute($productIds);
$ingredients = $ingredientStmt->fetchAll();

$recipeSql = '
    SELECT
        pi.product_id,
        pi.ingredient_id,
        pi.quantity
    FROM product_ingredients pi
    WHERE pi.product_id IN (' . $productIdPlaceholders . ')
      AND pi.quantity > 0
    ORDER BY pi.product_id ASC, pi.ingredient_id ASC
';

$recipeStmt = $pdo->prepare($recipeSql);
$recipeStmt->execute($productIds);
$recipes = $recipeStmt->fetchAll();

$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

if ($format === 'sql') {
    $seedSql = juliesExportBuildSqlSeed($host, $port, $database, $bakeryCategories, $dcStoreCodes, $branches, $products, $ingredients, $recipes);
    file_put_contents($outputPath, $seedSql);
} else {
    $fixture = [
        'meta' => [
            'source' => [
                'host' => $host,
                'port' => $port,
                'database' => $database,
            ],
            'generated_at' => gmdate('c'),
            'filter' => [
                'bakery_categories' => $bakeryCategories,
                'excluded_dc_store_codes' => $dcStoreCodes,
                'rule' => 'Products must be in the bakery category allow-list and must not be explicitly included for DC stores.',
                'branch_rule' => 'Branches must be active legacy stores with type branch/outlet, name like Julies %, and non-DC store codes.',
                'recipe_qty_must_be_positive' => true,
            ],
            'counts' => [
                'branches' => count($branches),
                'products' => count($products),
                'ingredients' => count($ingredients),
                'recipes' => count($recipes),
            ],
        ],
        'branches' => $branches,
        'products' => $products,
        'ingredients' => $ingredients,
        'recipes' => $recipes,
    ];

    $encoded = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fwrite(STDERR, "Unable to encode fixture JSON.\n");
        exit(1);
    }

    file_put_contents($outputPath, $encoded . PHP_EOL);
}

fwrite(STDOUT, sprintf(
    "Exported %d branches, %d products, %d ingredients, and %d recipe rows to %s (%s)\n",
    count($branches),
    count($products),
    count($ingredients),
    count($recipes),
    $outputPath,
    $format
));