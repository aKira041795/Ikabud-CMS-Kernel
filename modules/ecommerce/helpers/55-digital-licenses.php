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

    $db = app()->db();
    if (!$db) return;

    // Load ecommerce settings to check if digital fulfillment is configured
    $settings = isset($_ENV['TEST_TENANT_ID']) ? \readTenantModuleSettingsForTenant('ecommerce', (int)$_ENV['TEST_TENANT_ID']) : \readTenantModuleSettings('ecommerce');
    $privateKey = trim((string)($settings['license_private_key_pem'] ?? ''));
    if ($privateKey === '') {
        return; // Store hasn't configured a key to sign licenses.
    }

    try {
        // Fetch order details and items
        $stmt = $db->prepare('SELECT * FROM ec_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return;

        $email = trim((string)($order['customer_email'] ?? ''));

        $stmtItems = $db->prepare('SELECT * FROM ec_order_items WHERE order_id = ?');
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) return;

        $issuedKeys = [];

        foreach ($items as $item) {
            $prodId = (int)$item['product_id'];

            // Query product metadata to check if it's a digital software license
            $stmtMeta = $db->prepare("SELECT meta_key, meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key IN ('_is_digital', '_license_module', '_license_tier', '_license_duration_days')");
            $stmtMeta->execute([$prodId]);
            $metaRows = $stmtMeta->fetchAll(PDO::FETCH_ASSOC);

            $meta = [];
            foreach ($metaRows as $row) {
                $meta[$row['meta_key']] = $row['meta_value'];
            }

            if (empty($meta['_is_digital']) || $meta['_is_digital'] !== '1' || empty($meta['_license_module'])) {
                continue; // Not a digital software product
            }

            $targetModule = trim((string)$meta['_license_module']);
            $targetTier = trim((string)($meta['_license_tier'] ?? 'pro'));
            $durationDays = (int)($meta['_license_duration_days'] ?? 365);

            $qty = max(1, (int)$item['quantity']);

            for ($i = 0; $i < $qty; $i++) {
                $expiresAt = time() + ($durationDays * 86400);

                // Build JWT payload
                $jwtPayload = [
                    'iss' => 'ikabud_ecommerce',
                    'sub' => $email,
                    'aud' => $targetModule,
                    'tier' => $targetTier,
                    'iat' => time(),
                    'exp' => $expiresAt,
                    'jti' => bin2hex(random_bytes(16)) // Unique token ID
                ];

                $licenseKey = ec_license_generate_jwt($jwtPayload, $privateKey);

                if ($licenseKey === '') continue;

                // Insert into ec_order_licenses
                $stmtInsert = $db->prepare('INSERT INTO ec_order_licenses (order_id, order_item_id, customer_email, target_module, target_tier, license_key, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmtInsert->execute([
                    $orderId,
                    (int)$item['id'],
                    $email,
                    $targetModule,
                    $targetTier,
                    $licenseKey,
                    'active'
                ]);

                $issuedKeys[] = [
                    'module' => $targetModule,
                    'tier' => $targetTier,
                    'key' => $licenseKey
                ];
            }
        }

        // Ideally, if keys were issued, the system would immediately send an email with $issuedKeys to $email.
        // Currently, we just persist them for fulfillment.

    } catch (Throwable $e) {
        write_log('Failed to generate digital license for order ' . $orderId . ': ' . $e->getMessage(), 'error', [
            'order_id' => $orderId,
            'exception' => $e->getMessage()
        ]);
    }
});