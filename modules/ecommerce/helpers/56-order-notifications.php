<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Order Notification (helpers/56-order-notifications.php)
//
// Listens to ecommerce.order.created and sends a notification email to the
// address configured in the 'admin_notification_email' ecommerce setting.
// ─────────────────────────────────────────────────────────────────────────

app()->events()->listen('ecommerce.order.created', function (array $payload) {
    $orderId     = (int)($payload['order_id']     ?? 0);
    $orderNumber = (string)($payload['order_number'] ?? '');
    $total       = (float)($payload['total']       ?? 0);
    $source      = (string)($payload['source']     ?? 'web');

    if ($orderId <= 0) {
        return;
    }

    $settings = isset($_ENV['TEST_TENANT_ID'])
        ? readTenantModuleSettingsForTenant('ecommerce', (int)$_ENV['TEST_TENANT_ID'])
        : readTenantModuleSettings('ecommerce');

    $adminEmail = trim((string)($settings['admin_notification_email'] ?? ''));
    if ($adminEmail === '' || !function_exists('sendEmail')) {
        return;
    }

    try {
        $order = ecOrderGet($orderId);
        if (!$order) {
            return;
        }

        $customerEmail  = (string)($order['customer_email']  ?? $payload['customer_email'] ?? '');
        $customerName   = trim((string)($order['customer_name']  ?? $order['guest_name']    ?? ''));
        $currency       = (string)($order['currency']        ?? ecSettings('currency'));
        $currencySymbol = (string)ecSettings('currency_symbol');
        $baseUrl        = rtrim((string)app()->config('app.url', ''), '/');
        $adminOrderUrl  = $baseUrl . '/ecommerce/admin/orders/' . $orderId;

        $formattedTotal = $currencySymbol . number_format($total, 2);
        $formattedCustomer = $customerName !== ''
            ? $customerName . ' <' . $customerEmail . '>'
            : $customerEmail;

        $itemRows = '';
        foreach ($order['items'] ?? [] as $item) {
            $itemRows .= '<tr>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">'
                . htmlspecialchars((string)($item['product_title'] ?? ''), ENT_QUOTES)
                . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:center;">'
                . (int)($item['qty'] ?? 1)
                . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:right;">'
                . $currencySymbol . number_format((float)($item['unit_price'] ?? 0), 2)
                . '</td>'
                . '</tr>';
        }

        $template = ecCompileEmailTemplate('admin_order_notification', [
            'order_number' => $orderNumber,
            'customer_line' => $formattedCustomer,
            'customer_name' => $customerName !== '' ? $customerName : 'Customer',
            'customer_email' => $customerEmail,
            'order_total' => $formattedTotal,
            'source' => $source,
            'source_suffix' => $source !== 'web' ? ' via ' . $source : '',
        ]);

        $body = ecWrapEmailTemplateHtml(
            '<h2 style="color:#ea580c;">New Order Received — #' . htmlspecialchars($orderNumber, ENT_QUOTES) . '</h2>'
            . $template['message_html']
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">'
            . '<tr><td style="padding:4px 8px;font-weight:600;width:40%;">Order Number</td><td style="padding:4px 8px;">#' . htmlspecialchars($orderNumber, ENT_QUOTES) . '</td></tr>'
            . '<tr style="background:#f9fafb;"><td style="padding:4px 8px;font-weight:600;">Customer</td><td style="padding:4px 8px;">' . htmlspecialchars($formattedCustomer, ENT_QUOTES) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;font-weight:600;">Total</td><td style="padding:4px 8px;">' . htmlspecialchars($formattedTotal, ENT_QUOTES) . '</td></tr>'
            . '</table>'
            . ($itemRows !== ''
                ? '<table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;margin-bottom:16px;">'
                    . '<thead><tr style="background:#f9fafb;">'
                    . '<th style="padding:6px 10px;text-align:left;font-size:12px;text-transform:uppercase;color:#6b7280;">Product</th>'
                    . '<th style="padding:6px 10px;text-align:center;font-size:12px;text-transform:uppercase;color:#6b7280;">Qty</th>'
                    . '<th style="padding:6px 10px;text-align:right;font-size:12px;text-transform:uppercase;color:#6b7280;">Unit Price</th>'
                    . '</tr></thead>'
                    . '<tbody>' . $itemRows . '</tbody>'
                    . '</table>'
                : '')
            . '<p><a href="' . htmlspecialchars($adminOrderUrl, ENT_QUOTES) . '" style="color:#ea580c;">View Order in Admin →</a></p>'
        );

        sendEmail($adminEmail, $template['subject'], $body);

    } catch (\Throwable $e) {
        write_log('Admin order notification failed for order ' . $orderId . ': ' . $e->getMessage(), 'warning', [
            'module'   => 'ecommerce',
            'order_id' => $orderId,
        ]);
    }
});
