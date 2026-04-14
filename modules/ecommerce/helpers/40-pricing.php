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
function ecTaxSettingKeyForClass(string $taxClass): ?string
{
    return match (ecProductNormalizeTaxClass($taxClass)) {
        'standard' => 'tax_standard_rules',
        'reduced' => 'tax_reduced_rules',
        'zero' => 'tax_zero_rules',
        default => null,
    };
}

function ecTaxNormalizeLocation(array $address = []): array
{
    $postalCode = $address['postal_code'] ?? $address['postal'] ?? $address['zip'] ?? $address['zip_code'] ?? '';

    return [
        'country' => strtoupper(trim((string)($address['country'] ?? ''))),
        'state' => strtoupper(trim((string)($address['state'] ?? ''))),
        'city' => strtoupper(trim((string)($address['city'] ?? ''))),
        'postal_code' => strtoupper(trim((string)$postalCode)),
    ];
}

function ecTaxParseRules(string $rawRules): array
{
    $rules = [];
    $lines = preg_split('/\r\n|\r|\n/', $rawRules) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = array_map(static fn(string $part): string => trim($part), explode('|', $line));
        $parts = array_pad($parts, 5, '');
        $rate = is_numeric($parts[4]) ? max(0.0, (float)$parts[4]) : null;
        if ($rate === null) {
            continue;
        }

        $rules[] = [
            'country' => strtoupper($parts[0]),
            'state' => strtoupper($parts[1]),
            'city' => strtoupper($parts[2]),
            'postal_code' => strtoupper($parts[3]),
            'rate' => round($rate, 4),
        ];
    }

    return $rules;
}

function ecTaxRules(string $taxClass): array
{
    $settingKey = ecTaxSettingKeyForClass($taxClass);
    if ($settingKey === null) {
        return [];
    }

    return ecTaxParseRules((string)ecSettings($settingKey, ''));
}

function ecTaxRuleSpecificity(array $rule): int
{
    $score = 0;
    foreach (['country', 'state', 'city', 'postal_code'] as $key) {
        $value = trim((string)($rule[$key] ?? ''));
        if ($value !== '' && $value !== '*') {
            $score++;
        }
    }

    return $score;
}

function ecTaxRuleValueMatches(string $pattern, string $value): bool
{
    $pattern = strtoupper(trim($pattern));
    $value = strtoupper(trim($value));

    if ($pattern === '' || $pattern === '*') {
        return true;
    }
    if ($value === '') {
        return false;
    }
    if (!str_contains($pattern, '*')) {
        return $pattern === $value;
    }

    $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
    return (bool)preg_match($regex, $value);
}

function ecTaxRuleMatches(array $rule, array $location): bool
{
    return ecTaxRuleValueMatches((string)($rule['country'] ?? ''), (string)($location['country'] ?? ''))
        && ecTaxRuleValueMatches((string)($rule['state'] ?? ''), (string)($location['state'] ?? ''))
        && ecTaxRuleValueMatches((string)($rule['city'] ?? ''), (string)($location['city'] ?? ''))
        && ecTaxRuleValueMatches((string)($rule['postal_code'] ?? ''), (string)($location['postal_code'] ?? ''));
}

function ecTaxResolveRate(string $taxClass, array $taxAddress = []): float
{
    $taxClass = ecProductNormalizeTaxClass($taxClass);
    $location = ecTaxNormalizeLocation($taxAddress);
    $locations = [$location];
    $defaultCountry = strtoupper(trim((string)ecSettings('tax_default_country', '')));

    if (($location['country'] ?? '') === '' && $defaultCountry !== '') {
        $fallbackLocation = $location;
        $fallbackLocation['country'] = $defaultCountry;
        $locations[] = $fallbackLocation;
    }

    $matchedRule = null;
    foreach ($locations as $candidateLocation) {
        foreach (ecTaxRules($taxClass) as $rule) {
            if (!ecTaxRuleMatches($rule, $candidateLocation)) {
                continue;
            }

            if ($matchedRule === null || ecTaxRuleSpecificity($rule) > ecTaxRuleSpecificity($matchedRule)) {
                $matchedRule = $rule;
            }
        }

        if ($matchedRule !== null) {
            break;
        }
    }

    if ($matchedRule !== null) {
        return (float)$matchedRule['rate'];
    }

    if ($taxClass === 'zero') {
        return 0.0;
    }

    return max(0.0, (float)ecSettings('tax_rate', 0));
}

function ecTaxBuildLabel(array $taxBreakdown, bool $taxInclusive): string
{
    if ($taxBreakdown === []) {
        return $taxInclusive ? 'Tax included' : 'Tax';
    }

    $rates = [];
    foreach ($taxBreakdown as $entry) {
        $rates[] = round((float)($entry['rate'] ?? 0.0), 4);
    }
    $rates = array_values(array_unique($rates));

    if (count($rates) === 1) {
        $prefix = $taxInclusive ? 'Tax included' : 'Tax';
        return $prefix . ' (' . rtrim(rtrim(number_format((float)$rates[0], 2, '.', ''), '0'), '.') . '%)';
    }

    return $taxInclusive ? 'Tax included (mixed)' : 'Tax (mixed)';
}

function ecShippingDefaultCountry(): string
{
    $country = strtoupper(trim((string)ecSettings('shipping_default_country', '')));
    if ($country !== '') {
        return $country;
    }

    return strtoupper(trim((string)ecSettings('tax_default_country', '')));
}

function ecShippingNormalizeLocation(array $address = []): array
{
    $postalCode = $address['postal_code'] ?? $address['postal'] ?? $address['zip'] ?? $address['zip_code'] ?? '';
    $country = strtoupper(trim((string)($address['country'] ?? '')));
    if ($country === '') {
        $country = ecShippingDefaultCountry();
    }

    return [
        'country' => $country,
        'state' => strtoupper(trim((string)($address['state'] ?? ''))),
        'city' => strtoupper(trim((string)($address['city'] ?? ''))),
        'postal_code' => strtoupper(trim((string)$postalCode)),
    ];
}

function ecShippingCartMetrics(array $items, ?string $couponCode = null): array
{
    $subtotal = 0.0;
    $itemCount = 0;
    $currencyCode = ecResolveCartItemsCurrencyCode($items);
    foreach ($items as $item) {
        $qty = max(1, (int)($item['qty'] ?? 1));
        $subtotal += (float)($item['price_snapshot'] ?? 0) * $qty;
        $itemCount += $qty;
    }

    $discount = 0.0;
    if ($couponCode !== null && trim($couponCode) !== '') {
        $coupon = ecCouponValidate($couponCode, $subtotal, $currencyCode);
        if (!empty($coupon['valid']) && ($coupon['type'] ?? '') !== 'gift_card') {
            $discount = (float)($coupon['discount_amount'] ?? 0.0);
        }
    }

    return [
        'currency' => $currencyCode,
        'subtotal' => round($subtotal, 2),
        'discount' => round($discount, 2),
        'chargeable_subtotal' => round(max(0.0, $subtotal - $discount), 2),
        'item_count' => $itemCount,
    ];
}

function ecShippingNormalizeRate(array $rate, array $metrics = []): array
{
    $subtotal = (float)($metrics['chargeable_subtotal'] ?? $metrics['subtotal'] ?? 0.0);
    $currencyCode = ecCurrencyNormalizeCode($metrics['currency'] ?? '') ?: ecCurrentCurrencyCode();
    $baseRate = ecCurrencyConvertAmount((float)($rate['rate'] ?? 0.0), ecStoreBaseCurrencyCode(), $currencyCode);
    $freeAbove = isset($rate['free_above']) && $rate['free_above'] !== null && $rate['free_above'] !== ''
        ? ecCurrencyConvertAmount((float)$rate['free_above'], ecStoreBaseCurrencyCode(), $currencyCode)
        : null;
    $resolvedRate = ($freeAbove !== null && $subtotal >= $freeAbove) ? 0.0 : $baseRate;
    $label = trim((string)($rate['label'] ?? $rate['name'] ?? 'Shipping'));
    $sym = ecCurrencySymbolFor($currencyCode);

    return [
        'id' => (int)($rate['id'] ?? 0),
        'label' => $label,
        'name' => $label,
        'carrier' => trim((string)($rate['carrier'] ?? '')),
        'currency' => $currencyCode,
        'rate' => $resolvedRate,
        'rate_fmt' => $sym . number_format($resolvedRate, 2),
        'free_above' => $freeAbove,
        'estimated_days' => trim((string)($rate['estimated_days'] ?? '')),
        'is_active' => array_key_exists('is_active', $rate) ? (int)!empty($rate['is_active']) : 1,
        'sort_order' => (int)($rate['sort_order'] ?? 0),
        'zone_id' => isset($rate['zone_id']) ? (int)$rate['zone_id'] : 0,
        'zone_name' => trim((string)($rate['zone_name'] ?? '')),
        'source' => trim((string)($rate['source'] ?? 'database')),
        'rule_index' => isset($rate['rule_index']) ? (int)$rate['rule_index'] : null,
    ];
}

function ecShippingParseTableRateRules(string $rawRules): array
{
    $rules = [];
    $lines = preg_split('/\r\n|\r|\n/', $rawRules) ?: [];

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = array_map(static fn(string $part): string => trim($part), explode('|', $line));
        $parts = array_pad($parts, 12, '');
        if (!is_numeric($parts[8])) {
            continue;
        }

        $rules[] = [
            'id' => -100000 - $index,
            'country' => strtoupper($parts[0]),
            'state' => strtoupper($parts[1]),
            'city' => strtoupper($parts[2]),
            'postal_code' => strtoupper($parts[3]),
            'min_subtotal' => $parts[4] !== '' ? max(0.0, (float)$parts[4]) : null,
            'max_subtotal' => $parts[5] !== '' ? max(0.0, (float)$parts[5]) : null,
            'min_items' => $parts[6] !== '' ? max(0, (int)$parts[6]) : null,
            'max_items' => $parts[7] !== '' ? max(0, (int)$parts[7]) : null,
            'rate' => round(max(0.0, (float)$parts[8]), 2),
            'label' => $parts[9] !== '' ? $parts[9] : 'Shipping',
            'carrier' => $parts[10],
            'estimated_days' => $parts[11],
            'free_above' => null,
            'sort_order' => $index,
            'source' => 'table_rate',
            'rule_index' => $index,
        ];
    }

    return $rules;
}

function ecShippingTableRateRules(): array
{
    return ecShippingParseTableRateRules((string)ecSettings('shipping_table_rate_rules', ''));
}

function ecShippingRuleSpecificity(array $rule): int
{
    $score = 0;
    foreach (['country', 'state', 'city', 'postal_code'] as $key) {
        $value = trim((string)($rule[$key] ?? ''));
        if ($value !== '' && $value !== '*') {
            $score++;
        }
    }

    return $score;
}

function ecShippingRuleMatches(array $rule, array $location, array $metrics): bool
{
    if (!ecTaxRuleMatches($rule, $location)) {
        return false;
    }

    $chargeableSubtotal = (float)($metrics['chargeable_subtotal'] ?? 0.0);
    $itemCount = (int)($metrics['item_count'] ?? 0);

    if (($rule['min_subtotal'] ?? null) !== null && $chargeableSubtotal < (float)$rule['min_subtotal']) {
        return false;
    }
    if (($rule['max_subtotal'] ?? null) !== null && $chargeableSubtotal > (float)$rule['max_subtotal']) {
        return false;
    }
    if (($rule['min_items'] ?? null) !== null && $itemCount < (int)$rule['min_items']) {
        return false;
    }
    if (($rule['max_items'] ?? null) !== null && $itemCount > (int)$rule['max_items']) {
        return false;
    }

    return true;
}

function ecShippingTableRates(array $items, array $address = [], ?string $couponCode = null): array
{
    if (!ecCartRequiresShipping($items)) {
        return [];
    }

    $metrics = ecShippingCartMetrics($items, $couponCode);
    $location = ecShippingNormalizeLocation($address);
    $rates = [];

    foreach (ecShippingTableRateRules() as $rule) {
        if (!ecShippingRuleMatches($rule, $location, $metrics)) {
            continue;
        }
        $rates[] = ecShippingNormalizeRate($rule, $metrics);
    }

    usort($rates, static function (array $left, array $right): int {
        $specificity = ecShippingRuleSpecificity($right) <=> ecShippingRuleSpecificity($left);
        if ($specificity !== 0) {
            return $specificity;
        }

        $rateCompare = ((float)$left['rate']) <=> ((float)$right['rate']);
        if ($rateCompare !== 0) {
            return $rateCompare;
        }

        return ((int)$left['sort_order']) <=> ((int)$right['sort_order']);
    });

    return $rates;
}

function ecShippingZoneMatches(array $zone, array $location): bool
{
    $rawCountries = $zone['countries'] ?? null;
    if ($rawCountries === null || $rawCountries === '' || $rawCountries === 'null') {
        return true;
    }

    $countries = [];
    if (is_string($rawCountries)) {
        $decoded = json_decode($rawCountries, true);
        if (is_array($decoded)) {
            $countries = $decoded;
        }
    } elseif (is_array($rawCountries)) {
        $countries = $rawCountries;
    }

    $country = strtoupper(trim((string)($location['country'] ?? '')));
    if ($country === '') {
        return false;
    }

    $normalizedCountries = array_values(array_filter(array_map(
        static fn(mixed $value): string => strtoupper(trim((string)$value)),
        $countries
    )));

    return in_array($country, $normalizedCountries, true);
}

function ecShippingDbRates(array $address = [], ?string $couponCode = null, array $items = []): array
{
    if ($items !== [] && !ecCartRequiresShipping($items)) {
        return [];
    }

    $location = ecShippingNormalizeLocation($address);
    $metrics = ecShippingCartMetrics($items, $couponCode);

    try {
        $zones = ecDb()->query(
            'SELECT * FROM ec_shipping_zones WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $zoneIds = [];
    foreach ($zones as $zone) {
        if (ecShippingZoneMatches($zone, $location)) {
            $zoneIds[] = (int)($zone['id'] ?? 0);
        }
    }

    if ($zoneIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($zoneIds), '?'));
    try {
        $rows = ecDb()->query(
            "SELECT sr.*, sz.name AS zone_name
             FROM ec_shipping_rates sr
             INNER JOIN ec_shipping_zones sz ON sz.id = sr.zone_id
             WHERE sr.is_active = 1 AND sr.zone_id IN ($placeholders)
             ORDER BY sz.sort_order ASC, sr.sort_order ASC, sr.rate ASC, sr.id ASC",
            $zoneIds
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    return array_map(static fn(array $row): array => ecShippingNormalizeRate($row, $metrics), $rows);
}

function ecShippingAvailableRates(array $items, array $address = [], ?string $couponCode = null): array
{
    if (!ecCartRequiresShipping($items)) {
        return [];
    }

    $storeRates = ecStoreShippingRates($items, $address, $couponCode, ecShippingResolveStoreContext($items, $address));
    if ($storeRates !== []) {
        return $storeRates;
    }

    $tableRates = ecShippingTableRates($items, $address, $couponCode);
    if ($tableRates !== []) {
        return $tableRates;
    }

    return ecShippingDbRates($address, $couponCode, $items);
}

function ecShippingQuote(array $items, array $address = [], ?string $couponCode = null, ?int $selectedRateId = null, array $options = []): array
{
    $requiresShipping = ecCartRequiresShipping($items);
    $location = ecShippingNormalizeLocation($address);
    $rates = $requiresShipping ? ecShippingAvailableRates($items, $location, $couponCode) : [];
    $selectedRate = null;

    if ($selectedRateId !== null) {
        foreach ($rates as $rate) {
            if ((int)($rate['id'] ?? 0) === $selectedRateId) {
                $selectedRate = $rate;
                break;
            }
        }
    }

    if ($selectedRate === null && isset($rates[0])) {
        $selectedRate = $rates[0];
    }

    $resolvedRateId = $selectedRate !== null ? (int)($selectedRate['id'] ?? 0) : null;

    return [
        'requires_shipping' => $requiresShipping,
        'address' => $location,
        'rates' => $rates,
        'selected_rate_id' => $resolvedRateId,
        'selected_rate' => $selectedRate,
        'totals' => ecCalculateTotals($items, $couponCode, $resolvedRateId, $location, $options),
    ];
}
    function ecAuthoritativeCartItemPricing(array $item): ?array
    {
        $productId = max(0, (int)($item['product_id'] ?? 0));
        if ($productId <= 0 || !function_exists('ecProductGet')) {
            return null;
        }

        $product = ecProductGet($productId, false);
        if (!is_array($product)) {
            return null;
        }

        $storeId = max(0, (int)($item['store_id'] ?? 0));
        if ($storeId <= 0 && function_exists('ecProductStoreAssignmentMap')) {
            $assignmentMap = ecProductStoreAssignmentMap([$productId]);
            $assignedStores = array_values(array_unique(array_filter(array_map(
                static fn(array $store): int => max(0, (int)($store['id'] ?? 0)),
                (array)($assignmentMap[$productId] ?? [])
            ))));
            if (count($assignedStores) === 1) {
                $storeId = $assignedStores[0];
            }
        }

        if ($storeId > 0 && function_exists('ecStoreById') && function_exists('ecStoreApplyProductOverrides')) {
            $store = ecStoreById($storeId);
            if (is_array($store)) {
                $product = ecStoreApplyProductOverrides($product, $store);
                if (!is_array($product)) {
                    return null;
                }
            }
        }

        $pricing = is_array($product['pricing'] ?? null) ? $product['pricing'] : [];
        $segmentUserId = function_exists('ecSegmentCurrentUserId') ? ecSegmentCurrentUserId() : 0;
        if ($segmentUserId > 0 && function_exists('ecCustomerActiveSegments') && function_exists('ecSegmentApplyProductPrice')) {
            $activeSegments = ecCustomerActiveSegments($segmentUserId);
            if ($activeSegments !== []) {
                $pricing = ecSegmentApplyProductPrice($pricing, $activeSegments);
            }
        }

        $sourceCurrency = ecCurrencyNormalizeCode($pricing['currency'] ?? '') ?: ecStoreBaseCurrencyCode();
        $targetCurrency = ecCurrencyNormalizeCode($item['currency'] ?? '');
        if ($targetCurrency === '' && $storeId > 0 && function_exists('ecStoreSettingsArray')) {
            $storeSettings = ecStoreSettingsArray($storeId);
            $targetCurrency = ecCurrencyNormalizeCode($storeSettings['currency'] ?? '');
        }
        if ($targetCurrency === '') {
            $targetCurrency = $sourceCurrency;
        }

        $basePrice = array_key_exists('active_price', $pricing) && $pricing['active_price'] !== null
            ? (float)$pricing['active_price']
            : (float)($pricing['price'] ?? 0.0);
        $variantId = max(0, (int)($item['variant_id'] ?? 0));
        if ($variantId > 0 && function_exists('ecProductVariantGet')) {
            $variant = ecProductVariantGet($variantId, $productId);
            if (is_array($variant) && $variant['price_override'] !== null && $variant['price_override'] !== '') {
                $basePrice = (float)$variant['price_override'];
            }
        }

        return [
            'base_price' => ecCurrencyConvertAmount($basePrice, $sourceCurrency, $targetCurrency),
            'currency' => $targetCurrency,
            'store_id' => $storeId,
        ];
    }


/**
 * Re-fetch authoritative product prices from the database and override
 * the price_snapshot in each cart item. This prevents session-based
 * price manipulation attacks where an attacker lowers price_snapshot
 * before checkout.
 *
 * Logic:
 *  - Items with a product_id get their base price replaced with the
 *    current DB active_price (considering sale prices).
 *  - Add-on adjustments (addon_total) are preserved since they come
 *    from DB-backed configurations at add-time.
 *  - Variant price_overrides are also fetched and applied.
 *  - Items without a product_id (custom/manual line items) are untouched.
 *  - Discrepancies are logged for auditing purposes.
 *
 * @param array $items Cart items with price_snapshot, product_id, variant_id, addon_total
 * @return array Items with price_snapshot overridden to authoritative DB prices
 */
function ecEnforceCurrentPrices(array $items): array
{
    if (empty($items)) {
        return $items;
    }

    // Collect product IDs needing a fresh price lookup.
    $productIds = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid > 0) {
            $productIds[$pid] = true;
        }
    }

    if (empty($productIds)) {
        return $items; // No product-linked items—custom line items only.
    }

    // Batch-fetch base prices from cms_entity_capabilities (pricing capability).
    $livePrices = [];
    $db = null;
    try {
        $db = function_exists('ecDb') ? ecDb() : null;
        if ($db !== null) {
            $idList = implode(',', array_map('intval', array_keys($productIds)));
            $rows = $db->query(
                "SELECT entity_id, config FROM cms_entity_capabilities WHERE entity_id IN ($idList) AND capability_id = 'pricing'"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $config    = (array)json_decode((string)($row['config'] ?? '{}'), true);
                $price     = isset($config['price']) ? (float)$config['price'] : null;
                $salePrice = isset($config['sale_price']) ? (float)$config['sale_price'] : null;
                $onSale    = $price !== null && $salePrice !== null && $salePrice > 0 && $salePrice < $price;
                $active    = $onSale ? $salePrice : $price;
                if ($active !== null) {
                    $livePrices[(int)$row['entity_id']] = round($active, 2);
                }
            }
        }
    } catch (\Throwable $e) {
        // Non-fatal: if DB is unavailable, fall back to stored snapshot.
        if (function_exists('write_log')) {
            write_log('ecEnforceCurrentPrices: DB price fetch failed, using snapshots', 'warning', ['error' => $e->getMessage()]);
        }
        return $items;
    }

    // Fetch variant price overrides for items that have variant_id.
    $variantPrices = [];
    $variantIds    = [];
    foreach ($items as $item) {
        $vid = (int)($item['variant_id'] ?? 0);
        if ($vid > 0 && (int)($item['product_id'] ?? 0) > 0) {
            $variantIds[] = $vid;
        }
    }
    if (!empty($variantIds) && $db !== null) {
        try {
            $vIdList = implode(',', array_map('intval', array_unique($variantIds)));
            $vRows = $db->query(
                "SELECT id, price_override FROM ec_product_variants WHERE id IN ($vIdList) AND is_active = 1"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($vRows as $vRow) {
                $po = $vRow['price_override'];
                if ($po !== null && $po !== '') {
                    $variantPrices[(int)$vRow['id']] = round((float)$po, 2);
                }
            }
        } catch (\Throwable $ignored) {}
    }

    foreach ($items as &$item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $authoritativePricing = ecAuthoritativeCartItemPricing($item);
        $vid = (int)($item['variant_id'] ?? 0);
        $dbBasePrice = $authoritativePricing['base_price'] ?? null;
        if ($dbBasePrice === null && array_key_exists($pid, $livePrices)) {
            $dbBasePrice = ($vid > 0 && array_key_exists($vid, $variantPrices))
                ? $variantPrices[$vid]
                : $livePrices[$pid];
        }
        if ($dbBasePrice === null) {
            continue;
        }

        $addonTotal   = round((float)($item['addon_total'] ?? 0.0), 2);
        $authoritative = round($dbBasePrice + $addonTotal, 2);
        $stored        = round((float)($item['price_snapshot'] ?? 0), 2);

        if (!empty($authoritativePricing['currency'])) {
            $item['currency'] = (string)$authoritativePricing['currency'];
        }
        if (!empty($authoritativePricing['store_id'])) {
            $item['store_id'] = (int)$authoritativePricing['store_id'];
        }

        if (abs($authoritative - $stored) > 0.01) {
            if (function_exists('write_log')) {
                write_log('ecEnforceCurrentPrices: price_snapshot mismatch', 'warning', [
                    'product_id'    => $pid,
                    'variant_id'    => $vid ?: null,
                    'stored'        => $stored,
                    'authoritative' => $authoritative,
                ]);
            }
            $item['price_snapshot']      = $authoritative;
            $item['base_price_snapshot'] = $dbBasePrice;
        }
    }
    unset($item);

    return $items;
}

function ecCalculateTotals(array $items, ?string $couponCode = null, ?int $shippingRateId = null, array $taxAddress = [], array $options = []): array
{
    // F3 Security: Re-fetch authoritative prices from DB to prevent cart price manipulation.
    // price_snapshot is stored in session/DB and could be tampered with. We replace the base
    // product price with the current DB price while preserving legitimate add-on adjustments.
    $items = ecEnforceCurrentPrices($items);

    $subtotal = 0.00;
    $currencyCode = ecResolveCartItemsCurrencyCode($items);
    foreach ($items as $item) {
        $subtotal += (float)($item['price_snapshot'] ?? 0) * max(1, (int)($item['qty'] ?? 1));
    }

    // Coupon / gift card application
    $discount    = 0.00;
    $giftCardCredit = 0.00;
    $couponData  = [];
    $couponDiscount = 0.00;
    if ($couponCode !== null) {
        $validation = ecCouponValidate($couponCode, $subtotal, $currencyCode);
        if ($validation['valid']) {
            $couponData = $validation;
            if (($validation['type'] ?? '') === 'gift_card') {
                $couponData['applies_to'] = 'total';
            } else {
                $couponDiscount = (float)($validation['discount_amount'] ?? 0.0);
                $discount = $couponDiscount;
                $couponData['applies_to'] = 'subtotal';
            }
        }
    }

    $loyaltyData = [
        'requested_points' => 0,
        'applied_points' => 0,
        'discount_amount' => 0.0,
        'balance' => 0,
        'remaining_balance' => 0,
    ];
    $customerId = isset($options['customer_id']) ? (int)$options['customer_id'] : 0;
    $requestedLoyaltyPoints = isset($options['loyalty_points']) ? max(0, (int)$options['loyalty_points']) : 0;
    if ($customerId > 0 && $requestedLoyaltyPoints > 0 && function_exists('ecLoyaltyNormalizeRedemption')) {
        $eligibleAmount = max(0.0, $subtotal - $couponDiscount);
        $loyaltyData = ecLoyaltyNormalizeRedemption($customerId, $eligibleAmount, $requestedLoyaltyPoints, $currencyCode);
        $discount += (float)($loyaltyData['discount_amount'] ?? 0.0);
    }

    // Tax
    $taxInclusive = (bool)ecSettings('tax_inclusive');
    $taxable    = max(0.0, $subtotal - $discount);
    $tax        = 0.00;

    $taxBreakdownMap = [];
    $remainingDiscount = $discount;
    $itemCount = count($items);
    foreach ($items as $index => $item) {
        $lineSubtotal = (float)($item['price_snapshot'] ?? 0) * max(1, (int)($item['qty'] ?? 1));
        if ($lineSubtotal <= 0) {
            continue;
        }

        $lineDiscount = 0.00;
        if ($discount > 0 && $subtotal > 0) {
            if ($index === $itemCount - 1) {
                $lineDiscount = $remainingDiscount;
            } else {
                $lineDiscount = round($discount * ($lineSubtotal / $subtotal), 2);
                $remainingDiscount -= $lineDiscount;
            }
        }

        $taxClass = isset($item['product_id']) && (int)$item['product_id'] > 0
            ? ecProductTaxClass((int)$item['product_id'])
            : 'standard';
        $lineRate = ecTaxResolveRate($taxClass, $taxAddress);
        $lineTaxable = max(0.0, $lineSubtotal - $lineDiscount);
        $lineTax = 0.00;

        if ($lineRate > 0) {
            if ($taxInclusive) {
                $lineTax = $lineTaxable - ($lineTaxable / (1 + $lineRate / 100));
            } else {
                $lineTax = $lineTaxable * ($lineRate / 100);
            }
        }

        $lineTax = round($lineTax, 2);
        $tax += $lineTax;

        $breakdownKey = $taxClass . '|' . number_format($lineRate, 4, '.', '');
        if (!isset($taxBreakdownMap[$breakdownKey])) {
            $taxBreakdownMap[$breakdownKey] = [
                'tax_class' => $taxClass,
                'tax_class_label' => ecProductTaxClassLabels()[$taxClass] ?? ucfirst($taxClass),
                'rate' => round($lineRate, 4),
                'amount' => 0.0,
            ];
        }
        $taxBreakdownMap[$breakdownKey]['amount'] = round((float)$taxBreakdownMap[$breakdownKey]['amount'] + $lineTax, 2);
    }
    $tax = round($tax, 2);

    // Shipping
    $shipping = 0.00;
    if ($shippingRateId) {
        $shippingData = ecShippingRateGet($shippingRateId, $items, $taxAddress, $couponCode);
        if ($shippingData) {
            $shipping = (float)($shippingData['rate'] ?? 0.0);
        }
    }

    if (($couponData['type'] ?? '') === 'gift_card') {
        $giftCardBase = max(0.0, $subtotal - $discount + ($taxInclusive ? 0 : $tax) + $shipping);
        $giftCardCredit = min((float)($couponData['value'] ?? 0.0), $giftCardBase);
        $couponData['discount_amount'] = round($giftCardCredit, 2);
        $couponData['remaining_balance'] = round(max(0.0, (float)($couponData['value'] ?? 0.0) - $giftCardCredit), 2);
    }

    $total = max(0.0, $subtotal - $discount + ($taxInclusive ? 0 : $tax) + $shipping - $giftCardCredit);
    $displayDiscount = round($discount + $giftCardCredit, 2);
    $nonLoyaltyDiscount = round($couponDiscount + $giftCardCredit, 2);
    $discountLabel = ($couponData['type'] ?? '') === 'gift_card' ? 'Gift Card' : 'Discount';

    $sym = ecCurrencySymbolFor($currencyCode);
    $taxBreakdown = array_values(array_map(static function (array $entry) use ($sym): array {
        $entry['amount_fmt'] = $sym . number_format((float)$entry['amount'], 2);
        return $entry;
    }, $taxBreakdownMap));

    $uniqueRates = array_values(array_unique(array_map(
        static fn(array $entry): float => round((float)($entry['rate'] ?? 0.0), 4),
        $taxBreakdown
    )));
    $resolvedTaxRate = count($uniqueRates) === 1 ? (float)$uniqueRates[0] : 0.0;

    return [
        'currency' => $currencyCode,
        'currency_symbol' => $sym,
        'subtotal'       => round($subtotal, 2),
        'subtotal_fmt'   => $sym . number_format($subtotal, 2),
        'discount'       => $displayDiscount,
        'discount_fmt'   => $sym . number_format($displayDiscount, 2),
        'discount_label' => $discountLabel,
        'non_loyalty_discount_amount' => $nonLoyaltyDiscount,
        'non_loyalty_discount_amount_fmt' => $sym . number_format($nonLoyaltyDiscount, 2),
        'coupon_discount_amount' => round($couponDiscount, 2),
        'coupon_discount_amount_fmt' => $sym . number_format($couponDiscount, 2),
        'loyalty_discount_amount' => round((float)($loyaltyData['discount_amount'] ?? 0.0), 2),
        'loyalty_discount_amount_fmt' => $sym . number_format((float)($loyaltyData['discount_amount'] ?? 0.0), 2),
        'loyalty_points_requested' => (int)($loyaltyData['requested_points'] ?? 0),
        'loyalty_points_applied' => (int)($loyaltyData['applied_points'] ?? 0),
        'loyalty_points_balance' => (int)($loyaltyData['balance'] ?? 0),
        'loyalty_points_remaining_balance' => (int)($loyaltyData['remaining_balance'] ?? 0),
        'gift_card_amount' => round($giftCardCredit, 2),
        'gift_card_amount_fmt' => $sym . number_format($giftCardCredit, 2),
        'tax'            => round($tax, 2),
        'tax_fmt'        => $sym . number_format($tax, 2),
        'tax_label'      => ecTaxBuildLabel($taxBreakdown, $taxInclusive),
        'tax_rate'       => $resolvedTaxRate,
        'tax_breakdown'  => $taxBreakdown,
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
function ecCouponValidate(string $code, float $subtotal, ?string $currencyCode = null): array
{
    if (trim($code) === '') {
        return ['valid' => false, 'error' => 'No coupon code provided'];
    }

    $currencyCode = ecCurrencyNormalizeCode($currencyCode ?? ecStoreBaseCurrencyCode()) ?: ecStoreBaseCurrencyCode();

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
    $minimumAmount = ecCurrencyConvertAmount((float)($row['min_order_amount'] ?? 0), ecStoreBaseCurrencyCode(), $currencyCode);
    if ($minimumAmount > 0 && $subtotal < $minimumAmount) {
        return ['valid' => false, 'error' => 'Order minimum not met for this coupon'];
    }

    $type = ecCouponNormalizeType((string)($row['type'] ?? 'percent'));

    $discount = 0.00;
    if ($type === 'percent') {
        $discount = $subtotal * ((float)$row['value'] / 100);
    } else {
        $convertedValue = ecCurrencyConvertAmount((float)$row['value'], ecStoreBaseCurrencyCode(), $currencyCode);
        $discount = min($convertedValue, $subtotal);
    }

    return [
        'valid'           => true,
        'error'           => null,
        'code'            => $row['code'],
        'type'            => $type,
        'currency'        => $currencyCode,
        'value'           => $type === 'percent'
            ? (float)$row['value']
            : ecCurrencyConvertAmount((float)$row['value'], ecStoreBaseCurrencyCode(), $currencyCode),
        'discount_amount' => round($discount, 2),
        'remaining_balance' => $type === 'gift_card'
            ? ecCurrencyConvertAmount((float)$row['value'], ecStoreBaseCurrencyCode(), $currencyCode)
            : null,
        'description'     => $row['description'] ?? '',
    ];
}

function ecCouponAllowedTypes(): array
{
    return ['percent', 'fixed', 'gift_card'];
}

function ecCouponNormalizeType(string $type): string
{
    $type = trim(strtolower($type));
    return in_array($type, ecCouponAllowedTypes(), true) ? $type : 'percent';
}

/**
 * Increment coupon uses_count. Called after order is placed.
 */
function ecCouponUse(string $code, ?float $appliedAmount = null): void
{
    try {
        $normalizedCode = strtoupper(trim($code));
        $coupon = ecDb()->query(
            "SELECT code, type, value FROM ec_coupons WHERE code = ? LIMIT 1",
            [$normalizedCode]
        )->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$coupon) {
            return;
        }

        if (ecCouponNormalizeType((string)($coupon['type'] ?? '')) === 'gift_card') {
            $amount = max(0.0, round((float)($appliedAmount ?? 0.0), 2));
            if ($amount <= 0) {
                return;
            }

            $remainingBalance = max(0.0, round((float)($coupon['value'] ?? 0.0) - $amount, 2));
            $isActive = $remainingBalance > 0 ? (int)($coupon['is_active'] ?? 1) : 0;

            ecDb()->execute(
                "UPDATE ec_coupons
                 SET value = ?,
                     uses_count = uses_count + 1,
                     is_active = ?,
                     updated_at = NOW()
                 WHERE code = ?",
                [$remainingBalance, $isActive, $normalizedCode]
            );
            return;
        }

        ecDb()->execute(
            "UPDATE ec_coupons SET uses_count = uses_count + 1 WHERE code = ?",
            [$normalizedCode]
        );
    } catch (\Throwable $e) {
        write_log('ecCouponUse error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
    }
}

/**
 * Get a shipping rate record by ID.
 */
function ecShippingRateGet(int $rateId, array $items = [], array $address = [], ?string $couponCode = null): ?array
{
    if ($rateId === 0) {
        return null;
    }

    if ($items !== [] || $address !== [] || $rateId < 0) {
        foreach (ecShippingAvailableRates($items, $address, $couponCode) as $rate) {
            if ((int)($rate['id'] ?? 0) === $rateId) {
                return $rate;
            }
        }
        if ($rateId < 0) {
            return null;
        }
    }

    try {
        $row = ecDb()->query(
            "SELECT * FROM ec_shipping_rates WHERE id = ? AND is_active = 1 LIMIT 1",
            [$rateId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ? ecShippingNormalizeRate($row) : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function ecShippingResolveStoreContext(array $items = [], array $address = [], array $options = []): ?array
{
    $explicitStoreId = max(0, (int)($options['store_id'] ?? 0));
    if ($explicitStoreId > 0 && function_exists('ecStoreById')) {
        return ecStoreById($explicitStoreId);
    }

    $storeIds = [];
    foreach ($items as $item) {
        $itemStoreId = max(0, (int)($item['store_id'] ?? 0));
        if ($itemStoreId > 0) {
            $storeIds[$itemStoreId] = true;
        }
    }

    if ($storeIds === [] && function_exists('ecProductStoreAssignmentMap')) {
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn(array $item): int => max(0, (int)($item['product_id'] ?? 0)),
            $items
        ))));
        $assignmentMap = $productIds !== [] ? ecProductStoreAssignmentMap($productIds) : [];
        foreach ($productIds as $productId) {
            $assignedStores = array_values(array_filter(array_map('intval', $assignmentMap[$productId] ?? [])));
            if (count($assignedStores) === 1) {
                $storeIds[$assignedStores[0]] = true;
                continue;
            }
            if (count($assignedStores) > 1) {
                $storeIds = [];
                break;
            }
        }
    }

    if (count($storeIds) !== 1 || !function_exists('ecStoreById')) {
        if (function_exists('ecStoreResolveContext')) {
            $activeStore = ecStoreResolveContext();
            if (is_array($activeStore)) {
                return $activeStore;
            }
        }

        return null;
    }

    return ecStoreById((int)array_key_first($storeIds));
}

function ecStoreShippingRates(array $items, array $address = [], ?string $couponCode = null, ?array $store = null): array
{
    if ($store === null || !function_exists('ecStoreSettingsArray')) {
        return [];
    }

    $settings = ecStoreSettingsArray($store);
    $mode = trim((string)($settings['shipping_mode'] ?? ''));
    if (!in_array($mode, ['flat', 'table'], true)) {
        return [];
    }

    $metrics = ecShippingCartMetrics($items, $couponCode);

    if ($mode === 'flat') {
        $rate = isset($settings['shipping_flat_rate']) && is_numeric((string)$settings['shipping_flat_rate'])
            ? round((float)$settings['shipping_flat_rate'], 2)
            : 0.0;
        $freeAbove = isset($settings['shipping_free_above']) && is_numeric((string)$settings['shipping_free_above'])
            ? round((float)$settings['shipping_free_above'], 2)
            : null;

        return [ecShippingNormalizeRate([
            'id' => -300000 - (int)($store['id'] ?? 0),
            'label' => trim((string)($settings['shipping_label'] ?? 'Store Shipping')) ?: 'Store Shipping',
            'carrier' => trim((string)($settings['shipping_carrier'] ?? '')),
            'estimated_days' => trim((string)($settings['shipping_estimated_days'] ?? '')),
            'rate' => $rate,
            'free_above' => $freeAbove,
            'sort_order' => 0,
            'source' => 'store_flat_rate',
            'zone_name' => (string)($store['name'] ?? ''),
        ], $metrics)];
    }

    $rawRules = trim((string)($settings['shipping_table_rate_rules'] ?? ''));
    if ($rawRules === '') {
        return [];
    }

    $location = ecShippingNormalizeLocation(array_merge([
        'country' => (string)($settings['shipping_default_country'] ?? ''),
    ], $address));
    $rates = [];

    foreach (ecShippingParseTableRateRules($rawRules) as $rule) {
        if (!ecShippingRuleMatches($rule, $location, $metrics)) {
            continue;
        }
        $rule['id'] = ((int)($rule['id'] ?? -100000)) - ((int)($store['id'] ?? 0) * 1000);
        $rule['zone_name'] = (string)($store['name'] ?? '');
        $rule['source'] = 'store_table_rate';
        $rates[] = ecShippingNormalizeRate($rule, $metrics);
    }

    usort($rates, static function (array $left, array $right): int {
        $rateCompare = ((float)$left['rate']) <=> ((float)$right['rate']);
        if ($rateCompare !== 0) {
            return $rateCompare;
        }

        return ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0));
    });

    return $rates;
}

/**
 * List available shipping rates (optionally filtered by zone).
 */
function ecShippingRates(int $zoneId = 1): array
{
    try {
        $rows = ecDb()->query(
            "SELECT sr.*, sz.name as zone_name
             FROM ec_shipping_rates sr
             INNER JOIN ec_shipping_zones sz ON sz.id = sr.zone_id
             WHERE sr.zone_id = ? AND sr.is_active = 1
             ORDER BY sr.sort_order ASC, sr.rate ASC",
            [$zoneId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $row): array => ecShippingNormalizeRate($row), $rows);
    } catch (\Throwable $e) {
        return [];
    }
}
