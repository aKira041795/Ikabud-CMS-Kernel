<?php

declare(strict_types=1);

function ecSubscriptionAllowedIntervalUnits(): array
{
    return [
        'day' => 'day',
        'week' => 'week',
        'month' => 'month',
        'year' => 'year',
    ];
}

function ecSubscriptionIntervalLabel(string $unit, int $count = 1): string
{
    $units = ecSubscriptionAllowedIntervalUnits();
    $unit = isset($units[$unit]) ? $units[$unit] : 'month';
    $count = max(1, $count);

    return $count . ' ' . $unit . ($count === 1 ? '' : 's');
}

function ecProductSubscriptionDefaults(): array
{
    return [
        'is_subscription' => false,
        'subscription_interval_unit' => 'month',
        'subscription_interval_count' => 1,
        'subscription_trial_days' => 0,
        'subscription_max_cycles' => 0,
        'subscription_grace_period_days' => 7,
        'subscription_summary' => [
            'interval_unit' => 'month',
            'interval_count' => 1,
            'interval_label' => '1 month',
            'trial_days' => 0,
            'trial_label' => '',
            'max_cycles' => 0,
            'grace_period_days' => 7,
            'formatted_price' => '',
            'recurring_label' => '',
        ],
        'product_type' => 'physical',
    ];
}

function ecProductSubscriptionMetaFromMetaMap(array $metaMap, ?array $pricing = null): array
{
    $defaults = ecProductSubscriptionDefaults();
    $units = ecSubscriptionAllowedIntervalUnits();

    $isSubscription = ($metaMap['_is_subscription'] ?? '0') === '1';
    $intervalUnit = strtolower(trim((string)($metaMap['_subscription_interval_unit'] ?? $defaults['subscription_interval_unit'])));
    if (!isset($units[$intervalUnit])) {
        $intervalUnit = $defaults['subscription_interval_unit'];
    }

    $intervalCount = max(1, (int)($metaMap['_subscription_interval_count'] ?? $defaults['subscription_interval_count']));
    $trialDays = max(0, (int)($metaMap['_subscription_trial_days'] ?? $defaults['subscription_trial_days']));
    $maxCycles = max(0, (int)($metaMap['_subscription_max_cycles'] ?? $defaults['subscription_max_cycles']));
    $gracePeriodDays = max(0, (int)($metaMap['_subscription_grace_period_days'] ?? $defaults['subscription_grace_period_days']));

    $activePrice = null;
    if (is_array($pricing)) {
        if (array_key_exists('active_price', $pricing) && $pricing['active_price'] !== null) {
            $activePrice = (float)$pricing['active_price'];
        } elseif (array_key_exists('price', $pricing) && $pricing['price'] !== null) {
            $activePrice = (float)$pricing['price'];
        }
    }

    $intervalLabel = ecSubscriptionIntervalLabel($intervalUnit, $intervalCount);
    $formattedPrice = '';
    if (is_array($pricing)) {
        $formattedPrice = trim((string)($pricing['formatted'] ?? ''));
    }
    if ($formattedPrice === '' && $activePrice !== null) {
        $formattedPrice = ecCurrencyFormatAmount(
            $activePrice,
            is_array($pricing) ? (string)($pricing['currency'] ?? ecStoreBaseCurrencyCode()) : ecStoreBaseCurrencyCode()
        );
    }
    $trialLabel = $trialDays > 0
        ? $trialDays . ' day' . ($trialDays === 1 ? '' : 's') . ' free trial'
        : '';
    $recurringLabel = $formattedPrice !== ''
        ? $formattedPrice . ' every ' . $intervalLabel
        : 'Every ' . $intervalLabel;

    return array_merge($defaults, [
        'is_subscription' => $isSubscription,
        'subscription_interval_unit' => $intervalUnit,
        'subscription_interval_count' => $intervalCount,
        'subscription_trial_days' => $trialDays,
        'subscription_max_cycles' => $maxCycles,
        'subscription_grace_period_days' => $gracePeriodDays,
        'subscription_summary' => [
            'interval_unit' => $intervalUnit,
            'interval_count' => $intervalCount,
            'interval_label' => $intervalLabel,
            'trial_days' => $trialDays,
            'trial_label' => $trialLabel,
            'max_cycles' => $maxCycles,
            'grace_period_days' => $gracePeriodDays,
            'formatted_price' => $formattedPrice,
            'recurring_label' => $recurringLabel,
        ],
        'product_type' => $isSubscription ? 'subscription' : 'physical',
    ]);
}

function ecProductSaveSubscriptionMeta(int $productId, array $input): void
{
    $normalized = ecProductSubscriptionMetaFromMetaMap([
        '_is_subscription' => !empty($input['is_subscription']) ? '1' : '0',
        '_subscription_interval_unit' => (string)($input['subscription_interval_unit'] ?? 'month'),
        '_subscription_interval_count' => (string)($input['subscription_interval_count'] ?? 1),
        '_subscription_trial_days' => (string)($input['subscription_trial_days'] ?? 0),
        '_subscription_max_cycles' => (string)($input['subscription_max_cycles'] ?? 0),
        '_subscription_grace_period_days' => (string)($input['subscription_grace_period_days'] ?? 7),
    ]);

    $meta = [
        '_is_subscription' => $normalized['is_subscription'] ? '1' : '0',
        '_subscription_interval_unit' => (string)$normalized['subscription_interval_unit'],
        '_subscription_interval_count' => (string)$normalized['subscription_interval_count'],
        '_subscription_trial_days' => (string)$normalized['subscription_trial_days'],
        '_subscription_max_cycles' => (string)$normalized['subscription_max_cycles'],
        '_subscription_grace_period_days' => (string)$normalized['subscription_grace_period_days'],
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
        write_log('ecProductSaveSubscriptionMeta error: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

function ecSubscriptionStorageAvailable(): bool
{
    try {
        ecDb()->query('SELECT 1 FROM ec_subscriptions LIMIT 1');
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecSubscriptionsEnabled(): bool
{
    return ecSubscriptionStorageAvailable() && (bool)ecSettings('feature_subscriptions_enabled', true);
}

function ecSubscriptionAddPeriod(string $startAt, int $intervalCount, string $intervalUnit): string
{
    try {
        $base = new DateTimeImmutable($startAt !== '' ? $startAt : 'now');
    } catch (\Throwable $e) {
        $base = new DateTimeImmutable('now');
    }

    $units = ecSubscriptionAllowedIntervalUnits();
    $intervalUnit = isset($units[$intervalUnit]) ? $intervalUnit : 'month';
    $intervalCount = max(1, $intervalCount);
    $modified = $base->modify('+' . $intervalCount . ' ' . $units[$intervalUnit]);

    return ($modified ?: $base)->format('Y-m-d H:i:s');
}

function ecSubscriptionNormalizeRow(array $row): array
{
    $intervalCount = max(1, (int)($row['interval_count'] ?? 1));
    $intervalUnit = strtolower(trim((string)($row['interval_unit'] ?? 'month')));
    if (!isset(ecSubscriptionAllowedIntervalUnits()[$intervalUnit])) {
        $intervalUnit = 'month';
    }

    $row['quantity'] = max(1, (int)($row['quantity'] ?? 1));
    $row['interval_count'] = $intervalCount;
    $row['interval_unit'] = $intervalUnit;
    $row['trial_days'] = max(0, (int)($row['trial_days'] ?? 0));
    $row['max_cycles'] = max(0, (int)($row['max_cycles'] ?? 0));
    $row['grace_period_days'] = max(0, (int)($row['grace_period_days'] ?? 0));
    $row['renewal_count'] = max(0, (int)($row['renewal_count'] ?? 0));
    $row['recurring_amount'] = (float)($row['recurring_amount'] ?? 0.0);
    $row['interval_label'] = ecSubscriptionIntervalLabel($intervalUnit, $intervalCount);
    $row['recurring_amount_fmt'] = ecCurrencySymbolFor((string)($row['currency'] ?? ecStoreBaseCurrencyCode())) . number_format((float)$row['recurring_amount'], 2);
    $row['recurring_label'] = $row['recurring_amount_fmt'] . ' every ' . $row['interval_label'];
    $row['trial_label'] = $row['trial_days'] > 0
        ? $row['trial_days'] . ' day' . ($row['trial_days'] === 1 ? '' : 's') . ' free trial'
        : '';
    $row['is_active'] = (string)($row['status'] ?? 'active') === 'active';

    return $row;
}

function ecSubscriptionsForOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecSubscriptionStorageAvailable()) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT id, order_id, order_item_id, customer_id, customer_email, product_id, product_title, quantity,
                    status, interval_unit, interval_count, trial_days, max_cycles, grace_period_days,
                    recurring_amount, currency, start_at, current_period_start_at, current_period_end_at,
                    next_renewal_at, renewal_count, cancelled_at, cancellation_reason, created_at, updated_at
               FROM ec_subscriptions WHERE order_id = ? ORDER BY id ASC',
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    return array_map('ecSubscriptionNormalizeRow', $rows);
}

function ecSubscriptionProductMetaMap(array $productIds): array
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
            AND meta_key IN ('_is_subscription','_subscription_interval_unit','_subscription_interval_count','_subscription_trial_days','_subscription_max_cycles','_subscription_grace_period_days')",
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
        $normalized[$productId] = ecProductSubscriptionMetaFromMetaMap($metaByProduct[$productId] ?? []);
    }

    return $normalized;
}

function ecCartSubscriptionSummary(array $cartItems): array
{
    $productIds = array_values(array_unique(array_filter(array_map(static fn(array $item): int => (int)($item['product_id'] ?? 0), $cartItems))));
    $productMap = ecSubscriptionProductMetaMap($productIds);
    $subscriptionItems = [];
    $nonSubscriptionItems = [];

    foreach ($cartItems as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $subscriptionMeta = $productMap[$productId] ?? ecProductSubscriptionDefaults();
        if (!empty($subscriptionMeta['is_subscription'])) {
            $price = (float)($item['price_snapshot'] ?? 0.0);
            $formattedPrice = (string)ecSettings('currency_symbol') . number_format($price, 2);
            $subscriptionItems[] = [
                'product_id' => $productId,
                'product_title' => trim((string)($item['product_title'] ?? 'Subscription')),
                'qty' => max(1, (int)($item['qty'] ?? 1)),
                'interval_unit' => $subscriptionMeta['subscription_interval_unit'],
                'interval_count' => $subscriptionMeta['subscription_interval_count'],
                'interval_label' => $subscriptionMeta['subscription_summary']['interval_label'],
                'trial_days' => $subscriptionMeta['subscription_trial_days'],
                'trial_label' => $subscriptionMeta['subscription_summary']['trial_label'],
                'formatted_price' => $formattedPrice,
                'recurring_label' => $formattedPrice . ' every ' . $subscriptionMeta['subscription_summary']['interval_label'],
            ];
            continue;
        }

        $nonSubscriptionItems[] = $item;
    }

    $errors = [];
    if ($subscriptionItems !== []) {
        if ($nonSubscriptionItems !== []) {
            $errors[] = 'Subscriptions must be purchased separately from one-time products.';
        }

        $uniqueSubscriptionProducts = [];
        $subscriptionQty = 0;
        foreach ($subscriptionItems as $subscriptionItem) {
            $uniqueSubscriptionProducts[$subscriptionItem['product_id']] = true;
            $subscriptionQty += (int)($subscriptionItem['qty'] ?? 1);
        }

        if (count($uniqueSubscriptionProducts) > 1 || $subscriptionQty > 1) {
            $errors[] = 'Only one subscription product can be purchased per order.';
        }
    }

    return [
        'has_subscription' => $subscriptionItems !== [],
        'has_non_subscription' => $nonSubscriptionItems !== [],
        'is_valid' => $errors === [],
        'errors' => $errors,
        'items' => $subscriptionItems,
        'primary_item' => $subscriptionItems[0] ?? null,
    ];
}

function ecCartCanAddProduct(array $cartItems, array $product, int $qty = 1): array
{
    $summary = ecCartSubscriptionSummary($cartItems);
    $isSubscription = !empty($product['is_subscription']);

    if ($isSubscription) {
        if ($qty !== 1) {
            return ['ok' => false, 'error' => 'Subscription products can only be purchased one at a time.'];
        }
        if (!empty($product['bundle_children']) || !empty($product['grouped_children'])) {
            return ['ok' => false, 'error' => 'Grouped or bundled subscription products are not supported.'];
        }
        if (!empty($product['is_external_product'])) {
            return ['ok' => false, 'error' => 'Subscription products cannot use external checkout mode.'];
        }
        if (!empty($summary['has_subscription'])) {
            return ['ok' => false, 'error' => 'Only one subscription product can be purchased per order.'];
        }
        if ($cartItems !== []) {
            return ['ok' => false, 'error' => 'Subscriptions must be purchased separately from one-time products.'];
        }
    } elseif (!empty($summary['has_subscription'])) {
        return ['ok' => false, 'error' => 'Subscriptions must be purchased separately from one-time products.'];
    }

    return ['ok' => true];
}

function ecSubscriptionCreateForPaidOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecSubscriptionStorageAvailable()) {
        return ['ok' => false, 'created' => 0];
    }

    $order = ecOrderGet($orderId);
    if (!is_array($order) || empty($order['items'])) {
        return ['ok' => false, 'created' => 0];
    }

    $productIds = array_values(array_unique(array_filter(array_map(static fn(array $item): int => (int)($item['product_id'] ?? 0), (array)$order['items']))));
    $productMap = ecSubscriptionProductMetaMap($productIds);
    $db = ecDb();
    $created = 0;

    foreach ((array)$order['items'] as $item) {
        $orderItemId = (int)($item['id'] ?? 0);
        $productId = (int)($item['product_id'] ?? 0);
        if ($orderItemId <= 0 || $productId <= 0) {
            continue;
        }

        $subscriptionMeta = $productMap[$productId] ?? ecProductSubscriptionDefaults();
        if (empty($subscriptionMeta['is_subscription'])) {
            continue;
        }

        $existingId = (int)($db->query('SELECT id FROM ec_subscriptions WHERE order_item_id = ? LIMIT 1', [$orderItemId])->fetchColumn() ?: 0);
        if ($existingId > 0) {
            continue;
        }

        $startAt = trim((string)($order['created_at'] ?? date('Y-m-d H:i:s')));
        $trialDays = (int)($subscriptionMeta['subscription_trial_days'] ?? 0);
        $intervalCount = (int)($subscriptionMeta['subscription_interval_count'] ?? 1);
        $intervalUnit = (string)($subscriptionMeta['subscription_interval_unit'] ?? 'month');
        $currentPeriodEndAt = $trialDays > 0
            ? ecSubscriptionAddPeriod($startAt, $trialDays, 'day')
            : ecSubscriptionAddPeriod($startAt, $intervalCount, $intervalUnit);

        $db->execute(
            'INSERT INTO ec_subscriptions (
                order_id, order_item_id, customer_id, customer_email, product_id, product_title, quantity,
                status, interval_unit, interval_count, trial_days, max_cycles, grace_period_days,
                recurring_amount, currency, start_at, current_period_start_at, current_period_end_at,
                next_renewal_at, renewal_count, cancelled_at, cancellation_reason, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NOW(), NOW())',
            [
                $orderId,
                $orderItemId,
                isset($order['customer_id']) ? (int)$order['customer_id'] : null,
                (string)($order['customer_email'] ?? $order['guest_email'] ?? ''),
                $productId,
                trim((string)($item['product_title'] ?? 'Subscription')),
                max(1, (int)($item['qty'] ?? 1)),
                'active',
                $intervalUnit,
                max(1, $intervalCount),
                max(0, $trialDays),
                max(0, (int)($subscriptionMeta['subscription_max_cycles'] ?? 0)),
                max(0, (int)($subscriptionMeta['subscription_grace_period_days'] ?? 0)),
                (float)($item['line_total'] ?? (($item['unit_price'] ?? 0) * max(1, (int)($item['qty'] ?? 1)))),
                (string)($order['currency'] ?? ecSettings('currency')),
                $startAt,
                $startAt,
                $currentPeriodEndAt,
                $currentPeriodEndAt,
            ]
        );

        $created++;
    }

    return ['ok' => true, 'created' => $created];
}

app()->events()->listen('ecommerce.order.paid', function (array $payload): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    try {
        ecSubscriptionCreateForPaidOrder($orderId);
    } catch (\Throwable $e) {
        write_log('Failed to create subscription for paid order ' . $orderId . ': ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'order_id' => $orderId,
        ]);
    }
});