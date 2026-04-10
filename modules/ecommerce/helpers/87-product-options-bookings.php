<?php

declare(strict_types=1);

function ecProductParseAddonLines(string $lines): array
{
    $rows = preg_split('/\r\n|\r|\n/', $lines) ?: [];
    $addons = [];
    $usedIds = [];

    foreach ($rows as $row) {
        $row = trim($row);
        if ($row === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $row));
        $label = (string)($parts[0] ?? '');
        if ($label === '') {
            continue;
        }

        $price = max(0.0, (float)($parts[1] ?? 0));
        $description = (string)($parts[2] ?? '');
        $id = strtolower(trim((string)preg_replace('/[^a-z0-9]+/', '-', $label), '-')) ?: 'addon';
        $baseId = $id;
        $suffix = 2;
        while (in_array($id, $usedIds, true)) {
            $id = $baseId . '-' . $suffix;
            $suffix++;
        }
        $usedIds[] = $id;

        $addons[] = [
            'id' => $id,
            'label' => $label,
            'price' => round($price, 2),
            'description' => $description,
        ];
    }

    return $addons;
}

function ecProductAddonConfigFromMetaMap(array $metaMap): array
{
    $raw = $metaMap['_product_addons'] ?? '[]';
    $decoded = json_decode((string)$raw, true);
    $addons = is_array($decoded) ? $decoded : [];
    $normalized = [];

    foreach ($addons as $addon) {
        if (!is_array($addon)) {
            continue;
        }

        $label = trim((string)($addon['label'] ?? ''));
        if ($label === '') {
            continue;
        }

        $normalized[] = [
            'id' => trim((string)($addon['id'] ?? '')) !== '' ? trim((string)$addon['id']) : strtolower(trim((string)preg_replace('/[^a-z0-9]+/', '-', $label), '-')),
            'label' => $label,
            'price' => round(max(0.0, (float)($addon['price'] ?? 0.0)), 2),
            'description' => trim((string)($addon['description'] ?? '')),
        ];
    }

    return $normalized;
}

function ecProductSaveAddonMeta(int $productId, array $input): void
{
    $addons = ecProductParseAddonLines((string)($input['addon_lines'] ?? ''));

    try {
        moduleWithContext('cms', static function () use ($productId, $addons): void {
            cmsDb()->execute(
                "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
                 VALUES (?, '_product_addons', ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                [$productId, json_encode($addons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        });
    } catch (\Throwable $e) {
        write_log('ecProductSaveAddonMeta error: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

function ecProductBookingDefaults(): array
{
    return [
        'enabled' => false,
        'duration_minutes' => 60,
        'notice_hours' => 24,
        'available_weekdays' => [1, 2, 3, 4, 5],
        'time_slots' => ['09:00', '13:00', '15:00'],
        'summary' => '60 minute appointment',
    ];
}

function ecProductNormalizeBookingTimeSlots(mixed $slots): array
{
    if (is_string($slots)) {
        $decoded = json_decode($slots, true);
        if (is_array($decoded)) {
            $slots = $decoded;
        } else {
            $slots = preg_split('/[\r\n,]+/', $slots) ?: [];
        }
    }

    if (!is_array($slots)) {
        $slots = [];
    }

    $normalized = [];
    foreach ($slots as $slot) {
        $value = trim((string)$slot);
        if ($value === '' || !preg_match('/^\d{2}:\d{2}$/', $value)) {
            continue;
        }
        if (!in_array($value, $normalized, true)) {
            $normalized[] = $value;
        }
    }

    sort($normalized);
    return $normalized;
}

function ecProductNormalizeBookingWeekdays(mixed $weekdays): array
{
    if (is_string($weekdays)) {
        $decoded = json_decode($weekdays, true);
        if (is_array($decoded)) {
            $weekdays = $decoded;
        } else {
            $weekdays = preg_split('/[\r\n,]+/', $weekdays) ?: [];
        }
    }

    if (!is_array($weekdays)) {
        $weekdays = [];
    }

    $normalized = [];
    foreach ($weekdays as $weekday) {
        $value = (int)$weekday;
        if ($value < 0 || $value > 6 || in_array($value, $normalized, true)) {
            continue;
        }
        $normalized[] = $value;
    }

    sort($normalized);
    return $normalized;
}

function ecProductBookingConfigFromMetaMap(array $metaMap): array
{
    $defaults = ecProductBookingDefaults();
    $enabled = ($metaMap['_booking_enabled'] ?? '0') === '1';
    $duration = max(15, (int)($metaMap['_booking_duration_minutes'] ?? $defaults['duration_minutes']));
    $noticeHours = max(0, (int)($metaMap['_booking_notice_hours'] ?? $defaults['notice_hours']));
    $availableWeekdays = ecProductNormalizeBookingWeekdays($metaMap['_booking_available_weekdays'] ?? $defaults['available_weekdays']);
    $timeSlots = ecProductNormalizeBookingTimeSlots($metaMap['_booking_time_slots'] ?? $defaults['time_slots']);

    return [
        'enabled' => $enabled,
        'duration_minutes' => $duration,
        'notice_hours' => $noticeHours,
        'available_weekdays' => $availableWeekdays,
        'time_slots' => $timeSlots,
        'summary' => $duration . ' minute appointment',
    ];
}

function ecProductSaveBookingMeta(int $productId, array $input): void
{
    $config = ecProductBookingConfigFromMetaMap([
        '_booking_enabled' => !empty($input['booking_enabled']) ? '1' : '0',
        '_booking_duration_minutes' => (string)($input['booking_duration_minutes'] ?? 60),
        '_booking_notice_hours' => (string)($input['booking_notice_hours'] ?? 24),
        '_booking_available_weekdays' => json_encode(ecProductNormalizeBookingWeekdays($input['booking_available_weekdays'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '_booking_time_slots' => json_encode(ecProductNormalizeBookingTimeSlots($input['booking_time_slots'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $meta = [
        '_booking_enabled' => $config['enabled'] ? '1' : '0',
        '_booking_duration_minutes' => (string)$config['duration_minutes'],
        '_booking_notice_hours' => (string)$config['notice_hours'],
        '_booking_available_weekdays' => json_encode($config['available_weekdays'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '_booking_time_slots' => json_encode($config['time_slots'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        write_log('ecProductSaveBookingMeta error: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

function ecProductSelectedAddons(array $product, mixed $selectedIds): array
{
    if (!is_array($selectedIds)) {
        $selectedIds = $selectedIds === null || $selectedIds === '' ? [] : [$selectedIds];
    }

    $selectedIds = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $selectedIds))));
    $addons = is_array($product['addons'] ?? null) ? $product['addons'] : [];
    $selected = [];
    $total = 0.0;

    foreach ($addons as $addon) {
        if (!is_array($addon)) {
            continue;
        }
        $addonId = trim((string)($addon['id'] ?? ''));
        if ($addonId === '' || !in_array($addonId, $selectedIds, true)) {
            continue;
        }

        $snapshot = [
            'id' => $addonId,
            'label' => trim((string)($addon['label'] ?? 'Addon')),
            'price' => round((float)($addon['price'] ?? 0.0), 2),
            'description' => trim((string)($addon['description'] ?? '')),
        ];
        $total += (float)$snapshot['price'];
        $selected[] = $snapshot;
    }

    return [
        'items' => $selected,
        'total' => round($total, 2),
    ];
}

function ecProductNormalizeBookingSelection(mixed $input): array
{
    $input = is_array($input) ? $input : [];

    return [
        'date' => trim((string)($input['date'] ?? '')),
        'time' => trim((string)($input['time'] ?? '')),
        'notes' => trim((string)($input['notes'] ?? '')),
    ];
}

function ecProductValidateBookingSelection(array $product, array $bookingSelection): array
{
    $config = is_array($product['booking'] ?? null) ? $product['booking'] : ecProductBookingDefaults();
    if (empty($config['enabled'])) {
        return ['ok' => true, 'booking' => ['has_booking' => false]];
    }

    $booking = ecProductNormalizeBookingSelection($bookingSelection);
    if ($booking['date'] === '' || $booking['time'] === '') {
        return ['ok' => false, 'error' => 'Select a booking date and time before adding this product to the cart.'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking['date']) || !preg_match('/^\d{2}:\d{2}$/', $booking['time'])) {
        return ['ok' => false, 'error' => 'The selected booking slot is invalid.'];
    }

    $scheduledFor = strtotime($booking['date'] . ' ' . $booking['time']);
    if ($scheduledFor === false) {
        return ['ok' => false, 'error' => 'The selected booking slot is invalid.'];
    }

    $noticeHours = max(0, (int)($config['notice_hours'] ?? 24));
    if ($scheduledFor < (time() + ($noticeHours * 3600))) {
        return ['ok' => false, 'error' => 'This appointment requires at least ' . $noticeHours . ' hours notice.'];
    }

    $weekday = (int)date('w', $scheduledFor);
    $allowedWeekdays = ecProductNormalizeBookingWeekdays($config['available_weekdays'] ?? []);
    if ($allowedWeekdays !== [] && !in_array($weekday, $allowedWeekdays, true)) {
        return ['ok' => false, 'error' => 'The selected day is not available for bookings.'];
    }

    $timeSlots = ecProductNormalizeBookingTimeSlots($config['time_slots'] ?? []);
    if ($timeSlots !== [] && !in_array($booking['time'], $timeSlots, true)) {
        return ['ok' => false, 'error' => 'The selected time is no longer available.'];
    }

    $durationMinutes = max(15, (int)($config['duration_minutes'] ?? 60));
    $endsAt = date('Y-m-d H:i:s', strtotime('+' . $durationMinutes . ' minutes', $scheduledFor));

    return [
        'ok' => true,
        'booking' => [
            'has_booking' => true,
            'scheduled_for' => date('Y-m-d H:i:s', $scheduledFor),
            'scheduled_date' => date('Y-m-d', $scheduledFor),
            'scheduled_time' => date('H:i', $scheduledFor),
            'ends_at' => $endsAt,
            'duration_minutes' => $durationMinutes,
            'notes' => $booking['notes'],
        ],
    ];
}

function ecCartCanonicalOptionsJson(array $item): string
{
    $payload = [];

    if (!empty($item['selected_addons']) && is_array($item['selected_addons'])) {
        $payload['selected_addons'] = array_values(array_map(static function (array $addon): array {
            return [
                'id' => trim((string)($addon['id'] ?? '')),
                'label' => trim((string)($addon['label'] ?? '')),
                'price' => round((float)($addon['price'] ?? 0.0), 2),
                'description' => trim((string)($addon['description'] ?? '')),
            ];
        }, $item['selected_addons']));
    }

    if (!empty($item['booking']) && is_array($item['booking']) && !empty($item['booking']['has_booking'])) {
        $payload['booking'] = [
            'has_booking' => true,
            'scheduled_for' => (string)($item['booking']['scheduled_for'] ?? ''),
            'scheduled_date' => (string)($item['booking']['scheduled_date'] ?? ''),
            'scheduled_time' => (string)($item['booking']['scheduled_time'] ?? ''),
            'ends_at' => (string)($item['booking']['ends_at'] ?? ''),
            'duration_minutes' => (int)($item['booking']['duration_minutes'] ?? 0),
            'notes' => (string)($item['booking']['notes'] ?? ''),
        ];
    }

    return $payload === []
        ? ''
        : (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ecCartPrepareExtendedItemData(array $product, array $input): array
{
    $addonSelection = ecProductSelectedAddons($product, $input['add_ons'] ?? $input['addons'] ?? []);
    $bookingValidation = ecProductValidateBookingSelection($product, (array)($input['booking'] ?? []));
    if (empty($bookingValidation['ok'])) {
        return $bookingValidation;
    }

    $selectedAddons = $addonSelection['items'];
    $addonTotal = round((float)($addonSelection['total'] ?? 0.0), 2);
    $booking = is_array($bookingValidation['booking'] ?? null) ? $bookingValidation['booking'] : ['has_booking' => false];

    return [
        'ok' => true,
        'price_adjustment' => $addonTotal,
        'selected_addons' => $selectedAddons,
        'addon_total' => $addonTotal,
        'booking' => $booking,
    ];
}

function ecHydrateLineItemOptions(array $item, ?string $currencyCode = null): array
{
    $currencyCode = ecCurrencyNormalizeCode($currencyCode ?? $item['currency'] ?? ecStoreBaseCurrencyCode()) ?: ecStoreBaseCurrencyCode();
    $optionsJson = trim((string)($item['options_json'] ?? $item['snapshot_json'] ?? ''));
    $decoded = $optionsJson !== '' ? json_decode($optionsJson, true) : [];
    $decoded = is_array($decoded) ? $decoded : [];

    $selectedAddons = [];
    foreach ((array)($decoded['selected_addons'] ?? $item['selected_addons'] ?? []) as $addon) {
        if (!is_array($addon)) {
            continue;
        }
        $selectedAddons[] = [
            'id' => trim((string)($addon['id'] ?? '')),
            'label' => trim((string)($addon['label'] ?? 'Addon')),
            'price' => round((float)($addon['price'] ?? 0.0), 2),
            'description' => trim((string)($addon['description'] ?? '')),
        ];
    }

    $addonTotal = round(array_sum(array_map(static fn(array $addon): float => (float)($addon['price'] ?? 0.0), $selectedAddons)), 2);
    $booking = is_array($decoded['booking'] ?? null) ? $decoded['booking'] : (is_array($item['booking'] ?? null) ? $item['booking'] : []);
    $item['selected_addons'] = $selectedAddons;
    $item['addon_total'] = $addonTotal;
    $item['addon_total_fmt'] = ecCurrencyFormatAmount($addonTotal, $currencyCode);
    $item['booking'] = array_merge(['has_booking' => false], is_array($booking) ? $booking : []);

    return $item;
}

function ecBookingStorageAvailable(): bool
{
    try {
        ecDb()->query('SELECT 1 FROM ec_bookings LIMIT 1');
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecBookingsForOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecBookingStorageAvailable()) {
        return [];
    }

    try {
        return ecDb()->query(
            'SELECT * FROM ec_bookings WHERE order_id = ? ORDER BY scheduled_for ASC, id ASC',
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecBookingsForCustomer(int $customerId, int $limit = 20): array
{
    if ($customerId <= 0 || !ecBookingStorageAvailable()) {
        return [];
    }

    try {
        return ecDb()->query(
            'SELECT * FROM ec_bookings WHERE customer_id = ? ORDER BY scheduled_for DESC, id DESC LIMIT ?',
            [$customerId, max(1, $limit)]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecBookingCreatePendingRecordsForOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecBookingStorageAvailable()) {
        return ['ok' => false, 'created' => 0];
    }

    $order = ecOrderGet($orderId);
    if (!is_array($order) || empty($order['items'])) {
        return ['ok' => false, 'created' => 0];
    }

    $db = ecDb();
    $created = 0;
    foreach ((array)$order['items'] as $item) {
        $orderItemId = (int)($item['id'] ?? 0);
        if ($orderItemId <= 0) {
            continue;
        }

        $lineItem = ecHydrateLineItemOptions($item, (string)($order['currency'] ?? ecStoreBaseCurrencyCode()));
        $booking = is_array($lineItem['booking'] ?? null) ? $lineItem['booking'] : [];
        if (empty($booking['has_booking'])) {
            continue;
        }

        $existingId = (int)($db->query('SELECT id FROM ec_bookings WHERE order_item_id = ? LIMIT 1', [$orderItemId])->fetchColumn() ?: 0);
        if ($existingId > 0) {
            continue;
        }

        $db->execute(
            'INSERT INTO ec_bookings (
                order_id, order_item_id, customer_id, customer_email, product_id, product_title,
                status, scheduled_for, ends_at, duration_minutes, notes, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $orderId,
                $orderItemId,
                isset($order['customer_id']) ? (int)$order['customer_id'] : null,
                (string)($order['customer_email'] ?? $order['guest_email'] ?? ''),
                (int)($item['product_id'] ?? 0),
                trim((string)($item['product_title'] ?? 'Booking')),
                'pending',
                (string)($booking['scheduled_for'] ?? ''),
                (string)($booking['ends_at'] ?? ''),
                max(15, (int)($booking['duration_minutes'] ?? 60)),
                trim((string)($booking['notes'] ?? '')),
            ]
        );
        $created++;
    }

    return ['ok' => true, 'created' => $created];
}

function ecBookingConfirmPaidOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecBookingStorageAvailable()) {
        return ['ok' => false, 'confirmed' => 0];
    }

    ecBookingCreatePendingRecordsForOrder($orderId);
    ecDb()->execute("UPDATE ec_bookings SET status = 'confirmed', updated_at = NOW() WHERE order_id = ? AND status = 'pending'", [$orderId]);

    try {
        $confirmed = (int)(ecDb()->query("SELECT COUNT(*) FROM ec_bookings WHERE order_id = ? AND status = 'confirmed'", [$orderId])->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        $confirmed = 0;
    }

    return ['ok' => true, 'confirmed' => $confirmed];
}

app()->events()->listen('ecommerce.order.paid', function (array $payload): void {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    try {
        ecBookingConfirmPaidOrder($orderId);
    } catch (\Throwable $e) {
        write_log('Failed to confirm bookings for paid order ' . $orderId . ': ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'order_id' => $orderId,
        ]);
    }
});