<?php

declare(strict_types=1);

/**
 * Integration test: store-context propagation through order → payment → license → email.
 *
 * Verifies that when a digital product is purchased through a specific store:
 *  1. ecOrderMarkPaid fires ecommerce.order.paid with store_id + authority_scope.
 *  2. License listener reads store-aware private key + issuer URL.
 *  3. JWT iss_url reflects the store URL, not the global tenant URL.
 *  4. ec_order_licenses.store_id is populated (when migration present).
 *  5. Admin notification routes to store-specific admin_notification_email.
 *  6. Customer confirmation reply-to uses store-specific admin_email.
 *  7. Store-communications creates an order_paid notification for the store.
 *  8. Subscription display uses per-currency symbol, not global.
 *  9. ecStoreAwareSetting resolves store → global → default correctly.
 *
 * Host: cmsnew.test (tenant 1).
 */

$_SERVER['HTTP_HOST']   = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

// ── Stub sendEmail BEFORE any helper loads it ─────────────────────────────
$capturedEmails = [];

function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    global $capturedEmails;
    $capturedEmails[] = compact('to', 'subject', 'body', 'options');
    return true;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

// Run the new migration to ensure store_id column exists on ec_order_licenses.
// Use kernel DB (app()->db()) since ModuleDB blocks DDL.
try {
    app()->db()->query('SELECT store_id FROM ec_order_licenses WHERE 1 = 0')->fetchAll();
} catch (\Throwable $e) {
    try {
        app()->db()->exec('ALTER TABLE ec_order_licenses ADD COLUMN store_id INT UNSIGNED NULL DEFAULT NULL');
    } catch (\Throwable $e2) {
        // Column may already exist — ignore
    }
    try {
        app()->db()->exec('CREATE INDEX idx_ec_order_licenses_store_id ON ec_order_licenses (store_id)');
    } catch (\Throwable $e3) {
        // Index may already exist — ignore
    }
}

// ── Test harness ──────────────────────────────────────────────────────────
$pass   = 0;
$fail   = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// ── Clean logs ────────────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// ── Constants ─────────────────────────────────────────────────────────────
const TEST_EMAIL   = 'store-license-test@example.com';
const DIGITAL_PROD = 1225; // Guidance Monitoring — _is_digital=1, _license_module=guidance

// ── Cleanup helpers ───────────────────────────────────────────────────────
$createdOrderIds = [];
$createdStoreIds = [];

function cleanupTestOrders(array $orderIds): void
{
    if (empty($orderIds)) return;
    $db = ecDb();
    foreach ($orderIds as $oid) {
        $db->execute('DELETE FROM ec_order_licenses WHERE order_id = ?', [$oid]);
        $db->execute('DELETE FROM ec_order_items WHERE order_id = ?', [$oid]);
        $db->execute('DELETE FROM ec_order_meta WHERE order_id = ?', [$oid]);
        $db->execute('DELETE FROM ec_payment_transactions WHERE order_id = ?', [$oid]);
        try { $db->execute('DELETE FROM ec_order_status_history WHERE order_id = ?', [$oid]); } catch (\Throwable $e) {}
        $db->execute('DELETE FROM ec_orders WHERE id = ?', [$oid]);
    }
}

function cleanupTestStores(array $storeIds): void
{
    if (empty($storeIds)) return;
    $db = ecDb();
    foreach ($storeIds as $sid) {
        try { $db->execute('DELETE FROM ec_store_product_overrides WHERE store_id = ?', [(int)$sid]); } catch (\Throwable $e) {}
        try { $db->execute('DELETE FROM ec_store_users WHERE store_id = ?', [(int)$sid]); } catch (\Throwable $e) {}
        try { $db->execute('DELETE FROM ec_store_inventory_sources WHERE store_id = ?', [(int)$sid]); } catch (\Throwable $e) {}
        try { $db->execute('DELETE FROM ec_store_notifications WHERE store_id = ?', [(int)$sid]); } catch (\Throwable $e) {}
        try { $db->execute('DELETE FROM ec_store_messages WHERE store_id = ?', [(int)$sid]); } catch (\Throwable $e) {}
        $db->execute('DELETE FROM ec_stores WHERE id = ?', [(int)$sid]);
    }
}

function buildDigitalOrderData(string $email, int $productId, int $storeId = 0): array
{
    $data = [
        'cart_items' => [[
            'product_id'     => $productId,
            'variant_id'     => null,
            'product_title'  => 'Guidance Monitoring',
            'sku'            => 'GUIDE-TEST',
            'price_snapshot' => 0.00,
            'qty'            => 1,
            'variant_label'  => null,
        ]],
        'subtotal'         => 0.00,
        'discount_amount'  => 0.00,
        'tax_amount'       => 0.00,
        'shipping_amount'  => 0.00,
        'total'            => 0.00,
        'currency'         => 'PHP',
        'coupon_code'      => null,
        'shipping_rate_id' => null,
        'source'           => 'web',
        'billing'          => [
            'first_name' => 'StoreTest', 'last_name' => 'User',
            'email' => $email,
            'address_line1' => '1 Test', 'city' => 'Manila',
            'state' => 'MM', 'postal_code' => '1000', 'country' => 'PH',
        ],
        'shipping'     => [],
        'guest_email'  => $email,
        'guest_name'   => 'StoreTest User',
        'customer_id'  => null,
        'customer_note' => '',
    ];

    if ($storeId > 0) {
        $data['store_id'] = $storeId;
        $data['cart_items'][0]['store_id'] = $storeId;
    }

    return $data;
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 1 — ecStoreAwareSetting resolution: store → global → default
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 1: ecStoreAwareSetting resolution ──\n";

try {
    $storeSeed = substr(bin2hex(random_bytes(4)), 0, 8);
    $storeRow = ecStoreCreate([
        'name' => 'Settings Test ' . strtoupper($storeSeed),
        'code' => 'set-test-' . $storeSeed,
        'slug' => 'set-test-' . $storeSeed,
        'description' => 'Settings resolution fixture',
        'is_active' => true,
        'is_default' => false,
    ]);
    $settingsStoreId = (int)($storeRow['id'] ?? 0);
    $createdStoreIds[] = $settingsStoreId;

    // Save a store URL override and admin_email
    ecDb()->execute(
        "UPDATE ec_stores SET settings_json = ? WHERE id = ?",
        [json_encode([
            'store_url' => 'https://my-store.example.com',
            'admin_email' => 'store-admin@example.com',
            'admin_notification_email' => 'store-notify@example.com',
            'products_per_page' => 24,
        ]), $settingsStoreId]
    );

    // Re-fetch store to get fresh settings
    $storeForSettings = ecStoreById($settingsStoreId);

    // Test: store override wins
    $storeUrl = ecStoreSetting($storeForSettings, 'store_url');
    t('ecStoreSetting returns store_url override', $storeUrl === 'https://my-store.example.com', (string)$storeUrl);

    $ppp = ecStoreAwareSetting('products_per_page', $storeForSettings, 12);
    t('ecStoreAwareSetting returns store override for products_per_page', (int)$ppp === 24, "got: {$ppp}");

    $adminEmail = ecStoreAwareSetting('admin_email', $storeForSettings, '');
    t('ecStoreAwareSetting returns store admin_email', $adminEmail === 'store-admin@example.com', (string)$adminEmail);

    // Test: falls back to global when store has no override
    $globalCurrency = ecSettings('currency');
    $resolvedCurrency = ecStoreAwareSetting('currency', $storeForSettings, 'USD');
    t('ecStoreAwareSetting falls to global for unset key', $resolvedCurrency === $globalCurrency, "store=null, global={$globalCurrency}, resolved={$resolvedCurrency}");

    // Test: falls back to default when neither store nor global has value
    $obscure = ecStoreAwareSetting('nonexistent_key_xyz', $storeForSettings, 'FALLBACK');
    t('ecStoreAwareSetting uses default when both levels empty', $obscure === 'FALLBACK', (string)$obscure);

    // Test null store → pure global
    $globalOnly = ecStoreAwareSetting('products_per_page', null, 12);
    $expectedGlobal = ecSettings('products_per_page');
    t('ecStoreAwareSetting with null store returns global', (string)$globalOnly === (string)$expectedGlobal || ($expectedGlobal === null && $globalOnly === 12));

    // Test: ec_license_store_aware_base_url
    $storeBaseUrl = ec_license_store_aware_base_url($storeForSettings);
    t('ec_license_store_aware_base_url returns store_url when set', $storeBaseUrl === 'https://my-store.example.com', $storeBaseUrl);

    $globalBaseUrl = ec_license_store_aware_base_url(null);
    t('ec_license_store_aware_base_url fallback is ec_license_public_base_url', $globalBaseUrl === ec_license_public_base_url());

} catch (\Throwable $e) {
    t('Suite 1 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 2 — Store-owned order: event payload has store context
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 2: ecommerce.order.paid event payload has store context ──\n";

$capturedPaidPayload = null;
$originalListener    = null;

// Register a one-shot listener to capture the event payload
app()->events()->listen('ecommerce.order.paid', static function (array $payload) use (&$capturedPaidPayload): void {
    $capturedPaidPayload = $payload;
});

try {
    $storeSeed2 = substr(bin2hex(random_bytes(4)), 0, 8);
    $store2 = ecStoreCreate([
        'name' => 'Event Test ' . strtoupper($storeSeed2),
        'code' => 'evt-test-' . $storeSeed2,
        'slug' => 'evt-test-' . $storeSeed2,
        'description' => 'Event payload fixture',
        'is_active' => true,
        'is_default' => false,
    ]);
    $eventStoreId = (int)($store2['id'] ?? 0);
    $createdStoreIds[] = $eventStoreId;

    // Add a store user so ecStoreNotificationCreate has a target (Suite 5 depends on this)
    $testStoreUserId = 99999;
    try {
        ecDb()->execute('INSERT INTO ec_store_users (store_id, user_id, role) VALUES (?, ?, ?)', [$eventStoreId, $testStoreUserId, 'owner']);
    } catch (\Throwable $e) { /* may already exist */ }

    $orderData = buildDigitalOrderData(TEST_EMAIL, DIGITAL_PROD, $eventStoreId);
    $result2 = ecOrderCreate($orderData);
    $eventOrderId = (int)($result2['order_id'] ?? 0);
    $createdOrderIds[] = $eventOrderId;

    $capturedPaidPayload = null;
    $capturedEmails = [];

    ecOrderMarkPaid($eventOrderId, [
        'source' => 'test_store_context',
    ]);

    t('Paid event payload captured', $capturedPaidPayload !== null);

    if ($capturedPaidPayload !== null) {
        t('Paid payload contains order_id', (int)($capturedPaidPayload['order_id'] ?? 0) === $eventOrderId);
        t('Paid payload contains store_id', (int)($capturedPaidPayload['store_id'] ?? 0) === $eventStoreId, 'got: ' . (string)($capturedPaidPayload['store_id'] ?? 'null'));
        t('Paid payload authority_scope = store', ($capturedPaidPayload['authority_scope'] ?? '') === 'store', (string)($capturedPaidPayload['authority_scope'] ?? ''));
        t('Paid payload contains currency', ($capturedPaidPayload['currency'] ?? '') !== '', (string)($capturedPaidPayload['currency'] ?? ''));
        t('Paid payload contains customer_email', ($capturedPaidPayload['customer_email'] ?? '') !== '');
        t('Paid payload store array is populated', is_array($capturedPaidPayload['store'] ?? null));
    }

    // Verify ec_order_licenses.store_id was populated
    if (ec_license_has_store_id_column()) {
        $licRow = ecDb()->query('SELECT store_id FROM ec_order_licenses WHERE order_id = ? LIMIT 1', [$eventOrderId])->fetch(PDO::FETCH_ASSOC);
        if (is_array($licRow)) {
            t('License row has store_id = event store', (int)($licRow['store_id'] ?? 0) === $eventStoreId, 'got: ' . (string)($licRow['store_id'] ?? 'null'));
        } else {
            t('License row was created for store-owned order', false, 'no license found');
        }
    } else {
        echo "  ⊘ ec_order_licenses.store_id column not yet migrated — skipping column assertion\n";
    }

} catch (\Throwable $e) {
    t('Suite 2 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 3 — Store-specific JWT issuer URL in license
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 3: License JWT iss_url reflects store URL ──\n";

try {
    $storeSeed3 = substr(bin2hex(random_bytes(4)), 0, 8);
    $store3 = ecStoreCreate([
        'name' => 'License URL Test ' . strtoupper($storeSeed3),
        'code' => 'lic-url-' . $storeSeed3,
        'slug' => 'lic-url-' . $storeSeed3,
        'description' => 'License issuer URL fixture',
        'is_active' => true,
        'is_default' => false,
    ]);
    $licStoreId = (int)($store3['id'] ?? 0);
    $createdStoreIds[] = $licStoreId;

    // Set store_url in settings_json
    ecDb()->execute(
        "UPDATE ec_stores SET settings_json = ? WHERE id = ?",
        [json_encode(['store_url' => 'https://custom-store.example.com']), $licStoreId]
    );

    $orderData3 = buildDigitalOrderData(TEST_EMAIL, DIGITAL_PROD, $licStoreId);
    $result3 = ecOrderCreate($orderData3);
    $licOrderId = (int)($result3['order_id'] ?? 0);
    $createdOrderIds[] = $licOrderId;

    $capturedEmails = [];
    ecOrderMarkPaid($licOrderId);

    $licRow3 = ecDb()->query('SELECT license_key FROM ec_order_licenses WHERE order_id = ? LIMIT 1', [$licOrderId])->fetch(PDO::FETCH_ASSOC);

    if (is_array($licRow3) && !empty($licRow3['license_key'])) {
        $parts = explode('.', (string)$licRow3['license_key']);
        t('JWT has 3 segments', count($parts) === 3);

        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $issUrl = (string)($payload['iss_url'] ?? '');

            t('JWT iss_url uses store URL', $issUrl === 'https://custom-store.example.com', "got: {$issUrl}");
            t('JWT iss_url does NOT use global tenant host', !str_contains($issUrl, 'cmsnew.test'), "global host found in: {$issUrl}");
        }

        // Verify the license delivery email download links also use the store URL
        if (!empty($capturedEmails)) {
            $emailBody = (string)$capturedEmails[0]['body'];
            t('License email download link uses store URL', str_contains($emailBody, 'https://custom-store.example.com/ecommerce/download/'), substr($emailBody, 0, 300));
        } else {
            t('License email was sent', false, 'no emails captured');
        }
    } else {
        t('License generated for store order with custom URL', false, 'no license row');
    }

} catch (\Throwable $e) {
    t('Suite 3 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 4 — Admin notification routes to store admin email
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 4: Admin notification uses store-specific email ──\n";

try {
    $storeSeed4 = substr(bin2hex(random_bytes(4)), 0, 8);
    $store4 = ecStoreCreate([
        'name' => 'Notification Test ' . strtoupper($storeSeed4),
        'code' => 'notif-' . $storeSeed4,
        'slug' => 'notif-' . $storeSeed4,
        'description' => 'Notification routing fixture',
        'is_active' => true,
        'is_default' => false,
    ]);
    $notifStoreId = (int)($store4['id'] ?? 0);
    $createdStoreIds[] = $notifStoreId;

    ecDb()->execute(
        "UPDATE ec_stores SET settings_json = ? WHERE id = ?",
        [json_encode([
            'admin_notification_email' => 'store-orders@example.com',
            'admin_email' => 'store-reply@example.com',
        ]), $notifStoreId]
    );

    $orderData4 = buildDigitalOrderData(TEST_EMAIL, DIGITAL_PROD, $notifStoreId);
    $result4 = ecOrderCreate($orderData4);
    $notifOrderId = (int)($result4['order_id'] ?? 0);
    $createdOrderIds[] = $notifOrderId;

    // Manually invoke notification functions to test store routing
    $capturedEmails = [];

    $order4 = ecOrderGet($notifOrderId);
    $notifPayload = [
        'order_id'     => $notifOrderId,
        'order_number' => $order4['order_number'] ?? '',
        'total'        => (float)($order4['total'] ?? 0),
        'source'       => 'web',
    ];

    // Test admin notification
    ecSendAdminOrderNotification($notifPayload);

    $adminNotifEmails = array_filter($capturedEmails, fn($e) => str_contains($e['to'], 'store-orders@example.com'));
    t('Admin notification routed to store admin email', count($adminNotifEmails) > 0, 'captured: ' . implode(', ', array_column($capturedEmails, 'to')));

    // Test customer notification reply-to
    $capturedEmails = [];
    ecSendCustomerOrderConfirmation($notifPayload);

    $customerEmails = array_filter($capturedEmails, fn($e) => $e['to'] === TEST_EMAIL);
    if (!empty($customerEmails)) {
        $firstCustomerEmail = array_values($customerEmails)[0];
        $replyTo = (string)($firstCustomerEmail['options']['reply_to'] ?? '');
        t('Customer email reply-to is store admin_email', $replyTo === 'store-reply@example.com', "reply_to={$replyTo}");
    } else {
        t('Customer notification email was sent', count($customerEmails) > 0, 'none captured for ' . TEST_EMAIL);
    }

} catch (\Throwable $e) {
    t('Suite 4 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 5 — Store-communications paid-order notification
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 5: Store-communications creates paid notification ──\n";

try {
    // Check if store_id from Suite 2 got a paid notification (requires store user added in Suite 2)
    if (isset($eventStoreId) && $eventStoreId > 0 && isset($testStoreUserId) && function_exists('ecStoreNotificationList')) {
        $notifResult = ecStoreNotificationList($eventStoreId, $testStoreUserId, 50);
        $items = is_array($notifResult['items'] ?? null) ? $notifResult['items'] : [];
        $paidNotifs = array_filter($items, fn($n) => ($n['type'] ?? '') === 'order_paid');
        t('Store received order_paid notification', count($paidNotifs) > 0, 'count=' . count($paidNotifs));
    } else {
        echo "  ⊘ Skipped: no store or ecStoreNotificationList unavailable\n";
    }
} catch (\Throwable $e) {
    t('Suite 5 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 6 — Subscription currency display uses per-subscription currency
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 6: Subscription display currency ──\n";

try {
    if (function_exists('ecSubscriptionNormalizeRow')) {
        $fakeRow = [
            'id' => 1,
            'customer_id' => 1,
            'product_id' => 1,
            'order_id' => 1,
            'status' => 'active',
            'recurring_amount' => 29.99,
            'currency' => 'EUR',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'trial_days' => 0,
            'max_cycles' => 0,
            'grace_period_days' => 0,
            'renewal_count' => 0,
            'next_billing_at' => date('Y-m-d H:i:s', time() + 86400),
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $normalized = ecSubscriptionNormalizeRow($fakeRow);
        $formatted = (string)($normalized['recurring_amount_fmt'] ?? '');
        $eurSymbol = ecCurrencySymbolFor('EUR');

        t('Subscription format uses per-row currency symbol', str_starts_with($formatted, $eurSymbol), "expected {$eurSymbol}... got: {$formatted}");
        t('Subscription format does NOT use global symbol for EUR row', !str_starts_with($formatted, (string)ecSettings('currency_symbol')) || ecSettings('currency_symbol') === $eurSymbol, "formatted={$formatted}, global=" . ecSettings('currency_symbol'));
    } else {
        echo "  ⊘ ecSubscriptionNormalizeRow not available\n";
    }
} catch (\Throwable $e) {
    t('Suite 6 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 7 — ecStoreSettingsJsonFromInput accepts new fields
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 7: ecStoreSettingsJsonFromInput processes new fields ──\n";

try {
    $json = ecStoreSettingsJsonFromInput([
        'setting_admin_email' => 'admin@store.com',
        'setting_admin_notification_email' => 'notify@store.com',
        'setting_store_url' => 'https://store.example.com',
        'setting_license_private_key_pem' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----",
    ]);

    $parsed = json_decode((string)$json, true);
    t('admin_email saved in settings_json', ($parsed['admin_email'] ?? '') === 'admin@store.com');
    t('admin_notification_email saved in settings_json', ($parsed['admin_notification_email'] ?? '') === 'notify@store.com');
    t('store_url saved in settings_json', ($parsed['store_url'] ?? '') === 'https://store.example.com');
    t('license_private_key_pem saved in settings_json', str_contains((string)($parsed['license_private_key_pem'] ?? ''), 'BEGIN RSA PRIVATE KEY'));
} catch (\Throwable $e) {
    t('Suite 7 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 8 — Global order (no store) still works correctly
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 8: Global (non-store) order path unbroken ──\n";

try {
    $globalData = buildDigitalOrderData('global-test@example.com', DIGITAL_PROD);
    $resultG = ecOrderCreate($globalData);
    $globalOrderId = (int)($resultG['order_id'] ?? 0);
    $createdOrderIds[] = $globalOrderId;

    $globalPaidPayload = null;
    app()->events()->listen('ecommerce.order.paid', static function (array $payload) use (&$globalPaidPayload): void {
        $globalPaidPayload = $payload;
    });

    $capturedEmails = [];

    ecOrderMarkPaid($globalOrderId);

    // ecOrderCreate may assign a default store via ecStoreResolveContext(), so
    // instead of asserting store_id=0 we verify the payload is consistent with
    // what ecOrderOperationalAuthority resolves for the actual order record.
    $globalAuthority = ecOrderOperationalAuthority($globalOrderId);
    $expectedGlobalStoreId = (int)($globalAuthority['store_id'] ?? 0);
    $expectedGlobalScope   = (string)($globalAuthority['scope'] ?? 'global');

    t('Non-explicit-store order paid payload store_id matches authority', (int)($globalPaidPayload['store_id'] ?? -1) === $expectedGlobalStoreId, "expected={$expectedGlobalStoreId}, got=" . (string)($globalPaidPayload['store_id'] ?? 'null'));
    t('Non-explicit-store order paid payload authority_scope matches authority', ($globalPaidPayload['authority_scope'] ?? '') === $expectedGlobalScope, "expected={$expectedGlobalScope}, got=" . (string)($globalPaidPayload['authority_scope'] ?? 'null'));

    $globalLic = ecDb()->query('SELECT license_key FROM ec_order_licenses WHERE order_id = ? LIMIT 1', [$globalOrderId])->fetch(PDO::FETCH_ASSOC);
    t('Global order still generates license', is_array($globalLic) && !empty($globalLic['license_key']));

    if (is_array($globalLic) && !empty($globalLic['license_key'])) {
        $parts = explode('.', (string)$globalLic['license_key']);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $issUrl = (string)($payload['iss_url'] ?? '');
            // If a default store was auto-assigned with a store_url, the iss_url may use that; otherwise it uses the tenant host.
            $expectedBaseUrl = ec_license_store_aware_base_url($globalAuthority['store'] ?? null);
            t('Non-explicit-store order JWT iss_url uses correct base', str_starts_with($issUrl, $expectedBaseUrl) || $issUrl === $expectedBaseUrl, "iss_url={$issUrl}, expected_base={$expectedBaseUrl}");
        }
    }

    t('Global order still sends license email', count($capturedEmails) >= 1, 'emails=' . count($capturedEmails));
} catch (\Throwable $e) {
    t('Suite 8 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Cleanup
// ══════════════════════════════════════════════════════════════════════════
cleanupTestOrders($createdOrderIds);
cleanupTestStores($createdStoreIds);

// ══════════════════════════════════════════════════════════════════════════
// Log validation
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Log checks ──\n";

$appLog   = (string)file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = (string)file_get_contents(STORAGE_PATH . '/logs/error.log');

t('No ModuleDB DENIED in app.log', !str_contains($appLog, 'ModuleDB DENIED'));
t('No PHP fatal in error.log', !preg_match('/PHP Fatal/', $errorLog));
t('No PHP warning in error.log', !preg_match('/PHP Warning/', $errorLog), substr($errorLog, 0, 300) ?: 'clean');

// ══════════════════════════════════════════════════════════════════════════
// Results
// ══════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 56) . "\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
echo str_repeat('═', 56) . "\n";

if (!empty($errors)) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  • {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
