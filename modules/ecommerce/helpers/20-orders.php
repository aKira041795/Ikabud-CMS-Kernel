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

function ecWmsFulfillmentManagedBridgeNames(): array
{
    return [
        'ecommerce_wms_reserve',
        'ecommerce_wms_order_create',
        'ecommerce_wms_release',
        'ecommerce_wms_cancel_order',
        'wms_ecommerce_processing',
        'wms_ecommerce_shipped',
        'wms_ecommerce_delivered',
    ];
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
    ];
}

function ecSyncWmsFulfillmentBridges(bool $enabled): array
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
    $currency     = $data['currency'] ?? ecSettings('currency');

    // Begin DB transaction
    $db->beginTransaction();

    try {
        // Insert order
        $db->execute(
            "INSERT INTO ec_orders (
                order_number, customer_id, guest_email, guest_name, source,
                status, payment_status, subtotal, discount_amount, tax_amount,
                shipping_amount, total, currency, coupon_code, customer_note,
                confirmation_token, placed_by_user_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $orderNumber,
                $customerId,
                $data['guest_email'] ?? null,
                $data['guest_name']  ?? null,
                $source,
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
            ]
        );

        $orderId = (int)$db->lastInsertId();

        // Insert order items
        $wmsAuthorityActive = ecUsesWmsStockAuthority();
        if (!empty($data['cart_items'])) {
            $itemValues = [];
            $itemParams = [];
            foreach ($data['cart_items'] as $item) {
                $unitPrice = (float)($item['price_snapshot'] ?? 0);
                $qty       = max(1, (int)($item['qty'] ?? 1));

                $itemValues[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $itemParams,
                    $orderId,
                    (int)$item['product_id'],
                    $item['variant_id'] ?? null,
                    $item['product_title'] ?? '',
                    $item['sku']           ?? '',
                    $unitPrice,
                    $qty,
                    round($unitPrice * $qty, 2),
                    $item['variant_label'] ?? null
                );
                // WMS becomes the stock authority once the order-created bridge is active.
                if (!$wmsAuthorityActive) {
                    ecProductDecrementStock((int)$item['product_id'], $qty);
                }
            }
            if ($itemValues) {
                $db->execute(
                    "INSERT INTO ec_order_items (order_id, product_id, variant_id, product_title, sku, unit_price, qty, line_total, variant_label) VALUES " . implode(', ', $itemValues),
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
            "INSERT INTO ec_payment_transactions (order_id, gateway, amount, currency, status, created_at, updated_at)
             VALUES (?, 'manual', ?, ?, 'pending', NOW(), NOW())",
            [$orderId, (float)($data['total'] ?? 0), $currency]
        );

        // Increment coupon uses
        if (!empty($data['coupon_code'])) {
            ecCouponUse((string)$data['coupon_code']);
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

    // Fire event
    try {
        $eventIdempotencyKey = ecOrderEventIdempotencyKey($orderId, 'created');
        app()->events()->fire('ecommerce.order.created', [
            'order_id'       => $orderId,
            'order_number'   => $orderNumber,
            'customer_email' => $data['guest_email'] ?? ($data['billing']['email'] ?? ''),
            'total'          => (float)($data['total'] ?? 0),
            'source'         => $source,
            'actor_user_id'  => isset($data['placed_by_user_id']) ? (int)$data['placed_by_user_id'] : null,
            'idempotency_key' => $eventIdempotencyKey,
            'order'          => $bridgeSnapshot,
        ]);
    } catch (\Throwable $e) {
        write_log('ecommerce.order.created event error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
    }

    return [
        'order_id'           => $orderId,
        'order_number'       => $orderNumber,
        'confirmation_token' => $token,
    ];
}

function ecUsesWmsStockAuthority(): bool
{
    return \Ikabud\Kernel\IntegrationBridge::hasActiveBridge('ecommerce.order.created', 'wms.stock.reserve@1');
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

    $order['currency_symbol'] = (string)ecSettings('currency_symbol');
    $order['total_amount'] = isset($order['total']) ? (float)$order['total'] : (float)($order['total_amount'] ?? 0);
    $order['subtotal_amount'] = isset($order['subtotal']) ? (float)$order['subtotal'] : (float)($order['subtotal_amount'] ?? 0);
    $order['discount_amount'] = (float)($order['discount_amount'] ?? 0);
    $order['tax_amount'] = (float)($order['tax_amount'] ?? 0);
    $order['shipping_amount'] = (float)($order['shipping_amount'] ?? 0);
    $order['billing'] = $billing;
    $order['shipping'] = $shipping;
    $order['customer_email'] = $billing['email'] !== '' ? $billing['email'] : (string)($order['guest_email'] ?? '');
    $order['customer_name'] = trim($billing['first_name'] . ' ' . $billing['last_name']);

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
            $where  .= ' AND o.confirmation_token = ?';
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
            $payment['label'] = ucfirst((string)($payment['gateway'] ?? ''));
            $order['payment'] = $payment;
        } else {
            $order['payment'] = null;
        }

        $order['licenses'] = $db->query(
            "SELECT id, order_item_id, product_id, target_module, target_tier, license_key, download_token, status, created_at, downloaded_at
               FROM ec_order_licenses WHERE order_id = ? ORDER BY id ASC",
            [$id]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

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
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['payment_status'])) {
        $where[]  = 'payment_status = ?';
        $params[] = $filters['payment_status'];
    }
    if (!empty($filters['source'])) {
        $where[]  = 'source = ?';
        $params[] = $filters['source'];
    }
    if (!empty($filters['search'])) {
        $where[]  = '(order_number LIKE ? OR guest_email LIKE ? OR guest_name LIKE ?)';
        $s        = '%' . $filters['search'] . '%';
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'DATE(created_at) >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'DATE(created_at) <= ?';
        $params[] = $filters['date_to'];
    }

    $limit  = min(100, max(1, (int)($filters['limit']  ?? 25)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    $whereStr = implode(' AND ', $where);

    try {
        $total = (int)$db->query("SELECT COUNT(*) FROM ec_orders WHERE $whereStr", $params)->fetchColumn();
        $rows  = $db->query(
            "SELECT id, order_number, customer_id, guest_email, guest_name, source, status, payment_status, total, currency, created_at
             FROM ec_orders
             WHERE $whereStr
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

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

    if ($current === $newStatus) {
        if ($historyKey !== '') {
            ecOrderRecordStatusHistory($orderId, $newStatus, [
                'source' => $source,
                'note' => $note,
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
            }
            app()->events()->fire($eventKey, $eventPayload);
        } catch (\Throwable $e) {}
    }

    return true;
}

function ecOrderRecordStatusHistory(int $orderId, string $status, array $options = []): void
{
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

/**
 * Update payment status (e.g. manual mark-as-paid).
 * Idempotent: if the order is already paid the event is not re-fired,
 * preventing duplicate license generation and email delivery.
 */
function ecOrderMarkPaid(int $orderId): void
{
    $db = ecDb();

    // Only fire the paid event once — prevent double-email from webhook + return-URL
    $currentStatus = (string)($db->query('SELECT payment_status FROM ec_orders WHERE id = ? LIMIT 1', [$orderId])->fetchColumn() ?: '');
    if ($currentStatus === 'paid') {
        return;
    }

    $db->execute("UPDATE ec_orders SET payment_status = 'paid', updated_at = NOW() WHERE id = ?", [$orderId]);
    $db->execute("UPDATE ec_payment_transactions SET status = 'succeeded', updated_at = NOW() WHERE order_id = ? AND status = 'pending'", [$orderId]);

    $order = ecOrderGet($orderId);
    if ($order) {
        try {
            app()->events()->fire('ecommerce.order.paid', [
                'order_id'     => $orderId,
                'order_number' => $order['order_number'],
                'total'        => $order['total'],
            ]);
        } catch (\Throwable $e) {}
    }
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
        $count = (int)ecDb()->query(
            "SELECT COUNT(*) FROM ec_orders WHERE YEAR(created_at) = ?",
            [$year]
        )->fetchColumn();
    } catch (\Throwable $e) {
        $count = 0;
    }

    return $prefix . '-' . $year . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
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
            $row['currency_symbol'] = (string)ecSettings('currency_symbol');
            $row['total_amount'] = isset($row['total']) ? (float)$row['total'] : 0.0;
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
            $licenseSet = array_flip((array)$licenseOrderIds);
            foreach ($items as &$item) {
                $item['has_licenses'] = isset($licenseSet[$item['id']]);
            }
            unset($item);
        }

        return ['items' => $items, 'total' => $total];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }
}
