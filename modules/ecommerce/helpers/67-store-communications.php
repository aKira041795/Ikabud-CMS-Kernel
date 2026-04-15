<?php

declare(strict_types=1);

function ecStoreNotificationsStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = ecTableExists('ec_store_notifications');
    return $ready;
}

function ecStoreMessagesStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = ecTableExists('ec_store_messages');
    return $ready;
}

function ecStoreNotificationTargetUserIds(int $storeId): array
{
    $userIds = [];
    foreach (ecStoreUserList($storeId) as $user) {
        $userId = (int)($user['user_id'] ?? 0);
        if ($userId > 0) {
            $userIds[$userId] = true;
        }
    }

    return array_map('intval', array_keys($userIds));
}

function ecStoreNotificationCreate(int $storeId, array $payload, ?array $targetUserIds = null): void
{
    if (!ecStoreNotificationsStorageAvailable() || $storeId <= 0) {
        return;
    }

    $userIds = $targetUserIds !== null
        ? array_values(array_unique(array_filter(array_map('intval', $targetUserIds))))
        : ecStoreNotificationTargetUserIds($storeId);

    if ($userIds === []) {
        return;
    }

    foreach ($userIds as $userId) {
        try {
            ecDb()->execute(
                'INSERT INTO ec_store_notifications (store_id, user_id, type, title, body, action_url, related_order_id, related_return_request_id, related_product_id, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())',
                [
                    $storeId,
                    $userId,
                    trim((string)($payload['type'] ?? 'info')) ?: 'info',
                    trim((string)($payload['title'] ?? 'Store update')) ?: 'Store update',
                    trim((string)($payload['body'] ?? '')),
                    trim((string)($payload['action_url'] ?? '')) ?: null,
                    max(0, (int)($payload['related_order_id'] ?? 0)) ?: null,
                    max(0, (int)($payload['related_return_request_id'] ?? 0)) ?: null,
                    max(0, (int)($payload['related_product_id'] ?? 0)) ?: null,
                ]
            );
        } catch (Throwable $e) {
            write_log('ecStoreNotificationCreate failed: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce', 'store_id' => $storeId]);
        }
    }
}

function ecStoreNotificationList(int $storeId, int $userId, int $limit = 25, int $offset = 0): array
{
    if (!ecStoreNotificationsStorageAvailable() || $storeId <= 0 || $userId <= 0) {
        return ['items' => [], 'total' => 0];
    }

    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);

    try {
        $total = (int)(ecDb()->query(
            'SELECT COUNT(*) FROM ec_store_notifications WHERE store_id = ? AND user_id = ?',
            [$storeId, $userId]
        )->fetchColumn() ?: 0);

        $items = ecDb()->query(
            'SELECT * FROM ec_store_notifications WHERE store_id = ? AND user_id = ? ORDER BY is_read ASC, created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$storeId, $userId, $limit, $offset]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['items' => $items, 'total' => $total];
    } catch (Throwable $e) {
        return ['items' => [], 'total' => 0];
    }
}

function ecStoreNotificationUnreadCount(int $storeId, int $userId): int
{
    if (!ecStoreNotificationsStorageAvailable() || $storeId <= 0 || $userId <= 0) {
        return 0;
    }

    try {
        return (int)(ecDb()->query(
            'SELECT COUNT(*) FROM ec_store_notifications WHERE store_id = ? AND user_id = ? AND is_read = 0',
            [$storeId, $userId]
        )->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function ecStoreNotificationMarkRead(int $notificationId, int $storeId, int $userId): bool
{
    if (!ecStoreNotificationsStorageAvailable() || $notificationId <= 0 || $storeId <= 0 || $userId <= 0) {
        return false;
    }

    try {
        ecDb()->execute(
            'UPDATE ec_store_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND store_id = ? AND user_id = ?',
            [$notificationId, $storeId, $userId]
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ecStoreNotificationMarkAllRead(int $storeId, int $userId): bool
{
    if (!ecStoreNotificationsStorageAvailable() || $storeId <= 0 || $userId <= 0) {
        return false;
    }

    try {
        ecDb()->execute(
            'UPDATE ec_store_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE store_id = ? AND user_id = ? AND is_read = 0',
            [$storeId, $userId]
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ecStoreOrderStoreIds(array $order): array
{
    $storeIds = [];
    foreach ((array)($order['items'] ?? []) as $item) {
        $storeId = max(0, (int)($item['store_id'] ?? 0));
        if ($storeId > 0) {
            $storeIds[$storeId] = true;
        }
    }

    if ($storeIds === [] && !empty($order['store_id'])) {
        $storeIds[(int)$order['store_id']] = true;
    }

    return array_map('intval', array_keys($storeIds));
}

function ecStoreCreateOrderNotifications(array $payload): void
{
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $order = ecOrderGet($orderId);
    if (!is_array($order)) {
        return;
    }

    $currencySymbol = (string)($order['currency_symbol'] ?? ecCurrencySymbolFor((string)($order['currency'] ?? '')));
    $storeItems = [];
    foreach ((array)($order['items'] ?? []) as $item) {
        $storeId = max(0, (int)($item['store_id'] ?? 0));
        if ($storeId <= 0) {
            continue;
        }
        if (!isset($storeItems[$storeId])) {
            $storeItems[$storeId] = [];
        }
        $storeItems[$storeId][] = $item;
    }

    foreach ($storeItems as $storeId => $items) {
        ecStoreNotificationCreate($storeId, [
            'type' => 'order_created',
            'title' => 'New order #' . (string)($order['order_number'] ?? $orderId),
            'body' => count($items) . ' line item(s) from this store were included in a new order worth ' . $currencySymbol . number_format((float)($order['total_amount'] ?? 0), 2) . '.',
            'action_url' => ecGetBaseUrl() . '/ecommerce/store-admin/' . $storeId . '/orders?search=' . urlencode((string)($order['order_number'] ?? '')),
            'related_order_id' => $orderId,
        ]);
    }
}

function ecStoreCreateReturnNotifications(array $payload, string $status): void
{
    $request = is_array($payload['return_request'] ?? null) ? $payload['return_request'] : [];
    if ($request === []) {
        return;
    }

    $storeIds = [];
    foreach ((array)($request['items'] ?? []) as $item) {
        $storeId = max(0, (int)($item['store_id'] ?? 0));
        if ($storeId > 0) {
            $storeIds[$storeId] = true;
        }
    }

    $labels = [
        'requested' => 'New return request',
        'approved' => 'Return approved',
        'rejected' => 'Return rejected',
        'cancelled' => 'Return cancelled',
    ];

    foreach (array_keys($storeIds) as $storeId) {
        ecStoreNotificationCreate((int)$storeId, [
            'type' => 'return_' . $status,
            'title' => ($labels[$status] ?? 'Return update') . ' #' . (string)($request['request_number'] ?? ''),
            'body' => trim((string)($request['reason'] ?? 'Return request updated.')),
            'action_url' => ecGetBaseUrl() . '/ecommerce/store-admin/' . $storeId . '/returns',
            'related_order_id' => max(0, (int)($request['order_id'] ?? 0)) ?: null,
            'related_return_request_id' => max(0, (int)($request['id'] ?? 0)) ?: null,
        ]);
    }
}

function ecStoreMessageThreadList(int $storeId, int $limit = 25): array
{
    if (!ecStoreMessagesStorageAvailable() || $storeId <= 0) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT order_id, MAX(created_at) AS last_message_at, COUNT(*) AS message_count
             FROM ec_store_messages
             WHERE store_id = ?
             GROUP BY order_id
             ORDER BY last_message_at DESC
             LIMIT ?',
            [$storeId, max(1, min(100, $limit))]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $threads = [];
    foreach ($rows as $row) {
        $orderId = max(0, (int)($row['order_id'] ?? 0));
        $order = $orderId > 0 ? ecOrderGet($orderId) : null;
        $messages = $orderId > 0 ? ecStoreMessagesForOrder($storeId, $orderId) : [];
        $lastMessage = $messages !== [] ? end($messages) : null;
        if ($lastMessage !== false) {
            reset($messages);
        }

        $threads[] = [
            'order_id' => $orderId,
            'order_number' => (string)($order['order_number'] ?? ''),
            'customer_name' => (string)($order['customer_name'] ?? $order['guest_name'] ?? 'Customer'),
            'customer_email' => (string)($order['customer_email'] ?? ''),
            'last_message_at' => (string)($row['last_message_at'] ?? ''),
            'message_count' => (int)($row['message_count'] ?? 0),
            'last_message_excerpt' => is_array($lastMessage) ? mb_substr(trim((string)($lastMessage['body'] ?? '')), 0, 120) : '',
        ];
    }

    return $threads;
}

function ecStoreMessagesForOrder(int $storeId, int $orderId): array
{
    if (!ecStoreMessagesStorageAvailable() || $storeId <= 0 || $orderId <= 0) {
        return [];
    }

    try {
        return ecDb()->query(
            'SELECT * FROM ec_store_messages WHERE store_id = ? AND order_id = ? ORDER BY created_at ASC, id ASC',
            [$storeId, $orderId]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ecStoreMessageCreateFromCustomer(int $orderId, int $storeId, array $user, string $body): array
{
    $body = trim($body);
    if (!ecStoreMessagesStorageAvailable() || $orderId <= 0 || $storeId <= 0) {
        return ['ok' => false, 'error' => 'Messaging is unavailable.'];
    }
    if ($body === '') {
        return ['ok' => false, 'error' => 'Message body is required.'];
    }

    $order = ecOrderGet($orderId, (int)($user['id'] ?? 0));
    if (!is_array($order) || !in_array($storeId, ecStoreOrderStoreIds($order), true)) {
        return ['ok' => false, 'error' => 'Order thread not found for this store.'];
    }

    try {
        ecDb()->execute(
            'INSERT INTO ec_store_messages (store_id, order_id, customer_user_id, sender_type, sender_user_id, sender_name, body, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $storeId,
                $orderId,
                max(0, (int)($user['id'] ?? 0)) ?: null,
                'customer',
                max(0, (int)($user['id'] ?? 0)) ?: null,
                trim((string)($user['display_name'] ?? $user['name'] ?? $user['email'] ?? 'Customer')) ?: 'Customer',
                $body,
            ]
        );

        ecStoreNotificationCreate($storeId, [
            'type' => 'message',
            'title' => 'New customer message',
            'body' => 'Order #' . (string)($order['order_number'] ?? $orderId) . ': ' . mb_substr($body, 0, 120),
            'action_url' => ecGetBaseUrl() . '/ecommerce/store-admin/' . $storeId . '/messages?order=' . $orderId,
            'related_order_id' => $orderId,
        ]);

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function ecStoreMessageCreateFromStore(int $orderId, int $storeId, array $user, string $body): array
{
    $body = trim($body);
    if (!ecStoreMessagesStorageAvailable() || $orderId <= 0 || $storeId <= 0) {
        return ['ok' => false, 'error' => 'Messaging is unavailable.'];
    }
    if ($body === '') {
        return ['ok' => false, 'error' => 'Message body is required.'];
    }

    $order = ecOrderGet($orderId);
    if (!is_array($order) || !in_array($storeId, ecStoreOrderStoreIds($order), true)) {
        return ['ok' => false, 'error' => 'Order thread not found for this store.'];
    }

    try {
        ecDb()->execute(
            'INSERT INTO ec_store_messages (store_id, order_id, customer_user_id, sender_type, sender_user_id, sender_name, body, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $storeId,
                $orderId,
                max(0, (int)($order['customer_id'] ?? 0)) ?: null,
                'store',
                max(0, (int)($user['id'] ?? 0)) ?: null,
                trim((string)($user['display_name'] ?? $user['name'] ?? 'Store')) ?: 'Store',
                $body,
            ]
        );

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function ecOrderMessageThreadsForCustomer(array $order): array
{
    if (!ecStoreMessagesStorageAvailable()) {
        return [];
    }

    $threads = [];
    foreach (ecStoreOrderStoreIds($order) as $storeId) {
        $threads[] = [
            'store' => ecStoreById($storeId),
            'messages' => ecStoreMessagesForOrder($storeId, (int)($order['id'] ?? 0)),
        ];
    }

    return array_values(array_filter($threads, static fn(array $thread): bool => is_array($thread['store'] ?? null)));
}

function ecStoreLoyaltySummary(int $storeId, int $limit = 50): array
{
    if (!function_exists('ecLoyaltyStorageAvailable') || !ecLoyaltyStorageAvailable() || $storeId <= 0) {
        return ['entries' => [], 'total_earned' => 0, 'total_redeemed' => 0, 'unique_customers' => 0];
    }

    try {
        $orderScope = ecStoreOrderScopePredicate('o', 'store_loyalty_scope_items');
        $scopeParams = ecStoreScopeQueryParams($storeId, (int)$orderScope['params_per_store']);
        $entries = ecDb()->query(
            'SELECT l.*, u.display_name, u.email
             FROM ec_loyalty_ledger l
             INNER JOIN ec_orders o ON o.id = l.order_id
             LEFT JOIN cms_users u ON u.id = l.customer_id
             WHERE ' . $orderScope['sql'] . '
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT ?',
            array_merge($scopeParams, [max(1, min(200, $limit))])
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalEarned = (int)(ecDb()->query(
            "SELECT COALESCE(SUM(l.points), 0)
             FROM ec_loyalty_ledger l
             INNER JOIN ec_orders o ON o.id = l.order_id
             WHERE {$orderScope['sql']}
               AND l.entry_type = 'earn'",
            $scopeParams
        )->fetchColumn() ?: 0);

        $totalRedeemed = abs((int)(ecDb()->query(
            "SELECT COALESCE(SUM(l.points), 0)
             FROM ec_loyalty_ledger l
             INNER JOIN ec_orders o ON o.id = l.order_id
             WHERE {$orderScope['sql']}
               AND l.entry_type = 'redeem'",
            $scopeParams
        )->fetchColumn() ?: 0));

        $uniqueCustomers = (int)(ecDb()->query(
            'SELECT COUNT(DISTINCT l.customer_id)
             FROM ec_loyalty_ledger l
             INNER JOIN ec_orders o ON o.id = l.order_id
             WHERE ' . $orderScope['sql'] . '
               AND l.customer_id IS NOT NULL',
            $scopeParams
        )->fetchColumn() ?: 0);

        return [
            'entries' => $entries,
            'total_earned' => $totalEarned,
            'total_redeemed' => $totalRedeemed,
            'unique_customers' => $uniqueCustomers,
        ];
    } catch (Throwable $e) {
        return ['entries' => [], 'total_earned' => 0, 'total_redeemed' => 0, 'unique_customers' => 0];
    }
}

app()->events()->listen('ecommerce.order.created', static function (array $payload): void {
    ecStoreCreateOrderNotifications($payload);
});

app()->events()->listen('ecommerce.order.paid', static function (array $payload): void {
    $storeId = (int)($payload['store_id'] ?? 0);
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($storeId <= 0 || $orderId <= 0) {
        return;
    }
    $orderNumber = (string)($payload['order_number'] ?? '');
    $currencySymbol = (string)($payload['currency_symbol'] ?? ecCurrencySymbolFor((string)($payload['currency'] ?? '')));
    $total = (float)($payload['total'] ?? 0);

    ecStoreNotificationCreate($storeId, [
        'type' => 'order_paid',
        'title' => 'Order #' . ($orderNumber ?: (string)$orderId) . ' paid',
        'body' => 'Payment of ' . $currencySymbol . number_format($total, 2) . ' received for order #' . ($orderNumber ?: (string)$orderId) . '.',
        'action_url' => ecGetBaseUrl() . '/ecommerce/store-admin/' . $storeId . '/orders?search=' . urlencode($orderNumber),
        'related_order_id' => $orderId,
    ]);
});

app()->events()->listen('ecommerce.return.requested', static function (array $payload): void {
    ecStoreCreateReturnNotifications($payload, 'requested');
});

app()->events()->listen('ecommerce.return.approved', static function (array $payload): void {
    ecStoreCreateReturnNotifications($payload, 'approved');
});

app()->events()->listen('ecommerce.return.rejected', static function (array $payload): void {
    ecStoreCreateReturnNotifications($payload, 'rejected');
});