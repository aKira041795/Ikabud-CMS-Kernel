<?php

declare(strict_types=1);

function ecProductAttributeStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (?, ?)' 
        );
        $stmt->execute(['ec_product_attributes', 'ec_product_attribute_values']);
        $ready = (int)$stmt->fetchColumn() === 2;
    } catch (\Throwable $e) {
        $ready = false;
    }

    if ($ready) {
        return true;
    }

    $migrationPath = BASE_PATH . '/modules/ecommerce/database/migrations/015_ec_product_attributes.sql';
    if (!is_file($migrationPath)) {
        return false;
    }

    try {
        $sql = (string)file_get_contents($migrationPath);
        if (trim($sql) !== '') {
            app()->db()->exec($sql);
        }
    } catch (\Throwable $e) {
        write_log('ecProductAttributeStorageAvailable migration fallback failed: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (?, ?)' 
        );
        $stmt->execute(['ec_product_attributes', 'ec_product_attribute_values']);
        $ready = (int)$stmt->fetchColumn() === 2;
    } catch (\Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function ecProductAttributeSlug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = (string)preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? substr($slug, 0, 120) : 'attribute';
}

function ecProductNormalizeAttributeFilters(mixed $filters): array
{
    if (!is_array($filters)) {
        return [];
    }

    $normalized = [];
    foreach ($filters as $attributeSlug => $values) {
        $attributeKey = ecProductAttributeSlug((string)$attributeSlug);
        if ($attributeKey === '') {
            continue;
        }

        if (!is_array($values)) {
            $values = [$values];
        }

        $normalizedValues = [];
        foreach ($values as $value) {
            $valueSlug = ecProductAttributeSlug((string)$value);
            if ($valueSlug === '' || in_array($valueSlug, $normalizedValues, true)) {
                continue;
            }
            $normalizedValues[] = $valueSlug;
        }

        if ($normalizedValues !== []) {
            $normalized[$attributeKey] = $normalizedValues;
        }
    }

    ksort($normalized);
    return $normalized;
}

function ecProductAttributeSelectedValueCount(array $filters): int
{
    $count = 0;
    foreach (ecProductNormalizeAttributeFilters($filters) as $values) {
        $count += count($values);
    }
    return $count;
}

function ecProductAttributeFiltersFromInput(array $input): array
{
    return ecProductNormalizeAttributeFilters($input['attr'] ?? $input['attribute_filters'] ?? []);
}

function ecProductParseAttributeLines(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $attributes = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }

        [$namePart, $valuesPart] = array_map('trim', explode(':', $line, 2));
        if ($namePart === '' || $valuesPart === '') {
            continue;
        }

        $attributeSlug = ecProductAttributeSlug($namePart);
        $values = array_filter(array_map('trim', explode(',', $valuesPart)), static fn(string $value): bool => $value !== '');
        if ($attributeSlug === '' || $values === []) {
            continue;
        }

        $attribute = [
            'slug' => $attributeSlug,
            'name' => $namePart,
            'values' => [],
        ];

        foreach ($values as $value) {
            $valueSlug = ecProductAttributeSlug($value);
            if ($valueSlug === '' || isset($attribute['values'][$valueSlug])) {
                continue;
            }
            $attribute['values'][$valueSlug] = [
                'slug' => $valueSlug,
                'label' => $value,
            ];
        }

        if ($attribute['values'] === []) {
            continue;
        }

        $attribute['values'] = array_values($attribute['values']);
        $attributes[$attributeSlug] = $attribute;
    }

    return array_values($attributes);
}

function ecProductAttributesToLines(array $attributes): string
{
    $lines = [];
    foreach ($attributes as $attribute) {
        if (!is_array($attribute)) {
            continue;
        }
        $name = trim((string)($attribute['name'] ?? ''));
        $values = is_array($attribute['values'] ?? null) ? $attribute['values'] : [];
        $labels = [];
        foreach ($values as $value) {
            $label = trim((string)($value['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if ($name !== '' && $labels !== []) {
            $lines[] = $name . ': ' . implode(', ', $labels);
        }
    }

    return implode("\n", $lines);
}

function ecProductAttributes(int $productId): array
{
    if ($productId < 1 || !ecProductAttributeStorageAvailable()) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT a.id AS attribute_id, a.slug AS attribute_slug, a.name AS attribute_name, a.sort_order AS attribute_sort_order, '
            . 'v.value_slug, v.value_label, v.sort_order '
            . 'FROM ec_product_attribute_values v '
            . 'INNER JOIN ec_product_attributes a ON a.id = v.attribute_id '
            . 'WHERE v.product_id = ? '
            . 'ORDER BY a.sort_order ASC, a.name ASC, v.sort_order ASC, v.value_label ASC',
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $attributes = [];
    foreach ($rows as $row) {
        $attributeSlug = trim((string)($row['attribute_slug'] ?? ''));
        if ($attributeSlug === '') {
            continue;
        }

        if (!isset($attributes[$attributeSlug])) {
            $attributes[$attributeSlug] = [
                'id' => (int)($row['attribute_id'] ?? 0),
                'slug' => $attributeSlug,
                'name' => trim((string)($row['attribute_name'] ?? '')),
                'values' => [],
            ];
        }

        $attributes[$attributeSlug]['values'][] = [
            'slug' => trim((string)($row['value_slug'] ?? '')),
            'label' => trim((string)($row['value_label'] ?? '')),
        ];
    }

    return array_values($attributes);
}

function ecProductSaveAttributes(int $productId, array $attributes): void
{
    if ($productId < 1 || !ecProductAttributeStorageAvailable()) {
        return;
    }

    $db = ecDb();
    $db->execute('DELETE FROM ec_product_attribute_values WHERE product_id = ?', [$productId]);

    $sortOrder = 0;
    foreach ($attributes as $attribute) {
        if (!is_array($attribute)) {
            continue;
        }

        $attributeName = trim((string)($attribute['name'] ?? ''));
        $attributeSlug = ecProductAttributeSlug((string)($attribute['slug'] ?? $attributeName));
        $values = is_array($attribute['values'] ?? null) ? $attribute['values'] : [];
        if ($attributeName === '' || $attributeSlug === '' || $values === []) {
            continue;
        }

        $db->execute(
            'INSERT INTO ec_product_attributes (slug, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order), updated_at = NOW()',
            [$attributeSlug, $attributeName, $sortOrder]
        );

        $attributeId = (int)$db->query('SELECT id FROM ec_product_attributes WHERE slug = ? LIMIT 1', [$attributeSlug])->fetchColumn();
        if ($attributeId < 1) {
            $sortOrder++;
            continue;
        }

        $valueSort = 0;
        foreach ($values as $value) {
            $valueLabel = trim((string)($value['label'] ?? ''));
            $valueSlug = ecProductAttributeSlug((string)($value['slug'] ?? $valueLabel));
            if ($valueLabel === '' || $valueSlug === '') {
                continue;
            }

            $db->execute(
                'INSERT INTO ec_product_attribute_values (product_id, attribute_id, value_slug, value_label, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [$productId, $attributeId, $valueSlug, $valueLabel, $valueSort]
            );
            $valueSort++;
        }

        $sortOrder++;
    }
}

function ecProductAttributeFilterSql(array $attributeFilters, string $contentAlias = 'c'): array
{
    $attributeFilters = ecProductNormalizeAttributeFilters($attributeFilters);
    if ($attributeFilters === [] || !ecProductAttributeStorageAvailable()) {
        return ['join' => '', 'params' => []];
    }

    $joins = [];
    $params = [];
    $index = 0;
    foreach ($attributeFilters as $attributeSlug => $valueSlugs) {
        $valuePlaceholders = implode(',', array_fill(0, count($valueSlugs), '?'));
        $valueAlias = 'pavf' . $index;
        $attributeAlias = 'paf' . $index;
        $joins[] = ' INNER JOIN ec_product_attribute_values ' . $valueAlias . ' ON ' . $valueAlias . '.product_id = ' . $contentAlias . '.id';
        $joins[] = ' INNER JOIN ec_product_attributes ' . $attributeAlias . ' ON ' . $attributeAlias . '.id = ' . $valueAlias . '.attribute_id AND ' . $attributeAlias . '.slug = ? AND ' . $valueAlias . '.value_slug IN (' . $valuePlaceholders . ')';
        $params[] = $attributeSlug;
        $params = array_merge($params, $valueSlugs);
        $index++;
    }

    return ['join' => implode(' ', $joins), 'params' => $params];
}

function ecProductAttributeFacetSummary(array $filters = []): array
{
    if (!ecProductAttributeStorageAvailable()) {
        return [];
    }

    $selectedFilters = ecProductNormalizeAttributeFilters($filters['attribute_filters'] ?? $filters['attributes'] ?? []);
    $search = trim((string)($filters['search'] ?? ''));
    $status = trim((string)($filters['status'] ?? 'published'));
    $categoryId = isset($filters['category_id']) && $filters['category_id'] !== null ? (int)$filters['category_id'] : 0;

    $joinParts = [
        'INNER JOIN ec_product_attribute_values pav ON pav.product_id = c.id',
        'INNER JOIN ec_product_attributes pa ON pa.id = pav.attribute_id',
    ];
    $joinParams = [];
    if ($categoryId > 0) {
        $joinParts[] = 'INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id = ?';
        $joinParams[] = $categoryId;
    }

    $where = ["c.type = 'product'", 'c.deleted_at IS NULL'];
    $params = [];
    if ($status !== '') {
        $where[] = 'c.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(c.title LIKE ? OR c.excerpt LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    try {
        $rows = ecDb()->query(
            'SELECT pa.slug AS attribute_slug, pa.name AS attribute_name, pav.value_slug, pav.value_label, COUNT(DISTINCT c.id) AS product_count '
            . 'FROM cms_content c ' . implode(' ', $joinParts) . ' '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'GROUP BY pa.id, pa.slug, pa.name, pav.value_slug, pav.value_label, pa.sort_order, pav.sort_order '
            . 'ORDER BY pa.sort_order ASC, pa.name ASC, pav.sort_order ASC, pav.value_label ASC',
            array_merge($joinParams, $params)
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $facets = [];
    foreach ($rows as $row) {
        $attributeSlug = trim((string)($row['attribute_slug'] ?? ''));
        $valueSlug = trim((string)($row['value_slug'] ?? ''));
        if ($attributeSlug === '' || $valueSlug === '') {
            continue;
        }

        if (!isset($facets[$attributeSlug])) {
            $facets[$attributeSlug] = [
                'attribute_slug' => $attributeSlug,
                'attribute_name' => trim((string)($row['attribute_name'] ?? '')),
                'values' => [],
            ];
        }

        $facets[$attributeSlug]['values'][] = [
            'value_slug' => $valueSlug,
            'value_label' => trim((string)($row['value_label'] ?? '')),
            'count' => (int)($row['product_count'] ?? 0),
            'is_selected' => in_array($valueSlug, $selectedFilters[$attributeSlug] ?? [], true),
        ];
    }

    return array_values($facets);
}