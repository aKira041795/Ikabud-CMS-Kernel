<?php

declare(strict_types=1);

/**
 * ── Digital Software Licenses ─────────────────────────────────────
 * Generates offline-verifiable JSON Web Tokens (JWT) using Ed25519 or
 * HMAC-SHA256 when a customer purchases a digital module entitlement.
 */

function ec_license_generate_jwt(array $payload, string $privateKeyPem): string
{
    $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
    $payloadJson = json_encode($payload);

    if ($header === false || $payloadJson === false) {
        return '';
    }

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadJson));

    $signature = '';
    $success = openssl_sign(
        $base64UrlHeader . '.' . $base64UrlPayload,
        $signature,
        $privateKeyPem,
        OPENSSL_ALGO_SHA256
    );

    if (!$success) {
        return '';
    }

    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
}

// Hook into order paid
app()->events()->listen('ecommerce.order.paid', function (array $payload) {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0) return;

    // Load ecommerce settings to check if digital fulfillment is configured
    $settings = isset($_ENV['TEST_TENANT_ID']) ? \readTenantModuleSettingsForTenant('ecommerce', (int)$_ENV['TEST_TENANT_ID']) : \readTenantModuleSettings('ecommerce');
    $privateKey = trim((string)($settings['license_private_key_pem'] ?? ''));
    if ($privateKey === '') {
        return; // Store hasn't configured a key to sign licenses.
    }

    try {
        // ecOrderGet() hydrates customer_email from billing meta — raw SELECT * gives only guest_email
        $order = ecOrderGet($orderId);
        if (!$order) return;

        // Idempotency: skip if licenses were already generated for this order
        if (!empty($order['licenses'])) return;

        $email      = trim((string)($order['customer_email'] ?? ''));
        $customerId = isset($order['customer_id']) ? (int)$order['customer_id'] : null;
        $items      = $order['items'] ?? [];

        if (empty($items)) return;

        $db         = ecDb();
        $issuedKeys = [];

        foreach ($items as $item) {
            $prodId = (int)$item['product_id'];

            // Query product metadata to check if it's a digital software license
            $metaRows = $db->query(
                "SELECT meta_key, meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key IN ('_is_digital', '_license_module', '_license_tier', '_license_duration_days')",
                [$prodId]
            )->fetchAll(\PDO::FETCH_ASSOC);

            $meta = [];
            foreach ($metaRows as $row) {
                $meta[$row['meta_key']] = $row['meta_value'];
            }

            if (empty($meta['_is_digital']) || $meta['_is_digital'] !== '1' || empty($meta['_license_module'])) {
                continue; // Not a digital software product
            }

            $targetModule = trim((string)$meta['_license_module']);
            $targetTier   = trim((string)($meta['_license_tier'] ?? 'pro'));
            $durationDays = (int)($meta['_license_duration_days'] ?? 365);

            $qty = max(1, (int)(array_key_exists('qty', $item) ? $item['qty'] : ($item['quantity'] ?? 1)));

            for ($i = 0; $i < $qty; $i++) {
                $expiresAt = time() + ($durationDays * 86400);

                // Build JWT payload
                $jwtPayload = [
                    'iss'  => 'ikabud_ecommerce',
                    'sub'  => $email,
                    'aud'  => $targetModule,
                    'tier' => $targetTier,
                    'iat'  => time(),
                    'exp'  => $expiresAt,
                    'jti'  => bin2hex(random_bytes(16)),
                ];

                $licenseKey = ec_license_generate_jwt($jwtPayload, $privateKey);

                if ($licenseKey === '') continue;

                $downloadToken = bin2hex(random_bytes(32));

                // Insert into ec_order_licenses
                $db->execute(
                    'INSERT INTO ec_order_licenses (order_id, order_item_id, customer_email, customer_id, product_id, target_module, target_tier, license_key, download_token, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $orderId,
                        (int)$item['id'],
                        $email,
                        $customerId,
                        $prodId,
                        $targetModule,
                        $targetTier,
                        $licenseKey,
                        $downloadToken,
                        'active',
                    ]
                );

                $issuedKeys[] = [
                    'module'         => $targetModule,
                    'tier'           => $targetTier,
                    'key'            => $licenseKey,
                    'download_token' => $downloadToken,
                ];
            }
        }

        // Send license delivery email if any keys were issued and email is configured.
        if (!empty($issuedKeys) && $email !== '' && function_exists('sendEmail')) {
            $baseUrl  = rtrim((string)app()->config('app.url', ''), '/');
            $orderNum = (string)($order['order_number'] ?? '');

            $rows = '';
            foreach ($issuedKeys as $k) {
                $downloadUrl = $baseUrl . '/ecommerce/download/' . $k['download_token'];
                $rows .= '<tr>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">'
                    . htmlspecialchars($k['module'], ENT_QUOTES) . ' &mdash; ' . htmlspecialchars($k['tier'], ENT_QUOTES)
                    . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-family:monospace;font-size:11px;word-break:break-all;">'
                    . htmlspecialchars($k['key'], ENT_QUOTES)
                    . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">'
                    . '<a href="' . htmlspecialchars($downloadUrl, ENT_QUOTES) . '" style="color:#ea580c;">Download</a>'
                    . '</td>'
                    . '</tr>';
            }

            $body = '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#374151;max-width:600px;margin:auto;">'
                . '<h2 style="color:#ea580c;">Your Digital License(s) for Order #' . htmlspecialchars($orderNum, ENT_QUOTES) . '</h2>'
                . '<p>Thank you for your purchase! Your license key(s) are ready.</p>'
                . '<table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;">'
                . '<thead><tr style="background:#f9fafb;">'
                . '<th style="padding:8px 12px;text-align:left;font-size:12px;text-transform:uppercase;color:#6b7280;">Product</th>'
                . '<th style="padding:8px 12px;text-align:left;font-size:12px;text-transform:uppercase;color:#6b7280;">License Key (JWT)</th>'
                . '<th style="padding:8px 12px;text-align:left;font-size:12px;text-transform:uppercase;color:#6b7280;">Download</th>'
                . '</tr></thead>'
                . '<tbody>' . $rows . '</tbody>'
                . '</table>'
                . '<p style="margin-top:20px;font-size:13px;color:#6b7280;">You can also access your licenses any time from your <a href="' . htmlspecialchars($baseUrl . '/ecommerce/my-orders', ENT_QUOTES) . '" style="color:#ea580c;">account order history</a>.</p>'
                . '</body></html>';

            sendEmail($email, 'Your Digital License(s) – Order #' . $orderNum, $body);
        }

    } catch (Throwable $e) {
        write_log('Failed to generate digital license for order ' . $orderId . ': ' . $e->getMessage(), 'error', [
            'order_id' => $orderId,
            'exception' => $e->getMessage()
        ]);
    }
});