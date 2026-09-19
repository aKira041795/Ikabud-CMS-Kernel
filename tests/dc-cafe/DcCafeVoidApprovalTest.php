<?php
/**
 * DC Cafe — void approval test.
 *
 * Voiding a sale returns stock and removes revenue, so it is a classic route
 * for taking cash out of a drawer. These tests guard the rules that make the
 * control real rather than decorative:
 *
 *   1. the throttle table is declared in the manifest (ModuleDB denies otherwise)
 *   2. a cashier may start a void but cannot complete one unaided
 *   3. the PIN is required regardless of who is signed in, because the till
 *      session is shared — otherwise the recorded approver means nothing
 *   4. the PIN is stored as a bcrypt hash and never leaves the server
 *   5. only an administrator or supervisor may hold one
 *   6. repeated guesses are throttled, and a success clears the history
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-void-approval', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';

$h->fingerprint('modules/dc-cafe/module.json');
$h->fingerprint('modules/dc-cafe/handlers-orders.php');
$h->fingerprint('modules/dc-cafe/handlers.php');
$h->fingerprint('modules/dc-cafe/helpers.php');
$h->fingerprint('modules/dc-cafe/database/migrations/037_add_void_approval.sql');
$h->fingerprint('templates/modules/dc-cafe/partials/void-approval.disyl');

$manifest = json_decode((string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/module.json'), true) ?: [];
$migrationSql = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/database/migrations/037_add_void_approval.sql');
$orders = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers-orders.php');

// Assertions about the void flow must be scoped to its own function: several
// patterns (beginTransaction, stock restores) also appear in apiCreateOrder.
$voidStart = strpos($orders, 'function apiVoidOrder(');
$voidEnd = strpos($orders, '// ─── Export Handlers', $voidStart);
$void = ($voidStart !== false && $voidEnd !== false)
    ? substr($orders, $voidStart, $voidEnd - $voidStart)
    : '';
$h->test('the void flow could be located for inspection', $void !== '');
$handlers = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers.php');
$helpers = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/helpers.php');
$partial = (string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/partials/void-approval.disyl');
$pos = (string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/pos/index.disyl');
$detail = (string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/orders/detail.disyl');

// ── 1. Schema ──
$h->section('Schema');
$h->test('dc_void_attempts is declared in owns_tables',
    in_array('dc_void_attempts', (array) ($manifest['owns_tables'] ?? []), true),
    'ModuleDB denies an undeclared table, turning every void into a 500');
$h->test('the migration is registered',
    count(array_filter((array) ($manifest['migrations'] ?? []),
        fn($m) => str_contains((string) $m, '037_add_void_approval'))) === 1);
$h->test('the column add is guarded for MySQL 5.7',
    str_contains($migrationSql, 'information_schema.columns') && str_contains($migrationSql, 'PREPARE stmt'));
$h->test('the throttle table is InnoDB',
    str_contains($migrationSql, 'ENGINE=InnoDB'));
$h->test('the throttle is indexed by the window it is read with',
    str_contains($migrationSql, 'idx_dc_void_attempts_window'));

// ModuleDB allows SHOW TABLES/COLUMNS but denies information_schema.columns,
// so the column check goes through SHOW rather than a catalog query.
$hasColumn = count(dcDb()->query("SHOW COLUMNS FROM dc_users LIKE 'void_pin_hash'")->fetchAll()) === 1;
$h->test('dc_users carries a void PIN column', $hasColumn);

// ── 2. The PIN is a real secret ──
$h->section('PIN Storage');
$h->test('the PIN is hashed with bcrypt, matching the login password',
    (bool) preg_match('/password_hash\(\$pin, PASSWORD_BCRYPT\)/', $handlers));
$h->test('the PIN is never stored as plain text',
    !str_contains($handlers, "SET void_pin_hash = \$pin") && !str_contains($handlers, "'void_pin' => \$pin"));
$h->test('the hash is never returned to the client',
    str_contains($handlers, 'AS has_void_pin')
    && !preg_match('/SELECT\s+\*/i', substr($handlers, strpos($handlers, 'FROM dc_users'), 400)),
    'the users query must select named columns, never the hash');
$h->test('the audit records existence, never the PIN',
    (bool) preg_match("/'has_void_pin' => !\\\$clearing/", $handlers));

// ── 3. Who may approve ──
$h->section('Approver Eligibility');
$h->test('only an admin or supervisor may hold a PIN',
    (bool) preg_match("/in_array\(\(string\) \\\$user\['role'\], \['admin', 'supervisor'\], true\)/", $handlers));
$h->test('a PIN on another role is refused, not stored',
    str_contains($handlers, 'Only an administrator or supervisor can approve a void'));
$h->test('setting a PIN is admin-only',
    (bool) preg_match('/function apiSetVoidPin[\s\S]{0,220}requireAnyRole\(\'admin\'\)/', $handlers));
$h->test('the PIN format is constrained for a counter keypad',
    str_contains($handlers, '/^[0-9]{4,12}$/'));
$h->test('verification only considers active approver accounts',
    (bool) preg_match("/role IN \('admin', 'supervisor'\)[\s\S]{0,120}is_active = 1/", $helpers)
    || (bool) preg_match("/is_active = 1 AND deleted_at IS NULL[\s\S]{0,80}role IN \('admin', 'supervisor'\)/", $helpers));

// ── 4. A cashier may start a void, but not complete one ──
$h->section('Cashier Initiation');
$h->test('a cashier is allowed to reach the void endpoint',
    (bool) preg_match("/function apiVoidOrder[\s\S]{0,260}requireAnyRole\('admin', 'supervisor', 'cashier'\)/", $orders));
$h->test('the approval is required before the order is touched',
    strpos($void, 'dcVerifyVoidPin(') !== false
    && strpos($void, 'dcVerifyVoidPin(') < strpos($void, '$db->beginTransaction();'));
$h->test('the PIN is required regardless of the signed-in role',
    !preg_match('/if\s*\(\s*\$isSupervisor[\s\S]{0,80}skip/i', $void));
$h->test('a missing PIN is refused',
    str_contains($void, "dcInput('void_pin')"));
$h->test('a failed approval records an attempt',
    (bool) preg_match('/\$approver === null[\s\S]{0,260}dcRecordVoidAttempt\(\$storeId, \$userId, false\)/', $void));

// ── 5. Audit identity ──
$h->section('Audit');
$h->test('the void audit names the approving user',
    str_contains($orders, "'approved_by' => (int) \$approver['user_id']"));
$h->test('it also records the role and username for a readable trail',
    str_contains($orders, "'approved_by_username'") && str_contains($orders, "'approved_by_role'"));
$h->test('it records who asked, so an override can be questioned',
    str_contains($orders, "'requested_by' => \$userId"));

// ── 6. Brute-force guard ──
$h->section('Throttle');
$h->test('a short PIN is not left open to unlimited guessing',
    defined('DC_VOID_MAX_FAILURES') && DC_VOID_MAX_FAILURES <= 10,
    'a 4-digit PIN is only 10,000 guesses');
$h->test('the failure window is bounded',
    defined('DC_VOID_FAILURE_WINDOW_MIN') && DC_VOID_FAILURE_WINDOW_MIN > 0);
$h->test('the throttle is consulted before any PIN is checked',
    strpos($orders, 'dcVoidThrottleError(') < strpos($orders, 'dcVerifyVoidPin('));
$h->test('it fails closed when the counter cannot be read',
    (bool) preg_match('/dcVoidFailureCount[\s\S]{0,600}return DC_VOID_MAX_FAILURES;/', $helpers));
$h->test('a success clears the branch failure history',
    (bool) preg_match('/dcRecordVoidAttempt\(\$storeId, \$userId, true\)[\s\S]{0,80}dcClearVoidFailures\(\$storeId\)/', $void));
$h->test('the current failure count is live', is_int(dcVoidFailureCount(1)));

// ── 7. No approver configured must not silently pass ──
$h->section('Fail Closed');
$h->test('a branch with no approver PIN is refused with an actionable message',
    str_contains($orders, 'No supervisor void PIN is configured')
    && str_contains($orders, 'Settings → User Accounts'));
$h->test('that check precedes the PIN check',
    strpos($orders, 'dcHasVoidApprovers()') < strpos($orders, 'dcVerifyVoidPin('));
$h->test('the approver-existence check fails closed on error',
    (bool) preg_match('/dcHasVoidApprovers[\s\S]{0,700}return false;/', $helpers));

// ── 8. One shared dialog ──
$h->section('Shared Dialog');
$h->test('the POS and the order detail page include the same partial',
    str_contains($pos, 'partials/void-approval.disyl') && str_contains($detail, 'partials/void-approval.disyl'));
$h->test('the PIN is posted, never placed in a URL',
    (bool) preg_match("/fetch\('\/dc-cafe\/api\/v1\/orders\/' \+ this\.orderId \+ '\/void'/", $partial));
$h->test('the trigger avoids a single-line object literal in markup',
    str_contains($pos, 'dcAskVoid(lastOrder.order_id)') && str_contains($detail, 'dcAskVoid({order.id})'),
    'DiSyL strips a single-line brace group that looks like a tag');
$h->test('the receipt keeps a single x-if root so nothing is dropped',
    (bool) preg_match('/<template x-if="lastOrder">\s*\{\*[^}]*\*\}\s*<div>/', $pos));
$h->test('a voucher-grade failure is surfaced to the cashier',
    str_contains($partial, 'Could not void this sale') && str_contains($partial, 'Incorrect void PIN') === false);

// Alpine only processes x-on:click inside an x-data scope. A plain callback
// still needs that scope, or the button silently does nothing when clicked.
$h->test('the detail page trigger sits inside an Alpine scope',
    (bool) preg_match('/<div x-data>[\s\S]{0,900}dcAskVoid\(/', $detail));

// ── 9. Admin policy switch ──
// Voiding is allowed by default and can be switched off by an administrator.
// Off must mean off on the server, not merely a hidden button.
$h->section('Void Policy Switch');
$fieldsByKey = [];
foreach ((array) ($manifest['settings_fields'] ?? []) as $field) {
    if (is_array($field) && isset($field['key'])) {
        $fieldsByKey[(string) $field['key']] = $field;
    }
}
$h->test('the switch is declared as an administrator setting',
    isset($fieldsByKey['pos_void_enabled']) && ($fieldsByKey['pos_void_enabled']['type'] ?? '') === 'checkbox');
$h->test('voiding is allowed by default',
    (string) ($fieldsByKey['pos_void_enabled']['default'] ?? '') === '1');
$h->test('the declared default is what the helper reports',
    dcVoidEnabled() === true);
$h->test('the helper reads the declared key',
    (bool) preg_match('/function dcVoidEnabled[\s\S]{0,120}dcSettingBool\(\'pos_void_enabled\'\)/', $helpers));
$h->test('the server refuses a void when the policy is off',
    (bool) preg_match('/if \(!dcVoidEnabled\(\)\) \{\s*dcJsonError\(\'Voiding is not enabled/', $void));
$h->test('the policy is checked before any approval work',
    strpos($void, 'dcVoidEnabled()') < strpos($void, 'dcVoidThrottleError('),
    'a disabled till must not spend an approval or record a failure');
$h->test('the check precedes the PIN check, so a valid PIN cannot bypass it',
    strpos($void, 'dcVoidEnabled()') < strpos($void, 'dcVerifyVoidPin('));
$h->test('the till is told the policy so it can hide the control',
    str_contains($handlers, "'void_enabled'") && str_contains($pos, 'voidEnabled: true'));
$h->test('the receipt control is gated on the policy',
    (bool) preg_match('/x-show="voidEnabled"[\s\S]{0,700}Void this sale/', $pos));
$h->test('the order detail page is handed the policy',
    str_contains($handlers, "'void_enabled' => dcVoidEnabled()"));
$h->test('the detail control is gated on the policy',
    (bool) preg_match("/\{if order\.status == 'completed' && void_enabled\}/", $detail));

$h->done();
