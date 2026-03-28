<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Pricing Calculations (helpers/40-pricing.php)
//
// Stateless calculation helpers. No DB writes here.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Calculate cart totals.
 *
 * @param array  $items       Cart items (each with price_snapshot, qty)
 * @param string|null $couponCode  Coupon code to apply (or null)
 * @return array  subtotal, discount, tax, shipping, total
 */
function ecCalculateTotals(array $items, ?string $couponCode = null, ?int $shippingRateId = null): array
{
    $subtotal = 0.00;
    foreach ($items as $item) {
        $subtotal += (float)($item['price_snapshot'] ?? 0) * max(1, (int)($item['qty'] ?? 1));
    }

    // Coupon discount
    $discount    = 0.00;
    $couponData  = null;
    if ($couponCode !== null) {
        $validation = ecCouponValidate($couponCode, $subtotal);
        if ($validation['valid']) {
            $couponData = $validation;
            $discount   = (float)($validation['discount_amount'] ?? 0.0);
        }
    }

    // Tax
    $taxRate    = (float)ecSettings('tax_rate');
    $taxInclusive = (bool)ecSettings('tax_inclusive');
    $taxable    = max(0.0, $subtotal - $discount);
    $tax        = 0.00;
    if ($taxRate > 0) {
        if ($taxInclusive) {
            // Tax already included — back-calculate
            $tax = $taxable - ($taxable / (1 + $taxRate / 100));
        } else {
            $tax = $taxable * ($taxRate / 100);
        }
        $tax = round($tax, 2);
    }

    // Shipping
    $shipping = 0.00;
    if ($shippingRateId) {
        $shippingData = ecShippingRateGet($shippingRateId);
        if ($shippingData) {
            $freeAbove = $shippingData['free_above'] !== null ? (float)$shippingData['free_above'] : null;
            if ($freeAbove === null || $subtotal < $freeAbove) {
                $shipping = (float)$shippingData['rate'];
            }
        }
    }

    $total = max(0.0, $subtotal - $discount + ($taxInclusive ? 0 : $tax) + $shipping);

    $sym = (string)ecSettings('currency_symbol');

    return [
        'subtotal'       => round($subtotal, 2),
        'subtotal_fmt'   => $sym . number_format($subtotal, 2),
        'discount'       => round($discount, 2),
        'discount_fmt'   => $sym . number_format($discount, 2),
        'tax'            => round($tax, 2),
        'tax_fmt'        => $sym . number_format($tax, 2),
        'tax_rate'       => $taxRate,
        'tax_inclusive'  => $taxInclusive,
        'shipping'       => round($shipping, 2),
        'shipping_fmt'   => $sym . number_format($shipping, 2),
        'total'          => round($total, 2),
        'total_fmt'      => $sym . number_format($total, 2),
        'coupon_code'    => $couponCode,
        'coupon'         => $couponData,
        'item_count'     => array_sum(array_column($items, 'qty')),
    ];
}

/**
 * Validate a coupon code against a given subtotal.
 * Returns ['valid' => bool, 'error' => string|null, 'discount_amount' => float]
 */
function ecCouponValidate(string $code, float $subtotal): array
{
    if (trim($code) === '') {
        return ['valid' => false, 'error' => 'No coupon code provided'];
    }

    try {
        $row = ecDb()->query(
            "SELECT * FROM ec_coupons WHERE code = ? LIMIT 1",
            [strtoupper(trim($code))]
        )->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['valid' => false, 'error' => 'Could not validate coupon'];
    }

    if (!$row) {
        return ['valid' => false, 'error' => 'Invalid coupon code'];
    }
    if (!$row['is_active']) {
        return ['valid' => false, 'error' => 'Coupon is not active'];
    }
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
        return ['valid' => false, 'error' => 'Coupon has expired'];
    }
    if ($row['max_uses'] !== null && $row['uses_count'] >= $row['max_uses']) {
        return ['valid' => false, 'error' => 'Coupon usage limit reached'];
    }
    if ($row['min_order_amount'] > 0 && $subtotal < (float)$row['min_order_amount']) {
        return ['valid' => false, 'error' => 'Order minimum not met for this coupon'];
    }

    $discount = 0.00;
    if ($row['type'] === 'percent') {
        $discount = $subtotal * ((float)$row['value'] / 100);
    } else {
        $discount = min((float)$row['value'], $subtotal);
    }

    return [
        'valid'           => true,
        'error'           => null,
        'code'            => $row['code'],
        'type'            => $row['type'],
        'value'           => (float)$row['value'],
        'discount_amount' => round($discount, 2),
        'description'     => $row['description'] ?? '',
    ];
}

/**
 * Increment coupon uses_count. Called after order is placed.
 */
function ecCouponUse(string $code): void
{
    try {
        ecDb()->execute(
            "UPDATE ec_coupons SET uses_count = uses_count + 1 WHERE code = ?",
            [strtoupper(trim($code))]
        );
    } catch (\Throwable $e) {
        write_log('ecCouponUse error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
    }
}

/**
 * Get a shipping rate record by ID.
 */
function ecShippingRateGet(int $rateId): ?array
{
    try {
        $row = ecDb()->query(
            "SELECT * FROM ec_shipping_rates WHERE id = ? AND is_active = 1 LIMIT 1",
            [$rateId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * List available shipping rates (optionally filtered by zone).
 */
function ecShippingRates(int $zoneId = 1): array
{
    try {
        return ecDb()->query(
            "SELECT sr.*, sz.name as zone_name
             FROM ec_shipping_rates sr
             INNER JOIN ec_shipping_zones sz ON sz.id = sr.zone_id
             WHERE sr.zone_id = ? AND sr.is_active = 1
             ORDER BY sr.sort_order ASC, sr.rate ASC",
            [$zoneId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}
