<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — POS (helpers/60-pos.php)
//
// Basic POS: product lookup, quick-sale order creation.
// POS orders are ec_orders with source='pos', payment_status='paid'.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Search products for the POS product picker.
 *
 * @param string $query  Name, title, or SKU to search for
 * @return array  Product rows with pricing and inventory
 */
function ecPosProductSearch(string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        // Return first N active products when no query
        return ecProductList(['limit' => $limit, 'status' => 'published'])['items'];
    }

    $db = ecDb();
    $q  = '%' . $query . '%';

    try {
        // Also search by SKU via cms_entity_capabilities JSON
        $rows = $db->query(
            "SELECT DISTINCT c.id, c.title, c.slug,
                    JSON_UNQUOTE(JSON_EXTRACT(inv.config, '$.sku')) as sku,
                    CAST(JSON_EXTRACT(inv.config, '$.stock_qty') AS SIGNED) as stock_qty,
                    JSON_EXTRACT(inv.config, '$.track_stock') as track_stock
             FROM cms_content c
             LEFT JOIN cms_entity_capabilities inv ON inv.entity_id = c.id AND inv.capability_id = 'inventory'
             WHERE c.type = 'product'
               AND c.status = 'published'
               AND c.deleted_at IS NULL
               AND (c.title LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(inv.config, '$.sku')) LIKE ?)
             LIMIT ?",
            [$q, $q, $limit]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['pricing']   = ecProductPricing((int)$row['id']);
            $row['inventory'] = ecProductInventory((int)$row['id']);
        }
        unset($row);

        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Process a quick POS sale.
 *
 * @param array $items     [{product_id, qty, price_snapshot, product_title, sku}, ...]
 * @param array $options   cashier_user_id, tender_amount, currency, customer_note, coupon_code
 * @return array  ['order_id' => int, 'order_number' => string, 'change' => float]
 */
function ecPosSale(array $items, array $options = []): array
{
    if (empty($items)) {
        throw new \InvalidArgumentException('POS sale requires at least one item');
    }

    $cashierUserId = (int)($options['cashier_user_id'] ?? 0);
    $couponCode    = $options['coupon_code'] ?? null;
    $shippingRateId = null; // POS sales: no shipping

    $totals = ecCalculateTotals($items, $couponCode, $shippingRateId);

    $orderData = [
        'cart_items'       => $items,
        'subtotal'         => $totals['subtotal'],
        'discount_amount'  => $totals['discount'],
        'tax_amount'       => $totals['tax'],
        'shipping_amount'  => 0.00,
        'total'            => $totals['total'],
        'currency'         => ecSettings('currency'),
        'coupon_code'      => $couponCode,
        'source'           => 'pos',
        'placed_by_user_id' => $cashierUserId ?: null,
        'customer_note'    => $options['customer_note'] ?? null,
        'billing'          => [],
        'shipping'         => [],
    ];

    $result = ecOrderCreate($orderData);
    $orderId = $result['order_id'];

    // Mark POS orders as paid immediately
    ecOrderMarkPaid($orderId);

    $tenderAmount = (float)($options['tender_amount'] ?? $totals['total']);
    $change       = max(0.0, round($tenderAmount - $totals['total'], 2));

    return [
        'order_id'     => $orderId,
        'order_number' => $result['order_number'],
        'total'        => $totals['total'],
        'change'       => $change,
        'totals'       => $totals,
    ];
}
