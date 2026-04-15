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

function ec_license_public_base_url(): string
{
    $configured = trim((string)app()->config('app.url', ''));
    $configuredPath = rtrim((string)parse_url($configured, PHP_URL_PATH), '/');
    $host = \Ikabud\Kernel\TenantResolver::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host !== '') {
        return rtrim(request_scheme() . '://' . $host . $configuredPath, '/');
    }

    return rtrim($configured, '/');
}

/**
 * Resolve the license issuer base URL with store awareness.
 * If the store has a `store_url` override in settings_json it takes precedence,
 * otherwise falls back to the tenant default (ec_license_public_base_url).
 */
function ec_license_store_aware_base_url(array|null $store): string
{
    if (is_array($store) && function_exists('ecStoreSetting')) {
        $storeUrl = trim((string)ecStoreSetting($store, 'store_url'));
        if ($storeUrl !== '') {
            return rtrim($storeUrl, '/');
        }
    }

    return ec_license_public_base_url();
}

/**
 * Check whether ec_order_licenses has the store_id column.
 */
function ec_license_has_store_id_column(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        ecDb()->query('SELECT store_id FROM ec_order_licenses WHERE 1 = 0');
        $has = true;
    } catch (\Throwable $e) {
        $has = false;
    }
    return $has;
}

function ecOrderLicenseFindById(int $licenseId): ?array
{
    if ($licenseId <= 0) {
        return null;
    }

    $row = ecDb()->query(
        "SELECT id, order_id, order_item_id, product_id, customer_email, target_module, target_tier, license_key, status, download_token, downloaded_at
           FROM ec_order_licenses WHERE id = ? LIMIT 1",
        [$licenseId]
    )->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function ecOutputOrderLicenseDownload(array $license): never
{
    $productId = (int)($license['product_id'] ?? 0);
    if ($productId > 0) {
        $filePath = (string)(ecDb()->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_download_file_path' LIMIT 1",
            [$productId]
        )->fetchColumn() ?: '');
        $fileName = (string)(ecDb()->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_download_file_name' LIMIT 1",
            [$productId]
        )->fetchColumn() ?: '');

        if ($filePath !== '') {
            $storagePath = STORAGE_PATH . '/digital/' . ltrim($filePath, '/');
            if (is_file($storagePath) && is_readable($storagePath)) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($storagePath);
                $safeFile = $fileName !== '' ? basename($fileName) : basename($filePath);
                $safeFile = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $safeFile) ?: 'download';

                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . $safeFile . '"');
                header('Content-Length: ' . filesize($storagePath));
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('X-Content-Type-Options: nosniff');
                readfile($storagePath);
                exit;
            }
            write_log('ecOutputOrderLicenseDownload: file missing on disk: ' . $storagePath, 'warning', ['module' => 'ecommerce']);
        }
    }

    $module = preg_replace('/[^a-z0-9_\-]/i', '', (string)($license['target_module'] ?? 'module'));
    $filename = 'license-' . $module . '.jwt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo (string)($license['license_key'] ?? '');
    exit;
}

// Hook into order paid
app()->events()->listen('ecommerce.order.paid', function (array $payload) {
    $orderId = (int)($payload['order_id'] ?? 0);
    if ($orderId <= 0) return;

    // Resolve store context from event payload (enriched by ecOrderMarkPaid).
    $storeId = (int)($payload['store_id'] ?? 0);
    $store   = ($storeId > 0 && function_exists('ecStoreById')) ? ecStoreById($storeId) : null;

    // Load ecommerce settings — prefer store-specific private key, fall back to global.
    $settings = isset($_ENV['TEST_TENANT_ID']) ? \readTenantModuleSettingsForTenant('ecommerce', (int)$_ENV['TEST_TENANT_ID']) : \readTenantModuleSettings('ecommerce');
    $privateKey = function_exists('ecStoreAwareSetting')
        ? trim((string)ecStoreAwareSetting('license_private_key_pem', $store, ''))
        : trim((string)($settings['license_private_key_pem'] ?? ''));
    if ($privateKey === '') {
        return; // Neither store nor global has a key to sign licenses.
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

        // Use store-specific base URL for issuer when available, otherwise tenant default.
        $issuerUrl  = ec_license_store_aware_base_url($store);

        if (empty($items)) return;

        $db         = ecDb();
        $issuedKeys = [];
        
        $productIds = array_values(array_unique(array_filter(array_map(static fn($i) => (int)($i['product_id'] ?? 0), $items))));
        $allMeta = [];
        if (!empty($productIds)) {
            $idsCsv = implode(',', array_fill(0, count($productIds), '?'));
            $metaStmt = $db->query(
                "SELECT content_id, meta_key, meta_value FROM cms_content_meta WHERE content_id IN ($idsCsv) AND meta_key IN ('_is_digital', '_license_module', '_license_tier', '_license_duration_days')",
                $productIds
            );
            $metaRows = $metaStmt ? $metaStmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            foreach ($metaRows as $row) {
                $allMeta[(int)$row['content_id']][$row['meta_key']] = $row['meta_value'];
            }
        }

        foreach ($items as $item) {
            $prodId = (int)$item['product_id'];
            
            $meta = $allMeta[$prodId] ?? [];

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

                if ($issuerUrl !== '') {
                    $jwtPayload['iss_url'] = $issuerUrl;
                }

                $licenseKey = ec_license_generate_jwt($jwtPayload, $privateKey);

                if ($licenseKey === '') continue;

                $downloadToken = bin2hex(random_bytes(32));

                // Insert into ec_order_licenses (include store_id when column exists)
                $hasStoreIdCol = ec_license_has_store_id_column();
                if ($hasStoreIdCol && $storeId > 0) {
                    $db->execute(
                        'INSERT INTO ec_order_licenses (order_id, order_item_id, customer_email, customer_id, product_id, target_module, target_tier, license_key, download_token, status, store_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                            $storeId,
                        ]
                    );
                } else {
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
                }

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
            $baseUrl  = ec_license_store_aware_base_url($store);
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

            // Detect whether a fresh customer account was auto-created during this checkout
            // (created within the last 10 minutes) so we can include a "set your password" CTA.
            $accountSetupSection = '';
            if (function_exists('cmsDb')) {
                try {
                    $cmDb = cmsDb();
                    $accRow = $cmDb->query(
                    "SELECT id, created_at FROM cms_users WHERE email = ? AND is_active = 1 LIMIT 1",
                    [$email]
                )->fetch(\PDO::FETCH_ASSOC);

                if ($accRow) {
                    $userId = (int)$accRow['id'];
                    $createdAt = strtotime($accRow['created_at'] ?? 'now');

                    if (time() - $createdAt < 600) {
                        $rawToken = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $rawToken);
                        $cmDb->execute(
                            'INSERT INTO cms_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 72 HOUR), NOW())',
                            [$userId, $tokenHash, '127.0.0.1']
                        );
                        $setupUrl = $baseUrl . '/cms/reset-password?token=' . urlencode($rawToken);
                        $accountSetupSection = '<div style="margin-top:24px;padding:16px 20px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;">'
                            . '<h3 style="margin:0 0 8px;color:#15803d;font-size:15px;">Your account is ready</h3>'
                            . '<p style="margin:0 0 12px;font-size:13px;color:#374151;">We automatically created a customer account for <strong>' . htmlspecialchars($email, ENT_QUOTES) . '</strong> so you can access your orders and licenses at any time.</p>'
                            . '<p style="margin:0 0 12px;font-size:13px;color:#374151;">Set a password to activate your account:</p>'
                            . '<a href="' . htmlspecialchars($setupUrl, ENT_QUOTES) . '" style="display:inline-block;padding:10px 20px;background:#15803d;color:#fff;border-radius:5px;text-decoration:none;font-weight:600;font-size:13px;">Set Your Password</a>'
                            . '<p style="margin:12px 0 0;font-size:12px;color:#6b7280;">This link expires in 72 hours. You can always use <a href="' . htmlspecialchars($baseUrl . '/cms/forgot-password', ENT_QUOTES) . '" style="color:#ea580c;">Forgot Password</a> to generate a new one.</p>'
                            . '</div>';
                    } else {
                        $loginUrl = $baseUrl . '/cms/login?redirect=' . urlencode('/ecommerce/my-orders');
                        $accountSetupSection = '<div style="margin-top:24px;padding:16px 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">'
                            . '<h3 style="margin:0 0 8px;color:#334155;font-size:15px;">Access your digital items anytime</h3>'
                            . '<p style="margin:0 0 12px;font-size:13px;color:#475569;">You can log in to your account at <strong>' . htmlspecialchars($email, ENT_QUOTES) . '</strong> to view your licenses and order history at your convenience.</p>'
                            . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES) . '" style="display:inline-block;padding:10px 20px;background:#3b82f6;color:#fff;border-radius:5px;text-decoration:none;font-weight:600;font-size:13px;">Log In to Your Account</a>'
                            . '</div>';
                    }
                }
            } catch (\Throwable $ignored) {
                // Non-fatal: email still sends without the account section
            }
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
                . $accountSetupSection
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
function ecOrderLicenseRegenerate(int $licenseId): bool
{
    $db = ecDb();
    $row = $db->query("SELECT id, order_id, customer_email, target_module, target_tier FROM ec_order_licenses WHERE id = ? LIMIT 1", [$licenseId])->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return false;

    // Resolve store context from the order for store-aware settings + issuer URL.
    $orderId = (int)($row['order_id'] ?? 0);
    $store = null;
    if ($orderId > 0 && function_exists('ecOrderOperationalAuthority')) {
        $authority = ecOrderOperationalAuthority($orderId);
        $store = is_array($authority['store'] ?? null) ? $authority['store'] : null;
    }

    // Load ecommerce settings — prefer store-specific private key, fall back to global.
    $settings = isset($_ENV['TEST_TENANT_ID']) ? \readTenantModuleSettingsForTenant('ecommerce', (int)$_ENV['TEST_TENANT_ID']) : \readTenantModuleSettings('ecommerce');
    $privateKey = function_exists('ecStoreAwareSetting')
        ? trim((string)ecStoreAwareSetting('license_private_key_pem', $store, ''))
        : trim((string)($settings['license_private_key_pem'] ?? ''));
    if ($privateKey === '') {
        return false; // Neither store nor global has a key to sign licenses.
    }

    $email = trim((string)($row['customer_email'] ?? ''));
    $targetModule = trim((string)$row['target_module']);
    $targetTier   = trim((string)($row['target_tier'] ?? 'pro'));
    $issuerUrl  = ec_license_store_aware_base_url($store);

    // Default 1-year duration
    $expiresAt = time() + (365 * 86400);

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

    if ($issuerUrl !== '') {
        $jwtPayload['iss_url'] = $issuerUrl;
    }

    $licenseKey = ec_license_generate_jwt($jwtPayload, $privateKey);

    if ($licenseKey === '') return false;

    $downloadToken = bin2hex(random_bytes(32));

    $db->execute("UPDATE ec_order_licenses SET license_key = ?, download_token = ?, downloaded_at = NULL WHERE id = ?", [
        $licenseKey,
        $downloadToken,
        $licenseId
    ]);

    return true;
}
