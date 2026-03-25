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
//  { product_id, variant_id, qty, price_snapshot, product_title, sku }
// ─────────────────────────────────────────────────────────────────────────

define('EC_SESSION_CART_KEY', 'ec_cart');
define('EC_SESSION_COUPON_KEY', 'ec_cart_coupon');

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
    unset($_SESSION[EC_SESSION_CART_KEY], $_SESSION[EC_SESSION_COUPON_KEY]);
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
    $couponCode = $userId
        ? ecDbCartCoupon($userId)
        : ($_SESSION[EC_SESSION_COUPON_KEY] ?? null);

    $totals = ecCalculateTotals($items, $couponCode);

    return ['items' => $items, 'totals' => $totals, 'coupon_code' => $couponCode];
}

/**
 * Add a product to the cart.
 * Validates stock, snapshots price.
 */
function ecCartAdd(int $productId, int $qty = 1, ?int $variantId = null): array
{
    if ($qty < 1) {
        return ['ok' => false, 'error' => 'Invalid quantity'];
    }

    $product = ecProductGet($productId);
    if (!$product) {
        return ['ok' => false, 'error' => 'Product not found'];
    }

    $pricing   = $product['pricing'];
    $inventory = $product['inventory'];

    // Stock check
    if ($inventory['track_stock'] && !$inventory['in_stock']) {
        return ['ok' => false, 'error' => 'Product is out of stock'];
    }

    $price = 0.00;
    $sku   = $inventory['sku'] ?? '';

    if ($variantId) {
        $variant = ecProductVariantGet($variantId, $productId);
        if (!$variant) {
            return ['ok' => false, 'error' => 'Variant not found'];
        }
        $price = $variant['price_override'] !== null ? (float)$variant['price_override'] : (float)($pricing['price'] ?? 0);
        $sku   = $variant['sku'] ?: $sku;
    } else {
        $price = (float)($pricing['price'] ?? 0);
    }

    $item = [
        'product_id'     => $productId,
        'variant_id'     => $variantId,
        'qty'            => $qty,
        'price_snapshot' => $price,
        'product_title'  => $product['title'],
        'sku'            => $sku,
    ];

    $user   = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;

    if ($userId) {
        ecDbCartAdd($userId, $item);
    } else {
        $items = ecSessionCartGet();
        // Merge if same product+variant already in cart
        $found = false;
        foreach ($items as &$existing) {
            if ($existing['product_id'] === $productId && $existing['variant_id'] === $variantId) {
                $existing['qty'] += $qty;
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

    return ['ok' => true, 'cart' => ecCartGet()];
}

/**
 * Update quantity of a cart item by index.
 */
function ecCartUpdate(int $itemIndex, int $qty): array
{
    $user   = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;

    if ($userId) {
        ecDbCartUpdateItem($userId, $itemIndex, $qty);
    } else {
        $items = ecSessionCartGet();
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
    $coupon = ecCouponValidate($code, ecCartSubtotal());
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
        return ecDb()->query(
            "SELECT ci.id as item_db_id, ci.product_id, ci.variant_id, ci.qty,
                    ci.price_snapshot, ci.product_title, ci.sku
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

    // Merge if same product+variant
    $existing = $db->query(
        "SELECT id, qty FROM ec_cart_items WHERE cart_id = ? AND product_id = ? AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL)) LIMIT 1",
        [$cartId, $item['product_id'], $item['variant_id'], $item['variant_id']]
    )->fetch(\PDO::FETCH_ASSOC);

    if ($existing) {
        $db->execute("UPDATE ec_cart_items SET qty = qty + ?, updated_at = NOW() WHERE id = ?", [$item['qty'], $existing['id']]);
    } else {
        $db->execute(
            "INSERT INTO ec_cart_items (cart_id, product_id, variant_id, qty, price_snapshot, product_title, sku, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$cartId, $item['product_id'], $item['variant_id'], $item['qty'], $item['price_snapshot'], $item['product_title'], $item['sku']]
        );
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
