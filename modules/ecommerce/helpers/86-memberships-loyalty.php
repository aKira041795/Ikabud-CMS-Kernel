<?php

declare(strict_types=1);

if (!defined('EC_SESSION_LOYALTY_KEY')) {
    define('EC_SESSION_LOYALTY_KEY', 'ec_cart_loyalty_points');
}

function ecMembershipNormalizeTier(string $tier): string
{
    $tier = strtolower(trim($tier));
    $tier = preg_replace('/[^a-z0-9_-]+/', '-', $tier) ?? '';
    $tier = trim($tier, '-');

    return $tier !== '' ? $tier : 'member';
}

function ecMembershipNormalizeTierList(mixed $tiers): array
{
    if (is_string($tiers)) {
        $decoded = json_decode($tiers, true);
        if (is_array($decoded)) {
            $tiers = $decoded;
        } else {
            $tiers = preg_split('/[\r\n,]+/', $tiers) ?: [];
        }
    }

    if (!is_array($tiers)) {
        $tiers = [];
    }

    $normalized = [];
    foreach ($tiers as $tier) {
        $value = ecMembershipNormalizeTier((string)$tier);
        if ($value === '' || in_array($value, $normalized, true)) {
            continue;
        }
        $normalized[] = $value;
    }

    return $normalized;
}

function ecMembershipTierLabel(string $tier): string
{
    $tier = trim($tier);
    if ($tier === '') {
        return 'Member';
    }

    return ucwords(str_replace(['-', '_'], ' ', $tier));
}

function ecProductMembershipDefaults(): array
{
    return [
        'is_membership_product' => false,
        'membership_tier' => 'member',
        'membership_duration_days' => 365,
        'required_membership_tiers' => [],
        'membership_summary' => [
            'tier' => 'member',
            'tier_label' => 'Member',
            'duration_days' => 365,
            'duration_label' => '365 days access',
            'access_label' => '',
        ],
    ];
}

function ecProductMembershipMetaFromMetaMap(array $metaMap): array
{
    $defaults = ecProductMembershipDefaults();
    $isMembershipProduct = ($metaMap['_is_membership_product'] ?? '0') === '1';
    $membershipTier = ecMembershipNormalizeTier((string)($metaMap['_membership_tier'] ?? $defaults['membership_tier']));
    $membershipDurationDays = max(1, (int)($metaMap['_membership_duration_days'] ?? $defaults['membership_duration_days']));
    $requiredMembershipTiers = ecMembershipNormalizeTierList($metaMap['_required_membership_tiers'] ?? []);
    $durationLabel = $membershipDurationDays >= 365
        ? round($membershipDurationDays / 365, 1) . ' year access'
        : $membershipDurationDays . ' day' . ($membershipDurationDays === 1 ? '' : 's') . ' access';

    return array_merge($defaults, [
        'is_membership_product' => $isMembershipProduct,
        'membership_tier' => $membershipTier,
        'membership_duration_days' => $membershipDurationDays,
        'required_membership_tiers' => $requiredMembershipTiers,
        'membership_summary' => [
            'tier' => $membershipTier,
            'tier_label' => ecMembershipTierLabel($membershipTier),
            'duration_days' => $membershipDurationDays,
            'duration_label' => $durationLabel,
            'access_label' => $requiredMembershipTiers === []
                ? ''
                : 'Requires ' . implode(', ', array_map('ecMembershipTierLabel', $requiredMembershipTiers)),
        ],
    ]);
}

function ecProductSaveMembershipMeta(int $productId, array $input): void
{
    $normalized = ecProductMembershipMetaFromMetaMap([
        '_is_membership_product' => !empty($input['is_membership_product']) ? '1' : '0',
        '_membership_tier' => (string)($input['membership_tier'] ?? 'member'),
        '_membership_duration_days' => (string)($input['membership_duration_days'] ?? 365),
        '_required_membership_tiers' => json_encode(ecMembershipNormalizeTierList($input['required_membership_tiers'] ?? $input['required_membership_tiers_text'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $meta = [
        '_is_membership_product' => $normalized['is_membership_product'] ? '1' : '0',
        '_membership_tier' => (string)$normalized['membership_tier'],
        '_membership_duration_days' => (string)$normalized['membership_duration_days'],
        '_required_membership_tiers' => json_encode($normalized['required_membership_tiers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    try {
        moduleWithContext('cms', static function () use ($productId, $meta): void {
            $db = cmsDb();
            foreach ($meta as $key => $value) {
                $db->execute(
                    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                    [$productId, $key, $value]
                );
            }
        });
    } catch (\Throwable $e) {
        write_log('ecProductSaveMembershipMeta error: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

function ecMembershipStorageAvailable(): bool
{
    try {
        ecDb()->query('SELECT 1 FROM ec_memberships LIMIT 1');
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecMembershipProductMetaMap(array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $rows = ecDb()->query(
        "SELECT content_id, meta_key, meta_value
           FROM cms_content_meta
          WHERE content_id IN ($placeholders)
            AND meta_key IN ('_is_membership_product','_membership_tier','_membership_duration_days','_required_membership_tiers')",
        $productIds
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $metaByProduct = [];
    foreach ($rows as $row) {
        $productId = (int)($row['content_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        if (!isset($metaByProduct[$productId])) {
            $metaByProduct[$productId] = [];
        }
        $metaByProduct[$productId][(string)($row['meta_key'] ?? '')] = (string)($row['meta_value'] ?? '');
    }

    $normalized = [];
    foreach ($productIds as $productId) {
        $normalized[$productId] = ecProductMembershipMetaFromMetaMap($metaByProduct[$productId] ?? []);
    }

    return $normalized;
}

function ecMembershipNormalizeRow(array $row): array
{
    $row['membership_tier'] = ecMembershipNormalizeTier((string)($row['membership_tier'] ?? 'member'));
    $row['tier_label'] = ecMembershipTierLabel((string)$row['membership_tier']);
    $row['duration_days'] = max(1, (int)($row['duration_days'] ?? 365));
    $row['is_active'] = (string)($row['status'] ?? 'active') === 'active'
        && ((string)($row['ends_at'] ?? '') === '' || strtotime((string)$row['ends_at']) >= time());

    if (!empty($row['ends_at'])) {
        $row['days_remaining'] = max(0, (int)ceil((strtotime((string)$row['ends_at']) - time()) / 86400));
    } else {
        $row['days_remaining'] = null;
    }

    return $row;
}

function ecMembershipsForCustomer(int $customerId = 0, string $customerEmail = '', ?string $status = null): array
{
    if (!ecMembershipStorageAvailable()) {
        return [];
    }

    $where = [];
    $params = [];
    if ($customerId > 0) {
        $where[] = 'customer_id = ?';
        $params[] = $customerId;
    }
    $customerEmail = trim(strtolower($customerEmail));
    if ($customerEmail !== '') {
        $where[] = 'LOWER(customer_email) = ?';
        $params[] = $customerEmail;
    }
    if ($where === []) {
        return [];
    }

    $sql = 'SELECT * FROM ec_memberships WHERE (' . implode(' OR ', $where) . ')';
    if ($status !== null && $status !== '') {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';

    try {
        $rows = ecDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    return array_map('ecMembershipNormalizeRow', $rows);
}

function ecCustomerActiveMembershipTiers(int $customerId = 0, string $customerEmail = ''): array
{
    $tiers = [];
    foreach (ecMembershipsForCustomer($customerId, $customerEmail, 'active') as $membership) {
        if (empty($membership['is_active'])) {
            continue;
        }
        $tier = (string)($membership['membership_tier'] ?? '');
        if ($tier !== '' && !in_array($tier, $tiers, true)) {
            $tiers[] = $tier;
        }
    }

    return $tiers;
}

function ecCustomerHasMembershipTier(int $customerId = 0, string $customerEmail = '', mixed $requiredTiers = []): bool
{
    $required = ecMembershipNormalizeTierList($requiredTiers);
    if ($required === []) {
        return true;
    }

    $active = ecCustomerActiveMembershipTiers($customerId, $customerEmail);
    return array_intersect($required, $active) !== [];
}

function ecMembershipGateForProduct(array $product, ?array $user = null): array
{
    $required = ecMembershipNormalizeTierList($product['required_membership_tiers'] ?? []);
    if ($required === []) {
        return [
            'allowed' => true,
            'requires_membership' => false,
            'login_required' => false,
            'required_tiers' => [],
            'active_tiers' => [],
            'message' => '',
        ];
    }

    $user = $user ?? app()->user();
    $customerId = ($user && ($user['source'] ?? '') === 'cms') ? (int)($user['id'] ?? 0) : 0;
    $customerEmail = trim((string)($user['email'] ?? ''));
    $activeTiers = $customerId > 0 || $customerEmail !== '' ? ecCustomerActiveMembershipTiers($customerId, $customerEmail) : [];
    $allowed = array_intersect($required, $activeTiers) !== [];
    $loginRequired = !$allowed && $customerId <= 0;

    return [
        'allowed' => $allowed,
        'requires_membership' => true,
        'login_required' => $loginRequired,
        'required_tiers' => $required,
        'active_tiers' => $activeTiers,
        'message' => $allowed
            ? ''
            : ($loginRequired
                ? 'Sign in with an account that has the required membership tier to access this product.'
                : 'This product requires an active ' . implode(' or ', array_map('ecMembershipTierLabel', $required)) . ' membership.'),
    ];
}

function ecMembershipCreateForPaidOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecMembershipStorageAvailable()) {
        return ['ok' => false, 'created' => 0];
    }

    $order = ecOrderGet($orderId);
    if (!is_array($order) || empty($order['items'])) {
        return ['ok' => false, 'created' => 0];
    }

    $productIds = array_values(array_unique(array_filter(array_map(static fn(array $item): int => (int)($item['product_id'] ?? 0), (array)$order['items']))));
    $productMap = ecMembershipProductMetaMap($productIds);
    $db = ecDb();
    $created = 0;

    foreach ((array)$order['items'] as $item) {
        $orderItemId = (int)($item['id'] ?? 0);
        $productId = (int)($item['product_id'] ?? 0);
        if ($orderItemId <= 0 || $productId <= 0) {
            continue;
        }

        $membershipMeta = $productMap[$productId] ?? ecProductMembershipDefaults();
        if (empty($membershipMeta['is_membership_product'])) {
            continue;
        }

        $existingId = (int)($db->query('SELECT id FROM ec_memberships WHERE order_item_id = ? LIMIT 1', [$orderItemId])->fetchColumn() ?: 0);
        if ($existingId > 0) {
            continue;
        }

        $startsAt = trim((string)($order['created_at'] ?? date('Y-m-d H:i:s')));
        $endsAt = date('Y-m-d H:i:s', strtotime($startsAt . ' +' . max(1, (int)$membershipMeta['membership_duration_days']) . ' days'));

        $db->execute(
            'INSERT INTO ec_memberships (
                order_id, order_item_id, customer_id, customer_email, product_id, product_title,
                membership_tier, status, duration_days, starts_at, ends_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $orderId,
                $orderItemId,
                isset($order['customer_id']) ? (int)$order['customer_id'] : null,
                (string)($order['customer_email'] ?? $order['guest_email'] ?? ''),
                $productId,
                trim((string)($item['product_title'] ?? 'Membership')),
                (string)$membershipMeta['membership_tier'],
                'active',
                (int)$membershipMeta['membership_duration_days'],
                $startsAt,
                $endsAt,
            ]
        );

        $created++;
    }

    return ['ok' => true, 'created' => $created];
}

function ecLoyaltyStorageAvailable(): bool
{
    try {
        ecDb()->query('SELECT 1 FROM ec_loyalty_ledger LIMIT 1');
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecLoyaltyEarnRatePerCurrencyUnit(): int
{
    $v = (int)(ecSettings('loyalty_earn_rate') ?: 0);
    return $v > 0 ? $v : 1;
}

function ecLoyaltyPointsPerCurrencyUnit(): int
{
    $v = (int)(ecSettings('loyalty_points_per_currency') ?: 0);
    return $v > 0 ? $v : 100;
}

function ecLoyaltyMinimumRedeemPoints(): int
{
    $v = (int)(ecSettings('loyalty_minimum_redeem') ?: 0);
    return $v >= 0 ? $v : 100;
}

function ecCustomerLoyaltyPointsBalance(int $customerId): int
{
    if ($customerId <= 0 || !ecLoyaltyStorageAvailable()) {
        return 0;
    }

    try {
        return max(0, (int)(ecDb()->query('SELECT COALESCE(SUM(points), 0) FROM ec_loyalty_ledger WHERE customer_id = ?', [$customerId])->fetchColumn() ?: 0));
    } catch (\Throwable $e) {
        return 0;
    }
}

function ecLoyaltyEntriesForCustomer(int $customerId, int $limit = 20): array
{
    if ($customerId <= 0 || !ecLoyaltyStorageAvailable()) {
        return [];
    }

    try {
        return ecDb()->query(
            'SELECT * FROM ec_loyalty_ledger WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
            [$customerId, max(1, $limit)]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecLoyaltyCurrencyDiscount(int $points, ?string $currencyCode = null): float
{
    $currencyCode = ecCurrencyNormalizeCode($currencyCode ?? ecStoreBaseCurrencyCode()) ?: ecStoreBaseCurrencyCode();
    $baseAmount = max(0, $points) / ecLoyaltyPointsPerCurrencyUnit();

    return round(ecCurrencyConvertAmount($baseAmount, ecStoreBaseCurrencyCode(), $currencyCode), 2);
}

function ecLoyaltyNormalizeRedemption(int $customerId, float $eligibleAmount, int $requestedPoints, ?string $currencyCode = null): array
{
    $currencyCode = ecCurrencyNormalizeCode($currencyCode ?? ecStoreBaseCurrencyCode()) ?: ecStoreBaseCurrencyCode();
    $requestedPoints = max(0, $requestedPoints);
    $balance = ecCustomerLoyaltyPointsBalance($customerId);
    $maxByAmount = (int)floor(max(0.0, $eligibleAmount) * ecLoyaltyPointsPerCurrencyUnit());
    $appliedPoints = min($requestedPoints, $balance, $maxByAmount);

    if ($appliedPoints > 0 && $appliedPoints < ecLoyaltyMinimumRedeemPoints()) {
        $appliedPoints = 0;
    }

    $discountAmount = $appliedPoints > 0 ? ecLoyaltyCurrencyDiscount($appliedPoints, $currencyCode) : 0.0;
    $maxDiscountAmount = round(max(0.0, $eligibleAmount), 2);
    if ($discountAmount > $maxDiscountAmount) {
        $discountAmount = $maxDiscountAmount;
        $appliedPoints = (int)floor($discountAmount * ecLoyaltyPointsPerCurrencyUnit());
    }

    return [
        'requested_points' => $requestedPoints,
        'applied_points' => $appliedPoints,
        'discount_amount' => round($discountAmount, 2),
        'balance' => $balance,
        'remaining_balance' => max(0, $balance - $appliedPoints),
        'currency' => $currencyCode,
    ];
}

function ecCartSelectedLoyaltyPoints(): int
{
    $user = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)($user['id'] ?? 0) : 0;

    if ($userId > 0) {
        try {
            return max(0, (int)(ecDb()->query('SELECT loyalty_points FROM ec_carts WHERE user_id = ? LIMIT 1', [$userId])->fetchColumn() ?: 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    return max(0, (int)($_SESSION[EC_SESSION_LOYALTY_KEY] ?? 0));
}

function ecCartSetLoyaltyPoints(int $points): void
{
    $points = max(0, $points);
    $user = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)($user['id'] ?? 0) : 0;

    if ($userId > 0) {
        $cartId = ecDbGetOrCreateCart($userId);
        ecDb()->execute('UPDATE ec_carts SET loyalty_points = ?, updated_at = NOW() WHERE id = ?', [$points, $cartId]);
        return;
    }

    $_SESSION[EC_SESSION_LOYALTY_KEY] = $points;
}

function ecCartApplyLoyalty(int $points): array
{
    $user = app()->user();
    $customerId = ($user && ($user['source'] ?? '') === 'cms') ? (int)($user['id'] ?? 0) : 0;
    if ($customerId <= 0) {
        return ['ok' => false, 'error' => 'Sign in to redeem loyalty points.'];
    }

    $cart = ecCartGet();
    if (empty($cart['items'])) {
        return ['ok' => false, 'error' => 'Your cart is empty.'];
    }

    $eligibleAmount = max(0.0, (float)($cart['totals']['subtotal'] ?? 0.0) - (float)($cart['totals']['coupon_discount_amount'] ?? 0.0));
    $redemption = ecLoyaltyNormalizeRedemption($customerId, $eligibleAmount, $points, (string)($cart['currency'] ?? ecStoreBaseCurrencyCode()));
    if ($points > 0 && (int)($redemption['applied_points'] ?? 0) <= 0) {
        return ['ok' => false, 'error' => 'Not enough eligible loyalty points are available for this cart.'];
    }

    ecCartSetLoyaltyPoints((int)($redemption['applied_points'] ?? 0));
    return ['ok' => true, 'cart' => ecCartGet()];
}

function ecCartClearLoyalty(): array
{
    ecCartSetLoyaltyPoints(0);
    return ['ok' => true, 'cart' => ecCartGet()];
}

function ecCartLoyaltySummary(int $customerId, array $totals, int $selectedPoints): array
{
    $balance = $customerId > 0 ? ecCustomerLoyaltyPointsBalance($customerId) : 0;
    $appliedPoints = max(0, (int)($totals['loyalty_points_applied'] ?? 0));
    $appliedDiscount = (float)($totals['loyalty_discount_amount'] ?? 0.0);

    return [
        'balance' => $balance,
        'balance_fmt' => number_format($balance),
        'selected_points' => max(0, $selectedPoints),
        'applied_points' => $appliedPoints,
        'discount_amount' => $appliedDiscount,
        'discount_amount_fmt' => ecCurrencyFormatAmount($appliedDiscount, (string)($totals['currency'] ?? ecStoreBaseCurrencyCode())),
        'can_redeem' => $customerId > 0 && $balance >= ecLoyaltyMinimumRedeemPoints(),
        'minimum_points' => ecLoyaltyMinimumRedeemPoints(),
        'points_per_currency_unit' => ecLoyaltyPointsPerCurrencyUnit(),
    ];
}

function ecLoyaltyRecordEntry(int $customerId, int $orderId, string $entryType, int $points, string $description): void
{
    if ($customerId <= 0 || $points === 0 || !ecLoyaltyStorageAvailable()) {
        return;
    }

    ecDb()->execute(
        'INSERT INTO ec_loyalty_ledger (customer_id, order_id, entry_type, points, description, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE description = VALUES(description)',
        [$customerId, $orderId > 0 ? $orderId : null, $entryType, $points, $description]
    );
}

function ecLoyaltyRecordPaidOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecLoyaltyStorageAvailable()) {
        return ['ok' => false, 'earned' => 0, 'redeemed' => 0];
    }

    $order = ecOrderGet($orderId);
    if (!is_array($order)) {
        return ['ok' => false, 'earned' => 0, 'redeemed' => 0];
    }

    $customerId = (int)($order['customer_id'] ?? 0);
    if ($customerId <= 0) {
        return ['ok' => false, 'earned' => 0, 'redeemed' => 0];
    }

    $redeemedPoints = max(0, (int)(($order['meta']['loyalty_points_redeemed'] ?? 0)));
    $loyaltyDiscount = max(0.0, (float)(($order['meta']['loyalty_discount_amount'] ?? 0)));

    if ($redeemedPoints > 0) {
        ecLoyaltyRecordEntry($customerId, $orderId, 'redeem', -$redeemedPoints, 'Redeemed on order #' . (string)($order['order_number'] ?? $orderId));
    }

    $earnBase = max(0.0, (float)($order['subtotal'] ?? 0.0) - max(0.0, (float)($order['discount_amount'] ?? 0.0) - $loyaltyDiscount));
    $earnedPoints = max(0, (int)floor($earnBase * ecLoyaltyEarnRatePerCurrencyUnit()));
    if ($earnedPoints > 0) {
        ecLoyaltyRecordEntry($customerId, $orderId, 'earn', $earnedPoints, 'Earned from order #' . (string)($order['order_number'] ?? $orderId));
    }

    return ['ok' => true, 'earned' => $earnedPoints, 'redeemed' => $redeemedPoints];
}

app()->events()->listen('ecommerce.order.paid', function (array $payload): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    try {
        ecMembershipCreateForPaidOrder($orderId);
    } catch (\Throwable $e) {
        write_log('Failed to create memberships for paid order ' . $orderId . ': ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'order_id' => $orderId,
        ]);
    }

    try {
        ecLoyaltyRecordPaidOrder($orderId);
    } catch (\Throwable $e) {
        write_log('Failed to record loyalty for paid order ' . $orderId . ': ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'order_id' => $orderId,
        ]);
    }
});

/**
 * Manually credit or debit loyalty points for a customer (admin action).
 * Returns ['ok' => bool, 'points' => int, 'error' => string].
 */
function ecLoyaltyAdminAdjust(int $customerId, int $points, string $description, int $adminUserId): array
{
    if (!ecLoyaltyStorageAvailable() || $customerId <= 0 || $points === 0) {
        return ['ok' => false, 'error' => 'Invalid parameters'];
    }

    $entryType = $points > 0 ? 'admin_credit' : 'admin_debit';
    $note      = $description . ' (admin #' . $adminUserId . ')';
    ecLoyaltyRecordEntry($customerId, 0, $entryType, $points, $note);

    return ['ok' => true, 'points' => $points];
}

// ── Milestone 6 — CMS-Wide Membership Gating ─────────────────────────────

/**
 * Simple API: returns true when $userId holds one of the $requiredMembership tiers.
 * $requiredMembership may be a tier string, a comma-separated list, or a JSON array.
 *
 * This is the canonical access-check entry point; callers outside ecommerce should
 * prefer this over `ecCustomerHasMembershipTier` directly.
 */
function ecMembershipUserHasAccess(int $userId, mixed $requiredMembership): bool
{
    // Fast path: empty / null / [] means no requirement → always allowed
    if ($requiredMembership === null || $requiredMembership === '' || $requiredMembership === [] || $requiredMembership === '[]') {
        return true;
    }
    $tiers = ecMembershipNormalizeTierList($requiredMembership);
    if ($tiers === []) {
        return true;
    }
    if ($userId <= 0 || !ecMembershipStorageAvailable()) {
        return false;
    }
    return ecCustomerHasMembershipTier($userId, '', $tiers);
}

/**
 * Returns the required membership tiers stored on a CMS content row (page or post).
 * Reads `_required_membership_tiers` from cms_content_meta — the same key used for products.
 *
 * @return string[]
 */
function ecContentMembershipRequiredTiers(int $contentId): array
{
    if ($contentId <= 0) {
        return [];
    }
    $result = [];
    try {
        moduleWithContext('cms', static function () use ($contentId, &$result): void {
            $row = cmsDb()->query(
                "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_required_membership_tiers' LIMIT 1",
                [$contentId]
            )->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return;
            }
            $value   = $row['meta_value'] ?? '';
            $decoded = json_decode((string)$value, true);
            $result  = ecMembershipNormalizeTierList(is_array($decoded) ? $decoded : (string)$value);
        });
    } catch (\Throwable $e) {
        return [];
    }
    return $result;
}

/**
 * Saves (or clears) required membership tiers on a CMS content row.
 * Pass an empty array to remove the gate.
 *
 * @param string[] $tiers
 */
function ecContentSaveMembershipTiers(int $contentId, array $tiers): void
{
    if ($contentId <= 0) {
        return;
    }
    $tiers = ecMembershipNormalizeTierList($tiers);
    $json  = json_encode($tiers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        moduleWithContext('cms', static function () use ($contentId, $tiers, $json): void {
            $db = cmsDb();
            if ($tiers === []) {
                $db->execute(
                    "DELETE FROM cms_content_meta WHERE content_id = ? AND meta_key = '_required_membership_tiers'",
                    [$contentId]
                );
            } else {
                $db->execute(
                    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
                     VALUES (?, '_required_membership_tiers', ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                    [$contentId, $json]
                );
            }
        });
    } catch (\Throwable $e) {
        write_log('ecContentSaveMembershipTiers failed for content ' . $contentId . ': ' . $e->getMessage(), 'warning', [
            'module'     => 'ecommerce',
            'content_id' => $contentId,
        ]);
    }
}

/**
 * Returns the membership gate result for a CMS page or post — same shape as
 * ecMembershipGateForProduct, so templates can use identical rendering logic.
 *
 * Gate result keys:
 *   allowed             bool   — whether current user may view the content
 *   requires_membership bool   — true when at least one tier is required
 *   login_required      bool   — true when not logged in and a tier is required
 *   required_tiers      array  — list of required tier slugs
 *   active_tiers        array  — tiers held by current user
 *   message             string — human-readable denial reason
 */
function ecMembershipGateForContent(int $contentId, ?array $user = null): array
{
    $required = ecContentMembershipRequiredTiers($contentId);

    if ($required === []) {
        return [
            'allowed'             => true,
            'requires_membership' => false,
            'login_required'      => false,
            'required_tiers'      => [],
            'active_tiers'        => [],
            'message'             => '',
        ];
    }

    $user          = $user ?? (function_exists('app') ? app()->user() : null);
    $customerId    = (is_array($user) && ($user['source'] ?? '') === 'cms') ? (int)($user['id'] ?? 0) : 0;
    $customerEmail = trim((string)(is_array($user) ? ($user['email'] ?? '') : ''));
    $activeTiers   = ($customerId > 0 || $customerEmail !== '')
        ? ecCustomerActiveMembershipTiers($customerId, $customerEmail)
        : [];
    $allowed       = array_intersect($required, $activeTiers) !== [];
    $loginRequired = !$allowed && $customerId <= 0;

    return [
        'allowed'             => $allowed,
        'requires_membership' => true,
        'login_required'      => $loginRequired,
        'required_tiers'      => $required,
        'active_tiers'        => $activeTiers,
        'message'             => $allowed
            ? ''
            : ($loginRequired
                ? 'Sign in with an account that has the required membership to access this content.'
                : 'This content requires an active ' . implode(' or ', array_map('ecMembershipTierLabel', $required)) . ' membership.'),
    ];
}

// ── Capability: ecommerce.membership.content_gate@1 ──────────────────────
// CMS or other modules call this via app()->capabilities()->call(...)
// Input payload: {content_id: int, user?: array}
// Returns the same array as ecMembershipGateForContent.

function ecMembershipContentGateCapabilityHandler(array $payload): array
{
    $contentId = (int)($payload['content_id'] ?? 0);
    $user      = is_array($payload['user'] ?? null) ? $payload['user'] : null;
    return ecMembershipGateForContent($contentId, $user);
}

try {
    app()->capabilities()->register(
        'ecommerce.membership.content_gate@1',
        'ecommerce',
        'ecMembershipContentGateCapabilityHandler',
        50,
        ['first']
    );
} catch (\Throwable $e) {
}