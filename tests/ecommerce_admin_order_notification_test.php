<?php

declare(strict_types=1);

/**
 * Tests that ecommerce sends an order notification email to the configured
 * admin address when a new order is placed (ecommerce.order.created event).
 *
 * Scenarios:
 *  1. When admin_notification_email is set, sendEmail is called with the admin
 *     address and contains order number, customer info, and admin order link.
 *  2. When admin_notification_email is blank, no email is sent.
 *  3. Customer and admin emails do not interfere — both can fire on the same order
 *     (digital product: admin gets order notice, customer gets license email).
 *
 * Host: cmsnew.test (tenant 1).
 */

$_SERVER['HTTP_HOST']   = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

// ── Stub sendEmail before any helpers load it ──────────────────────────────
$capturedEmails = [];

function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    global $capturedEmails;
    $capturedEmails[] = compact('to', 'subject', 'body');
    return true;
}

// ── Bootstrap ──────────────────────────────────────────────────────────────
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

// ── Helpers ────────────────────────────────────────────────────────────────
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

file_put_contents(STORAGE_PATH . '/logs/app.log',   '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// ── Constants ──────────────────────────────────────────────────────────────
const TEST_CUSTOMER_EMAIL = 'noah2.omamalin@gmail.com';
const ADMIN_NOTIFY_EMAIL  = 'storeadmin@cmsnew.test';
const DIGITAL_PROD_ID     = 1225;

// ── Cleanup helper ─────────────────────────────────────────────────────────
$createdOrderIds = [];

function cleanupOrders(array $ids): void
{
    if (empty($ids)) return;
    $db = ecDb();
    foreach ($ids as $id) {
        $db->execute('DELETE FROM ec_order_licenses   WHERE order_id = ?', [$id]);
        $db->execute('DELETE FROM ec_order_items      WHERE order_id = ?', [$id]);
        $db->execute('DELETE FROM ec_order_meta       WHERE order_id = ?', [$id]);
        $db->execute('DELETE FROM ec_payment_transactions WHERE order_id = ?', [$id]);
        $db->execute('DELETE FROM ec_orders           WHERE id = ?',        [$id]);
    }
}

function buildOrder(string $email, int $productId): array
{
    return [
        'cart_items' => [[
            'product_id'     => $productId,
            'variant_id'     => null,
            'product_title'  => 'Guidance Monitoring',
            'sku'            => 'GUIDE-PRO-NOTIF-TEST',
            'price_snapshot' => 499.00,
            'qty'            => 1,
            'variant_label'  => null,
        ]],
        'subtotal'        => 499.00,
        'discount_amount' => 0.00,
        'tax_amount'      => 0.00,
        'shipping_amount' => 0.00,
        'total'           => 499.00,
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

// Helper: inject admin email setting for the test, restore after
function withAdminNotifyEmail(string $email, callable $fn): mixed
{
    $settings = readTenantModuleSettings('ecommerce');
    $old = $settings['admin_notification_email'] ?? '';
    saveTenantModuleSettings('ecommerce', array_merge($settings, ['admin_notification_email' => $email]));
    try {
        return $fn();
    } finally {
        saveTenantModuleSettings('ecommerce', array_merge($settings, ['admin_notification_email' => $old]));
    }
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 1 — Admin notification email sent on order.created
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 1: Admin notification on new order ──\n";

$capturedEmails = [];
$order1Id = 0;

try {
    withAdminNotifyEmail(ADMIN_NOTIFY_EMAIL, function () use (&$order1Id, &$capturedEmails) {
        $result   = ecOrderCreate(buildOrder(TEST_CUSTOMER_EMAIL, DIGITAL_PROD_ID));
        $order1Id = (int)$result['order_id'];

        $GLOBALS['createdOrderIds'][] = $order1Id;

        // ecommerce.order.created fires inside ecOrderCreate → listener should have run
        $adminMails = array_values(array_filter($capturedEmails, fn($m) => $m['to'] === ADMIN_NOTIFY_EMAIL));

        t('Admin email sent',                    count($adminMails) >= 1, 'sent=' . count($adminMails));

        if (!empty($adminMails)) {
            $m = $adminMails[0];
            t('Admin email subject contains order number', str_contains($m['subject'], $result['order_number']));
            t('Admin email body contains order number',    str_contains($m['body'], $result['order_number']));
            t('Admin email body contains customer email',  str_contains($m['body'], TEST_CUSTOMER_EMAIL));
            t('Admin email body contains admin order URL', str_contains($m['body'], '/ecommerce/admin/orders/' . $order1Id));
            t('Admin email body contains total amount',    str_contains($m['body'], '499'));
        }
    });
} catch (\Throwable $e) {
    t('Suite 1 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 2 — When admin email is blank, no notification sent
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 2: No notification when admin email is blank ──\n";

$capturedEmails = [];
$order2Id = 0;

try {
    withAdminNotifyEmail('', function () use (&$order2Id) {
        $result   = ecOrderCreate(buildOrder(TEST_CUSTOMER_EMAIL, DIGITAL_PROD_ID));
        $order2Id = (int)$result['order_id'];
        $GLOBALS['createdOrderIds'][] = $order2Id;
    });

    $adminMails = array_filter($capturedEmails, fn($m) => $m['to'] === ADMIN_NOTIFY_EMAIL);
    t('No admin email sent when setting is blank', count($adminMails) === 0, 'sent=' . count($adminMails));

} catch (\Throwable $e) {
    t('Suite 2 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Suite 3 — Digital order: both admin notification AND license email fire
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Suite 3: Digital order sends both admin notice and license email ──\n";

$capturedEmails = [];
$order3Id = 0;

try {
    withAdminNotifyEmail(ADMIN_NOTIFY_EMAIL, function () use (&$order3Id) {
        $result   = ecOrderCreate(buildOrder(TEST_CUSTOMER_EMAIL, DIGITAL_PROD_ID));
        $order3Id = (int)$result['order_id'];
        $GLOBALS['createdOrderIds'][] = $order3Id;

        // Mark paid → fires ecommerce.order.paid → license email goes to customer
        ecOrderMarkPaid($order3Id);
    });

    // Admin gets order notice (from order.created)
    $adminMails    = array_values(array_filter($capturedEmails, fn($m) => $m['to'] === ADMIN_NOTIFY_EMAIL));
    // Customer gets license email (from order.paid)
    $customerMails = array_values(array_filter($capturedEmails, fn($m) => $m['to'] === TEST_CUSTOMER_EMAIL));

    t('Admin receives order notification',       count($adminMails) >= 1,    'admin_emails=' . count($adminMails));
    t('Customer receives license email',         count($customerMails) >= 1, 'customer_emails=' . count($customerMails));
    t('Admin and customer emails are separate',  $adminMails[0]['to'] !== ($customerMails[0]['to'] ?? ''));

    if (!empty($customerMails)) {
        t('Customer license email contains JWT download link', str_contains($customerMails[0]['body'], '/ecommerce/download/'));
    }

    // Verify license generated in DB
    $lic = ecDb()->query('SELECT id FROM ec_order_licenses WHERE order_id = ? LIMIT 1', [$order3Id])->fetch(PDO::FETCH_ASSOC);
    t('License row exists in DB', is_array($lic));

} catch (\Throwable $e) {
    t('Suite 3 — no exception', false, $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════
// Cleanup
// ══════════════════════════════════════════════════════════════════════════
cleanupOrders($createdOrderIds);

// ══════════════════════════════════════════════════════════════════════════
// Log checks
// ══════════════════════════════════════════════════════════════════════════
echo "\n── Log checks ──\n";

$appLog   = file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = file_get_contents(STORAGE_PATH . '/logs/error.log');

t('No ModuleDB DENIED in app.log', !str_contains($appLog, 'ModuleDB DENIED'));
t('No PHP fatal in error.log',     !preg_match('/PHP Fatal/', $errorLog));
t('No PHP warning in error.log',   !preg_match('/PHP Warning/', $errorLog), substr($errorLog, 0, 300) ?: 'clean');

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
