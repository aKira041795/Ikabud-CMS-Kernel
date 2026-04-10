<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/checkout';

$capturedEmails = [];

function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    global $capturedEmails;
    $capturedEmails[] = compact('to', 'subject', 'body', 'options');
    return true;
}

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$migration = __DIR__ . '/../modules/ecommerce/database/migrations/018_ec_abandoned_carts.sql';
if (is_file($migration)) {
    app()->db()->exec((string)file_get_contents($migration));
}

$pass = 0;
$fail = 0;
$errors = [];
$fixtureIds = [];
$originalSettings = getModuleSettings('ecommerce');

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

function ecommerceAbandonedCartApplySettings(array $settings): void
{
    saveModuleSettings('ecommerce', $settings);
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }
}

function cleanupEcommerceAbandonedCartFixtures(array $ids): void
{
    if ($ids !== []) {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        app()->db()->prepare("DELETE FROM ec_abandoned_carts WHERE id IN ({$placeholders})")->execute($ids);
    }

    ecSessionCartClear();
    ecAbandonedCartClearRecoveryToken();
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$settings = $originalSettings;
$settings['abandoned_cart_enabled'] = true;
$settings['abandoned_cart_first_delay_hours'] = '1';
$settings['abandoned_cart_second_delay_hours'] = '24';
$settings['abandoned_cart_third_delay_hours'] = '72';
$settings['email_tpl_abandoned_cart_subject'] = 'Return to finish your order';
$settings['email_tpl_abandoned_cart_message'] = "Hi {customer_greeting},\n\nYour cart still has {cart_item_count} item(s) waiting. Use {recovery_url} to return.";
ecommerceAbandonedCartApplySettings($settings);

echo "\n=== ECOMMERCE ABANDONED CARTS ===\n";

app()->db()->prepare('DELETE FROM ec_abandoned_carts WHERE guest_email = ?')->execute(['recover@example.test']);
ecSessionCartClear();
ecAbandonedCartClearRecoveryToken();

$cart = [
    'items' => [[
        'product_id' => 321,
        'variant_id' => null,
        'qty' => 2,
        'price_snapshot' => 64.75,
        'product_title' => 'Recovery Fixture Product',
        'sku' => 'REC-321',
    ]],
    'coupon_code' => 'SAVE10',
    'totals' => [
        'subtotal' => 129.50,
        'total' => 119.50,
        'item_count' => 2,
    ],
];

$record = ecAbandonedCartCaptureLead([
    'guest_email' => 'recover@example.test',
    'first_name' => 'Recover',
    'last_name' => 'Tester',
], $cart);

if ($record) {
    $fixtureIds[] = (int)$record['id'];
}

$recordSnapshot = is_array($record) ? (array)($record['cart_snapshot'] ?? []) : [];
$recordToken = is_array($record) ? (string)($record['recovery_token'] ?? '') : '';
$recordItemCount = is_array($record) ? (int)($record['item_count'] ?? 0) : 0;

t('abandoned cart storage is available', ecAbandonedCartStorageAvailable());
t('capture creates an active abandoned cart record', is_array($record) && (string)($record['status'] ?? '') === 'active', json_encode($record));
t('capture keeps coupon and item count snapshot', (string)($recordSnapshot['coupon_code'] ?? '') === 'SAVE10' && $recordItemCount === 2, json_encode($recordSnapshot));
t('capture generates a recovery token', strlen($recordToken) === 64, $recordToken);

if ($record) {
    app()->db()->prepare('UPDATE ec_abandoned_carts SET last_activity_at = ?, updated_at = ? WHERE id = ?')->execute([
        date('Y-m-d H:i:s', time() - 7200),
        date('Y-m-d H:i:s', time() - 7200),
        (int)$record['id'],
    ]);
}

$due = ecAbandonedCartDueReminders(10);
$results = ecAbandonedCartProcessDueReminders(10);
$recordAfterSend = $record ? ecAbandonedCartGet((int)$record['id']) : null;

t('due reminders pick the first stage after inactivity', count($due) === 1 && (int)($due[0]['reminder_stage'] ?? 0) === 1, json_encode($due));
t('process sends one recovery email', count($results) === 1 && !empty($results[0]['ok']) && count($capturedEmails) === 1, json_encode($results));
t('recovery email uses configured subject and restore link', (string)($capturedEmails[0]['subject'] ?? '') === 'Return to finish your order' && str_contains((string)($capturedEmails[0]['body'] ?? ''), '/ecommerce/recover-cart/'), json_encode($capturedEmails[0] ?? []));
t('first reminder timestamp is persisted', (string)($recordAfterSend['recovery_email_1_sent_at'] ?? '') !== '', json_encode($recordAfterSend));

ecSessionCartClear();
ecAbandonedCartClearRecoveryToken();

$restore = $recordToken !== '' ? ecAbandonedCartRestore($recordToken) : ['ok' => false];
$restoredCart = ecCartGet();

t('restore succeeds for active recovery token', !empty($restore['ok']), json_encode($restore));
t('restore repopulates the session cart and coupon', (int)($restoredCart['items'][0]['product_id'] ?? 0) === 321 && (string)($restoredCart['coupon_code'] ?? '') === 'SAVE10', json_encode($restoredCart));
t('restore remembers the active recovery token in session', ecAbandonedCartCurrentRecoveryToken() === $recordToken, ecAbandonedCartCurrentRecoveryToken());

if ($recordToken !== '') {
    ecAbandonedCartMarkRecovered(98765, null, 'recover@example.test', $recordToken);
}
$recordRecovered = $record ? ecAbandonedCartGet((int)$record['id']) : null;
$restoreRecovered = $recordToken !== '' ? ecAbandonedCartRestore($recordToken) : ['ok' => false];

t('mark recovered stores order id and recovered status', (string)($recordRecovered['status'] ?? '') === 'recovered' && (int)($recordRecovered['recovered_order_id'] ?? 0) === 98765, json_encode($recordRecovered));
t('recovered token can no longer restore a cart', empty($restoreRecovered['ok']), json_encode($restoreRecovered));

$emailTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/email-templates.disyl') ?: '';
$adminLayout = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/layouts/admin.disyl') ?: '';
$checkoutTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/checkout.disyl') ?: '';
$routes = file_get_contents(__DIR__ . '/../modules/ecommerce/routes.php') ?: '';

t('admin email template page exposes abandoned cart editor', str_contains($emailTemplate, 'Abandoned Cart Recovery') && str_contains($emailTemplate, 'email_tpl_abandoned_cart_subject'));
t('admin navigation exposes abandoned cart page', str_contains($adminLayout, '/ecommerce/admin/abandoned-carts'));
t('checkout template posts lead capture to the abandoned cart API', str_contains($checkoutTemplate, '/api/v1/ecommerce/abandoned-carts/capture'));
t('routes expose recover-cart and capture endpoints', str_contains($routes, '/ecommerce/recover-cart/{token}') && str_contains($routes, '/api/v1/ecommerce/abandoned-carts/capture'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no ModuleDB denials were logged', !str_contains($appLog, 'ModuleDB DENIED'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceAbandonedCartFixtures($fixtureIds);
ecommerceAbandonedCartApplySettings($originalSettings);

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);