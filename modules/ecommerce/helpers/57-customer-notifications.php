<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Customer Order Notification (helpers/57-customer-notifications.php)
//
// Listens to ecommerce.order.created and sends a confirmation email to the
// customer who placed the order.
// ─────────────────────────────────────────────────────────────────────────

function ecSendCustomerOrderConfirmation(array $payload): void
{
    if (!function_exists('sendEmail')) {
        return;
    }

    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    try {
        $order = ecOrderGet($orderId);
        if (!$order) {
            return;
        }

        $customerEmail = trim((string)($order['customer_email'] ?? $payload['customer_email'] ?? ''));
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $customerName   = trim((string)($order['customer_name'] ?? $order['guest_name'] ?? ''));
        $orderNumber    = (string)($order['order_number'] ?? $payload['order_number'] ?? '');
        $total          = (float)($order['total'] ?? $payload['total'] ?? 0);
        $currencyCode   = ecCurrencyNormalizeCode($order['currency'] ?? $payload['currency'] ?? '') ?: ecStoreBaseCurrencyCode();
        $currencySymbol = (string)($order['currency_symbol'] ?? $payload['currency_symbol'] ?? ecCurrencySymbolFor($currencyCode));
        
        $formattedTotal = $currencySymbol . number_format($total, 2);

        $settings = isset($_ENV['TEST_TENANT_ID'])
            ? readTenantModuleSettingsForTenant('ecommerce', (int)$_ENV['TEST_TENANT_ID'])
            : readTenantModuleSettings('ecommerce');

        $adminEmail = trim((string)($settings['admin_email'] ?? ''));
        $options = [];
        if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $options['reply_to'] = $adminEmail;
            $options['from'] = $adminEmail;
        }

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

        $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : rtrim((string)external_base_url(), '/');
        $loginUrl = $baseUrl . '/cms/login?redirect=' . urlencode('/ecommerce/my-orders');
        $myOrdersUrl = $baseUrl . '/ecommerce/my-orders';
        $forgotPasswordUrl = $baseUrl . '/cms/forgot-password';

        $accountInstructions = '';
        if (function_exists('cmsDb')) {
            try {
                $accRow = cmsDb()->query(
                    "SELECT id, created_at FROM cms_users WHERE email = ? AND is_active = 1 LIMIT 1",
                    [$customerEmail]
                )->fetch(\PDO::FETCH_ASSOC);

                if ($accRow) {
                    $createdAt = strtotime($accRow['created_at'] ?? 'now');
                    if (time() - $createdAt < 600) {
                        // User was just auto-created
                        $accountInstructions = '<div style="margin-top:24px;padding:16px 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">'
                            . '<h3 style="margin:0 0 8px;color:#334155;font-size:15px;">Complete your account registration</h3>'
                            . '<p style="margin:0 0 12px;font-size:13px;color:#475569;">To access your recent orders and digital items, please complete your account registration. Click <strong>Forgot Password</strong>, enter the email address you used during checkout, and reset your password to gain access.</p>'
                            . '<a href="' . htmlspecialchars($forgotPasswordUrl, ENT_QUOTES) . '" style="display:inline-block;padding:10px 20px;background:#3b82f6;color:#fff;border-radius:5px;text-decoration:none;font-weight:600;font-size:13px;">Forgot Password</a>'
                            . '</div>';
                    } else {
                        // Existing user
                        $accountInstructions = '<div style="margin-top:24px;padding:16px 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">'
                            . '<h3 style="margin:0 0 8px;color:#334155;font-size:15px;">Access your orders anytime</h3>'
                            . '<p style="margin:0 0 12px;font-size:13px;color:#475569;">Log in to your account at <strong>' . htmlspecialchars($customerEmail, ENT_QUOTES) . '</strong> to view your full order history.</p>'
                            . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES) . '" style="display:inline-block;padding:10px 20px;background:#3b82f6;color:#fff;border-radius:5px;text-decoration:none;font-weight:600;font-size:13px;">Log In to Your Account</a>'
                            . '</div>';
                    }
                }
            } catch (\Throwable $ignored) {}
        }

        $template = ecCompileEmailTemplate('customer_order_confirmation', [
            'order_number' => $orderNumber,
            'customer_name' => $customerName,
            'customer_greeting' => $customerName !== '' ? $customerName : 'there',
            'customer_email' => $customerEmail,
            'order_total' => $formattedTotal,
            'forgot_password_url' => $forgotPasswordUrl,
            'login_url' => $loginUrl,
            'my_orders_url' => $myOrdersUrl,
        ]);

        $body = ecWrapEmailTemplateHtml(
            '<h2 style="color:#2563eb;">Order Confirmation — #' . htmlspecialchars($orderNumber, ENT_QUOTES) . '</h2>'
            . $template['message_html']
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">'
            . '<tr><td style="padding:4px 8px;font-weight:600;width:40%;">Order Number</td><td style="padding:4px 8px;">#' . htmlspecialchars($orderNumber, ENT_QUOTES) . '</td></tr>'
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
            . $accountInstructions
            . '<p style="color:#6b7280;font-size:12px;margin-top:24px;">This is an automated receipt for your records.</p>'
        );

        sendEmail($customerEmail, $template['subject'], $body, $options);

    } catch (\Throwable $e) {
        write_log('Customer order confirmation email failed for order ' . $orderId . ': ' . $e->getMessage(), 'warning', [
            'module'   => 'ecommerce',
            'order_id' => $orderId,
        ]);
    }
}

app()->events()->listen('ecommerce.order.created', function (array $payload) {
    ecSendCustomerOrderConfirmation($payload);
});
