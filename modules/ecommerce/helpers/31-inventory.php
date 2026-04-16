<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Inventory & WMS helpers (helpers/31-inventory.php)
//
// Extracted from 30-products.php. Contains:
//   - ecProductInventoryStateFromConfig()
//   - ecProductApplyWmsInventorySnapshot()
//   - ecProductInventory() / ecProductUpdateInventory()
//   - ecProductDecrementStock() / ecProductIncrementStock()
//   - ecWmsInventorySnapshotMapForSkus() and friends
//   - ecWmsInventoryWarmProductCollection()
// ─────────────────────────────────────────────────────────────────────────

function ecProductInventoryStateFromConfig(array $config, bool $isDigital, int $lowStockThreshold): array
{
    if (empty($config)) {
        return [
            'in_stock' => true,
            'out_of_stock' => false,
            'low_stock' => false,
            'stock_qty' => 0,
            'sku' => '',
            'track_stock' => false,
            'badge' => ['label' => '', 'tone' => ''],
        ];
    }

    if ($isDigital) {
        return [
            'in_stock' => true,
            'out_of_stock' => false,
            'low_stock' => false,
            'stock_qty' => 0,
            'sku' => $config['sku'] ?? '',
            'track_stock' => false,
            'badge' => ['label' => '', 'tone' => ''],
        ];
    }

    $trackStock = (bool)($config['track_stock'] ?? true);
    $stockQty = (int)($config['stock_qty'] ?? 0);
    $inventory = [
        'track_stock' => $trackStock,
        'stock_qty' => $stockQty,
        'badge' => [
            'label' => ($trackStock && $stockQty <= 0 ? 'Out of stock' : ''),
            'tone' => ($trackStock && $stockQty <= 0 ? 'negative' : ''),
        ],
        'sku' => $config['sku'] ?? '',
        'in_stock' => !$trackStock || $stockQty > 0,
        'out_of_stock' => $trackStock && $stockQty <= 0,
        'low_stock' => $trackStock && $stockQty > 0 && $stockQty <= $lowStockThreshold,
    ];

    return ecProductApplyWmsInventorySnapshot($inventory);
}

function ecProductApplyWmsInventorySnapshot(array $inventory): array
{
    $wmsSnapshot = ecWmsInventorySnapshotForSku((string)($inventory['sku'] ?? ''));
    if ($wmsSnapshot === []) {
        return $inventory;
    }

    return array_merge($inventory, $wmsSnapshot, [
        'badge' => [
            'label' => !empty($wmsSnapshot['out_of_stock']) ? 'Out of stock' : '',
            'tone' => !empty($wmsSnapshot['out_of_stock']) ? 'negative' : '',
        ],
    ]);
}

function ecWmsInventoryWarmProductCollection(array $products): void
{
    $skus = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        foreach ([
            (string)($product['sku'] ?? ''),
            (string)($product['inventory']['sku'] ?? ''),
            (string)($product['capability_data']['inventory']['sku'] ?? ''),
        ] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $skus[] = $candidate;
            }
        }
    }

    ecWmsInventorySnapshotMapForSkus($skus);
}

/**
 * Get inventory config for a product.
 */
function ecProductInventory(int $productId): array
{
    try {
        $db  = ecDb();
        $row = $db->query(
            "SELECT config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'inventory' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['in_stock' => true, 'out_of_stock' => false, 'low_stock' => false, 'stock_qty' => 0, 'sku' => '', 'track_stock' => false, 'badge' => ['label' => '', 'tone' => '']];
        }

        $config     = (array)json_decode($row['config'] ?? '{}', true);

        // Digital products are always available — never block on stock.
        $digitalMeta = $db->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_is_digital' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC);
        $threshold  = (int)ecSettings('low_stock_threshold');

        return ecProductInventoryStateFromConfig(
            $config,
            ($digitalMeta['meta_value'] ?? '') === '1',
            $threshold
        );
    } catch (\Throwable $e) {
        return [];
    }
}

function ecProductUpdateInventory(int $productId, array $data): void
{
    if (!function_exists('cmsEntityAttachCapability')) {
        return;
    }
    $existing = ecProductInventory($productId);
    $prevQty  = (int)($existing['stock_qty'] ?? 0);
    $sku      = ecProductNormalizeSku($productId, $data['sku'] ?? ($existing['sku'] ?? ''));
    $config   = [
        'track_stock' => isset($data['track_stock']) ? (bool)$data['track_stock'] : ($existing['track_stock'] ?? true),
        'stock_qty'   => isset($data['stock_qty'])   ? (int)$data['stock_qty']    : $prevQty,
        'sku'         => $sku,
    ];
    $newQty = $config['stock_qty'];

    ecAttachCmsEntityCapability($productId, 'inventory', $config);

    // Fire back-in-stock notification when a manual update restocks from zero
    if (function_exists('ecStockNotificationCheckAndTrigger')) {
        ecStockNotificationCheckAndTrigger($productId, null, $prevQty, $newQty);
    }
}

function ecProductDecrementStock(int $productId, int $qty): bool
{
    $db = ecDb();

    // Digital products have unlimited availability — skip stock decrement.
    $digitalMeta = $db->query(
        "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_is_digital' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (($digitalMeta['meta_value'] ?? '') === '1') {
        return true;
    }

    // Read is fine via ecDb() (reads_tables), but update needs CMS context
    $row = $db->query(
        "SELECT id, config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'inventory' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        return true; // no inventory capability — nothing to decrement
    }

    $config     = (array)json_decode($row['config'] ?? '{}', true);
    $trackStock = (bool)($config['track_stock'] ?? true);
    if (!$trackStock) {
        return true;
    }

    // Atomic decrement: UPDATE with WHERE stock >= qty prevents overselling.
    // Uses JSON_SET to decrement in-place so concurrent requests cannot both
    // read the same value and clobber each other.
    $rowId = (int)$row['id'];
    $decremented = moduleWithContext('cms', static function () use ($rowId, $qty): bool {
        $stmt = cmsDb()->query(
            "UPDATE cms_entity_capabilities
             SET config = JSON_SET(
                 config,
                 '$.stock_qty',
                 GREATEST(0, CAST(COALESCE(JSON_EXTRACT(config, '$.stock_qty'), 0) AS SIGNED) - ?)
             ),
             updated_at = NOW()
             WHERE id = ?
               AND CAST(COALESCE(JSON_EXTRACT(config, '$.stock_qty'), 0) AS SIGNED) >= ?",
            [$qty, $rowId, $qty]
        );
        return $stmt->rowCount() > 0;
    });

    if (!$decremented) {
        return false; // insufficient stock
    }

    // Re-read to check if out of stock for event
    $updatedRow = $db->query(
        "SELECT config FROM cms_entity_capabilities WHERE id = ? LIMIT 1",
        [$rowId]
    )->fetch(\PDO::FETCH_ASSOC);
    $newQty = (int)(json_decode($updatedRow['config'] ?? '{}', true)['stock_qty'] ?? 0);

    // Fire out-of-stock event if reached zero
    if ($newQty === 0) {
        $product = ecProductGet($productId);
        app()->events()->fire('ecommerce.product.out_of_stock', [
            'product_id'    => $productId,
            'product_title' => $product['title'] ?? '',
            'sku'           => $config['sku']    ?? '',
        ]);
    }

    return true;
}

function ecProductIncrementStock(int $productId, int $qty): void
{
    if ($qty < 1) {
        return;
    }

    $db = ecDb();

    $digitalMeta = $db->query(
        "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_is_digital' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (($digitalMeta['meta_value'] ?? '') === '1') {
        return;
    }

    $row = $db->query(
        "SELECT id, config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'inventory' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $config = (array)json_decode($row['config'] ?? '{}', true);
    $trackStock = (bool)($config['track_stock'] ?? true);
    if (!$trackStock) {
        return;
    }

    $prevQty            = (int)($config['stock_qty'] ?? 0);
    $config['stock_qty'] = max(0, $prevQty + $qty);
    $newQty             = $config['stock_qty'];

    moduleWithContext('cms', static function () use ($config, $row): void {
        cmsDb()->execute(
            "UPDATE cms_entity_capabilities SET config = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($config), (int)$row['id']]
        );
    });

    // Fire back-in-stock notification when restocked from zero
    if (function_exists('ecStockNotificationCheckAndTrigger')) {
        ecStockNotificationCheckAndTrigger($productId, null, $prevQty, $newQty);
    }
}

function ecWmsInventorySnapshotFromRows(array $rows, int $threshold): array
{
    if ($rows === []) {
        return [];
    }

    $qtyOnHand = 0.0;
    $qtyReserved = 0.0;
    $qtyStaged = 0.0;
    $qtyAvailable = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $qtyOnHand += (float)($row['qty_on_hand'] ?? 0);
        $qtyReserved += (float)($row['qty_reserved'] ?? 0);
        $qtyStaged += (float)($row['qty_staged'] ?? 0);
        $qtyAvailable += (float)($row['qty_available'] ?? 0);
    }

    return [
        'track_stock' => true,
        'stock_qty' => (int)round($qtyAvailable),
        'qty_on_hand' => $qtyOnHand,
        'qty_reserved' => $qtyReserved,
        'qty_staged' => $qtyStaged,
        'qty_available' => $qtyAvailable,
        'in_stock' => $qtyAvailable > 0,
        'out_of_stock' => $qtyAvailable <= 0,
        'low_stock' => $qtyAvailable > 0 && $qtyAvailable <= $threshold,
        'source' => 'wms',
    ];
}

function ecWmsInventorySnapshotMapForSkus(array $skus): array
{
    static $cache = [];

    $normalizedSkus = array_values(array_unique(array_filter(array_map(
        static fn(mixed $value): string => strtoupper(trim((string)$value)),
        $skus
    ), static fn(string $value): bool => $value !== '')));
    if ($normalizedSkus === []) {
        return [];
    }

    // Phase 7A: per-store inventory source resolution.
    // This MUST run before the global integration-mode guard so that stores with
    // source_type='wms' work even when the global mode is ecommerce_authoritative_products.
    $warehouseId = 0;
    if (function_exists('ecStoreResolveContext') && function_exists('ecStoreInventorySource')) {
        $storeCtx = ecStoreResolveContext();
        if ($storeCtx !== null) {
            $storeId = (int)($storeCtx['id'] ?? 0);
            if ($storeId > 0) {
                $invSrc = ecStoreInventorySource($storeId);
                if (is_array($invSrc)) {
                    if (($invSrc['source_type'] ?? '') === 'local') {
                        // Store uses local stock_qty — WMS snapshot not applicable for this store.
                        return [];
                    }
                    if (($invSrc['source_type'] ?? '') === 'wms') {
                        $warehouseId = max(0, (int)($invSrc['warehouse_id'] ?? 0));
                    }
                }
            }
        }
    }

    // Global integration-mode guard: only applies when no per-store warehouse was resolved.
    if ($warehouseId <= 0) {
        $integrationMode = ecActiveIntegrationMode();
        if ($integrationMode !== 'wms_authoritative_products') {
            $integrationMode = ecActiveIntegrationMode(true);
        }
        if ($integrationMode !== 'wms_authoritative_products') {
            return [];
        }
        $warehouseId = max(0, (int)ecSettings('default_wms_warehouse_id'));
    }

    $threshold = (int)ecSettings('low_stock_threshold');
    $warehouseKey = (string)$warehouseId;
    if (!isset($cache[$warehouseKey]) || !is_array($cache[$warehouseKey])) {
        $cache[$warehouseKey] = [];
    }

    $missingSkus = [];
    foreach ($normalizedSkus as $normalizedSku) {
        if (!array_key_exists($normalizedSku, $cache[$warehouseKey])) {
            $missingSkus[] = $normalizedSku;
        }
    }

    if ($missingSkus !== []) {
        try {
            if (!function_exists('wms_cap_wms_stock_query_1')) {
                foreach ($missingSkus as $missingSku) {
                    $cache[$warehouseKey][$missingSku] = [];
                }
            } else {
                $result = moduleWithContext('wms', static function () use ($missingSkus, $warehouseId): array {
                    return wms_cap_wms_stock_query_1([
                        'warehouse_id' => $warehouseId,
                        'filters' => ['skus' => $missingSkus],
                    ]);
                });
                $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
                $groupedRows = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $rowSku = strtoupper(trim((string)($row['sku'] ?? '')));
                    if ($rowSku === '' || !in_array($rowSku, $missingSkus, true)) {
                        continue;
                    }

                    if (!isset($groupedRows[$rowSku])) {
                        $groupedRows[$rowSku] = [];
                    }
                    $groupedRows[$rowSku][] = $row;
                }

                foreach ($missingSkus as $missingSku) {
                    $cache[$warehouseKey][$missingSku] = ecWmsInventorySnapshotFromRows($groupedRows[$missingSku] ?? [], $threshold);
                }
            }
        } catch (\Throwable $e) {
            foreach ($missingSkus as $missingSku) {
                $cache[$warehouseKey][$missingSku] = [];
            }
        }
    }

    $snapshots = [];
    foreach ($normalizedSkus as $normalizedSku) {
        $snapshots[$normalizedSku] = $cache[$warehouseKey][$normalizedSku] ?? [];
    }

    return $snapshots;
}

function ecWmsInventorySnapshotForSku(string $sku): array
{
    $normalizedSku = strtoupper(trim($sku));
    if ($normalizedSku === '') {
        return [];
    }

    $snapshots = ecWmsInventorySnapshotMapForSkus([$normalizedSku]);
    return $snapshots[$normalizedSku] ?? [];
}