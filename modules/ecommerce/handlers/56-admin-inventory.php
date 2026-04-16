<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Inventory (handlers/56-admin-inventory.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/admin/inventory
 *
 * Full inventory overview: all products with stock tracking, search/filter,
 * low-stock badges, and WMS source indicator.
 */
function ecAdminInventory(): void
{
    $user  = ecRequireAdmin();
    $input = ecInput();

    $search  = trim((string)($input['search'] ?? ''));
    $filter  = trim((string)($input['filter'] ?? ''));  // all | low | out
    $storeId = max(0, (int)($input['store_id'] ?? 0));
    $page    = max(1, (int)($input['page'] ?? 1));
    $perPage = 50;

    $inventory = ecInventoryList([
        'search'   => $search,
        'filter'   => $filter,
        'store_id' => $storeId > 0 ? $storeId : null,
        'limit'    => $perPage,
        'offset'   => ($page - 1) * $perPage,
    ]);

    $stores = (function_exists('ecIsMultiStoreEnabled') ? ecIsMultiStoreEnabled() : ecStoreIsMultiStoreActive())
        ? (ecStoreList(['active_only' => false])['items'] ?? [])
        : [];

    $integrationMode = function_exists('ecActiveIntegrationMode') ? ecActiveIntegrationMode() : '';

    $ctx = ecAdminContext($user, 'inventory', [
        'page_title'       => 'Ecommerce — Inventory',
        'items'            => $inventory['items'],
        'total'            => $inventory['total'],
        'search'           => $search,
        'filter'           => $filter,
        'store_id'         => $storeId,
        'stores'           => $stores,
        'multi_store'      => count($stores) > 1,
        'page'             => $page,
        'per_page'         => $perPage,
        'total_pages'      => (int)ceil(max(1, $inventory['total']) / $perPage),
        'low_stock_threshold' => (int)ecSettings('low_stock_threshold'),
        'integration_mode' => $integrationMode,
        'message'          => $_SESSION['ec_message'] ?? null,
        'import_errors'    => $_SESSION['ec_inventory_import_errors'] ?? null,
    ]);
    unset($_SESSION['ec_message'], $_SESSION['ec_inventory_import_errors']);

    ecRender('modules/ecommerce/admin/inventory.disyl', $ctx);
}

/**
 * Full inventory list with search, filter, and pagination.
 */
function ecInventoryList(array $params = []): array
{
    $search   = trim((string)($params['search'] ?? ''));
    $filter   = trim((string)($params['filter'] ?? ''));
    $storeId  = max(0, (int)($params['store_id'] ?? 0));
    $limit    = max(1, (int)($params['limit'] ?? 50));
    $offset   = max(0, (int)($params['offset'] ?? 0));
    $threshold = (int)ecSettings('low_stock_threshold');

    try {
        $join = '';
        $where = '';
        $queryParams = [];

        if ($storeId > 0) {
            $join .= ' INNER JOIN ec_store_product_overrides store_po ON store_po.product_id = c.id AND store_po.store_id = ? AND store_po.is_visible = 1';
            $queryParams[] = $storeId;
        }

        if ($search !== '') {
            $where .= ' AND (c.title LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(ec.config, \'$.sku\')) LIKE ?)';
            $queryParams[] = '%' . $search . '%';
            $queryParams[] = '%' . $search . '%';
        }

        // Count total
        $countSql = "SELECT COUNT(DISTINCT c.id)
             FROM cms_content c
             {$join}
             INNER JOIN cms_entity_capabilities ec ON ec.entity_id = c.id AND ec.capability_id = 'inventory'
             WHERE c.type = 'product'
               AND c.deleted_at IS NULL
               AND JSON_EXTRACT(ec.config, '$.track_stock') = true
               {$where}";
        $total = (int)ecDb()->query($countSql, $queryParams)->fetchColumn();

        // Fetch rows
        $sql = "SELECT c.id, c.title, c.slug, c.status,
                    ec.config AS inventory_config,
                    COALESCE(dm.meta_value, '0') AS is_digital
             FROM cms_content c
             {$join}
             INNER JOIN cms_entity_capabilities ec ON ec.entity_id = c.id AND ec.capability_id = 'inventory'
             LEFT JOIN cms_content_meta dm ON dm.content_id = c.id AND dm.meta_key = '_is_digital'
             WHERE c.type = 'product'
               AND c.deleted_at IS NULL
               AND JSON_EXTRACT(ec.config, '$.track_stock') = true
               {$where}
             ORDER BY CAST(JSON_EXTRACT(ec.config, '$.stock_qty') AS SIGNED) ASC, c.title ASC
             LIMIT ? OFFSET ?";

        $fetchParams = array_merge($queryParams, [$limit, $offset]);
        $candidates = ecDb()->query($sql, $fetchParams)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Warm WMS snapshot cache
        ecWmsInventorySnapshotMapForSkus(array_map(static function (array $c): string {
            $config = (array)json_decode((string)($c['inventory_config'] ?? '{}'), true);
            return (string)($config['sku'] ?? '');
        }, $candidates));

        $rows = [];
        foreach ($candidates as $candidate) {
            $config = (array)json_decode((string)($candidate['inventory_config'] ?? '{}'), true);
            $inventory = ecProductInventoryStateFromConfig(
                $config,
                (string)($candidate['is_digital'] ?? '0') === '1',
                $threshold
            );

            $stockQty = (int)($inventory['stock_qty'] ?? 0);
            $source   = (string)($inventory['source'] ?? 'ecommerce');

            // Apply filter
            if ($filter === 'low' && !(!empty($inventory['low_stock']) && !empty($inventory['in_stock']))) {
                continue;
            }
            if ($filter === 'out' && empty($inventory['out_of_stock'])) {
                continue;
            }

            $rows[] = [
                'id'           => (int)$candidate['id'],
                'title'        => (string)($candidate['title'] ?? ''),
                'slug'         => (string)($candidate['slug'] ?? ''),
                'status'       => (string)($candidate['status'] ?? ''),
                'sku'          => (string)($inventory['sku'] ?? ''),
                'stock_qty'    => $stockQty,
                'track_stock'  => true,
                'in_stock'     => !empty($inventory['in_stock']),
                'out_of_stock' => !empty($inventory['out_of_stock']),
                'low_stock'    => !empty($inventory['low_stock']),
                'source'       => $source,
                'badge'        => $inventory['badge'] ?? ['label' => 'In Stock', 'tone' => 'success'],
            ];
        }

        // When filtering client-side, adjust total for the filtered view
        if ($filter !== '' && $filter !== 'all') {
            $total = count($rows);
        }

        return ['items' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        write_log('ecInventoryList error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return ['items' => [], 'total' => 0];
    }
}
