<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Orders (helpers/20-orders.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Valid order status transitions (from → allowed next statuses).
 */
const EC_ORDER_STATUS_TRANSITIONS = [
    'pending'    => ['processing', 'cancelled'],
    'processing' => ['shipped',    'cancelled'],
    'shipped'    => ['delivered',  'cancelled'],
    'delivered'  => ['refunded'],
    'cancelled'  => [],
    'refunded'   => [],
];

const EC_ORDER_STATUS_RANK = [
    'pending' => 10,
    'processing' => 20,
    'shipped' => 30,
    'delivered' => 40,
    'cancelled' => 50,
    'refunded' => 60,
];

function ecOrdersHasTokenExpiresAtColumn(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        ecDb()->query('SELECT token_expires_at FROM ec_orders WHERE 1 = 0');
        $has = true;
    } catch (\Throwable $e) {
        $has = false;
    }
    return $has;
}

function ecOrderItemsHasSnapshotJsonColumn(): bool
{
    static $has = null;
    if ($has === true) {
        return $has;
    }
    try {
        ecDb()->query('SELECT snapshot_json FROM ec_order_items WHERE 1 = 0');
        $has = true;
    } catch (\Throwable $e) {
        $has = false;
    }
    return $has;
}

function ecOrdersHasWarehouseIdColumn(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        ecDb()->query('SELECT warehouse_id FROM ec_orders WHERE 1 = 0');
        $has = true;
    } catch (\Throwable $e) {
        $has = false;
    }
    return $has;
}

function ecOrderItemsHasStoreIdColumn(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        ecDb()->query('SELECT store_id FROM ec_order_items WHERE 1 = 0');
        $has = true;
    } catch (\Throwable $e) {
        $has = false;
    }
    return $has;
}

function ecOrdersHasStoreIdColumn(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        ecDb()->query('SELECT store_id FROM ec_orders WHERE 1 = 0');
        $has = true;
    } catch (\Throwable $e) {
        $has = false;
    }
    return $has;
}

function ecOrderAssignedStoreIds(int $orderId): array
{
    if ($orderId <= 0) {
        return [];
    }

    $storeIds = [];

    try {
        if (ecOrdersHasStoreIdColumn()) {
            $orderStoreId = (int)(ecDb()->query(
                'SELECT store_id FROM ec_orders WHERE id = ? LIMIT 1',
                [$orderId]
            )->fetchColumn() ?: 0);
            if ($orderStoreId > 0) {
                $storeIds[] = $orderStoreId;
            }
        }

        if (ecOrderItemsHasStoreIdColumn()) {
            $itemStoreIds = ecDb()->query(
                'SELECT DISTINCT store_id FROM ec_order_items WHERE order_id = ? AND store_id IS NOT NULL AND store_id > 0 ORDER BY store_id ASC',
                [$orderId]
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($itemStoreIds as $itemStoreId) {
                $normalizedStoreId = (int)$itemStoreId;
                if ($normalizedStoreId > 0) {
                    $storeIds[] = $normalizedStoreId;
                }
            }
        }
    } catch (\Throwable $e) {
        return [];
    }

    $storeIds = array_values(array_unique(array_map(static fn(mixed $value): int => (int)$value, $storeIds)));
    sort($storeIds);

    return $storeIds;
}

function ecOrderBelongsToStore(int $orderId, int $storeId): bool
{
    if ($orderId <= 0 || $storeId <= 0) {
        return false;
    }

    return in_array($storeId, ecOrderAssignedStoreIds($orderId), true);
}

function ecStoreOrderTaggedItemsPredicate(string $orderAlias = 'o', string $itemAlias = 'store_scope_items'): string
{
    if (!ecOrderItemsHasStoreIdColumn()) {
        return '0=1';
    }

    return 'EXISTS (SELECT 1 FROM ec_order_items ' . $itemAlias
        . ' WHERE ' . $itemAlias . '.order_id = ' . $orderAlias . '.id'
        . ' AND ' . $itemAlias . '.store_id IS NOT NULL'
        . ' AND ' . $itemAlias . '.store_id > 0)';
}

function ecStoreOrderScopePredicate(string $orderAlias = 'o', string $itemAlias = 'store_scope_items'): array
{
    $clauses = [];
    $paramsPerStore = 0;

    if (ecOrdersHasStoreIdColumn()) {
        $clauses[] = $orderAlias . '.store_id = ?';
        $paramsPerStore++;
    }

    if (ecOrderItemsHasStoreIdColumn()) {
        $clauses[] = 'EXISTS (SELECT 1 FROM ec_order_items ' . $itemAlias
            . ' WHERE ' . $itemAlias . '.order_id = ' . $orderAlias . '.id'
            . ' AND ' . $itemAlias . '.store_id = ?)';
        $paramsPerStore++;
    }

    if ($clauses === []) {
        return ['sql' => '1=0', 'params_per_store' => 0];
    }

    return [
        'sql' => '(' . implode(' OR ', $clauses) . ')',
        'params_per_store' => $paramsPerStore,
    ];
}

function ecStoreOwnedLineItemPredicate(string $orderAlias = 'o', string $itemAlias = 'oi', string $scopedAlias = 'store_scope_items'): array
{
    $clauses = [];
    $paramsPerStore = 0;

    if (ecOrderItemsHasStoreIdColumn()) {
        $clauses[] = $itemAlias . '.store_id = ?';
        $paramsPerStore++;
    }

    if (ecOrdersHasStoreIdColumn()) {
        $fallback = $orderAlias . '.store_id = ?';
        $paramsPerStore++;

        if (ecOrderItemsHasStoreIdColumn()) {
            $fallback .= ' AND NOT ' . ecStoreOrderTaggedItemsPredicate($orderAlias, $scopedAlias);
        }

        $clauses[] = '(' . $fallback . ')';
    }

    if ($clauses === []) {
        return ['sql' => '1=0', 'params_per_store' => 0];
    }

    return [
        'sql' => '(' . implode(' OR ', $clauses) . ')',
        'params_per_store' => $paramsPerStore,
    ];
}

function ecStoreScopeQueryParams(int $storeId, int $paramsPerStore): array
{
    if ($storeId <= 0 || $paramsPerStore <= 0) {
        return [];
    }

    return array_fill(0, $paramsPerStore, $storeId);
}

function ecOrderOperationalAuthority(int $orderId): array
{
    $storeIds = ecOrderAssignedStoreIds($orderId);
    $exclusiveStoreId = count($storeIds) === 1 ? (int)($storeIds[0] ?? 0) : 0;
    $authorityStore = null;
    if ($exclusiveStoreId > 0 && function_exists('ecStoreById')) {
        $authorityStore = ecStoreById($exclusiveStoreId);
        if (!is_array($authorityStore)) {
            $authorityStore = null;
        }
    }

    return [
        'scope' => $exclusiveStoreId > 0 ? 'store' : 'global',
        'store_ids' => $storeIds,
        'store_id' => $exclusiveStoreId,
        'store' => $authorityStore,
        'is_store_owned' => $storeIds !== [],
        'is_exclusive_store_owned' => $exclusiveStoreId > 0,
        'can_process_globally' => $exclusiveStoreId <= 0,
    ];
}

function ecOrderAttachOperationalAuthority(array $order): array
{
    $orderId = (int)($order['id'] ?? $order['order_id'] ?? 0);
    if ($orderId <= 0) {
        $order['authority_scope'] = 'global';
        $order['authority_store_id'] = 0;
        $order['authority_store_name'] = '';
        $order['authority_store_slug'] = '';
        $order['can_process_globally'] = true;
        return $order;
    }

    $authority = ecOrderOperationalAuthority($orderId);
    $authorityStore = is_array($authority['store'] ?? null) ? $authority['store'] : null;
    $order['authority_scope'] = (string)($authority['scope'] ?? 'global');
    $order['authority_store_id'] = (int)($authority['store_id'] ?? 0);
    $order['authority_store_name'] = (string)($authorityStore['name'] ?? '');
    $order['authority_store_slug'] = (string)($authorityStore['slug'] ?? '');
    $order['can_process_globally'] = !empty($authority['can_process_globally']);

    return $order;
}

function ecOrdersAttachOperationalAuthority(array $orders): array
{
    return array_map(static fn(array $order): array => ecOrderAttachOperationalAuthority($order), $orders);
}

function ecOrderIsExclusivelyOwnedByStore(int $orderId, int $storeId): bool
{
    if ($orderId <= 0 || $storeId <= 0) {
        return false;
    }

    $authority = ecOrderOperationalAuthority($orderId);

    return !empty($authority['is_exclusive_store_owned'])
        && (int)($authority['store_id'] ?? 0) === $storeId;
}

function ecRefundStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = ecTableExists('ec_refunds') && ecTableExists('ec_refund_items');
    return $ready;
}

function ecRefundGenerateNumber(): string
{
    $year = date('Y');

    try {
        $count = (int)ecDb()->query(
            'SELECT COUNT(*) FROM ec_refunds WHERE YEAR(created_at) = ?',
            [$year]
        )->fetchColumn();
    } catch (\Throwable $e) {
        $count = 0;
    }

    return 'RF-' . $year . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

function ecActiveIntegrationMode(bool $refresh = false): string
{
    return kernelActiveProductIntegrationMode($refresh);
}

function ecManualPaymentModeSetting(): string
{
    $mode = trim((string)ecSettings('manual_payment_mode'));
    if (in_array($mode, ['pay_on_delivery', 'bank_transfer', 'offline_manual'], true)) {
        return $mode;
    }

    return 'pay_on_delivery';
}

function ecManualPaymentLabelSetting(): string
{
    return trim((string)ecSettings('payment_method_label')) ?: 'Manual';
}

function ecOrderManualPaymentMode(array $order): string
{
    $mode = trim((string)($order['meta']['payment_manual_mode'] ?? ''));
    if ($mode !== '') {
        return $mode;
    }

    return ecManualPaymentModeSetting();
}

function ecOrderManualPaymentLabel(array $order): string
{
    $label = trim((string)($order['meta']['payment_manual_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return ecManualPaymentLabelSetting();
}

function ecWmsFulfillmentManagedBridgeNames(): array
{
    return [
        'ecommerce_wms_reserve',
        'ecommerce_wms_order_create',
        'ecommerce_wms_release',
        'ecommerce_wms_refund_release',
        'ecommerce_wms_cancel_order',
        'wms_ecommerce_processing',
        'wms_ecommerce_shipped',
        'wms_ecommerce_tracking_sync',
        'wms_ecommerce_delivered',
        'wms_ecommerce_manual_payment_complete',
    ];
}

function ecOrderRefundBridgeItems(array $order, array $refund): array
{
    $warehouseId = (int)($order['warehouse_id'] ?? 0);
    $bridgeItems = [];
    foreach ((array)($refund['items'] ?? []) as $item) {
        $qty = (float)($item['restock_qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }

        $productId = (int)($item['product_id'] ?? 0);
        if ($productId < 1) {
            continue;
        }

        $bridgeItems[] = [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'qty' => $qty,
            'sku' => (string)($item['sku'] ?? ''),
        ];
    }

    return $bridgeItems;
}

function ecWmsFulfillmentBridgeDefinitions(): array
{
    return [
        [
            'name' => 'ecommerce_wms_reserve',
            'trigger_event' => 'ecommerce.order.created',
            'target_capability' => 'wms.stock.reserve@1',
            'mapping' => [
                'reference_type' => 'order',
                'reference_id' => '{{order.id}}',
                'items' => '{{order.items}}',
                'idempotency_key' => '{{idempotency_key}}',
                'actor_user_id' => '{{actor_user_id}}',
            ],
        ],
        [
            'name' => 'ecommerce_wms_order_create',
            'trigger_event' => 'ecommerce.order.created',
            'target_capability' => 'wms.order.create@1',
            'mapping' => [
                'order_number' => '{{order.order_number}}',
                'external_reference' => '{{order.order_number}}',
                'customer_name' => '{{order.customer_name}}',
                'warehouse_id' => '{{order.warehouse_id}}',
                'ordered_at' => '{{order.created_at}}',
                'items' => '{{order.items}}',
                'meta' => [
                    'source_module' => 'ecommerce',
                    'ecommerce_order_id' => '{{order.id}}',
                    'ecommerce_order_number' => '{{order.order_number}}',
                    'customer_email' => '{{order.customer_email}}',
                ],
            ],
        ],
        [
            'name' => 'ecommerce_wms_release',
            'trigger_event' => 'ecommerce.order.cancelled',
            'target_capability' => 'wms.stock.release@1',
            'mapping' => [
                'reference_type' => 'order',
                'reference_id' => '{{order.id}}',
                'items' => '{{order.items}}',
                'idempotency_key' => '{{idempotency_key}}',
                'actor_user_id' => '{{actor_user_id}}',
            ],
        ],
        [
            'name' => 'ecommerce_wms_refund_release',
            'trigger_event' => 'ecommerce.order.refunded',
            'target_capability' => 'wms.stock.release@1',
            'mapping' => [
                'reference_type' => 'order_refund',
                'reference_id' => '{{order.id}}',
                'warehouse_id' => '{{order.warehouse_id}}',
                'items' => '{{refund.release_items}}',
                'idempotency_key' => '{{idempotency_key}}',
                'actor_user_id' => '{{actor_user_id}}',
            ],
        ],
        [
            'name' => 'ecommerce_wms_cancel_order',
            'trigger_event' => 'ecommerce.order.cancelled',
            'target_capability' => 'wms.order.cancel@1',
            'mapping' => [
                'external_reference' => '{{order.order_number}}',
                'actor_user_id' => '{{actor_user_id}}',
            ],
        ],
        [
            'name' => 'wms_ecommerce_processing',
            'trigger_event' => 'wms.order.picked',
            'target_capability' => 'ecommerce.orders.status.sync@1',
            'mapping' => [
                'order_id' => '{{ecommerce_order_id}}',
                'external_reference' => '{{external_reference}}',
                'status' => 'processing',
                'source' => 'wms_bridge',
                'event' => 'wms.order.picked',
                'wms_order_id' => '{{wms_order_id}}',
                'history_key' => 'wms:{{wms_order_id}}:picked',
                'note' => 'WMS marked the order as picked.',
            ],
        ],
        [
            'name' => 'wms_ecommerce_shipped',
            'trigger_event' => 'wms.order.dispatched',
            'target_capability' => 'ecommerce.orders.status.sync@1',
            'mapping' => [
                'order_id' => '{{ecommerce_order_id}}',
                'external_reference' => '{{external_reference}}',
                'status' => 'shipped',
                'source' => 'wms_bridge',
                'event' => 'wms.order.dispatched',
                'wms_order_id' => '{{wms_order_id}}',
                'history_key' => 'wms:{{wms_order_id}}:dispatched',
                'note' => 'WMS marked the order as dispatched.',
            ],
        ],
        [
            'name' => 'wms_ecommerce_tracking_sync',
            'trigger_event' => 'wms.order.dispatched',
            'target_capability' => 'ecommerce.orders.tracking.sync@1',
            'mapping' => [
                'order_id' => '{{ecommerce_order_id}}',
                'external_reference' => '{{external_reference}}',
                'source' => 'wms_bridge',
                'event' => 'wms.order.dispatched',
                'wms_order_id' => '{{wms_order_id}}',
                'history_key' => 'wms:{{wms_order_id}}:tracking',
                'note' => 'WMS provided shipment tracking.',
                'tracking_number' => '{{tracking_number}}',
                'tracking_carrier' => '{{tracking_carrier}}',
                'tracking_url' => '{{tracking_url}}',
            ],
        ],
        [
            'name' => 'wms_ecommerce_delivered',
            'trigger_event' => 'wms.order.delivered',
            'target_capability' => 'ecommerce.orders.status.sync@1',
            'mapping' => [
                'order_id' => '{{ecommerce_order_id}}',
                'external_reference' => '{{external_reference}}',
                'status' => 'delivered',
                'source' => 'wms_bridge',
                'event' => 'wms.order.delivered',
                'wms_order_id' => '{{wms_order_id}}',
                'history_key' => 'wms:{{wms_order_id}}:delivered',
                'note' => 'WMS marked the order as delivered.',
            ],
        ],
        [
            'name' => 'wms_ecommerce_manual_payment_complete',
            'trigger_event' => 'wms.order.payment_collected',
            'target_capability' => 'ecommerce.orders.payment.sync@1',
            'mapping' => [
                'order_id' => '{{ecommerce_order_id}}',
                'external_reference' => '{{external_reference}}',
                'payment_status' => 'paid',
                'only_if_gateway' => 'manual',
                'only_if_manual_payment_mode' => 'pay_on_delivery',
                'source' => 'wms_bridge',
                'event' => 'wms.order.payment_collected',
                'wms_order_id' => '{{wms_order_id}}',
                'collected_at' => '{{collected_at}}',
                'payment_method' => '{{payment_method}}',
                'history_key' => 'wms:{{wms_order_id}}:paid',
                'note' => 'WMS collected pay-on-delivery payment.',
            ],
        ],
    ];
}

function ecSyncWmsFulfillmentBridges(bool $enabled, ?string $integrationMode = null): array
{
    if (!class_exists(\Ikabud\Kernel\IntegrationBridge::class)) {
        throw new RuntimeException('Integration bridge runtime is unavailable.');
    }

    if (!$enabled) {
        \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames(ecWmsFulfillmentManagedBridgeNames());
        return [];
    }

    $ids = [];
    foreach (ecWmsFulfillmentBridgeDefinitions() as $definition) {
        if (is_string($integrationMode) && $integrationMode !== '') {
            $definition['integration_mode'] = $integrationMode;
        }
        $ids[] = \Ikabud\Kernel\IntegrationBridge::upsertBridge($definition);
    }

    return $ids;
}

function ecWmsProductManagedBridgeNames(): array
{
    return [
        'wms_ecommerce_product_created',
        'wms_ecommerce_product_updated',
        'ecommerce_wms_product_created',
        'ecommerce_wms_product_updated',
    ];
}

function ecWmsProductBridgeDefinitions(string $mode): array
{
    if ($mode === 'wms_authoritative_products') {
        return [
            [
                'name' => 'wms_ecommerce_product_created',
                'trigger_event' => 'wms.product.created',
                'target_capability' => 'ecommerce.product.upsert@1',
                'mapping' => [
                    'sku' => '{{sku}}',
                    'title' => '{{name}}',
                    'excerpt' => '{{description}}',
                    'body' => '{{description}}',
                    'is_active' => '{{is_active}}',
                ],
            ],
            [
                'name' => 'wms_ecommerce_product_updated',
                'trigger_event' => 'wms.product.updated',
                'target_capability' => 'ecommerce.product.upsert@1',
                'mapping' => [
                    'sku' => '{{sku}}',
                    'title' => '{{name}}',
                    'excerpt' => '{{description}}',
                    'body' => '{{description}}',
                    'is_active' => '{{is_active}}',
                ],
            ],
        ];
    }

    if ($mode === 'ecommerce_authoritative_products') {
        return [
            [
                'name' => 'ecommerce_wms_product_created',
                'trigger_event' => 'ecommerce.product.created',
                'target_capability' => 'wms.product.upsert@1',
                'mapping' => [
                    'sku' => '{{sku}}',
                    'name' => '{{title}}',
                    'description' => '{{excerpt}}',
                    'is_active' => '{{is_active}}',
                    'product_type' => '{{product_type}}',
                ],
            ],
            [
                'name' => 'ecommerce_wms_product_updated',
                'trigger_event' => 'ecommerce.product.updated',
                'target_capability' => 'wms.product.upsert@1',
                'mapping' => [
                    'sku' => '{{sku}}',
                    'name' => '{{title}}',
                    'description' => '{{excerpt}}',
                    'is_active' => '{{is_active}}',
                    'product_type' => '{{product_type}}',
                ],
            ],
        ];
    }

    return [];
}

function ecSyncWmsProductAuthorityBridges(?string $mode): array
{
    if (!class_exists(\Ikabud\Kernel\IntegrationBridge::class)) {
        throw new RuntimeException('Integration bridge runtime is unavailable.');
    }

    $managedNames = ecWmsProductManagedBridgeNames();
    $normalizedMode = is_string($mode) ? trim($mode) : '';
    if ($normalizedMode === '' || $normalizedMode === 'decoupled') {
        \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames($managedNames);
        return [];
    }

    $definitions = ecWmsProductBridgeDefinitions($normalizedMode);
    $activeNames = array_values(array_filter(array_map(
        static fn(array $definition): string => trim((string)($definition['name'] ?? '')),
        $definitions
    )));
    $staleNames = array_values(array_diff($managedNames, $activeNames));
    if ($staleNames !== []) {
        \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames($staleNames);
    }

    $ids = [];
    foreach ($definitions as $definition) {
        $definition['integration_mode'] = $normalizedMode;
        $ids[] = \Ikabud\Kernel\IntegrationBridge::upsertBridge($definition);
    }

    return $ids;
}

/**
 * Create a new order from a validated checkout payload.
 *
 * @param array $data {
 *   cart_items, subtotal, discount_amount, tax_amount, shipping_amount, total,
 *   currency, coupon_code, shipping_rate_id, source,
 *   billing: {first_name, last_name, email, address_line1, ...},
 *   shipping: {first_name, last_name, address_line1, ...},
 *   customer_note, customer_id (optional), guest_email, guest_name
 * }
 * @return array  ['order_id' => int, 'order_number' => string, 'confirmation_token' => string]
 */
function ecOrderCreate(array $data): array
{
    $db = ecDb();

    $orderNumber  = ecOrderGenerateNumber();
    $token        = bin2hex(random_bytes(32));
    $customerId   = isset($data['customer_id']) ? (int)$data['customer_id'] : null;
    $source       = in_array($data['source'] ?? '', ['web', 'pos', 'api'], true) ? $data['source'] : 'web';
    $currency     = ecCurrencyNormalizeCode($data['currency'] ?? ecResolveCartItemsCurrencyCode((array)($data['cart_items'] ?? []))) ?: ecStoreBaseCurrencyCode();
    $paymentGatewayConfig = function_exists('ecPaymentGatewayConfig') ? ecPaymentGatewayConfig() : ['gateway' => 'manual'];
    $paymentGateway = trim((string)($paymentGatewayConfig['gateway'] ?? 'manual')) ?: 'manual';
    $manualPaymentMode = $paymentGateway === 'manual' ? ecManualPaymentModeSetting() : '';
    $manualPaymentLabel = $paymentGateway === 'manual' ? ecManualPaymentLabelSetting() : '';

    // Begin DB transaction
    $db->beginTransaction();

    try {
        // Resolve store context from the cart first so global storefront checkouts
        // still attribute the order to the owning store.
        $storeCtx  = function_exists('ecShippingResolveStoreContext')
            ? ecShippingResolveStoreContext((array)($data['cart_items'] ?? []), (array)($data['shipping'] ?? []), [
                'store_id' => max(0, (int)($data['store_id'] ?? 0)),
            ])
            : null;
        if (!is_array($storeCtx) && function_exists('ecStoreResolveContext')) {
            $storeCtx = ecStoreResolveContext();
        }
        $storeId   = $storeCtx ? (int)($storeCtx['id'] ?? 0) : 0;
        $hasStoreId = $storeId > 0 && function_exists('ecStoreStorageAvailable') && ecStoreStorageAvailable();

        // Phase 7B: resolve warehouse_id from store inventory source, fall back to global setting.
        $warehouseId = 0;
        if ($storeId > 0 && function_exists('ecStoreInventorySource')) {
            $invSrc = ecStoreInventorySource($storeId);
            if (is_array($invSrc) && ($invSrc['source_type'] ?? '') === 'wms') {
                $warehouseId = max(0, (int)($invSrc['warehouse_id'] ?? 0));
            }
        }
        if ($warehouseId <= 0) {
            $warehouseId = max(0, (int)ecSettings('default_wms_warehouse_id', 0));
        }
        $hasWarehouseId = $warehouseId > 0 && ecOrdersHasWarehouseIdColumn();

        // Insert order
        $hasTokenExpiry = ecOrdersHasTokenExpiresAtColumn();
        $orderCols = 'order_number, customer_id, guest_email, guest_name, source'
            . ($hasStoreId ? ', store_id' : '')
            . ($hasWarehouseId ? ', warehouse_id' : '')
            . ', status, payment_status, subtotal, discount_amount, tax_amount,
                shipping_amount, total, currency, coupon_code, customer_note,
                confirmation_token, placed_by_user_id' . ($hasTokenExpiry ? ', token_expires_at' : '') . ', created_at, updated_at';
        $orderPlaceholders = '?, ?, ?, ?, ?'
            . ($hasStoreId ? ', ?' : '')
            . ($hasWarehouseId ? ', ?' : '')
            . ', \'pending\', \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . ($hasTokenExpiry ? ', DATE_ADD(NOW(), INTERVAL 90 DAY)' : '') . ', NOW(), NOW()';
        $orderParams = [
            $orderNumber,
            $customerId,
            $data['guest_email'] ?? null,
            $data['guest_name']  ?? null,
            $source,
        ];
        if ($hasStoreId) {
            $orderParams[] = $storeId;
        }
        if ($hasWarehouseId) {
            $orderParams[] = $warehouseId;
        }
        array_push($orderParams,
            (float)($data['subtotal']         ?? 0),
            (float)($data['discount_amount']  ?? 0),
            (float)($data['tax_amount']        ?? 0),
            (float)($data['shipping_amount']   ?? 0),
            (float)($data['total']             ?? 0),
            $currency,
            $data['coupon_code'] ?? null,
            $data['customer_note'] ?? null,
            $token,
            $data['placed_by_user_id'] ?? null,
        );
        $db->execute(
            "INSERT INTO ec_orders ({$orderCols}) VALUES ({$orderPlaceholders})",
            $orderParams
        );

        $orderId = (int)$db->lastInsertId();

        // Insert order items
        $wmsAuthorityActive = ecUsesWmsStockAuthority();
        if (!empty($data['cart_items'])) {
            $itemValues = [];
            $itemParams = [];
            $hasSnapshotJson = ecOrderItemsHasSnapshotJsonColumn();
            $hasItemStoreId  = ecOrderItemsHasStoreIdColumn();
            foreach ($data['cart_items'] as $item) {
                $unitPrice = (float)($item['price_snapshot'] ?? 0);
                $qty       = max(1, (int)($item['qty'] ?? 1));
                $snapshotJson = trim((string)($item['options_json'] ?? (function_exists('ecCartCanonicalOptionsJson') ? ecCartCanonicalOptionsJson($item) : '')));

                // Phase 5 multi-store: resolve store_id for this line item.
                // Prefer explicit store_id on the cart item; fall back to the order-level store.
                $itemStoreId = null;
                if ($hasItemStoreId) {
                    $itemStoreId = isset($item['store_id']) && (int)$item['store_id'] > 0
                        ? (int)$item['store_id']
                        : ($storeId > 0 ? $storeId : null);
                }

                $placeholderCount = $hasSnapshotJson ? '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' : '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                if ($hasItemStoreId) {
                    $placeholderCount = $hasSnapshotJson ? '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' : '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                }
                $itemValues[] = $placeholderCount;
                $rowParams = [
                    $orderId,
                    (int)$item['product_id'],
                    $item['variant_id'] ?? null,
                    $item['product_title'] ?? '',
                    $item['sku']           ?? '',
                    $unitPrice,
                    $qty,
                    round($unitPrice * $qty, 2),
                    $item['variant_label'] ?? null,
                ];
                if ($hasSnapshotJson) {
                    $rowParams[] = $snapshotJson !== '' ? $snapshotJson : null;
                }
                if ($hasItemStoreId) {
                    $rowParams[] = $itemStoreId;
                }
                array_push($itemParams, ...$rowParams);
                // WMS becomes the stock authority once the order-created bridge is active.
                if (!$wmsAuthorityActive) {
                    $decremented = ecProductDecrementStock((int)$item['product_id'], $qty);
                    if (!$decremented) {
                        $productTitle = $item['product_title'] ?? ('Product #' . (int)$item['product_id']);
                        throw new \RuntimeException(
                            json_encode([
                                'type'          => 'insufficient_stock',
                                'product_id'    => (int)$item['product_id'],
                                'product_title' => $productTitle,
                                'requested_qty' => $qty,
                            ]),
                            409
                        );
                    }
                }
            }
            if ($itemValues) {
                $baseColumns = 'order_id, product_id, variant_id, product_title, sku, unit_price, qty, line_total, variant_label';
                if ($hasSnapshotJson) {
                    $baseColumns .= ', snapshot_json';
                }
                if ($hasItemStoreId) {
                    $baseColumns .= ', store_id';
                }
                $db->execute(
                    "INSERT INTO ec_order_items ({$baseColumns}) VALUES " . implode(', ', $itemValues),
                    $itemParams
                );
            }
        }

        $bridgeSnapshot = ecBuildOrderBridgeSnapshot($orderId, $orderNumber, $data, $source);

        // Insert address meta
        $addressFields = ['billing_first_name', 'billing_last_name', 'billing_email',
                          'billing_address_line1', 'billing_address_line2',
                          'billing_city', 'billing_state', 'billing_postal_code',
                          'billing_country', 'billing_phone',
                          'shipping_first_name', 'shipping_last_name',
                          'shipping_address_line1', 'shipping_address_line2',
                          'shipping_city', 'shipping_state', 'shipping_postal_code',
                          'shipping_country', 'shipping_rate_id', 'shipping_carrier'];

        $billing  = $data['billing']  ?? [];
        $shipping = $data['shipping'] ?? [];

        $meta = [];
        foreach ($billing as $k => $v) {
            $meta['billing_' . $k] = $v;
        }
        foreach ($shipping as $k => $v) {
            $meta['shipping_' . $k] = $v;
        }
        if (!empty($data['shipping_rate_id'])) {
            $meta['shipping_rate_id'] = $data['shipping_rate_id'];
        }
        if (!empty($data['loyalty_points_redeemed'])) {
            $meta['loyalty_points_redeemed'] = (string)((int)$data['loyalty_points_redeemed']);
        }
        if (!empty($data['loyalty_discount_amount'])) {
            $meta['loyalty_discount_amount'] = (string)round((float)$data['loyalty_discount_amount'], 2);
        }
        if ($paymentGateway === 'manual') {
            $meta['payment_manual_mode'] = $manualPaymentMode;
            $meta['payment_manual_label'] = $manualPaymentLabel;
        }
        $meta['integration_bridge_snapshot'] = json_encode($bridgeSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $metaValues = [];
        $metaParams = [];
        foreach ($meta as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $metaValues[] = '(?, ?, ?)';
            array_push($metaParams, $orderId, $key, (string)$value);
        }
        
        if ($metaValues) {
            $db->execute(
                "INSERT IGNORE INTO ec_order_meta (order_id, meta_key, meta_value) VALUES " . implode(', ', $metaValues),
                $metaParams
            );
        }

        ecOrderRecordStatusHistory($orderId, 'pending', [
            'source' => 'checkout',
            'history_key' => 'checkout:' . $orderId . ':pending',
            'meta' => ['source' => $source],
        ]);

        // Payment transaction record
        $db->execute(
            "INSERT INTO ec_payment_transactions (order_id, gateway, amount, currency, status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())",
            [
                $orderId,
                $paymentGateway,
                (float)($data['total'] ?? 0),
                $currency,
                null,
            ]
        );

        // Increment coupon uses
        if (!empty($data['coupon_code'])) {
            ecCouponUse(
                (string)$data['coupon_code'],
                (float)($data['discount_amount'] ?? 0),
                $currency,
                $storeId > 0 ? $storeId : null
            );
        }

        $db->commit();

    } catch (\Throwable $e) {
        $db->rollBack();
        write_log('ecOrderCreate failed: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'trace'  => $e->getTraceAsString(),
        ]);
        throw $e;
    }

    if (function_exists('ecBookingCreatePendingRecordsForOrder')) {
        try {
            ecBookingCreatePendingRecordsForOrder($orderId);
        } catch (\Throwable $e) {
            write_log('ecBookingCreatePendingRecordsForOrder failed: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce', 'order_id' => $orderId]);
        }
    }

    $createdEventPayload = ecBuildOrderCreatedEventPayload($orderId, $orderNumber, $data, $source, $bridgeSnapshot);
    if (empty($data['defer_created_event'])) {
        try {
            app()->events()->fire('ecommerce.order.created', $createdEventPayload);
        } catch (\Throwable $e) {
            write_log('ecommerce.order.created event error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
        }
    }

    return [
        'order_id'           => $orderId,
        'order_number'       => $orderNumber,
        'confirmation_token' => $token,
        'created_event_payload' => $createdEventPayload,
    ];
}

function ecUsesWmsStockAuthority(): bool
{
    return \Ikabud\Kernel\IntegrationBridge::hasActiveBridge('ecommerce.order.created', 'wms.stock.reserve@1');
}

function ecUsesWmsOrderCreateBridge(): bool
{
    return \Ikabud\Kernel\IntegrationBridge::hasActiveBridge('ecommerce.order.created', 'wms.order.create@1');
}

function ecOrderHasWmsReservation(int $orderId): bool
{
    if ($orderId <= 0 || !ecTableExists('wms_movements')) {
        return false;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT id FROM wms_movements WHERE reference_type = ? AND reference_id = ? AND movement_type = ? LIMIT 1'
        );
        $stmt->execute(['order', $orderId, 'reserved']);

        return $stmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecOrderHasLinkedWmsOrder(string $orderNumber): bool
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '' || !ecTableExists('wms_orders')) {
        return false;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT id FROM wms_orders WHERE external_reference = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$orderNumber]);

        return $stmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecEnsureCheckoutWmsBridgeApplied(int $orderId): void
{
    if ($orderId <= 0 || !class_exists(\Ikabud\Kernel\IntegrationBridge::class)) {
        return;
    }

    $needsReserve = ecUsesWmsStockAuthority();
    $needsOrderCreate = ecUsesWmsOrderCreateBridge();
    if (!$needsReserve && !$needsOrderCreate) {
        return;
    }

    $order = ecOrderGet($orderId);
    if (!$order) {
        return;
    }

    $hasReservation = !$needsReserve || ecOrderHasWmsReservation($orderId);
    $hasLinkedOrder = !$needsOrderCreate || ecOrderHasLinkedWmsOrder((string)($order['order_number'] ?? ''));
    if ($hasReservation && $hasLinkedOrder) {
        return;
    }

    $snapshot = ecOrderBridgeSnapshot($order);
    \Ikabud\Kernel\IntegrationBridge::handle([
        'order_id' => (int)($order['id'] ?? 0),
        'order_number' => (string)($order['order_number'] ?? ''),
        'customer_email' => (string)($order['customer_email'] ?? $order['guest_email'] ?? ''),
        'total' => (float)($order['total'] ?? 0),
        'source' => (string)($order['source'] ?? 'web'),
        'actor_user_id' => isset($order['placed_by_user_id']) ? (int)$order['placed_by_user_id'] : null,
        'idempotency_key' => ecOrderEventIdempotencyKey($orderId, 'created'),
        'order' => $snapshot,
    ], 'ecommerce.order.created');
}

function ecBuildOrderCreatedEventPayload(int $orderId, string $orderNumber, array $data, string $source, array $bridgeSnapshot): array
{
    return [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'customer_email' => $data['guest_email'] ?? ($data['billing']['email'] ?? ''),
        'total' => (float)($data['total'] ?? 0),
        'currency' => ecCurrencyNormalizeCode($data['currency'] ?? '') ?: ecStoreBaseCurrencyCode(),
        'currency_symbol' => ecCurrencySymbolFor((string)($data['currency'] ?? ecStoreBaseCurrencyCode())),
        'source' => $source,
        'actor_user_id' => isset($data['placed_by_user_id']) ? (int)$data['placed_by_user_id'] : null,
        'idempotency_key' => ecOrderEventIdempotencyKey($orderId, 'created'),
        'order' => $bridgeSnapshot,
    ];
}

function ecOrderEventIdempotencyKey(int $orderId, string $suffix): string
{
    return 'order_' . $orderId . '_' . trim($suffix);
}

function ecBuildOrderBridgeSnapshot(int $orderId, string $orderNumber, array $data, string $source): array
{
    $billing = is_array($data['billing'] ?? null) ? $data['billing'] : [];
    $customerName = trim((string)($billing['first_name'] ?? '') . ' ' . (string)($billing['last_name'] ?? ''));
    $customerEmail = (string)($data['guest_email'] ?? ($billing['email'] ?? ''));

    return [
        'id' => $orderId,
        'order_number' => $orderNumber,
        'warehouse_id' => ecResolveOrderWarehouseId($data),
        'items' => ecBuildOrderEventItems($data),
        'currency' => ecCurrencyNormalizeCode($data['currency'] ?? '') ?: ecStoreBaseCurrencyCode(),
        'currency_symbol' => ecCurrencySymbolFor((string)($data['currency'] ?? ecStoreBaseCurrencyCode())),
        'source' => $source,
        'created_at' => date('Y-m-d H:i:s'),
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'actor_user_id' => isset($data['placed_by_user_id']) ? (int)$data['placed_by_user_id'] : null,
    ];
}

function ecOrderBridgeSnapshot(array $order): array
{
    $raw = (string)($order['meta']['integration_bridge_snapshot'] ?? '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (!isset($decoded['id'])) {
                $decoded['id'] = (int)($order['id'] ?? 0);
            }
            if (!isset($decoded['items']) || !is_array($decoded['items'])) {
                $decoded['items'] = [];
            }
            if (!isset($decoded['order_number'])) {
                $decoded['order_number'] = (string)($order['order_number'] ?? '');
            }
            if (!array_key_exists('warehouse_id', $decoded)) {
                $decoded['warehouse_id'] = 0;
            }
            if (!isset($decoded['source'])) {
                $decoded['source'] = (string)($order['source'] ?? 'web');
            }
            if (!isset($decoded['currency'])) {
                $decoded['currency'] = ecCurrencyNormalizeCode($order['currency'] ?? '') ?: ecStoreBaseCurrencyCode();
            }
            if (!isset($decoded['currency_symbol'])) {
                $decoded['currency_symbol'] = ecCurrencySymbolFor((string)$decoded['currency']);
            }
            if (!isset($decoded['customer_name'])) {
                $decoded['customer_name'] = trim((string)($order['billing']['first_name'] ?? '') . ' ' . (string)($order['billing']['last_name'] ?? ''));
            }
            if (!isset($decoded['customer_email'])) {
                $decoded['customer_email'] = (string)($order['customer_email'] ?? $order['guest_email'] ?? '');
            }

            return $decoded;
        }
    }

    return [
        'id' => (int)($order['id'] ?? 0),
        'order_number' => (string)($order['order_number'] ?? ''),
        'warehouse_id' => (int)ecSettings('default_wms_warehouse_id', 0),
        'items' => ecBuildOrderEventItems([
            'cart_items' => (array)($order['items'] ?? []),
            'warehouse_id' => (int)ecSettings('default_wms_warehouse_id', 0),
        ]),
        'currency' => ecCurrencyNormalizeCode($order['currency'] ?? '') ?: ecStoreBaseCurrencyCode(),
        'currency_symbol' => ecCurrencySymbolFor((string)($order['currency'] ?? ecStoreBaseCurrencyCode())),
        'source' => (string)($order['source'] ?? 'web'),
        'created_at' => (string)($order['created_at'] ?? date('Y-m-d H:i:s')),
        'customer_name' => trim((string)($order['billing']['first_name'] ?? '') . ' ' . (string)($order['billing']['last_name'] ?? '')),
        'customer_email' => (string)($order['customer_email'] ?? $order['guest_email'] ?? ''),
        'actor_user_id' => isset($order['placed_by_user_id']) ? (int)$order['placed_by_user_id'] : null,
    ];
}

function ecResolveOrderWarehouseId(array $data): int
{
    if (isset($data['warehouse_id']) && (int)$data['warehouse_id'] > 0) {
        return (int)$data['warehouse_id'];
    }

    foreach ((array)($data['cart_items'] ?? []) as $item) {
        if ((int)($item['warehouse_id'] ?? 0) > 0) {
            return (int)$item['warehouse_id'];
        }
    }

    return (int)ecSettings('default_wms_warehouse_id', 0);
}

function ecBuildOrderEventItems(array $data): array
{
    $orderWarehouseId = ecResolveOrderWarehouseId($data);
    $eventItems = [];

    foreach ((array)($data['cart_items'] ?? []) as $item) {
        $warehouseId = (int)($item['warehouse_id'] ?? $orderWarehouseId);
        $eventItem = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'qty' => max(1, (int)($item['qty'] ?? 1)),
            'qty_ordered' => max(1, (int)($item['qty'] ?? 1)),
            'warehouse_id' => $warehouseId,
        ];

        if ((int)($item['location_id'] ?? 0) > 0) {
            $eventItem['location_id'] = (int)$item['location_id'];
        }
        if (!empty($item['batch_id'])) {
            $eventItem['batch_id'] = (int)$item['batch_id'];
        }
        if (!empty($item['sku'])) {
            $eventItem['sku'] = (string)$item['sku'];
        }

        $eventItems[] = $eventItem;
    }

    return $eventItems;
}

function ecOrderHydrateData(array $order): array
{
    $meta = is_array($order['meta'] ?? null) ? $order['meta'] : [];

    $billing = [
        'first_name'    => (string)($meta['billing_first_name'] ?? ''),
        'last_name'     => (string)($meta['billing_last_name'] ?? ''),
        'email'         => (string)($meta['billing_email'] ?? ($order['guest_email'] ?? '')),
        'phone'         => (string)($meta['billing_phone'] ?? ''),
        'address_line1' => (string)($meta['billing_address_line1'] ?? ''),
        'address_line2' => (string)($meta['billing_address_line2'] ?? ''),
        'city'          => (string)($meta['billing_city'] ?? ''),
        'state'         => (string)($meta['billing_state'] ?? ''),
        'postal_code'   => (string)($meta['billing_postal_code'] ?? ''),
        'country'       => (string)($meta['billing_country'] ?? ''),
    ];

    $shipping = [
        'first_name'    => (string)($meta['shipping_first_name'] ?? $billing['first_name']),
        'last_name'     => (string)($meta['shipping_last_name'] ?? $billing['last_name']),
        'email'         => (string)($meta['shipping_email'] ?? $billing['email']),
        'phone'         => (string)($meta['shipping_phone'] ?? $billing['phone']),
        'address_line1' => (string)($meta['shipping_address_line1'] ?? $billing['address_line1']),
        'address_line2' => (string)($meta['shipping_address_line2'] ?? $billing['address_line2']),
        'city'          => (string)($meta['shipping_city'] ?? $billing['city']),
        'state'         => (string)($meta['shipping_state'] ?? $billing['state']),
        'postal_code'   => (string)($meta['shipping_postal_code'] ?? $billing['postal_code']),
        'country'       => (string)($meta['shipping_country'] ?? $billing['country']),
    ];

    $order['currency'] = ecCurrencyNormalizeCode($order['currency'] ?? '') ?: ecStoreBaseCurrencyCode();
    $order['currency_symbol'] = ecCurrencySymbolFor((string)$order['currency']);
    $order['total_amount'] = isset($order['total']) ? (float)$order['total'] : (float)($order['total_amount'] ?? 0);
    $order['subtotal_amount'] = isset($order['subtotal']) ? (float)$order['subtotal'] : (float)($order['subtotal_amount'] ?? 0);
    $order['discount_amount'] = (float)($order['discount_amount'] ?? 0);
    $order['tax_amount'] = (float)($order['tax_amount'] ?? 0);
    $order['shipping_amount'] = (float)($order['shipping_amount'] ?? 0);
    $order['loyalty_points_redeemed'] = max(0, (int)($meta['loyalty_points_redeemed'] ?? 0));
    $order['loyalty_discount_amount'] = max(0.0, (float)($meta['loyalty_discount_amount'] ?? 0.0));
    $order['non_loyalty_discount_amount'] = max(0.0, round((float)$order['discount_amount'] - (float)$order['loyalty_discount_amount'], 2));
    $order['total_amount_fmt'] = ecCurrencyFormatAmount((float)$order['total_amount'], (string)$order['currency'], (string)$order['currency_symbol']);
    $order['subtotal_amount_fmt'] = ecCurrencyFormatAmount((float)$order['subtotal_amount'], (string)$order['currency'], (string)$order['currency_symbol']);
    $order['discount_amount_fmt'] = ecCurrencyFormatAmount((float)$order['discount_amount'], (string)$order['currency'], (string)$order['currency_symbol']);
    $order['non_loyalty_discount_amount_fmt'] = ecCurrencyFormatAmount((float)$order['non_loyalty_discount_amount'], (string)$order['currency'], (string)$order['currency_symbol']);
    $order['loyalty_discount_amount_fmt'] = ecCurrencyFormatAmount((float)$order['loyalty_discount_amount'], (string)$order['currency'], (string)$order['currency_symbol']);
    $order['tax_amount_fmt'] = ecCurrencyFormatAmount((float)$order['tax_amount'], (string)$order['currency'], (string)$order['currency_symbol']);
    $order['shipping_amount_fmt'] = ecCurrencyFormatAmount((float)$order['shipping_amount'], (string)$order['currency'], (string)$order['currency_symbol']);
    $order['billing'] = $billing;
    $order['shipping'] = $shipping;
    $order['shipment_tracking'] = ecOrderShipmentTrackingFromMeta($meta);
    $order['customer_email'] = $billing['email'] !== '' ? $billing['email'] : (string)($order['guest_email'] ?? '');
    $order['customer_name'] = trim($billing['first_name'] . ' ' . $billing['last_name']);
    $order['refunds'] = is_array($order['refunds'] ?? null) ? $order['refunds'] : [];
    $order['refund_summary'] = is_array($order['refund_summary'] ?? null)
        ? $order['refund_summary']
        : ecOrderRefundSummary($order, $order['refunds']);

    $refundedItemQuantities = ecOrderRefundedItemQuantities($order['refunds']);
    $order['items'] = is_array($order['items'] ?? null) ? $order['items'] : [];
    foreach ($order['items'] as &$item) {
        $orderItemId = (int)($item['id'] ?? 0);
        $refundedQty = (int)($refundedItemQuantities[$orderItemId] ?? 0);
        $item['refunded_qty'] = $refundedQty;
        $item['refundable_qty'] = max(0, (int)($item['qty'] ?? 0) - $refundedQty);
        $item['currency'] = (string)$order['currency'];
        $item['currency_symbol'] = (string)$order['currency_symbol'];
        if (function_exists('ecHydrateLineItemOptions')) {
            $item = ecHydrateLineItemOptions($item, (string)$order['currency']);
        }
        $item['unit_price_fmt'] = ecCurrencyFormatAmount((float)($item['unit_price'] ?? 0), (string)$order['currency'], (string)$order['currency_symbol']);
        $item['line_total_fmt'] = ecCurrencyFormatAmount((float)($item['line_total'] ?? 0), (string)$order['currency'], (string)$order['currency_symbol']);
    }
    unset($item);

    if (!empty($order['items']) && !empty($order['licenses'])) {
        $licensesByItem = [];
        foreach ($order['licenses'] as $lic) {
            $licensesByItem[$lic['order_item_id']][] = $lic;
        }
        foreach ($order['items'] as &$itm) {
            $iid = $itm['id'];
            if (isset($licensesByItem[$iid])) {
                $itm['licenses'] = $licensesByItem[$iid];
                $itm['license_key'] = $licensesByItem[$iid][0]['license_key'] ?? '';
            }
        }
        unset($itm);
    }

    if (!empty($order['items']) && !empty($order['subscriptions'])) {
        $subscriptionsByItem = [];
        foreach ($order['subscriptions'] as $subscription) {
            $subscriptionsByItem[(int)($subscription['order_item_id'] ?? 0)][] = $subscription;
        }
        foreach ($order['items'] as &$itm) {
            $iid = (int)($itm['id'] ?? 0);
            if (isset($subscriptionsByItem[$iid])) {
                $itm['subscriptions'] = $subscriptionsByItem[$iid];
                $itm['subscription'] = $subscriptionsByItem[$iid][0] ?? null;
            }
        }
        unset($itm);
    }

    if (!empty($order['items']) && !empty($order['memberships'])) {
        $membershipsByItem = [];
        foreach ($order['memberships'] as $membership) {
            $membershipsByItem[(int)($membership['order_item_id'] ?? 0)][] = function_exists('ecMembershipNormalizeRow')
                ? ecMembershipNormalizeRow($membership)
                : $membership;
        }
        foreach ($order['items'] as &$itm) {
            $iid = (int)($itm['id'] ?? 0);
            if (isset($membershipsByItem[$iid])) {
                $itm['memberships'] = $membershipsByItem[$iid];
                $itm['membership'] = $membershipsByItem[$iid][0] ?? null;
            }
        }
        unset($itm);
    }

    if (!empty($order['items']) && !empty($order['bookings'])) {
        $bookingsByItem = [];
        foreach ($order['bookings'] as $booking) {
            $bookingsByItem[(int)($booking['order_item_id'] ?? 0)][] = $booking;
        }
        foreach ($order['items'] as &$itm) {
            $iid = (int)($itm['id'] ?? 0);
            if (isset($bookingsByItem[$iid])) {
                $itm['bookings'] = $bookingsByItem[$iid];
                $itm['booking_record'] = $bookingsByItem[$iid][0] ?? null;
            }
        }
        unset($itm);
    }

    if (function_exists('ecOrderHydrateReturnRequests')) {
        $order = ecOrderHydrateReturnRequests($order);
    }

    if (empty($order['status_history'])) {
        $order['status_history'] = [[
            'status' => 'pending',
            'source' => 'legacy',
            'note' => null,
            'actor_user_id' => null,
            'history_key' => null,
            'meta' => [],
            'created_at' => (string)($order['created_at'] ?? date('Y-m-d H:i:s')),
        ]];
        if ((string)($order['status'] ?? 'pending') !== 'pending') {
            $order['status_history'][] = [[
                'status' => (string)$order['status'],
                'source' => 'legacy',
                'note' => null,
                'actor_user_id' => null,
                'history_key' => null,
                'meta' => [],
                'created_at' => (string)($order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s')),
            ]][0];
        }
    }

    return $order;
}

function ecOrderNormalizeShipmentTracking(array $tracking): array
{
    return [
        'tracking_number' => trim((string)($tracking['tracking_number'] ?? '')),
        'carrier' => trim((string)($tracking['carrier'] ?? $tracking['shipping_carrier'] ?? '')),
        'tracking_url' => trim((string)($tracking['tracking_url'] ?? '')),
    ];
}
function ecOrderShipmentTrackingFromMeta(array $meta): array
{
    $tracking = ecOrderNormalizeShipmentTracking([
        'tracking_number' => $meta['tracking_number'] ?? '',
        'carrier' => $meta['shipping_carrier'] ?? '',
        'tracking_url' => $meta['tracking_url'] ?? '',
    ]);
    $tracking['has_tracking'] = $tracking['tracking_number'] !== '' || $tracking['tracking_url'] !== '' || $tracking['carrier'] !== '';

    return $tracking;
}

function ecOrderSaveShipmentTracking(int $orderId, array $tracking): array
{
    $normalized = ecOrderNormalizeShipmentTracking($tracking);

    foreach ([
        'tracking_number' => $normalized['tracking_number'],
        'shipping_carrier' => $normalized['carrier'],
        'tracking_url' => $normalized['tracking_url'],
    ] as $key => $value) {
        ecDb()->execute(
            "INSERT INTO ec_order_meta (order_id, meta_key, meta_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$orderId, $key, $value]
        );
    }

    $normalized['has_tracking'] = $normalized['tracking_number'] !== '' || $normalized['tracking_url'] !== '' || $normalized['carrier'] !== '';

    return $normalized;
}

/**
 * Fetch a single order with items and meta.
 * ACL: admins see all; customers see own orders; guests need confirmation token.
 */
function ecOrderGet(int $id, ?int $customerId = null, ?string $token = null): ?array
{
    $db = ecDb();

    try {
        $where  = 'o.id = ?';
        $params = [$id];

        if ($customerId !== null) {
            $where  .= ' AND (o.customer_id = ? OR o.confirmation_token = ?)';
            $params[] = $customerId;
            $params[] = $token ?? '';
        } elseif ($token !== null) {
            // F18: Only accept non-expired confirmation tokens.
            $where  .= ' AND o.confirmation_token = ? AND (o.token_expires_at IS NULL OR o.token_expires_at > NOW())';
            $params[] = $token;
        }

        $order = $db->query(
            "SELECT o.* FROM ec_orders o WHERE $where LIMIT 1",
            $params
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        $order['items'] = $db->query(
            "SELECT * FROM ec_order_items WHERE order_id = ? ORDER BY id ASC",
            [$id]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $metaRows = $db->query(
            "SELECT meta_key, meta_value FROM ec_order_meta WHERE order_id = ?",
            [$id]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $order['meta'] = [];
        foreach ($metaRows as $m) {
            $order['meta'][$m['meta_key']] = $m['meta_value'];
        }

        $payment = $db->query(
            "SELECT * FROM ec_payment_transactions WHERE order_id = ? ORDER BY id DESC LIMIT 1",
            [$id]
        )->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($payment) {
            $gateway = trim((string)($payment['gateway'] ?? ''));
            $payment['label'] = $gateway === 'manual'
                ? ecOrderManualPaymentLabel($order)
                : ucfirst($gateway);
            if ($gateway === 'manual') {
                $payment['mode'] = ecOrderManualPaymentMode($order);
            }
            $order['payment'] = $payment;
        } else {
            $order['payment'] = [
                'gateway' => '',
                'label' => '',
            ];
        }

        $order['licenses'] = $db->query(
            "SELECT id, order_item_id, product_id, target_module, target_tier, license_key, download_token, status, created_at, downloaded_at
               FROM ec_order_licenses WHERE order_id = ? ORDER BY id ASC",
            [$id]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $order['subscriptions'] = function_exists('ecSubscriptionsForOrder')
            ? ecSubscriptionsForOrder($id)
            : [];
        $order['memberships'] = function_exists('ecMembershipStorageAvailable') && ecMembershipStorageAvailable()
            ? (ecDb()->query('SELECT * FROM ec_memberships WHERE order_id = ? ORDER BY id ASC', [$id])->fetchAll(\PDO::FETCH_ASSOC) ?: [])
            : [];
        $order['bookings'] = function_exists('ecBookingsForOrder')
            ? ecBookingsForOrder($id)
            : [];

        try {
            $historyRows = $db->query(
                'SELECT status, source, note, actor_user_id, history_key, meta, created_at FROM ec_order_status_history WHERE order_id = ? ORDER BY created_at ASC, id ASC',
                [$id]
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $historyRows = [];
        }

        $order['status_history'] = array_map(static function (array $row): array {
            $meta = [];
            if (!empty($row['meta'])) {
                $decoded = json_decode((string)$row['meta'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            return [
                'status' => (string)($row['status'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'note' => $row['note'] ?? null,
                'actor_user_id' => isset($row['actor_user_id']) ? (int)$row['actor_user_id'] : null,
                'history_key' => $row['history_key'] ?? null,
                'meta' => $meta,
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }, $historyRows);

        $order['refunds'] = ecOrderGetRefunds($id);
        $order['refund_summary'] = ecOrderRefundSummary($order, $order['refunds']);

        return ecOrderHydrateData($order);
    } catch (\Throwable $e) {
        write_log('ecOrderGet error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return null;
    }
}

/**
 * List orders (admin).
 *
 * @return array  ['items' => [...], 'total' => int]
 */
function ecOrderList(array $filters = []): array
{
    $db     = ecDb();

    $hasCmsUsers = false;
    try {
        $db->query('SELECT 1 FROM cms_users LIMIT 1');
        $hasCmsUsers = true;
    } catch (\Throwable $ignored) {}

    $tbl = 'o.';

    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[]  = $tbl . 'status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['payment_status'])) {
        $where[]  = $tbl . 'payment_status = ?';
        $params[] = $filters['payment_status'];
    }
    if (!empty($filters['source'])) {
        $where[]  = $tbl . 'source = ?';
        $params[] = $filters['source'];
    }
    if (!empty($filters['search'])) {
        $s = '%' . $filters['search'] . '%';
        if ($hasCmsUsers) {
            $where[]  = '(o.order_number LIKE ? OR o.guest_email LIKE ? OR o.guest_name LIKE ? OR cu.display_name LIKE ? OR cu.email LIKE ?)';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        } else {
            $where[]  = '(o.order_number LIKE ? OR o.guest_email LIKE ? OR o.guest_name LIKE ?)';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'DATE(' . $tbl . 'created_at) >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'DATE(' . $tbl . 'created_at) <= ?';
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['store_id'])) {
        $storeScope = ecStoreOrderScopePredicate('o', 'store_scope_items');
        $where[] = $storeScope['sql'];
        $params = array_merge($params, ecStoreScopeQueryParams((int)$filters['store_id'], (int)$storeScope['params_per_store']));
    }

    $limit  = min(100, max(1, (int)($filters['limit']  ?? 25)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    $whereStr = implode(' AND ', $where);

    try {
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM ec_orders o"
            . ($hasCmsUsers ? ' LEFT JOIN cms_users cu ON cu.id = o.customer_id' : '')
            . " WHERE $whereStr",
            $params
        )->fetchColumn();

        if ($hasCmsUsers) {
            $rows = $db->query(
                "SELECT o.id, o.order_number, o.customer_id, o.guest_email, o.guest_name, o.source,
                        o.status, o.payment_status, o.total, o.currency, o.created_at,
                        cu.display_name AS customer_display_name, cu.email AS customer_email
                 FROM ec_orders o
                 LEFT JOIN cms_users cu ON cu.id = o.customer_id
                 WHERE $whereStr
                 ORDER BY o.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$limit, $offset])
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            $rows = $db->query(
                "SELECT o.id, o.order_number, o.customer_id, o.guest_email, o.guest_name, o.source, o.status, o.payment_status, o.total, o.currency, o.created_at
                 FROM ec_orders o
                 WHERE $whereStr
                 ORDER BY o.created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$limit, $offset])
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $rows = array_map(static function (array $row): array {
            $currency = ecCurrencyNormalizeCode($row['currency'] ?? '') ?: ecStoreBaseCurrencyCode();
            $row['currency'] = $currency;
            $row['currency_symbol'] = ecCurrencySymbolFor($currency);
            $row['total_amount'] = (float)($row['total'] ?? 0);
            $row['total_amount_fmt'] = ecCurrencyFormatAmount((float)$row['total_amount'], $currency, (string)$row['currency_symbol']);
            $row['resolved_customer_name'] = trim((string)($row['customer_display_name'] ?? ''))
                ?: trim((string)($row['guest_name'] ?? ''))
                ?: trim((string)($row['customer_email'] ?? ''))
                ?: trim((string)($row['guest_email'] ?? ''))
                ?: '';
            return $row;
        }, $rows);

        return ['items' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }
}

/**
 * Update an order's status.
 */
function ecOrderUpdateStatus(int $orderId, string $newStatus, ?string $note = null): bool
{
    return ecOrderUpdateStatusWithOptions($orderId, $newStatus, $note, []);
}

function ecOrderUpdateStatusWithOptions(int $orderId, string $newStatus, ?string $note = null, array $options = []): bool
{
    $order = ecOrderGet($orderId);
    if (!$order) {
        return false;
    }

    $current  = (string)$order['status'];
    $source = trim((string)($options['source'] ?? 'ecommerce_admin')) ?: 'ecommerce_admin';
    $historyKey = trim((string)($options['history_key'] ?? ''));
    $actorUserId = isset($options['actor_user_id']) && (int)$options['actor_user_id'] > 0 ? (int)$options['actor_user_id'] : null;
    $meta = is_array($options['meta'] ?? null) ? $options['meta'] : [];
    $trackingInput = is_array($options['tracking'] ?? null) ? $options['tracking'] : [];
    $tracking = ecOrderNormalizeShipmentTracking($trackingInput);
    $hasTrackingInput = $tracking['tracking_number'] !== '' || $tracking['carrier'] !== '' || $tracking['tracking_url'] !== '';

    if ($hasTrackingInput) {
        ecOrderSaveShipmentTracking($orderId, $tracking);
        $meta = array_merge($meta, array_filter([
            'tracking_number' => $tracking['tracking_number'],
            'tracking_carrier' => $tracking['carrier'],
            'tracking_url' => $tracking['tracking_url'],
        ], static fn(mixed $value): bool => $value !== ''));
    }

    if ($current === $newStatus) {
        if ($historyKey !== '' || $hasTrackingInput) {
            ecOrderRecordStatusHistory($orderId, $newStatus, [
                'source' => $source,
                'note' => $note ?? ($hasTrackingInput ? 'Shipment tracking updated.' : null),
                'actor_user_id' => $actorUserId,
                'history_key' => $historyKey,
                'meta' => $meta,
            ]);
        }
        return true;
    }

    $allowed  = EC_ORDER_STATUS_TRANSITIONS[$current] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        return false;
    }

    $db = ecDb();
    $db->execute("UPDATE ec_orders SET status = ?, updated_at = NOW() WHERE id = ?", [$newStatus, $orderId]);

    if ($note !== null) {
        $db->execute(
            "INSERT INTO ec_order_meta (order_id, meta_key, meta_value) VALUES (?, 'admin_note', ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$orderId, $note]
        );
    }

    ecOrderRecordStatusHistory($orderId, $newStatus, [
        'source' => $source,
        'note' => $note,
        'actor_user_id' => $actorUserId,
        'history_key' => $historyKey,
        'meta' => $meta,
    ]);

    // Fire status-specific events
    $eventKey = match ($newStatus) {
        'shipped'   => 'ecommerce.order.shipped',
        'cancelled' => 'ecommerce.order.cancelled',
        default     => null,
    };
    if ($eventKey) {
        try {
            $eventPayload = ['order_id' => $orderId, 'order_number' => $order['order_number']];
            if ($newStatus === 'cancelled') {
                $bridgeSnapshot = ecOrderBridgeSnapshot($order);
                $eventPayload['idempotency_key'] = ecOrderEventIdempotencyKey($orderId, 'cancelled');
                $eventPayload['customer_email'] = (string)($order['customer_email'] ?? $order['guest_email'] ?? '');
                $eventPayload['source'] = (string)($bridgeSnapshot['source'] ?? $order['source'] ?? 'web');
                $eventPayload['actor_user_id'] = $bridgeSnapshot['actor_user_id'] ?? null;
                $eventPayload['order'] = $bridgeSnapshot;
            } elseif ($newStatus === 'shipped') {
                $shipmentTracking = $hasTrackingInput
                    ? $tracking
                    : ecOrderShipmentTrackingFromMeta((array)($order['meta'] ?? []));
                $eventPayload['tracking_number'] = (string)($shipmentTracking['tracking_number'] ?? '');
                $eventPayload['tracking_carrier'] = (string)($shipmentTracking['carrier'] ?? '');
                $eventPayload['tracking_url'] = (string)($shipmentTracking['tracking_url'] ?? '');
            }
            app()->events()->fire($eventKey, $eventPayload);
        } catch (\Throwable $e) {}
    }

    return true;
}

function ecOrderRecordStatusHistory(int $orderId, string $status, array $options = []): void
{
    if (!ecTableExists('ec_order_status_history')) {
        return;
    }

    $source = trim((string)($options['source'] ?? 'system')) ?: 'system';
    $note = array_key_exists('note', $options) && $options['note'] !== null ? trim((string)$options['note']) : null;
    $actorUserId = isset($options['actor_user_id']) && (int)$options['actor_user_id'] > 0 ? (int)$options['actor_user_id'] : null;
    $historyKey = trim((string)($options['history_key'] ?? ''));
    $meta = is_array($options['meta'] ?? null) ? $options['meta'] : [];

    ecDb()->execute(
        'INSERT INTO ec_order_status_history (order_id, status, source, note, actor_user_id, history_key, meta, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
        [
            $orderId,
            $status,
            $source,
            $note,
            $actorUserId,
            $historyKey !== '' ? $historyKey : null,
            $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]
    );
}

function ecOrderFindByNumber(string $orderNumber): ?array
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return null;
    }

    $orderId = (int)(ecDb()->query('SELECT id FROM ec_orders WHERE order_number = ? LIMIT 1', [$orderNumber])->fetchColumn() ?: 0);
    return $orderId > 0 ? ecOrderGet($orderId) : null;
}

function ecOrderStatusRank(string $status): int
{
    return EC_ORDER_STATUS_RANK[$status] ?? 0;
}

function ecCapResolveOrderForStatusSync(array $payload): ?array
{
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId > 0) {
        $order = ecOrderGet($orderId);
        if ($order !== null) {
            return $order;
        }
    }

    $externalReference = trim((string)($payload['external_reference'] ?? $payload['order_number'] ?? ''));
    if ($externalReference !== '') {
        $order = ecOrderFindByNumber($externalReference);
        if ($order !== null) {
            return $order;
        }
    }

    return null;
}

function ec_cap_orders_status_sync_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    $status = trim((string)($payload['status'] ?? ''));
    if (!isset(EC_ORDER_STATUS_RANK[$status])) {
        return ['ok' => false, 'error' => 'Unsupported status: ' . $status];
    }

    $order = ecCapResolveOrderForStatusSync($payload);
    if ($order === null) {
        return ['ok' => false, 'error' => 'Order not found for status sync.'];
    }

    $currentStatus = (string)($order['status'] ?? 'pending');
    if (ecOrderStatusRank($currentStatus) > ecOrderStatusRank($status)) {
        return [
            'ok' => true,
            'ignored' => true,
            'reason' => 'stale',
            'order_id' => (int)$order['id'],
            'current_status' => $currentStatus,
        ];
    }

    $updated = ecOrderUpdateStatusWithOptions((int)$order['id'], $status, isset($payload['note']) ? (string)$payload['note'] : null, [
        'source' => trim((string)($payload['source'] ?? 'wms_bridge')) ?: 'wms_bridge',
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
        'history_key' => trim((string)($payload['history_key'] ?? '')),
        'meta' => [
            'event' => (string)($payload['event'] ?? ''),
            'wms_order_id' => (int)($payload['wms_order_id'] ?? 0),
            'external_reference' => (string)($payload['external_reference'] ?? ''),
        ],
    ]);

    if (!$updated) {
        return ['ok' => false, 'error' => 'Status transition rejected.', 'order_id' => (int)$order['id'], 'current_status' => $currentStatus];
    }

    return ['ok' => true, 'order_id' => (int)$order['id'], 'status' => $status];
}

function ec_cap_orders_payment_sync_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    $paymentStatus = trim((string)($payload['payment_status'] ?? ''));
    if ($paymentStatus !== 'paid') {
        return ['ok' => false, 'error' => 'Unsupported payment status: ' . $paymentStatus];
    }

    $order = ecCapResolveOrderForStatusSync($payload);
    if ($order === null) {
        return ['ok' => false, 'error' => 'Order not found for payment sync.'];
    }

    $expectedGateway = trim((string)($payload['only_if_gateway'] ?? ''));
    $paymentGateway = trim((string)($order['payment']['gateway'] ?? ''));
    if ($expectedGateway !== '' && $paymentGateway !== $expectedGateway) {
        return [
            'ok' => true,
            'ignored' => true,
            'reason' => 'gateway_mismatch',
            'order_id' => (int)$order['id'],
            'payment_gateway' => $paymentGateway,
        ];
    }

    $expectedManualPaymentMode = trim((string)($payload['only_if_manual_payment_mode'] ?? ''));
    $manualPaymentMode = $paymentGateway === 'manual' ? ecOrderManualPaymentMode($order) : '';
    if ($expectedManualPaymentMode !== '' && $manualPaymentMode !== $expectedManualPaymentMode) {
        return [
            'ok' => true,
            'ignored' => true,
            'reason' => 'manual_payment_mode_mismatch',
            'order_id' => (int)$order['id'],
            'manual_payment_mode' => $manualPaymentMode,
        ];
    }

    if ((string)($order['payment_status'] ?? '') === 'paid') {
        return [
            'ok' => true,
            'ignored' => true,
            'reason' => 'already_paid',
            'order_id' => (int)$order['id'],
        ];
    }

    ecOrderMarkPaid((int)$order['id'], [
        'source' => trim((string)($payload['source'] ?? 'wms_bridge')) ?: 'wms_bridge',
        'event' => trim((string)($payload['event'] ?? '')),
        'wms_order_id' => isset($payload['wms_order_id']) ? (int)$payload['wms_order_id'] : 0,
        'history_key' => trim((string)($payload['history_key'] ?? '')),
        'note' => trim((string)($payload['note'] ?? '')),
        'payment_method' => trim((string)($payload['payment_method'] ?? '')),
        'collected_at' => trim((string)($payload['collected_at'] ?? '')),
        'manual_payment_mode' => $manualPaymentMode,
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
    ]);

    return [
        'ok' => true,
        'order_id' => (int)$order['id'],
        'payment_status' => 'paid',
    ];
}

function ec_cap_orders_tracking_sync_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    $order = ecCapResolveOrderForStatusSync($payload);
    if ($order === null) {
        return ['ok' => false, 'error' => 'Order not found for tracking sync.'];
    }

    $tracking = ecOrderNormalizeShipmentTracking([
        'tracking_number' => $payload['tracking_number'] ?? '',
        'carrier' => $payload['tracking_carrier'] ?? $payload['carrier'] ?? '',
        'tracking_url' => $payload['tracking_url'] ?? '',
    ]);
    if ($tracking['tracking_number'] === '' && $tracking['carrier'] === '' && $tracking['tracking_url'] === '') {
        return [
            'ok' => true,
            'ignored' => true,
            'reason' => 'no_tracking',
            'order_id' => (int)$order['id'],
        ];
    }

    $updated = ecOrderUpdateStatusWithOptions((int)$order['id'], (string)($order['status'] ?? 'pending'), isset($payload['note']) ? (string)$payload['note'] : null, [
        'source' => trim((string)($payload['source'] ?? 'wms_bridge')) ?: 'wms_bridge',
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
        'history_key' => trim((string)($payload['history_key'] ?? '')),
        'tracking' => $tracking,
        'meta' => [
            'event' => (string)($payload['event'] ?? ''),
            'wms_order_id' => (int)($payload['wms_order_id'] ?? 0),
            'external_reference' => (string)($payload['external_reference'] ?? ''),
        ],
    ]);

    if (!$updated) {
        return ['ok' => false, 'error' => 'Tracking sync failed.', 'order_id' => (int)$order['id']];
    }

    return [
        'ok' => true,
        'order_id' => (int)$order['id'],
        'tracking_number' => $tracking['tracking_number'],
        'tracking_carrier' => $tracking['carrier'],
        'tracking_url' => $tracking['tracking_url'],
    ];
}

/**
 * Update payment status (e.g. manual mark-as-paid).
 * Idempotent: if the order is already paid the event is not re-fired,
 * preventing duplicate license generation and email delivery.
 */
function ecOrderMarkPaid(int $orderId, array $options = []): void
{
    $db = ecDb();

    // Only fire the paid event once — prevent double-email from webhook + return-URL
    $currentStatus = (string)($db->query('SELECT payment_status FROM ec_orders WHERE id = ? LIMIT 1', [$orderId])->fetchColumn() ?: '');
    if ($currentStatus === 'paid') {
        return;
    }

    $paymentMeta = array_filter([
        'source' => trim((string)($options['source'] ?? '')),
        'event' => trim((string)($options['event'] ?? '')),
        'wms_order_id' => isset($options['wms_order_id']) && (int)$options['wms_order_id'] > 0 ? (int)$options['wms_order_id'] : null,
        'history_key' => trim((string)($options['history_key'] ?? '')),
        'note' => trim((string)($options['note'] ?? '')),
        'payment_method' => trim((string)($options['payment_method'] ?? '')),
        'collected_at' => trim((string)($options['collected_at'] ?? '')),
        'manual_payment_mode' => trim((string)($options['manual_payment_mode'] ?? '')),
        'actor_user_id' => isset($options['actor_user_id']) && (int)$options['actor_user_id'] > 0 ? (int)$options['actor_user_id'] : null,
    ], static fn(mixed $value): bool => $value !== null && $value !== '');
    $paymentMetaJson = $paymentMeta !== [] ? json_encode($paymentMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $db->execute("UPDATE ec_orders SET payment_status = 'paid', updated_at = NOW() WHERE id = ?", [$orderId]);
    if ($paymentMetaJson !== null) {
        $db->execute(
            "UPDATE ec_payment_transactions
             SET status = 'succeeded',
                 notes = ?,
                 updated_at = NOW()
             WHERE order_id = ? AND status = 'pending'",
            [$paymentMetaJson, $orderId]
        );
        $db->execute(
            "INSERT INTO ec_order_meta (order_id, meta_key, meta_value) VALUES (?, 'payment_completion_meta', ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$orderId, $paymentMetaJson]
        );
    } else {
        $db->execute("UPDATE ec_payment_transactions SET status = 'succeeded', updated_at = NOW() WHERE order_id = ? AND status = 'pending'", [$orderId]);
    }

    $order = ecOrderGet($orderId);
    if ($order) {
        // Resolve store authority so downstream listeners can read store-specific settings.
        $authority = function_exists('ecOrderOperationalAuthority')
            ? ecOrderOperationalAuthority($orderId)
            : ['scope' => 'global', 'store_id' => 0, 'store' => null];

        try {
            app()->events()->fire('ecommerce.order.paid', [
                'order_id'        => $orderId,
                'order_number'    => $order['order_number'],
                'total'           => $order['total'],
                'currency'        => (string)($order['currency'] ?? ''),
                'currency_symbol' => (string)($order['currency_symbol'] ?? ''),
                'customer_email'  => (string)($order['customer_email'] ?? ''),
                'store_id'        => (int)($authority['store_id'] ?? 0),
                'authority_scope' => (string)($authority['scope'] ?? 'global'),
                'store'           => $authority['store'] ?? null,
            ]);
        } catch (\Throwable $e) {}
    }
}

function ecOrderGetRefunds(int $orderId): array
{
    if ($orderId <= 0 || !ecRefundStorageAvailable()) {
        return [];
    }

    try {
        $refundRows = ecDb()->query(
            'SELECT r.*, u.display_name AS created_by_name
             FROM ec_refunds r
             LEFT JOIN cms_users u ON u.id = r.created_by_user_id
             WHERE r.order_id = ?
             ORDER BY r.created_at DESC, r.id DESC',
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    if ($refundRows === []) {
        return [];
    }

    $refundIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row['id'] ?? 0),
        $refundRows
    )));

    $itemsByRefundId = [];
    if ($refundIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($refundIds), '?'));
        try {
            $itemRows = ecDb()->query(
                'SELECT ri.*, oi.product_title, oi.sku, oi.unit_price, oi.qty AS ordered_qty
                 FROM ec_refund_items ri
                 INNER JOIN ec_order_items oi ON oi.id = ri.order_item_id
                 WHERE ri.refund_id IN (' . $placeholders . ')
                 ORDER BY ri.id ASC',
                $refundIds
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $itemRows = [];
        }

        foreach ($itemRows as $row) {
            $refundId = (int)($row['refund_id'] ?? 0);
            if ($refundId < 1) {
                continue;
            }
            $itemsByRefundId[$refundId][] = [
                'id' => (int)($row['id'] ?? 0),
                'order_item_id' => (int)($row['order_item_id'] ?? 0),
                'product_id' => (int)($row['product_id'] ?? 0),
                'product_title' => (string)($row['product_title'] ?? ''),
                'sku' => (string)($row['sku'] ?? ''),
                'unit_price' => (float)($row['unit_price'] ?? 0),
                'ordered_qty' => (int)($row['ordered_qty'] ?? 0),
                'qty_refunded' => (int)($row['qty_refunded'] ?? 0),
                'line_amount' => (float)($row['line_amount'] ?? 0),
                'restock_qty' => (int)($row['restock_qty'] ?? 0),
            ];
        }
    }

    $refunds = [];
    foreach ($refundRows as $row) {
        $refundId = (int)($row['id'] ?? 0);
        $meta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode((string)$row['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $refunds[] = [
            'id' => $refundId,
            'refund_number' => (string)($row['refund_number'] ?? ''),
            'status' => (string)($row['status'] ?? 'completed'),
            'refunded_amount' => (float)($row['refunded_amount'] ?? 0),
            'currency' => (string)($row['currency'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
            'admin_note' => (string)($row['admin_note'] ?? ''),
            'restock_inventory' => !empty($row['restock_inventory']),
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int)$row['created_by_user_id'] : null,
            'created_by_name' => (string)($row['created_by_name'] ?? ''),
            'gateway_refund_id' => (string)($row['gateway_refund_id'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'items' => $itemsByRefundId[$refundId] ?? [],
            'meta' => $meta,
        ];
    }

    return $refunds;
}

function ecOrderRefundSummary(array $order, array $refunds): array
{
    $totalRefundedAmount = 0.0;
    $totalRefundedQty = 0;
    foreach ($refunds as $refund) {
        if ((string)($refund['status'] ?? 'completed') === 'failed') {
            continue;
        }
        $totalRefundedAmount += (float)($refund['refunded_amount'] ?? 0);
        foreach ((array)($refund['items'] ?? []) as $item) {
            $totalRefundedQty += (int)($item['qty_refunded'] ?? 0);
        }
    }

    $currencySymbol = (string)($order['currency_symbol'] ?? ecSettings('currency_symbol'));
    $orderTotal = (float)($order['total'] ?? $order['total_amount'] ?? 0);
    $refundableAmount = max(0.0, round($orderTotal - $totalRefundedAmount, 2));

    return [
        'has_refunds' => $totalRefundedAmount > 0,
        'refund_count' => count($refunds),
        'total_refunded_amount' => round($totalRefundedAmount, 2),
        'total_refunded_amount_fmt' => $currencySymbol . number_format($totalRefundedAmount, 2),
        'refundable_amount' => $refundableAmount,
        'refundable_amount_fmt' => $currencySymbol . number_format($refundableAmount, 2),
        'total_refunded_qty' => $totalRefundedQty,
        'is_fully_refunded' => $refundableAmount <= 0.009,
    ];
}

function ecOrderRefundedItemQuantities(array $refunds): array
{
    $quantities = [];
    foreach ($refunds as $refund) {
        if ((string)($refund['status'] ?? 'completed') === 'failed') {
            continue;
        }
        foreach ((array)($refund['items'] ?? []) as $item) {
            $orderItemId = (int)($item['order_item_id'] ?? 0);
            if ($orderItemId < 1) {
                continue;
            }
            $quantities[$orderItemId] = (int)($quantities[$orderItemId] ?? 0) + (int)($item['qty_refunded'] ?? 0);
        }
    }

    return $quantities;
}

function ecOrderCreateRefund(int $orderId, array $refundItems, array $options = []): array
{
    if (!ecRefundStorageAvailable()) {
        throw new \RuntimeException('Refund storage is unavailable.');
    }

    $order = ecOrderGet($orderId);
    if (!$order) {
        throw new \InvalidArgumentException('Order not found.');
    }
    if ((string)($order['status'] ?? '') === 'cancelled') {
        throw new \InvalidArgumentException('Cancelled orders cannot be refunded.');
    }
    if (!in_array((string)($order['payment_status'] ?? ''), ['paid', 'refunded'], true)) {
        throw new \InvalidArgumentException('Only paid orders can be refunded.');
    }

    $reason = trim((string)($options['reason'] ?? ''));
    if ($reason === '') {
        throw new \InvalidArgumentException('Refund reason is required.');
    }

    $amount = round((float)($options['amount'] ?? 0), 2);
    if ($amount <= 0) {
        throw new \InvalidArgumentException('Refund amount must be greater than zero.');
    }

    $restockInventory = !empty($options['restock_inventory']);
    $adminNote = trim((string)($options['admin_note'] ?? ''));
    $createdByUserId = isset($options['created_by_user_id']) && (int)($options['created_by_user_id'] ?? 0) > 0
        ? (int)$options['created_by_user_id']
        : null;
    $paymentGateway = trim((string)($order['payment']['gateway'] ?? ''));
    $gatewayRefundId = trim((string)($options['gateway_refund_id'] ?? ''));
    $gatewayRefundResult = null;

    $refunds = ecOrderGetRefunds($orderId);
    $refundSummary = ecOrderRefundSummary($order, $refunds);
    if ($amount > (float)($refundSummary['refundable_amount'] ?? 0)) {
        throw new \InvalidArgumentException('Refund amount exceeds the remaining refundable total.');
    }

    $orderItemsById = [];
    foreach ((array)($order['items'] ?? []) as $item) {
        $orderItemsById[(int)($item['id'] ?? 0)] = $item;
    }
    $existingRefundedQuantities = ecOrderRefundedItemQuantities($refunds);

    $normalizedRefundItems = [];
    foreach ($refundItems as $orderItemId => $qtyValue) {
        $normalizedOrderItemId = (int)$orderItemId;
        $qtyRefunded = max(0, (int)$qtyValue);
        if ($normalizedOrderItemId < 1 || $qtyRefunded < 1) {
            continue;
        }
        $orderItem = $orderItemsById[$normalizedOrderItemId] ?? null;
        if (!is_array($orderItem)) {
            throw new \InvalidArgumentException('Refund item could not be matched to the order.');
        }

        $alreadyRefundedQty = (int)($existingRefundedQuantities[$normalizedOrderItemId] ?? 0);
        $maxRefundableQty = max(0, (int)($orderItem['qty'] ?? 0) - $alreadyRefundedQty);
        if ($qtyRefunded > $maxRefundableQty) {
            throw new \InvalidArgumentException('Refund quantity exceeds the remaining refundable quantity for an item.');
        }

        $normalizedRefundItems[] = [
            'order_item_id' => $normalizedOrderItemId,
            'product_id' => (int)($orderItem['product_id'] ?? 0),
            'qty_refunded' => $qtyRefunded,
            'line_amount' => round((float)($orderItem['unit_price'] ?? 0) * $qtyRefunded, 2),
            'restock_qty' => $restockInventory ? $qtyRefunded : 0,
        ];
    }

    if ($restockInventory && $normalizedRefundItems === []) {
        throw new \InvalidArgumentException('Select at least one order item quantity when restocking inventory.');
    }

    $shouldProcessGatewayRefund = empty($options['skip_gateway_refund']);
    if ($shouldProcessGatewayRefund && $gatewayRefundId === '' && in_array($paymentGateway, ['stripe', 'paypal'], true)) {
        $gatewayRefundResult = ecPaymentGatewayRefund($orderId, $amount, (string)($order['currency'] ?? ecSettings('currency')), [
            'gateway' => $paymentGateway,
            'reason' => $reason,
            'payment_intent_id' => (string)($order['payment']['payment_intent_id'] ?? ''),
            'capture_id' => (string)($order['payment']['gateway_txn_id'] ?? ''),
        ]);
        if (!($gatewayRefundResult['ok'] ?? false)) {
            throw new \InvalidArgumentException('Gateway refund failed: ' . (string)($gatewayRefundResult['error'] ?? 'unknown error'));
        }
        $gatewayRefundId = trim((string)($gatewayRefundResult['refund_id'] ?? ''));
    }

    $db = ecDb();
    $db->beginTransaction();

    try {
        $refundNumber = ecRefundGenerateNumber();
        $remainingRefundableAmount = round((float)($refundSummary['refundable_amount'] ?? 0) - $amount, 2);
        $refundMeta = [
            'payment_gateway' => $paymentGateway,
            'partial' => $remainingRefundableAmount > 0.009,
            'wms_stock_authority' => ecUsesWmsStockAuthority(),
            'gateway_refund_id' => $gatewayRefundId,
            'gateway_refund_status' => (string)($gatewayRefundResult['status'] ?? ''),
        ];

        $db->execute(
            'INSERT INTO ec_refunds (
                order_id, refund_number, created_by_user_id, refunded_amount, currency,
                reason, admin_note, restock_inventory, status, gateway_refund_id, meta, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $orderId,
                $refundNumber,
                $createdByUserId,
                $amount,
                (string)($order['currency'] ?? ecSettings('currency')),
                $reason,
                $adminNote !== '' ? $adminNote : null,
                $restockInventory ? 1 : 0,
                'completed',
                $gatewayRefundId !== '' ? $gatewayRefundId : null,
                json_encode($refundMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        $refundId = (int)$db->lastInsertId();

        foreach ($normalizedRefundItems as $refundItem) {
            $db->execute(
                'INSERT INTO ec_refund_items (
                    refund_id, order_item_id, product_id, qty_refunded, line_amount, restock_qty, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $refundId,
                    $refundItem['order_item_id'],
                    $refundItem['product_id'],
                    $refundItem['qty_refunded'],
                    $refundItem['line_amount'],
                    $refundItem['restock_qty'],
                ]
            );

            if ($restockInventory && !ecUsesWmsStockAuthority()) {
                ecProductIncrementStock((int)$refundItem['product_id'], (int)$refundItem['restock_qty']);
            }
        }

        $db->execute(
            'INSERT INTO ec_payment_transactions (order_id, gateway, gateway_txn_id, amount, currency, status, gateway_response, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $orderId,
                $paymentGateway !== '' ? $paymentGateway : 'manual',
                $gatewayRefundId !== '' ? $gatewayRefundId : null,
                $amount,
                (string)($order['currency'] ?? ecSettings('currency')),
                'refunded',
                !empty($gatewayRefundResult['raw']) ? json_encode($gatewayRefundResult['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                json_encode([
                    'refund_id' => $refundId,
                    'refund_number' => $refundNumber,
                    'reason' => $reason,
                    'restock_inventory' => $restockInventory,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        if ($remainingRefundableAmount <= 0.009) {
            $db->execute("UPDATE ec_orders SET payment_status = 'refunded', updated_at = NOW() WHERE id = ?", [$orderId]);
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $historyNote = 'Refund ' . $refundNumber . ' issued for ' . (string)($order['currency_symbol'] ?? ecSettings('currency_symbol')) . number_format($amount, 2) . '. ' . $reason;
    $historyMeta = [
        'refund_id' => $refundId,
        'refund_number' => $refundNumber,
        'refund_amount' => $amount,
        'restock_inventory' => $restockInventory,
        'item_count' => count($normalizedRefundItems),
        'partial' => $remainingRefundableAmount > 0.009,
        'gateway_refund_id' => $gatewayRefundId,
    ];

    if ($remainingRefundableAmount <= 0.009 && (string)($order['status'] ?? '') !== 'refunded') {
        ecOrderUpdateStatusWithOptions($orderId, 'refunded', $historyNote, [
            'source' => 'ecommerce_refund',
            'actor_user_id' => $createdByUserId,
            'history_key' => 'refund:' . $refundId,
            'meta' => $historyMeta,
        ]);
    } else {
        ecOrderRecordStatusHistory($orderId, 'refunded', [
            'source' => 'ecommerce_refund',
            'note' => $historyNote,
            'actor_user_id' => $createdByUserId,
            'history_key' => 'refund:' . $refundId,
            'meta' => $historyMeta,
        ]);
    }

    $updatedOrder = ecOrderGet($orderId) ?: $order;
    $refundRecord = null;
    foreach (ecOrderGetRefunds($orderId) as $refund) {
        if ((int)($refund['id'] ?? 0) === $refundId) {
            $refundRecord = $refund;
            break;
        }
    }
    if ($refundRecord === null) {
        throw new \RuntimeException('Refund record could not be reloaded.');
    }

    $refundRecord['release_items'] = ecOrderRefundBridgeItems($updatedOrder, $refundRecord);

    try {
        app()->events()->fire('ecommerce.order.refunded', [
            'order_id' => $orderId,
            'order_number' => (string)($updatedOrder['order_number'] ?? ''),
            'actor_user_id' => $createdByUserId,
            'idempotency_key' => 'refund_' . $refundId,
            'order' => ecOrderBridgeSnapshot($updatedOrder),
            'refund' => $refundRecord,
        ]);
    } catch (\Throwable $e) {
    }

    return [
        'refund' => $refundRecord,
        'order' => $updatedOrder,
    ];
}

/**
 * Generate the next sequential order number.
 * Format: {PREFIX}-{YYYY}-{0001}
 */
function ecOrderGenerateNumber(): string
{
    $prefix = strtoupper((string)ecSettings('order_number_prefix'));
    $year   = date('Y');

    try {
        $lastSequence = (int)ecDb()->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(order_number, '-', -1) AS UNSIGNED)), 0)
             FROM ec_orders
             WHERE YEAR(created_at) = ? AND order_number LIKE ?",
            [$year, $prefix . '-' . $year . '-%']
        )->fetchColumn();
    } catch (\Throwable $e) {
        $lastSequence = 0;
    }

    return $prefix . '-' . $year . '-' . str_pad((string)($lastSequence + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * List orders for a specific customer.
 */
function ecCustomerOrders(int $customerId, int $limit = 20, int $offset = 0): array
{
    try {
        $total = (int)ecDb()->query(
            "SELECT COUNT(*) FROM ec_orders WHERE customer_id = ?",
            [$customerId]
        )->fetchColumn();

        $rows = ecDb()->query(
            "SELECT id, order_number, status, payment_status, total, currency, created_at
             FROM ec_orders WHERE customer_id = ?
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$customerId, $limit, $offset]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $items = array_map(static function (array $row): array {
            $currency = ecCurrencyNormalizeCode($row['currency'] ?? '') ?: ecStoreBaseCurrencyCode();
            $row['currency'] = $currency;
            $row['currency_symbol'] = ecCurrencySymbolFor($currency);
            $row['total_amount'] = isset($row['total']) ? (float)$row['total'] : 0.0;
            $row['total_amount_fmt'] = ecCurrencyFormatAmount((float)$row['total_amount'], $currency, (string)$row['currency_symbol']);
            return $row;
        }, $rows);

        // Mark orders that have digital licenses so the list view can show a badge.
        if (!empty($items)) {
            $ids = array_column($items, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $licenseOrderIds = ecDb()->query(
                "SELECT DISTINCT order_id FROM ec_order_licenses WHERE order_id IN ($placeholders)",
                $ids
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $subscriptionOrderIds = ecSubscriptionStorageAvailable()
                ? (ecDb()->query(
                    "SELECT DISTINCT order_id FROM ec_subscriptions WHERE order_id IN ($placeholders)",
                    $ids
                )->fetchAll(\PDO::FETCH_COLUMN) ?: [])
                : [];
            $membershipOrderIds = function_exists('ecMembershipStorageAvailable') && ecMembershipStorageAvailable()
                ? (ecDb()->query(
                    "SELECT DISTINCT order_id FROM ec_memberships WHERE order_id IN ($placeholders)",
                    $ids
                )->fetchAll(\PDO::FETCH_COLUMN) ?: [])
                : [];
            $bookingOrderIds = function_exists('ecBookingStorageAvailable') && ecBookingStorageAvailable()
                ? (ecDb()->query(
                    "SELECT DISTINCT order_id FROM ec_bookings WHERE order_id IN ($placeholders)",
                    $ids
                )->fetchAll(\PDO::FETCH_COLUMN) ?: [])
                : [];
            $returnRequestOrderIds = ecReturnRequestStorageAvailable()
                ? (ecDb()->query(
                    "SELECT DISTINCT order_id FROM ec_return_requests WHERE order_id IN ($placeholders)",
                    $ids
                )->fetchAll(\PDO::FETCH_COLUMN) ?: [])
                : [];
            $licenseSet = array_flip((array)$licenseOrderIds);
            $subscriptionSet = array_flip((array)$subscriptionOrderIds);
            $membershipSet = array_flip((array)$membershipOrderIds);
            $bookingSet = array_flip((array)$bookingOrderIds);
            $returnRequestSet = array_flip((array)$returnRequestOrderIds);
            foreach ($items as &$item) {
                $item['has_licenses'] = isset($licenseSet[$item['id']]);
                $item['has_subscriptions'] = isset($subscriptionSet[$item['id']]);
                $item['has_memberships'] = isset($membershipSet[$item['id']]);
                $item['has_bookings'] = isset($bookingSet[$item['id']]);
                $item['has_return_requests'] = isset($returnRequestSet[$item['id']]);
            }
            unset($item);
        }

        return ['items' => $items, 'total' => $total];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }
}

// ─── Order Editing (Tier 3.3) ──────────────────────────────────────────

const EC_ORDER_EDITABLE_STATUSES = ['pending', 'processing'];

function ecOrderEditsAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        ecDb()->query('SELECT 1 FROM ec_order_edits LIMIT 1');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}

function ecOrderIsEditable(array $order): bool
{
    $status = strtolower(trim((string)($order['status'] ?? '')));
    return in_array($status, EC_ORDER_EDITABLE_STATUSES, true);
}

function ecOrderAddItem(int $orderId, int $productId, int $qty, float $unitPrice, array $options = []): array
{
    $db = ecDb();
    $order = ecOrderGet($orderId);
    if (!$order || !ecOrderIsEditable($order)) {
        return ['ok' => false, 'error' => 'Order is not editable'];
    }
    if ($qty <= 0) return ['ok' => false, 'error' => 'Quantity must be positive'];

    $lineTotal = round($unitPrice * $qty, 2);
    $title = trim((string)($options['product_title'] ?? 'Product'));
    $sku = trim((string)($options['sku'] ?? ''));
    $storeId = isset($options['store_id']) ? (int)$options['store_id'] : null;

    $previousTotal = (float)($order['total'] ?? 0);

    $cols = 'order_id, product_id, product_title, sku, qty, unit_price, line_total';
    $vals = '?, ?, ?, ?, ?, ?, ?';
    $params = [$orderId, $productId, $title, $sku, $qty, $unitPrice, $lineTotal];

    if ($storeId !== null && ecOrderItemsHasStoreIdColumn()) {
        $cols .= ', store_id';
        $vals .= ', ?';
        $params[] = $storeId;
    }

    $db->execute("INSERT INTO ec_order_items ({$cols}) VALUES ({$vals})", $params);
    $itemId = (int)$db->lastInsertId();

    $newTotal = $previousTotal + $lineTotal;
    $db->execute('UPDATE ec_orders SET total = ?, updated_at = NOW() WHERE id = ?', [$newTotal, $orderId]);

    ecOrderRecordEdit($orderId, 'add_item', [
        'item_id' => $itemId, 'product_id' => $productId, 'qty' => $qty, 'unit_price' => $unitPrice,
    ], $previousTotal, $newTotal);

    return ['ok' => true, 'item_id' => $itemId, 'new_total' => $newTotal];
}

function ecOrderRemoveItem(int $orderId, int $itemId): array
{
    $db = ecDb();
    $order = ecOrderGet($orderId);
    if (!$order || !ecOrderIsEditable($order)) {
        return ['ok' => false, 'error' => 'Order is not editable'];
    }

    $items = $order['items'] ?? [];
    if (count($items) <= 1) {
        return ['ok' => false, 'error' => 'Cannot remove the last item; cancel the order instead'];
    }

    $item = null;
    foreach ($items as $i) {
        if ((int)($i['id'] ?? 0) === $itemId) {
            $item = $i;
            break;
        }
    }
    if (!$item) return ['ok' => false, 'error' => 'Item not found in this order'];

    $previousTotal = (float)($order['total'] ?? 0);
    $lineTotal = (float)($item['line_total'] ?? 0);
    $newTotal = max(0, $previousTotal - $lineTotal);

    $db->execute('DELETE FROM ec_order_items WHERE id = ? AND order_id = ?', [$itemId, $orderId]);
    $db->execute('UPDATE ec_orders SET total = ?, updated_at = NOW() WHERE id = ?', [$newTotal, $orderId]);

    ecOrderRecordEdit($orderId, 'remove_item', [
        'item_id' => $itemId, 'product_id' => $item['product_id'] ?? null,
        'qty' => $item['qty'] ?? 0, 'unit_price' => $item['unit_price'] ?? 0,
    ], $previousTotal, $newTotal);

    return ['ok' => true, 'new_total' => $newTotal];
}

function ecOrderUpdateItemQty(int $orderId, int $itemId, int $newQty): array
{
    $db = ecDb();
    $order = ecOrderGet($orderId);
    if (!$order || !ecOrderIsEditable($order)) {
        return ['ok' => false, 'error' => 'Order is not editable'];
    }
    if ($newQty <= 0) return ['ok' => false, 'error' => 'Quantity must be positive'];

    $item = null;
    foreach ($order['items'] ?? [] as $i) {
        if ((int)($i['id'] ?? 0) === $itemId) {
            $item = $i;
            break;
        }
    }
    if (!$item) return ['ok' => false, 'error' => 'Item not found in this order'];

    $unitPrice = (float)($item['unit_price'] ?? 0);
    $oldQty = (int)($item['qty'] ?? 0);
    $oldLineTotal = (float)($item['line_total'] ?? 0);
    $newLineTotal = round($unitPrice * $newQty, 2);
    $previousTotal = (float)($order['total'] ?? 0);
    $newTotal = max(0, $previousTotal - $oldLineTotal + $newLineTotal);

    $db->execute(
        'UPDATE ec_order_items SET qty = ?, line_total = ? WHERE id = ? AND order_id = ?',
        [$newQty, $newLineTotal, $itemId, $orderId]
    );
    $db->execute('UPDATE ec_orders SET total = ?, updated_at = NOW() WHERE id = ?', [$newTotal, $orderId]);

    ecOrderRecordEdit($orderId, 'update_qty', [
        'item_id' => $itemId, 'old_qty' => $oldQty, 'new_qty' => $newQty,
        'old_line_total' => $oldLineTotal, 'new_line_total' => $newLineTotal,
    ], $previousTotal, $newTotal);

    return ['ok' => true, 'new_total' => $newTotal];
}

function ecOrderRecordEdit(int $orderId, string $editType, array $editData, float $previousTotal, float $newTotal): void
{
    if (!ecOrderEditsAvailable()) return;
    $userId = null;
    try { $userId = app()->auth()->id(); } catch (\Throwable $e) {}

    ecDb()->execute(
        'INSERT INTO ec_order_edits (order_id, edit_type, edit_data, previous_total, new_total, edited_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [$orderId, $editType, json_encode($editData), $previousTotal, $newTotal, $userId]
    );
}

function ecOrderEditHistory(int $orderId): array
{
    if (!ecOrderEditsAvailable()) return [];
    $rows = ecDb()->query(
        'SELECT * FROM ec_order_edits WHERE order_id = ? ORDER BY created_at ASC',
        [$orderId]
    );
    if ($rows instanceof \PDOStatement) $rows = $rows->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) ? array_map(function ($r) {
        $r['edit_data'] = json_decode($r['edit_data'] ?? '{}', true);
        return $r;
    }, $rows) : [];
}
