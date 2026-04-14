<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Cart (helpers/10-cart.php)
//
// Dual-mode cart:
//  - Guest:   $_SESSION['ec_cart'] array
//  - Customer: ec_carts + ec_cart_items DB rows, keyed by user_id
//
// Cart item shape:
//  { product_id, variant_id, qty, price_snapshot, currency, product_title, sku, options_json }
// ─────────────────────────────────────────────────────────────────────────

define('EC_SESSION_CART_KEY', 'ec_cart');
define('EC_SESSION_COUPON_KEY', 'ec_cart_coupon');

function ecCartItemsHasColumn(string $column): bool
{
    static $cache = [];

    $column = trim($column);
    if ($column === '') {
        return false;
    }

    if (($cache[$column] ?? null) === true) {
        return $cache[$column];
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['ec_cart_items', $column]);
        $cache[$column] = (int)($stmt->fetchColumn() ?: 0) > 0;
    } catch (\Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function ecCartItemsHasCurrencyColumn(): bool
{
    return ecCartItemsHasColumn('currency');
}

function ecCartItemsHasOptionsJsonColumn(): bool
{
    return ecCartItemsHasColumn('options_json');
}

// ── Session cart helpers ─────────────────────────────────────────────

function ecSessionCartGet(): array
{
    return $_SESSION[EC_SESSION_CART_KEY] ?? [];
}

function ecSessionCartSave(array $items): void
{
    $_SESSION[EC_SESSION_CART_KEY] = $items;
}

function ecSessionCartClear(): void
{
    unset($_SESSION[EC_SESSION_CART_KEY], $_SESSION[EC_SESSION_COUPON_KEY], $_SESSION[defined('EC_SESSION_LOYALTY_KEY') ? EC_SESSION_LOYALTY_KEY : 'ec_cart_loyalty_points']);
}

function ecCartHydrateStoreIds(array $items): array
{
    $productIds = [];
    foreach ($items as $item) {
        if (max(0, (int)($item['store_id'] ?? 0)) > 0) {
            continue;
        }

        $productId = max(0, (int)($item['product_id'] ?? 0));
        if ($productId > 0) {
            $productIds[$productId] = true;
        }
    }

    if ($productIds === [] || !function_exists('ecProductStoreAssignmentMap')) {
        return $items;
    }

    $assignmentMap = ecProductStoreAssignmentMap(array_map('intval', array_keys($productIds)));
    foreach ($items as &$item) {
        if (max(0, (int)($item['store_id'] ?? 0)) > 0) {
            continue;
        }

        $productId = max(0, (int)($item['product_id'] ?? 0));
        if ($productId <= 0) {
            continue;
        }

        $assignedStores = array_values(array_unique(array_filter(array_map(
            static fn(array $store): int => max(0, (int)($store['id'] ?? 0)),
            (array)($assignmentMap[$productId] ?? [])
        ))));
        if (count($assignedStores) === 1) {
            $item['store_id'] = $assignedStores[0];
        }
    }
    unset($item);

    return $items;
}

function ecCartResolvedStoreId(array $items): int
{
    $storeIds = [];
    foreach (ecCartHydrateStoreIds($items) as $item) {
        $storeId = max(0, (int)($item['store_id'] ?? 0));
        if ($storeId > 0) {
            $storeIds[$storeId] = true;
        }
    }

    return count($storeIds) === 1 ? (int)array_key_first($storeIds) : 0;
}

function ecCartResolvedStore(array $items): ?array
{
    $storeId = ecCartResolvedStoreId($items);
    if ($storeId <= 0 || !function_exists('ecStoreById')) {
        return null;
    }

    $store = ecStoreById($storeId);
    return is_array($store) ? $store : null;
}

function ecCartResolveStoreContextForProduct(array $product): ?array
{
    $storeCtx = null;
    $storeId  = max(0, (int)($product['store_id'] ?? 0));
    if ($storeId > 0 && function_exists('ecStoreById')) {
        $storeCtx = ecStoreById($storeId);
    }
    if ($storeCtx === null) {
        $requestSlug = trim((string)($_GET['store'] ?? ''));
        if ($requestSlug === '') {
            $requestSlug = trim((string)($_SERVER['HTTP_X_STORE_SLUG'] ?? ''));
        }
        if ($requestSlug !== '' && function_exists('ecStoreResolveContext')) {
            $storeCtx = ecStoreResolveContext();
        }
    }

    return is_array($storeCtx) ? $storeCtx : null;
}

function ecCartResolveTargetCurrencyForProduct(array $product): string
{
    // Store-level currency is authoritative — prices entered through store-admin
    // are native to that store's currency and must not be converted to whatever
    // the global/session selection happens to be. Mirror the same resolution
    // order used in ecBuildStorefrontCatalogItem() so cart and display agree.
    //
    // IMPORTANT: only resolve the request store context when the shopper
    // explicitly selected a store (?store=slug or X-Store-Slug header). The
    // default-store fallback in ecStoreResolveContext() must not impose a
    // currency conversion on products that are not assigned to any store; doing
    // so would apply the exchange-rate multiplier to already-native prices.
    $storeCtx = ecCartResolveStoreContextForProduct($product);
    if ($storeCtx !== null && function_exists('ecStoreSettingsArray')) {
        $storeCurrency = ecCurrencyNormalizeCode(ecStoreSettingsArray($storeCtx)['currency'] ?? '');
        if ($storeCurrency !== '') {
            return $storeCurrency;
        }
    }

    // No store-level override — honour the shopper's active currency selection.
    $currentCurrency = ecCurrencyNormalizeCode(function_exists('ecCurrentCurrencyCode') ? ecCurrentCurrencyCode() : '');
    if ($currentCurrency !== '') {
        return $currentCurrency;
    }

    $pricingCurrency = ecCurrencyNormalizeCode($product['pricing']['currency'] ?? '');
    if ($pricingCurrency !== '') {
        return $pricingCurrency;
    }

    return ecCurrentCurrencyCode();
}

// ── Unified cart API ─────────────────────────────────────────────────

/**
 * Get the current cart as a unified array regardless of guest/customer mode.
 * Returns ['items' => [...], 'totals' => [...], 'coupon' => [...|null]]
 */
function ecCartGet(): array
{
    $user = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;

    $items = $userId ? ecDbCartItems($userId) : ecSessionCartGet();
    $items = ecCartHydrateStoreIds($items);
    if (function_exists('ecEnforceCurrentPrices')) {
        $items = ecEnforceCurrentPrices($items);
    }
    $couponCode = $userId
        ? ecDbCartCoupon($userId)
        : ($_SESSION[EC_SESSION_COUPON_KEY] ?? null);
    $loyaltyPoints = function_exists('ecCartSelectedLoyaltyPoints') ? ecCartSelectedLoyaltyPoints() : 0;

    $totals = ecCalculateTotals($items, $couponCode, null, [], [
        'customer_id' => $userId > 0 ? $userId : null,
        'loyalty_points' => $loyaltyPoints,
    ]);
    $currencyCode = ecResolveCartItemsCurrencyCode($items);
    $currencySymbol = ecCurrencySymbolFor($currencyCode);
    $resolvedStore = ecCartResolvedStore($items);
    foreach ($items as &$item) {
        if (function_exists('ecHydrateLineItemOptions')) {
            $item = ecHydrateLineItemOptions($item, $currencyCode);
        }
        $itemCurrency = ecCurrencyNormalizeCode($item['currency'] ?? '') ?: $currencyCode;
        $unitPrice = round((float)($item['price_snapshot'] ?? 0), 2);
        $lineTotal = round($unitPrice * max(1, (int)($item['qty'] ?? 1)), 2);
        $item['currency'] = $itemCurrency;
        $item['currency_symbol'] = ecCurrencySymbolFor($itemCurrency ?: $currencyCode);
        $item['unit_price_fmt'] = ecCurrencyFormatAmount($unitPrice, $itemCurrency, $item['currency_symbol']);
        $item['line_total_fmt'] = ecCurrencyFormatAmount($lineTotal, $itemCurrency, $item['currency_symbol']);
    }
    unset($item);
    $subscriptionSummary = function_exists('ecCartSubscriptionSummary')
        ? ecCartSubscriptionSummary($items)
        : ['has_subscription' => false, 'is_valid' => true, 'errors' => [], 'items' => [], 'primary_item' => null];

    return [
        'items' => $items,
        'totals' => $totals,
        'coupon_code' => $couponCode,
        'currency' => $currencyCode,
        'currency_symbol' => $currencySymbol,
        'store' => $resolvedStore,
        'loyalty' => function_exists('ecCartLoyaltySummary')
            ? ecCartLoyaltySummary($userId, $totals, $loyaltyPoints)
            : ['balance' => 0, 'selected_points' => 0, 'applied_points' => 0, 'discount_amount' => 0.0, 'can_redeem' => false],
        'subscription' => $subscriptionSummary,
        'validation_errors' => (array)($subscriptionSummary['errors'] ?? []),
    ];
}

function ecCartPrepareItem(int $productId, int $qty = 1, ?int $variantId = null, array $options = []): array
{
    if ($qty < 1) {
        return ['ok' => false, 'error' => 'Invalid quantity'];
    }

    $product = ecProductGet($productId);
    if (!$product) {
        return ['ok' => false, 'error' => 'Product not found'];
    }
    if (function_exists('ecMembershipGateForProduct')) {
        $membershipGate = ecMembershipGateForProduct($product);
        if (!empty($membershipGate['requires_membership']) && empty($membershipGate['allowed'])) {
            return ['ok' => false, 'error' => (string)($membershipGate['message'] ?? 'This product requires an active membership.')];
        }
    }
    if (!empty($product['is_external_product'])) {
        return ['ok' => false, 'error' => 'This product must be purchased through an external checkout'];
    }
    if (function_exists('ecCartCanAddProduct')) {
        $cart = ecCartGet();
        $canAdd = ecCartCanAddProduct((array)($cart['items'] ?? []), $product, $qty);
        if (empty($canAdd['ok'])) {
            return ['ok' => false, 'error' => (string)($canAdd['error'] ?? 'This product cannot be added to the cart')];
        }
    }

    $itemStore = function_exists('ecCartResolveStoreContextForProduct')
        ? ecCartResolveStoreContextForProduct($product)
        : null;
    $itemStoreId = max(0, (int)($product['store_id'] ?? 0));
    if ($itemStore !== null && function_exists('ecStoreApplyProductOverrides')) {
        $product = ecStoreApplyProductOverrides($product, $itemStore);
        if (!is_array($product)) {
            return ['ok' => false, 'error' => 'This product is not available in the selected store.'];
        }
        $itemStoreId = max(0, (int)($itemStore['id'] ?? $itemStoreId));
    }
    $targetCurrency = ecCartResolveTargetCurrencyForProduct($product);
    $existingCart = ecCartGet();
    $existingItems = (array)($existingCart['items'] ?? []);
    if ($existingItems !== []) {
        $existingCurrency = ecResolveCartItemsCurrencyCode($existingItems);
        $existingStoreId = ecCartResolvedStoreId($existingItems);
        if ($existingCurrency !== '' && $targetCurrency !== '' && $existingCurrency !== $targetCurrency) {
            return ['ok' => false, 'error' => 'Products with different store currencies must be purchased in separate orders.'];
        }
        if ($existingStoreId > 0 && $itemStoreId > 0 && $existingStoreId !== $itemStoreId) {
            return ['ok' => false, 'error' => 'Products from different stores must be purchased in separate orders.'];
        }
    }

    $pricing   = is_array($product['pricing'] ?? null) ? $product['pricing'] : [];
    if ($itemStore !== null && function_exists('ecStoreApplyPricingCurrencyOverride')) {
        $pricing = ecStoreApplyPricingCurrencyOverride($pricing, $itemStore);
    }
    $inventory = $product['inventory'];

    if ($inventory['track_stock'] && !$inventory['in_stock']) {
        return ['ok' => false, 'error' => 'Product is out of stock'];
    }

    $price = 0.00;
    $sku   = $inventory['sku'] ?? '';
    $sourceCurrency = ecCurrencyNormalizeCode($pricing['currency'] ?? ecStoreBaseCurrencyCode()) ?: ecStoreBaseCurrencyCode();

    if ($variantId) {
        $variant = ecProductVariantGet($variantId, $productId);
        if (!$variant) {
            return ['ok' => false, 'error' => 'Variant not found'];
        }
        $basePrice = $variant['price_override'] !== null ? (float)$variant['price_override'] : (float)($pricing['active_price'] ?? $pricing['price'] ?? 0);
        $price = ecCurrencyConvertAmount($basePrice, $sourceCurrency, $targetCurrency);
        $sku   = $variant['sku'] ?: $sku;
    } else {
        $displayPricing = ecCurrencyPresentPricing($pricing, $targetCurrency);
        $price = (float)($displayPricing['active_price'] ?? $displayPricing['price'] ?? 0);
    }

    if (!empty($product['booking']['enabled']) && $qty !== 1) {
        return ['ok' => false, 'error' => 'Bookable products can only be added one appointment at a time.'];
    }

    $extendedData = function_exists('ecCartPrepareExtendedItemData')
        ? ecCartPrepareExtendedItemData($product, $options)
        : ['ok' => true, 'price_adjustment' => 0.0, 'selected_addons' => [], 'addon_total' => 0.0, 'booking' => ['has_booking' => false]];
    if (empty($extendedData['ok'])) {
        return $extendedData;
    }

    $price += (float)($extendedData['price_adjustment'] ?? 0.0);

    $item = [
        'product_id' => $productId,
        'variant_id' => $variantId,
        'qty' => $qty,
        'price_snapshot' => round($price, 2),
        'base_price_snapshot' => round($price - (float)($extendedData['price_adjustment'] ?? 0.0), 2),
        'currency' => $targetCurrency,
        'store_id' => $itemStoreId > 0 ? $itemStoreId : null,
        'product_title' => $product['title'],
        'sku' => $sku,
        'selected_addons' => is_array($extendedData['selected_addons'] ?? null) ? $extendedData['selected_addons'] : [],
        'addon_total' => round((float)($extendedData['addon_total'] ?? 0.0), 2),
        'booking' => is_array($extendedData['booking'] ?? null) ? $extendedData['booking'] : ['has_booking' => false],
    ];
    if (!empty($options['bundle_parent_id'])) {
        $item['bundle_parent_id'] = max(0, (int)$options['bundle_parent_id']);
    }
    $item['options_json'] = function_exists('ecCartCanonicalOptionsJson') ? ecCartCanonicalOptionsJson($item) : '';
    $item['options_signature'] = sha1((string)$item['options_json']);

    return [
        'ok' => true,
        'item' => $item,
    ];
}

function ecCartPersistItem(array $item): void
{
    $user   = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;

    if ($userId) {
        ecDbCartAdd($userId, $item);
        return;
    }

    $items = ecSessionCartGet();
    $found = false;
    $targetPrice = round((float)($item['price_snapshot'] ?? 0), 4);
    foreach ($items as &$existing) {
        $existingPrice = round((float)($existing['price_snapshot'] ?? 0), 4);
        $existingCurrency = ecCurrencyNormalizeCode($existing['currency'] ?? '');
        $targetCurrency = ecCurrencyNormalizeCode($item['currency'] ?? '');
        $existingSignature = (string)($existing['options_signature'] ?? sha1((string)($existing['options_json'] ?? '')));
        $targetSignature = (string)($item['options_signature'] ?? sha1((string)($item['options_json'] ?? '')));
        if (
            $existing['product_id'] === $item['product_id']
            && $existing['variant_id'] === $item['variant_id']
            && abs($existingPrice - $targetPrice) < 0.0001
            && $existingCurrency === $targetCurrency
            && $existingSignature === $targetSignature
        ) {
            $existing['qty'] += (int)$item['qty'];
            $found = true;
            break;
        }
    }
    unset($existing);

    if (!$found) {
        $items[] = $item;
    }

    ecSessionCartSave($items);
}

function ecCartPrepareBundleItems(array $product, int $bundleQty = 1): array
{
    $bundleQty = max(1, $bundleQty);
    $bundleChildren = is_array($product['bundle_children'] ?? null) ? $product['bundle_children'] : [];
    if ($bundleChildren === []) {
        return ['ok' => false, 'error' => 'Bundle items are not configured'];
    }

    $preparedItems = [];
    $childSubtotal = 0.0;

    foreach ($bundleChildren as $child) {
        if (!is_array($child)) {
            continue;
        }

        $childProductId = (int)($child['id'] ?? $child['product_id'] ?? 0);
        $childQty = max(1, (int)($child['bundle_qty'] ?? $child['qty'] ?? 1)) * $bundleQty;
        $childProduct = ecProductGet($childProductId, false);
        if (!is_array($childProduct)) {
            return ['ok' => false, 'error' => 'Bundle child product is unavailable'];
        }
        if (!empty($childProduct['is_external_product'])) {
            return ['ok' => false, 'error' => 'Bundles cannot include external checkout products'];
        }
        if (ecProductBundleChildSelections($childProductId) !== [] || ecProductGroupedChildSelections($childProductId) !== []) {
            return ['ok' => false, 'error' => 'Nested bundle or grouped products are not supported'];
        }

        $prepared = ecCartPrepareItem($childProductId, $childQty, null);
        if (!$prepared['ok']) {
            return $prepared;
        }

        $prepared['item']['bundle_parent_id'] = (int)($product['id'] ?? 0);
        $prepared['item']['options_json'] = function_exists('ecCartCanonicalOptionsJson')
            ? ecCartCanonicalOptionsJson($prepared['item'])
            : '';
        $prepared['item']['options_signature'] = sha1((string)$prepared['item']['options_json']);

        $preparedItems[] = $prepared['item'];
        $childSubtotal += ((float)$prepared['item']['price_snapshot'] * (int)$prepared['item']['qty']);
    }

    if ($preparedItems === []) {
        return ['ok' => false, 'error' => 'Bundle items are not configured'];
    }

    $pricing = is_array($product['pricing'] ?? null) ? $product['pricing'] : [];
    $targetTotal = (float)($pricing['active_price'] ?? $pricing['price'] ?? 0);
    $targetTotal = $targetTotal > 0 ? round($targetTotal * $bundleQty, 4) : round($childSubtotal, 4);
    if ($childSubtotal > 0 && $targetTotal > $childSubtotal) {
        $targetTotal = round($childSubtotal, 4);
    }

    if ($childSubtotal > 0 && abs($targetTotal - $childSubtotal) > 0.0001) {
        $allocatedTotal = 0.0;
        $lastIndex = count($preparedItems) - 1;

        foreach ($preparedItems as $index => $item) {
            $lineQty = max(1, (int)($item['qty'] ?? 1));
            $lineSubtotal = (float)$item['price_snapshot'] * $lineQty;
            if ($index === $lastIndex) {
                $allocatedLineTotal = round($targetTotal - $allocatedTotal, 4);
            } else {
                $allocatedLineTotal = round($targetTotal * ($lineSubtotal / $childSubtotal), 4);
                $allocatedTotal += $allocatedLineTotal;
            }

            $preparedItems[$index]['price_snapshot'] = $lineQty > 0
                ? round($allocatedLineTotal / $lineQty, 4)
                : 0.0;
        }
    }

    return [
        'ok' => true,
        'items' => $preparedItems,
    ];
}

function ecCartAddBundleProduct(array $product, int $bundleQty = 1): array
{
    $prepared = ecCartPrepareBundleItems($product, $bundleQty);
    if (!$prepared['ok']) {
        return $prepared;
    }

    foreach ($prepared['items'] as $item) {
        ecCartPersistItem($item);
    }

    return ['ok' => true, 'cart' => ecCartGet()];
}

function ecCartNormalizeGroupedItems(mixed $groupedItems): array
{
    if (!is_array($groupedItems)) {
        return [];
    }

    $normalized = [];
    foreach ($groupedItems as $groupedItem) {
        if (!is_array($groupedItem)) {
            continue;
        }

        $productId = (int)($groupedItem['product_id'] ?? $groupedItem['entity_id'] ?? 0);
        $qty = (int)($groupedItem['qty'] ?? 0);
        $variantId = isset($groupedItem['variant_id']) && $groupedItem['variant_id'] !== ''
            ? (int)$groupedItem['variant_id']
            : null;

        if ($productId < 1 || $qty < 1) {
            continue;
        }

        $normalized[] = [
            'product_id' => $productId,
            'qty' => $qty,
            'variant_id' => $variantId,
        ];
    }

    return $normalized;
}

function ecCartAddGroupedItems(array $groupedItems): array
{
    $normalizedItems = ecCartNormalizeGroupedItems($groupedItems);
    if ($normalizedItems === []) {
        return ['ok' => false, 'error' => 'Select at least one grouped product'];
    }

    $preparedItems = [];
    foreach ($normalizedItems as $groupedItem) {
        $prepared = ecCartPrepareItem((int)$groupedItem['product_id'], (int)$groupedItem['qty'], $groupedItem['variant_id']);
        if (!$prepared['ok']) {
            return $prepared;
        }

        $preparedItems[] = $prepared['item'];
    }

    foreach ($preparedItems as $item) {
        ecCartPersistItem($item);
    }

    return ['ok' => true, 'cart' => ecCartGet()];
}

/**
 * Add a product to the cart.
 * Validates stock, snapshots price.
 */
function ecCartAdd(int $productId, int $qty = 1, ?int $variantId = null, array $options = []): array
{
    $product = ecProductGet($productId);
    if (is_array($product) && !empty($product['bundle_children'])) {
        return ecCartAddBundleProduct($product, $qty);
    }

    $prepared = ecCartPrepareItem($productId, $qty, $variantId, $options);
    if (!$prepared['ok']) {
        return $prepared;
    }

    ecCartPersistItem($prepared['item']);

    return ['ok' => true, 'cart' => ecCartGet()];
}

/**
 * Update quantity of a cart item by index.
 */
function ecCartUpdate(int $itemIndex, int $qty): array
{
    $user   = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;

    $items = $userId ? ecDbCartItems($userId) : ecSessionCartGet();
    if (!isset($items[$itemIndex])) {
        return ['ok' => false, 'error' => 'Cart item not found', 'cart' => ecCartGet()];
    }

    $nextItems = $items;
    if ($qty <= 0) {
        array_splice($nextItems, $itemIndex, 1);
    } else {
        $nextItems[$itemIndex]['qty'] = $qty;
    }

    if (function_exists('ecCartSubscriptionSummary')) {
        $subscriptionSummary = ecCartSubscriptionSummary($nextItems);
        if (!empty($subscriptionSummary['has_subscription']) && !$subscriptionSummary['is_valid']) {
            return [
                'ok' => false,
                'error' => (string)($subscriptionSummary['errors'][0] ?? 'This cart update is not allowed for subscriptions.'),
                'cart' => ecCartGet(),
            ];
        }
    }

    if ($userId) {
        ecDbCartUpdateItem($userId, $itemIndex, $qty);
    } else {
        if ($qty <= 0) {
            array_splice($items, $itemIndex, 1);
        } elseif (isset($items[$itemIndex])) {
            $items[$itemIndex]['qty'] = $qty;
        }
        ecSessionCartSave($items);
    }

    return ['ok' => true, 'cart' => ecCartGet()];
}

/**
 * Remove a cart item by index.
 */
function ecCartRemove(int $itemIndex): array
{
    return ecCartUpdate($itemIndex, 0);
}

/**
 * Clear the entire cart.
 */
function ecCartClear(): void
{
    $user   = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;

    if ($userId) {
        ecDb()->execute("DELETE FROM ec_cart_items WHERE cart_id IN (SELECT id FROM ec_carts WHERE user_id = ?)", [$userId]);
        ecDb()->execute("DELETE FROM ec_carts WHERE user_id = ?", [$userId]);
    } else {
        ecSessionCartClear();
    }
}

/**
 * Apply a coupon code to the cart.
 */
function ecCartApplyCoupon(string $code): array
{
    $coupon = ecCouponValidate($code, ecCartSubtotal(), ecCurrentCartCurrencyCode());
    if (!$coupon['valid']) {
        return ['ok' => false, 'error' => $coupon['error']];
    }

    $user   = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;

    if ($userId) {
        ecDbCartSetCoupon($userId, $code);
    } else {
        $_SESSION[EC_SESSION_COUPON_KEY] = $code;
    }

    return ['ok' => true, 'coupon' => $coupon, 'cart' => ecCartGet()];
}

/**
 * Merge session cart into DB cart on customer login.
 */
function ecCartMergeOnLogin(int $userId): void
{
    $sessionItems = ecSessionCartGet();
    if (empty($sessionItems)) {
        return;
    }

    foreach ($sessionItems as $item) {
        ecDbCartAdd($userId, $item);
    }

    // Carry over coupon
    if (!empty($_SESSION[EC_SESSION_COUPON_KEY])) {
        ecDbCartSetCoupon($userId, $_SESSION[EC_SESSION_COUPON_KEY]);
    }

    ecSessionCartClear();
}

function ecCartSubtotal(): float
{
    $cart = ecCartGet();
    return (float)($cart['totals']['subtotal'] ?? 0.0);
}

// ── DB Cart helpers ──────────────────────────────────────────────────

function ecDbGetOrCreateCart(int $userId): int
{
    $db  = ecDb();
    $row = $db->query("SELECT id FROM ec_carts WHERE user_id = ? LIMIT 1", [$userId])->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }
    $db->execute("INSERT INTO ec_carts (user_id, created_at, updated_at) VALUES (?, NOW(), NOW())", [$userId]);
    return (int)$db->lastInsertId();
}

function ecDbCartItems(int $userId): array
{
    try {
        $hasOptionsJsonColumn = ecCartItemsHasOptionsJsonColumn();
        $currencySelect = ecCartItemsHasCurrencyColumn()
            ? 'ci.currency'
            : ('\'' . addslashes(ecStoreBaseCurrencyCode()) . '\' AS currency');
        $optionsJsonSelect = $hasOptionsJsonColumn ? 'ci.options_json' : 'NULL AS options_json';

        return ecDb()->query(
            "SELECT ci.id as item_db_id, ci.product_id, ci.variant_id, ci.qty,
                    ci.price_snapshot, {$currencySelect}, ci.product_title, ci.sku, {$optionsJsonSelect}
             FROM ec_cart_items ci
             INNER JOIN ec_carts c ON c.id = ci.cart_id
             WHERE c.user_id = ?
             ORDER BY ci.id ASC",
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecDbCartAdd(int $userId, array $item): void
{
    $cartId = ecDbGetOrCreateCart($userId);
    $db     = ecDb();
    $hasCurrencyColumn = ecCartItemsHasCurrencyColumn();
    $hasOptionsJsonColumn = ecCartItemsHasOptionsJsonColumn();
    $currencyCode = $item['currency'] ?? ecStoreBaseCurrencyCode();

    // Merge if same product+variant
    $existingSql = "SELECT id, qty FROM ec_cart_items
                 WHERE cart_id = ? AND product_id = ?
                     AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL))
                     AND price_snapshot = ?";
    $existingParams = [$cartId, $item['product_id'], $item['variant_id'], $item['variant_id'], $item['price_snapshot']];
    if ($hasCurrencyColumn) {
        $existingSql .= ' AND currency = ?';
        $existingParams[] = $currencyCode;
    }
    if ($hasOptionsJsonColumn) {
        $existingSql .= "
                     AND (options_json = ? OR (options_json IS NULL AND ? = ''))";
        $existingParams[] = $item['options_json'] ?? '';
        $existingParams[] = $item['options_json'] ?? '';
    }
    $existingSql .= ' LIMIT 1';

    $existing = $db->query($existingSql, $existingParams)->fetch(\PDO::FETCH_ASSOC);

    if ($existing) {
        $db->execute("UPDATE ec_cart_items SET qty = qty + ?, updated_at = NOW() WHERE id = ?", [$item['qty'], $existing['id']]);
    } else {
        if ($hasCurrencyColumn && $hasOptionsJsonColumn) {
            $db->execute(
                "INSERT INTO ec_cart_items (cart_id, product_id, variant_id, qty, price_snapshot, currency, product_title, sku, options_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$cartId, $item['product_id'], $item['variant_id'], $item['qty'], $item['price_snapshot'], $currencyCode, $item['product_title'], $item['sku'], $item['options_json'] ?? null]
            );
        } elseif ($hasCurrencyColumn) {
            $db->execute(
                "INSERT INTO ec_cart_items (cart_id, product_id, variant_id, qty, price_snapshot, currency, product_title, sku, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$cartId, $item['product_id'], $item['variant_id'], $item['qty'], $item['price_snapshot'], $currencyCode, $item['product_title'], $item['sku']]
            );
        } elseif ($hasOptionsJsonColumn) {
            $db->execute(
                "INSERT INTO ec_cart_items (cart_id, product_id, variant_id, qty, price_snapshot, product_title, sku, options_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$cartId, $item['product_id'], $item['variant_id'], $item['qty'], $item['price_snapshot'], $item['product_title'], $item['sku'], $item['options_json'] ?? null]
            );
        } else {
            $db->execute(
                "INSERT INTO ec_cart_items (cart_id, product_id, variant_id, qty, price_snapshot, product_title, sku, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$cartId, $item['product_id'], $item['variant_id'], $item['qty'], $item['price_snapshot'], $item['product_title'], $item['sku']]
            );
        }
    }

    $db->execute("UPDATE ec_carts SET updated_at = NOW() WHERE id = ?", [$cartId]);
}

function ecDbCartUpdateItem(int $userId, int $itemIndex, int $qty): void
{
    $items = ecDbCartItems($userId);
    if (!isset($items[$itemIndex])) {
        return;
    }
    $itemDbId = $items[$itemIndex]['item_db_id'] ?? 0;
    if (!$itemDbId) {
        return;
    }

    $db = ecDb();
    if ($qty <= 0) {
        $db->execute("DELETE FROM ec_cart_items WHERE id = ?", [$itemDbId]);
    } else {
        $db->execute("UPDATE ec_cart_items SET qty = ?, updated_at = NOW() WHERE id = ?", [$qty, $itemDbId]);
    }
}

function ecDbCartCoupon(int $userId): ?string
{
    try {
        $row = ecDb()->query("SELECT coupon_code FROM ec_carts WHERE user_id = ? LIMIT 1", [$userId])->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['coupon_code'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function ecDbCartSetCoupon(int $userId, string $code): void
{
    $cartId = ecDbGetOrCreateCart($userId);
    ecDb()->execute("UPDATE ec_carts SET coupon_code = ?, updated_at = NOW() WHERE id = ?", [$code, $cartId]);
}

// ── Variant getter ───────────────────────────────────────────────────

function ecProductVariantGet(int $variantId, int $productId): ?array
{
    try {
        $row = ecDb()->query(
            "SELECT * FROM ec_product_variants WHERE id = ? AND product_id = ? AND is_active = 1 LIMIT 1",
            [$variantId, $productId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Digital product detection ────────────────────────────────────────────

/**
 * Returns true when the given cart items array contains at least one product
 * whose `_is_digital` meta is '1' (i.e. requires a license / file download).
 *
 * @param array $cartItems  Items as returned by ecCartGet()['items']
 */
function ecCartHasDigitalItems(array $cartItems): bool
{
    if (empty($cartItems)) {
        return false;
    }

    $db = ecDb();
    $productIds = array_unique(array_map('intval', array_column($cartItems, 'product_id')));
    $productIds = array_filter($productIds);
    if (empty($productIds)) {
        return false;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $row = $db->query(
            "SELECT content_id FROM cms_content_meta
              WHERE meta_key = '_is_digital' AND meta_value = '1'
                AND content_id IN ($placeholders)
              LIMIT 1",
            array_values($productIds)
        )->fetch(\PDO::FETCH_ASSOC);

        return !empty($row);
    } catch (\Throwable $e) {
        return false;
    }
}

function ecCartRequiresShipping(array $cartItems): bool
{
    if (empty($cartItems)) {
        return false;
    }

    $productIds = array_unique(array_map('intval', array_column($cartItems, 'product_id')));
    $productIds = array_values(array_filter($productIds));
    if ($productIds === []) {
        return false;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = ecDb()->query(
            "SELECT content_id FROM cms_content_meta
             WHERE meta_key = '_is_digital' AND meta_value = '1'
               AND content_id IN ($placeholders)",
            $productIds
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return true;
    }

    $digitalLookup = [];
    foreach ($rows as $row) {
        $digitalLookup[(int)($row['content_id'] ?? 0)] = true;
    }

    foreach ($productIds as $productId) {
        if (!isset($digitalLookup[$productId])) {
            return true;
        }
    }

    return false;
}
