<?php

declare(strict_types=1);

/**
 * End-to-end test: digital product purchase → license generation → email delivery.
 *
 * Covers:
 *  1. Order created with digital item for noah2.omamalin@gmail.com.
 *  2. ecOrderMarkPaid() fires ecommerce.order.paid.
 *  3. Listener generates an ec_order_licenses row with a valid RS256 JWT.
 *  4. sendEmail() is called with the correct recipient and JWT in body.
 *  5. ecOrderMarkPaid() is idempotent — re-calling does not generate a second license.
 *  6. Admin mark-as-paid path also triggers email (via the same ecOrderMarkPaid).
 *
 * Host: cmsnew.test (tenant 1 — has ecommerce + digital product id=1225).
 */

$_SERVER['HTTP_HOST']  = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

// ── Stub sendEmail BEFORE any helper loads it ──────────────────────────────
// email.php is only auto-loaded via public/index.php (not bootstrap), so we
// define our capture stub here and rely on function_exists guards elsewhere.
$capturedEmails = [];

function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    global $capturedEmails;
    $capturedEmails[] = compact('to', 'subject', 'body');
    return true;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/guidance/helpers.php';

// ── Helpers ───────────────────────────────────────────────────────────────
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

function restoreTenantModuleSetting(string $moduleId, string $key, bool $hadOriginal, mixed $originalValue): void
{
    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null) {
        return;
    }

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        $table = moduleTenantSettingsTable();
        if (!$hadOriginal) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE tenant_id = :tid AND module_id = :mid AND setting_key = :skey");
            $stmt->execute([':tid' => $tenantId, ':mid' => $moduleId, ':skey' => $key]);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO {$table} (tenant_id, module_id, setting_key, setting_value, created_at, updated_at)\n"
            . "VALUES (:tid, :mid, :skey, :sval, NOW(), NOW())\n"
            . "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':mid' => $moduleId,
            ':skey' => $key,
            ':sval' => json_encode($originalValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        invalidateTenantModuleSettingsCache();
        $tid = $tenantId ?? 0;
        $GLOBALS['cms_settings_cached_t' . $tid] = false;
    }
}

// ── Clean logs ────────────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log',   '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// ── Constants ─────────────────────────────────────────────────────────────
const TEST_EMAIL   = 'noah2.omamalin@gmail.com';
const DIGITAL_PROD = 1225; // "Guidance Monitoring" — _is_digital=1, _license_module=guidance

// ── Cleanup helpers ───────────────────────────────────────────────────────
$createdOrderIds = [];

function cleanupTestOrders(array $orderIds): void
{
    if (empty($orderIds)) {
        return;
    }
    $db = ecDb();
    foreach ($orderIds as $oid) {
        $db->execute('DELETE FROM ec_order_licenses WHERE order_id = ?', [$oid]);
        $db->execute('DELETE FROM ec_order_items WHERE order_id = ?', [$oid]);
        $db->execute('DELETE FROM ec_order_meta WHERE order_id = ?', [$oid]);
        $db->execute('DELETE FROM ec_payment_transactions WHERE order_id = ?', [$oid]);
        $db->execute('DELETE FROM ec_orders WHERE id = ?', [$oid]);
    }
}

// ── Minimal order payload helper ──────────────────────────────────────────
function buildDigitalOrderData(string $email, int $productId): array
{
    return [
        'cart_items' => [
            [
                'product_id'     => $productId,
                'variant_id'     => null,
                'product_title'  => 'Guidance Monitoring',
                'sku'            => 'GUIDE-PRO-TEST',
                'price_snapshot' => 0.00,
                'qty'            => 1,
                'variant_label'  => null,
            ],
        ],
        'subtotal'        => 0.00,
        'discount_amount' => 0.00,
        'tax_amount'      => 0.00,
        'shipping_amount' => 0.00,
        'total'           => 0.00,
        'currency'        => 'PHP',
        'coupon_code'     => null,
        'shipping_rate_id' => null,
        'source'          => 'web',
        'billing'         => [
            'first_name'    => 'Noah',
            'last_name'     => 'Test',
            'email'         => $email,
            'address_line1' => '123 Test St',
            'address_line2' => '',
            'city'          => 'Manila',
            'state'         => 'Metro Manila',
            'postal_code'   => '1000',
            'country'       => 'PH',
            'phone'         => '',
        ],
        'shipping'        => [],
        'guest_email'     => $email,
        'guest_name'      => 'Noah Test',
        'customer_id'     => null,
        'customer_note'   => '',
    ];
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 1 — Order creation and license generation on payment
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 1: Order creation → mark paid → license generated ──\n";

$capturedEmails = [];
$order1Id       = 0;

try {
    $result = ecOrderCreate(buildDigitalOrderData(TEST_EMAIL, DIGITAL_PROD));
    $order1Id = (int)$result['order_id'];
    $createdOrderIds[] = $order1Id;

    t('ecOrderCreate returns order_id', $order1Id > 0, "got {$order1Id}");
    t('ecOrderCreate returns order_number', !empty($result['order_number']));
    t('ecOrderCreate returns confirmation_token', !empty($result['confirmation_token']));

    // Verify order row in DB
    $orderRow = ecDb()->query('SELECT payment_status, guest_email FROM ec_orders WHERE id = ?', [$order1Id])->fetch(PDO::FETCH_ASSOC);
    t('Order exists in ec_orders',  is_array($orderRow));
    t('Order payment_status = pending', ($orderRow['payment_status'] ?? '') === 'pending');
    t('Order guest_email matches customer', ($orderRow['guest_email'] ?? '') === TEST_EMAIL);

    // Verify order item
    $itemRow = ecDb()->query('SELECT product_id, qty FROM ec_order_items WHERE order_id = ?', [$order1Id])->fetch(PDO::FETCH_ASSOC);
    t('Order item exists', is_array($itemRow));
    t('Order item product_id matches fixture', (int)($itemRow['product_id'] ?? 0) === DIGITAL_PROD);

    // No licenses yet — event not fired
    $licensesBeforePaid = ecDb()->query('SELECT COUNT(*) FROM ec_order_licenses WHERE order_id = ?', [$order1Id])->fetchColumn();
    t('No licenses before mark-paid', (int)$licensesBeforePaid === 0);

    // ── Mark as paid (fires ecommerce.order.paid event) ──
    $capturedEmails = [];
    ecOrderMarkPaid($order1Id);

    // Verify payment_status updated
    $paidStatus = ecDb()->query('SELECT payment_status FROM ec_orders WHERE id = ?', [$order1Id])->fetchColumn();
    t('Order payment_status = paid after ecOrderMarkPaid', $paidStatus === 'paid');

    // Verify license was generated
    $license = ecDb()->query('SELECT * FROM ec_order_licenses WHERE order_id = ? LIMIT 1', [$order1Id])->fetch(PDO::FETCH_ASSOC);
    t('License row created in ec_order_licenses', is_array($license));

    if (is_array($license)) {
        t('License target_module = guidance',  ($license['target_module'] ?? '') === 'guidance');
        t('License target_tier = pro',          ($license['target_tier']  ?? '') === 'pro');
        t('License status = active',            ($license['status']       ?? '') === 'active');
        t('License customer_email = test email', trim((string)($license['customer_email'] ?? '')) === TEST_EMAIL);
        t('License download_token is 64 hex chars', strlen((string)($license['download_token'] ?? '')) === 64);

        // Validate JWT structure (3 dot-separated segments)
        $jwt     = (string)($license['license_key'] ?? '');
        $parts   = explode('.', $jwt);
        t('License key is a 3-segment JWT', count($parts) === 3, 'segments=' . count($parts));

        // Validate JWT payload content
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            t('JWT iss = ikabud_ecommerce',  ($payload['iss'] ?? '') === 'ikabud_ecommerce');
            t('JWT iss_url is present',      !empty($payload['iss_url']));
            t('JWT iss_url host = cmsnew.test', ((string)parse_url((string)($payload['iss_url'] ?? ''), PHP_URL_HOST)) === 'cmsnew.test', (string)($payload['iss_url'] ?? ''));
            t('JWT aud = guidance',           ($payload['aud'] ?? '') === 'guidance');
            t('JWT tier = pro',               ($payload['tier'] ?? '') === 'pro');
            t('JWT sub = customer email',     ($payload['sub']  ?? '') === TEST_EMAIL);
            t('JWT exp is in the future',     (int)($payload['exp'] ?? 0) > time());
            t('JWT jti is present',           !empty($payload['jti']));

            $guidanceOriginal = readTenantModuleSettings('guidance');
            $guidanceStoreHadOriginal = array_key_exists('license_store_url', $guidanceOriginal);
            $guidanceStoreOriginalValue = $guidanceOriginal['license_store_url'] ?? null;
            $guidancePublicKeyHadOriginal = array_key_exists('license_public_key_pem', $guidanceOriginal);
            $guidancePublicKeyOriginalValue = $guidanceOriginal['license_public_key_pem'] ?? null;
            $ecommerceOriginal = readTenantModuleSettings('ecommerce');
            $ecommercePublicKeyHadOriginal = array_key_exists('license_public_key_pem', $ecommerceOriginal);
            $ecommercePublicKeyOriginalValue = $ecommerceOriginal['license_public_key_pem'] ?? null;
            $bundledPublicKey = (string)(@file_get_contents(__DIR__ . '/../modules/guidance/license-key.pem') ?: '');

            try {
                saveTenantModuleSettings('ecommerce', ['license_public_key_pem' => $bundledPublicKey]);
                saveTenantModuleSettings('guidance', [
                    'license_store_url' => 'https://cmsnew.test',
                    'license_public_key_pem' => '',
                ]);
                $matchingStoreVerification = guidanceVerifyLicenseJwt($jwt);
                t('Guidance accepts JWT when issuer store matches tenant setting', !empty($matchingStoreVerification['ok']), is_array($matchingStoreVerification) ? (string)($matchingStoreVerification['error'] ?? '') : '');
                t('Guidance exposes issuer host from JWT', (($matchingStoreVerification['issuer_host'] ?? '') === 'cmsnew.test'), is_array($matchingStoreVerification) ? (string)($matchingStoreVerification['issuer_host'] ?? '') : '');
                t('Guidance can verify via issuing store ecommerce public key', (($matchingStoreVerification['key_source'] ?? '') === 'ecommerce_current_tenant_setting'), is_array($matchingStoreVerification) ? (string)($matchingStoreVerification['key_source'] ?? '') : '');

                saveTenantModuleSettings('guidance', [
                    'license_store_url' => 'https://cmsnew.test',
                    'license_public_key_pem' => $bundledPublicKey,
                ]);
                $moduleKeyVerification = guidanceVerifyLicenseJwt($jwt);
                t('Guidance accepts JWT with configured module public key override', !empty($moduleKeyVerification['ok']), is_array($moduleKeyVerification) ? (string)($moduleKeyVerification['error'] ?? '') : '');
                t('Guidance prefers the configured module public key override', (($moduleKeyVerification['key_source'] ?? '') === 'guidance_module_setting'), is_array($moduleKeyVerification) ? (string)($moduleKeyVerification['key_source'] ?? '') : '');

                saveTenantModuleSettings('guidance', [
                    'license_store_url' => 'https://different-store.test',
                    'license_public_key_pem' => '',
                ]);
                $mismatchedStoreVerification = guidanceVerifyLicenseJwt($jwt);
                t('Guidance rejects JWT when issuer store does not match tenant setting', empty($mismatchedStoreVerification['ok']), is_array($mismatchedStoreVerification) ? (string)($mismatchedStoreVerification['error'] ?? '') : '');
            } finally {
                restoreTenantModuleSetting('guidance', 'license_store_url', $guidanceStoreHadOriginal, $guidanceStoreOriginalValue);
                restoreTenantModuleSetting('guidance', 'license_public_key_pem', $guidancePublicKeyHadOriginal, $guidancePublicKeyOriginalValue);
                restoreTenantModuleSetting('ecommerce', 'license_public_key_pem', $ecommercePublicKeyHadOriginal, $ecommercePublicKeyOriginalValue);
            }

            // Verify RS256 signature with bundled public key
            $pubKeyPath = __DIR__ . '/../modules/guidance/license-key.pem';
            $pubKey     = file_exists($pubKeyPath) ? file_get_contents($pubKeyPath) : '';
            if ($pubKey !== '') {
                $sigInput   = $parts[0] . '.' . $parts[1];
                $sigDecoded = base64_decode(strtr($parts[2], '-_', '+/'));
                $verified   = openssl_verify($sigInput, $sigDecoded, $pubKey, OPENSSL_ALGO_SHA256);
                t('JWT RS256 signature verifies', $verified === 1, "openssl_verify returned {$verified}");
            } else {
                t('Public key file present', false, 'modules/guidance/license-key.pem not found');
            }
        }
    }

    // ── Email delivery ────────────────────────────────────────────────────
    t('sendEmail was called', count($capturedEmails) >= 1, 'captured=' . count($capturedEmails));

    if (!empty($capturedEmails)) {
        $mail = $capturedEmails[0];
        t('Email recipient = test email', $mail['to'] === TEST_EMAIL, "got: {$mail['to']}");
        t('Email subject contains order number', str_contains($mail['subject'], $result['order_number']));
        t('Email body contains JWT', isset($license) && is_array($license) && str_contains($mail['body'], ($license['license_key'] ?? '')));
        t('Email body contains download link', str_contains($mail['body'], '/ecommerce/download/'));
    }

} catch (Throwable $e) {
    t('Suite 1 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 2 — Idempotency: re-calling ecOrderMarkPaid does not generate extra licenses
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 2: ecOrderMarkPaid is idempotent ──\n";

$capturedEmails = [];

try {
    if ($order1Id > 0) {
        ecOrderMarkPaid($order1Id); // call again — order is already paid

        $licenseCount = (int)ecDb()->query('SELECT COUNT(*) FROM ec_order_licenses WHERE order_id = ?', [$order1Id])->fetchColumn();
        t('Second ecOrderMarkPaid does not add license rows', $licenseCount === 1, "count={$licenseCount}");
        t('Second ecOrderMarkPaid does not resend email', count($capturedEmails) === 0, 'emails_sent=' . count($capturedEmails));
    } else {
        t('Suite 2 skipped (order not created)', false, 'order_id=0');
    }
} catch (Throwable $e) {
    t('Suite 2 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 3 — Admin mark-as-paid path generates license and email for new order
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 3: Admin mark-as-paid path ──\n";

$capturedEmails = [];
$order2Id       = 0;

try {
    // Create a second order (simulates admin creating order manually, then marking paid)
    $result2  = ecOrderCreate(buildDigitalOrderData(TEST_EMAIL, DIGITAL_PROD));
    $order2Id = (int)$result2['order_id'];
    $createdOrderIds[] = $order2Id;

    t('Second order created', $order2Id > 0, "id={$order2Id}");

    // Reset captured emails to only catch the license email triggered by payment
    $capturedEmails = [];

    // Simulate admin click "Mark as Paid" — same code path as admin handler
    ecOrderMarkPaid($order2Id);

    $license2 = ecDb()->query('SELECT target_tier, license_key FROM ec_order_licenses WHERE order_id = ? LIMIT 1', [$order2Id])->fetch(PDO::FETCH_ASSOC);
    t('Admin mark-as-paid generates license', is_array($license2), "order_id={$order2Id}");

    if (is_array($license2)) {
        $parts2 = explode('.', (string)($license2['license_key'] ?? ''));
        t('Admin-path JWT has 3 segments', count($parts2) === 3);
    }

    t('Admin mark-as-paid triggers email', count($capturedEmails) >= 1, 'emails=' . count($capturedEmails));
    if (!empty($capturedEmails)) {
        t('Admin-path email to test address', $capturedEmails[0]['to'] === TEST_EMAIL);
    }

} catch (Throwable $e) {
    t('Suite 3 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Cleanup
// ══════════════════════════════════════════════════════════════════════════
cleanupTestOrders($createdOrderIds);

// ══════════════════════════════════════════════════════════════════════════
// Log validation
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Log checks ──\n";

$appLog   = file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = file_get_contents(STORAGE_PATH . '/logs/error.log');

t('No ModuleDB DENIED in app.log',  !str_contains($appLog, 'ModuleDB DENIED'));
t('No PHP fatal in error.log',      !preg_match('/PHP Fatal/', $errorLog));
t('No PHP warning in error.log',    !preg_match('/PHP Warning/', $errorLog), substr($errorLog, 0, 300) ?: 'clean');

// ══════════════════════════════════════════════════════════════════════════
// Results
// ══════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 44) . "\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
echo str_repeat('═', 44) . "\n";

if (!empty($errors)) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  • {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
