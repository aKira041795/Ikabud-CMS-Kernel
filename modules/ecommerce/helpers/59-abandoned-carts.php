<?php

declare(strict_types=1);

function ecAbandonedCartStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        ecDb()->query('SELECT 1 FROM ec_abandoned_carts LIMIT 1');
        $ready = true;
    } catch (\Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function ecAbandonedCartSessionId(): string
{
    $sessionId = trim((string)session_id());
    return $sessionId;
}

function ecAbandonedCartEnabled(): bool
{
    return ecAbandonedCartStorageAvailable() && (bool)ecSettings('abandoned_cart_enabled');
}

function ecAbandonedCartReminderHours(): array
{
    $hours = [
        1 => max(1, (int)ecSettings('abandoned_cart_first_delay_hours')),
        2 => max(1, (int)ecSettings('abandoned_cart_second_delay_hours')),
        3 => max(1, (int)ecSettings('abandoned_cart_third_delay_hours')),
    ];

    if ($hours[2] < $hours[1]) {
        $hours[2] = $hours[1];
    }
    if ($hours[3] < $hours[2]) {
        $hours[3] = $hours[2];
    }

    return $hours;
}

function ecAbandonedCartGenerateToken(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (\Throwable $e) {
        return hash('sha256', uniqid('ec_abandoned_', true));
    }
}

function ecAbandonedCartCurrentUser(): array
{
    $user = app()->user();
    return is_array($user) ? $user : [];
}

function ecAbandonedCartLeadContext(array $lead = []): array
{
    $user = ecAbandonedCartCurrentUser();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)($user['id'] ?? 0) : 0;
    $userEmail = strtolower(trim((string)($user['email'] ?? '')));
    $userName = trim((string)($user['display_name'] ?? $user['name'] ?? ''));

    $guestEmail = strtolower(trim((string)($lead['guest_email'] ?? $userEmail)));
    $guestName = trim((string)($lead['guest_name'] ?? $userName));
    if ($guestName === '' && (!empty($lead['first_name']) || !empty($lead['last_name']))) {
        $guestName = trim((string)($lead['first_name'] ?? '') . ' ' . (string)($lead['last_name'] ?? ''));
    }

    return [
        'user_id' => $userId > 0 ? $userId : null,
        'session_id' => ecAbandonedCartSessionId(),
        'guest_email' => $guestEmail,
        'guest_name' => $guestName,
    ];
}

function ecAbandonedCartSnapshot(array $cart): array
{
    $items = [];
    foreach ((array)($cart['items'] ?? []) as $item) {
        $items[] = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'variant_id' => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
            'qty' => max(1, (int)($item['qty'] ?? 1)),
            'price_snapshot' => round((float)($item['price_snapshot'] ?? 0), 2),
            'product_title' => trim((string)($item['product_title'] ?? '')), 
            'sku' => trim((string)($item['sku'] ?? '')),
        ];
    }

    return [
        'items' => $items,
        'coupon_code' => trim((string)($cart['coupon_code'] ?? '')),
        'totals' => [
            'subtotal' => round((float)($cart['totals']['subtotal'] ?? 0), 2),
            'total' => round((float)($cart['totals']['total'] ?? 0), 2),
            'item_count' => max(0, (int)($cart['totals']['item_count'] ?? 0)),
        ],
    ];
}

function ecAbandonedCartCartUserId(?int $preferredUserId = null): int
{
    if (($preferredUserId ?? 0) > 0) {
        return (int)$preferredUserId;
    }

    $user = ecAbandonedCartCurrentUser();
    if (($user['source'] ?? '') === 'cms') {
        return max(0, (int)($user['id'] ?? 0));
    }

    return 0;
}

function ecAbandonedCartRestoreSnapshot(array $snapshot, ?int $userId = null): void
{
    $items = array_values((array)($snapshot['items'] ?? []));
    $userId = ecAbandonedCartCartUserId($userId);

    if ($userId > 0) {
        ecDb()->execute("DELETE FROM ec_cart_items WHERE cart_id IN (SELECT id FROM ec_carts WHERE user_id = ?)", [$userId]);
        ecDb()->execute("DELETE FROM ec_carts WHERE user_id = ?", [$userId]);

        foreach ($items as $item) {
            ecDbCartAdd($userId, [
                'product_id' => (int)($item['product_id'] ?? 0),
                'variant_id' => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
                'qty' => max(1, (int)($item['qty'] ?? 1)),
                'price_snapshot' => round((float)($item['price_snapshot'] ?? 0), 2),
                'product_title' => trim((string)($item['product_title'] ?? '')),
                'sku' => trim((string)($item['sku'] ?? '')),
            ]);
        }
    } else {
        ecSessionCartSave($items);
    }

    $couponCode = trim((string)($snapshot['coupon_code'] ?? ''));
    if ($userId > 0) {
        if ($couponCode !== '') {
            ecDbCartSetCoupon($userId, $couponCode);
        }
    } elseif ($couponCode !== '') {
        $_SESSION[EC_SESSION_COUPON_KEY] = $couponCode;
    } else {
        unset($_SESSION[EC_SESSION_COUPON_KEY]);
    }
}

function ecAbandonedCartNormalizeRow(array $row): array
{
    $snapshot = [];
    $rawSnapshot = (string)($row['cart_snapshot'] ?? '');
    if ($rawSnapshot !== '') {
        $decoded = json_decode($rawSnapshot, true);
        if (is_array($decoded)) {
            $snapshot = $decoded;
        }
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'user_id' => isset($row['user_id']) && (int)$row['user_id'] > 0 ? (int)$row['user_id'] : null,
        'session_id' => trim((string)($row['session_id'] ?? '')),
        'guest_email' => strtolower(trim((string)($row['guest_email'] ?? ''))),
        'guest_name' => trim((string)($row['guest_name'] ?? '')),
        'recovery_token' => trim((string)($row['recovery_token'] ?? '')),
        'status' => trim((string)($row['status'] ?? 'active')),
        'cart_snapshot' => $snapshot,
        'item_count' => max(0, (int)($row['item_count'] ?? 0)),
        'subtotal' => round((float)($row['subtotal'] ?? 0), 2),
        'total' => round((float)($row['total'] ?? 0), 2),
        'recovery_email_1_sent_at' => trim((string)($row['recovery_email_1_sent_at'] ?? '')),
        'recovery_email_2_sent_at' => trim((string)($row['recovery_email_2_sent_at'] ?? '')),
        'recovery_email_3_sent_at' => trim((string)($row['recovery_email_3_sent_at'] ?? '')),
        'recovered_order_id' => isset($row['recovered_order_id']) && (int)$row['recovered_order_id'] > 0 ? (int)$row['recovered_order_id'] : null,
        'recovered_at' => trim((string)($row['recovered_at'] ?? '')),
        'last_activity_at' => trim((string)($row['last_activity_at'] ?? '')),
        'created_at' => trim((string)($row['created_at'] ?? '')),
        'updated_at' => trim((string)($row['updated_at'] ?? '')),
        'total_fmt' => (string)ecSettings('currency_symbol') . number_format((float)($row['total'] ?? 0), 2),
    ];
}

function ecAbandonedCartFindActiveRecord(array $context): ?array
{
    if (!ecAbandonedCartStorageAvailable()) {
        return null;
    }

    $where = ["status = 'active'", 'recovered_order_id IS NULL'];
    $params = [];

    if (!empty($context['user_id'])) {
        $where[] = 'user_id = ?';
        $params[] = (int)$context['user_id'];
    } elseif (!empty($context['guest_email'])) {
        $where[] = 'guest_email = ?';
        $params[] = (string)$context['guest_email'];
    } elseif (!empty($context['session_id'])) {
        $where[] = 'session_id = ?';
        $params[] = (string)$context['session_id'];
    } else {
        return null;
    }

    try {
        $row = ecDb()->query(
            'SELECT * FROM ec_abandoned_carts WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1',
            $params
        )->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }

    return is_array($row) ? ecAbandonedCartNormalizeRow($row) : null;
}

function ecAbandonedCartCloseActive(array $context): void
{
    if (!ecAbandonedCartStorageAvailable()) {
        return;
    }

    $where = ["status = 'active'", 'recovered_order_id IS NULL'];
    $params = [];
    if (!empty($context['user_id'])) {
        $where[] = 'user_id = ?';
        $params[] = (int)$context['user_id'];
    } elseif (!empty($context['guest_email'])) {
        $where[] = 'guest_email = ?';
        $params[] = (string)$context['guest_email'];
    } elseif (!empty($context['session_id'])) {
        $where[] = 'session_id = ?';
        $params[] = (string)$context['session_id'];
    } else {
        return;
    }

    try {
        ecDb()->execute(
            'UPDATE ec_abandoned_carts SET status = ?, updated_at = NOW() WHERE ' . implode(' AND ', $where),
            array_merge(['closed'], $params)
        );
    } catch (\Throwable $e) {
    }
}

function ecAbandonedCartCaptureLead(array $lead = [], ?array $cart = null): ?array
{
    if (!ecAbandonedCartEnabled()) {
        return null;
    }

    $cart = is_array($cart) ? $cart : ecCartGet();
    $context = ecAbandonedCartLeadContext($lead);
    $snapshot = ecAbandonedCartSnapshot($cart);
    if ($snapshot['items'] === []) {
        ecAbandonedCartCloseActive($context);
        return null;
    }

    if ($context['guest_email'] === '' || !filter_var($context['guest_email'], FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $existing = ecAbandonedCartFindActiveRecord($context);
    $payload = [
        $context['user_id'],
        $context['session_id'],
        $context['guest_email'],
        $context['guest_name'],
        json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (int)($snapshot['totals']['item_count'] ?? count($snapshot['items'])),
        round((float)($snapshot['totals']['subtotal'] ?? 0), 2),
        round((float)($snapshot['totals']['total'] ?? 0), 2),
    ];

    if ($existing) {
        ecDb()->execute(
            'UPDATE ec_abandoned_carts
             SET user_id = ?, session_id = ?, guest_email = ?, guest_name = ?, cart_snapshot = ?, item_count = ?, subtotal = ?, total = ?, last_activity_at = NOW(), updated_at = NOW()
             WHERE id = ? LIMIT 1',
            array_merge($payload, [(int)$existing['id']])
        );

        return ecAbandonedCartGet((int)$existing['id']);
    }

    $token = ecAbandonedCartGenerateToken();
    ecDb()->execute(
        'INSERT INTO ec_abandoned_carts
         (user_id, session_id, guest_email, guest_name, recovery_token, status, cart_snapshot, item_count, subtotal, total, last_activity_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
        [
            $context['user_id'],
            $context['session_id'],
            $context['guest_email'],
            $context['guest_name'],
            $token,
            'active',
            json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (int)($snapshot['totals']['item_count'] ?? count($snapshot['items'])),
            round((float)($snapshot['totals']['subtotal'] ?? 0), 2),
            round((float)($snapshot['totals']['total'] ?? 0), 2),
        ]
    );

    return ecAbandonedCartGet((int)ecDb()->lastInsertId());
}

function ecAbandonedCartGet(int $id): ?array
{
    if ($id <= 0 || !ecAbandonedCartStorageAvailable()) {
        return null;
    }

    try {
        $row = ecDb()->query('SELECT * FROM ec_abandoned_carts WHERE id = ? LIMIT 1', [$id])->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }

    return is_array($row) ? ecAbandonedCartNormalizeRow($row) : null;
}

function ecAbandonedCartGetByToken(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !ecAbandonedCartStorageAvailable()) {
        return null;
    }

    try {
        $row = ecDb()->query('SELECT * FROM ec_abandoned_carts WHERE recovery_token = ? LIMIT 1', [$token])->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }

    return is_array($row) ? ecAbandonedCartNormalizeRow($row) : null;
}

function ecAbandonedCartRememberRecoveryToken(string $token): void
{
    if ($token !== '') {
        $_SESSION['ec_abandoned_cart_recovery_token'] = $token;
    }
}

function ecAbandonedCartCurrentRecoveryToken(): string
{
    return trim((string)($_SESSION['ec_abandoned_cart_recovery_token'] ?? ''));
}

function ecAbandonedCartClearRecoveryToken(): void
{
    unset($_SESSION['ec_abandoned_cart_recovery_token']);
}

function ecAbandonedCartRestore(string $token): array
{
    $record = ecAbandonedCartGetByToken($token);
    if (!$record || (string)($record['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'Recovery link is invalid or has expired.'];
    }

    ecAbandonedCartRestoreSnapshot((array)($record['cart_snapshot'] ?? []), isset($record['user_id']) ? (int)$record['user_id'] : null);
    ecAbandonedCartRememberRecoveryToken((string)$record['recovery_token']);

    try {
        ecDb()->execute('UPDATE ec_abandoned_carts SET last_activity_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1', [(int)$record['id']]);
    } catch (\Throwable $e) {
    }

    return ['ok' => true, 'record' => ecAbandonedCartGet((int)$record['id']) ?? $record];
}

function ecAbandonedCartRecoveryUrl(array $record): string
{
    return rtrim(ecGetBaseUrl(), '/') . '/ecommerce/recover-cart/' . rawurlencode((string)($record['recovery_token'] ?? ''));
}

function ecAbandonedCartItemsSummary(array $record): string
{
    $items = (array)($record['cart_snapshot']['items'] ?? []);
    if ($items === []) {
        return '';
    }

    $lines = [];
    foreach ($items as $item) {
        $title = trim((string)($item['product_title'] ?? 'Product'));
        $qty = max(1, (int)($item['qty'] ?? 1));
        $price = round((float)($item['price_snapshot'] ?? 0), 2);
        $lines[] = '<li style="margin:0 0 8px;">' . htmlspecialchars($title, ENT_QUOTES) . ' × ' . $qty . ' <span style="color:#64748b;">(' . htmlspecialchars((string)ecSettings('currency_symbol') . number_format($price, 2), ENT_QUOTES) . ' each)</span></li>';
    }

    return '<ul style="padding-left:20px;margin:16px 0;">' . implode('', $lines) . '</ul>';
}

function ecAbandonedCartSendRecoveryEmail(array $record, int $stage): array
{
    $email = strtolower(trim((string)($record['guest_email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Valid email is required.'];
    }
    if (!function_exists('sendEmail')) {
        return ['ok' => false, 'error' => 'send_unavailable'];
    }

    $greeting = trim((string)($record['guest_name'] ?? ''));
    if ($greeting === '') {
        $greeting = 'there';
    }

    $vars = [
        'customer_greeting' => $greeting,
        'customer_email' => $email,
        'cart_item_count' => (string)max(0, (int)($record['item_count'] ?? 0)),
        'cart_total' => (string)ecSettings('currency_symbol') . number_format((float)($record['total'] ?? 0), 2),
        'recovery_url' => ecAbandonedCartRecoveryUrl($record),
        'recovery_stage' => (string)$stage,
    ];
    $template = ecCompileEmailTemplate('abandoned_cart_recovery', $vars);
    $content = $template['message_html']
        . '<p style="margin:0 0 16px;">Your cart still has <strong>' . max(0, (int)($record['item_count'] ?? 0)) . '</strong> item(s) worth <strong>' . htmlspecialchars($vars['cart_total'], ENT_QUOTES) . '</strong>.</p>'
        . ecAbandonedCartItemsSummary($record)
        . '<p style="margin:24px 0 0;">'
        . '<a href="' . htmlspecialchars($vars['recovery_url'], ENT_QUOTES) . '" style="display:inline-block;padding:12px 20px;border-radius:6px;background:#ea580c;color:#ffffff;text-decoration:none;font-weight:600;">Return to Checkout</a>'
        . '</p>';
    $body = ecWrapEmailTemplateHtml(
        '<h2 style="color:#ea580c;">Complete your checkout</h2>'
        . $content
    );

    $sent = sendEmail($email, (string)($template['subject'] ?? 'Complete your checkout'), $body, ['reply_to' => (string)ecSettings('admin_email')]);
    if (!$sent) {
        return ['ok' => false, 'error' => 'send_failed'];
    }

    $column = 'recovery_email_' . $stage . '_sent_at';
    ecDb()->execute(
        'UPDATE ec_abandoned_carts SET ' . $column . ' = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1',
        [(int)$record['id']]
    );

    return ['ok' => true, 'record' => ecAbandonedCartGet((int)$record['id']) ?? $record, 'stage' => $stage];
}

function ecAbandonedCartDueReminders(int $limit = 50): array
{
    if (!ecAbandonedCartEnabled()) {
        return [];
    }

    $hours = ecAbandonedCartReminderHours();
    $limit = max(1, min(200, $limit));

    try {
        $rows = ecDb()->query(
            "SELECT * FROM ec_abandoned_carts WHERE status = 'active' AND recovered_order_id IS NULL AND guest_email <> '' ORDER BY last_activity_at ASC LIMIT {$limit}"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $due = [];
    $now = time();
    foreach ($rows as $row) {
        $record = ecAbandonedCartNormalizeRow($row);
        $lastActivity = strtotime((string)($record['last_activity_at'] ?? '')) ?: 0;
        if ($lastActivity <= 0) {
            continue;
        }

        $elapsedHours = ($now - $lastActivity) / 3600;
        $stage = 0;
        if (($record['recovery_email_1_sent_at'] ?? '') === '' && $elapsedHours >= $hours[1]) {
            $stage = 1;
        } elseif (($record['recovery_email_1_sent_at'] ?? '') !== '' && ($record['recovery_email_2_sent_at'] ?? '') === '' && $elapsedHours >= $hours[2]) {
            $stage = 2;
        } elseif (($record['recovery_email_2_sent_at'] ?? '') !== '' && ($record['recovery_email_3_sent_at'] ?? '') === '' && $elapsedHours >= $hours[3]) {
            $stage = 3;
        }

        if ($stage > 0) {
            $record['reminder_stage'] = $stage;
            $due[] = $record;
        }
    }

    return $due;
}

function ecAbandonedCartProcessDueReminders(int $limit = 50): array
{
    $results = [];
    foreach (ecAbandonedCartDueReminders($limit) as $record) {
        $results[] = ecAbandonedCartSendRecoveryEmail($record, (int)($record['reminder_stage'] ?? 1));
    }

    return $results;
}

function ecAbandonedCartMarkRecovered(int $orderId, ?int $customerId = null, string $customerEmail = '', ?string $recoveryToken = null): void
{
    if (!ecAbandonedCartStorageAvailable() || $orderId <= 0) {
        ecAbandonedCartClearRecoveryToken();
        return;
    }

    $params = [];
    $sql = '';
    $recoveryToken = trim((string)$recoveryToken);
    $customerEmail = strtolower(trim($customerEmail));
    if ($recoveryToken !== '') {
        $sql = 'UPDATE ec_abandoned_carts SET status = ?, recovered_order_id = ?, recovered_at = NOW(), updated_at = NOW() WHERE recovery_token = ? AND status = ? LIMIT 1';
        $params = ['recovered', $orderId, $recoveryToken, 'active'];
    } elseif (($customerId ?? 0) > 0) {
        $sql = 'UPDATE ec_abandoned_carts SET status = ?, recovered_order_id = ?, recovered_at = NOW(), updated_at = NOW() WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1';
        $params = ['recovered', $orderId, $customerId, 'active'];
    } elseif ($customerEmail !== '') {
        $sql = 'UPDATE ec_abandoned_carts SET status = ?, recovered_order_id = ?, recovered_at = NOW(), updated_at = NOW() WHERE guest_email = ? AND status = ? ORDER BY id DESC LIMIT 1';
        $params = ['recovered', $orderId, $customerEmail, 'active'];
    }

    if ($sql !== '') {
        try {
            ecDb()->execute($sql, $params);
        } catch (\Throwable $e) {
        }
    }

    ecAbandonedCartClearRecoveryToken();
}

function ecAbandonedCartMetrics(): array
{
    if (!ecAbandonedCartStorageAvailable()) {
        return [
            'active_count' => 0,
            'recovered_count' => 0,
            'closed_count' => 0,
            'revenue_at_risk' => 0.0,
            'recovered_revenue' => 0.0,
            'revenue_at_risk_fmt' => (string)ecSettings('currency_symbol') . number_format(0, 2),
            'recovered_revenue_fmt' => (string)ecSettings('currency_symbol') . number_format(0, 2),
        ];
    }

    try {
        $counts = ecDb()->query(
            "SELECT status, COUNT(*) AS total, COALESCE(SUM(total), 0) AS revenue FROM ec_abandoned_carts GROUP BY status"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $counts = [];
    }

    $metrics = [
        'active_count' => 0,
        'recovered_count' => 0,
        'closed_count' => 0,
        'revenue_at_risk' => 0.0,
        'recovered_revenue' => 0.0,
    ];
    foreach ($counts as $row) {
        $status = trim((string)($row['status'] ?? ''));
        if ($status === 'active') {
            $metrics['active_count'] = (int)($row['total'] ?? 0);
            $metrics['revenue_at_risk'] = round((float)($row['revenue'] ?? 0), 2);
        } elseif ($status === 'recovered') {
            $metrics['recovered_count'] = (int)($row['total'] ?? 0);
            $metrics['recovered_revenue'] = round((float)($row['revenue'] ?? 0), 2);
        } elseif ($status === 'closed') {
            $metrics['closed_count'] = (int)($row['total'] ?? 0);
        }
    }

    $metrics['revenue_at_risk_fmt'] = (string)ecSettings('currency_symbol') . number_format((float)$metrics['revenue_at_risk'], 2);
    $metrics['recovered_revenue_fmt'] = (string)ecSettings('currency_symbol') . number_format((float)$metrics['recovered_revenue'], 2);

    return $metrics;
}

function ecAbandonedCartList(int $limit = 50): array
{
    if (!ecAbandonedCartStorageAvailable()) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    try {
        $rows = ecDb()->query(
            "SELECT * FROM ec_abandoned_carts ORDER BY updated_at DESC LIMIT {$limit}"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    return array_map('ecAbandonedCartNormalizeRow', $rows);
}