<?php
/**
 * DC Cafe — order as audit trail.
 *
 * The order is the unit of record for everything that happens at the till, so
 * its history is surfaced on the order itself and the trail must cover the
 * events that move money or stock.
 *
 * These tests guard:
 *   1. the audit table is declared for read (ModuleDB denies otherwise)
 *   2. the trail is read-only history
 *   3. the actions that matter actually write to it
 *   4. the sales report shows voids and excludes them from the totals
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-audit-trail', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';

$h->fingerprint('modules/dc-cafe/module.json');
$h->fingerprint('modules/dc-cafe/helpers.php');
$h->fingerprint('modules/dc-cafe/handlers.php');
$h->fingerprint('modules/dc-cafe/handlers-orders.php');
$h->fingerprint('modules/dc-cafe/handlers-products.php');
$h->fingerprint('templates/modules/dc-cafe/orders/detail.disyl');

$manifest = json_decode((string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/module.json'), true) ?: [];
$helpers = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/helpers.php');
$orders = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers-orders.php');
$products = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers-products.php');
$handlersSource = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers.php');
$detail = (string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/orders/detail.disyl');

// ── 1. Read access ──
$h->section('Read Access');
$h->test('audit_logs is declared for reading',
    in_array('audit_logs', (array) ($manifest['reads_tables'] ?? []), true),
    'ModuleDB denies an undeclared table, so the trail would render empty');
$h->test('the module reads the trail but does not claim to own it',
    !in_array('audit_logs', (array) ($manifest['owns_tables'] ?? []), true),
    'read-only access is enough and keeps the log kernel-owned');

// ── 2. The trail is history, not state ──
$h->section('Read Only');
$h->test('the activity reader never writes',
    !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', substr($helpers, strpos($helpers, 'function dcOrderActivity'), 1400)));
$h->test('the trail is scoped to this module and entity type',
    (bool) preg_match("/module = 'dc-cafe' AND entity_type = 'dc_orders' AND entity_id = \?/", $helpers));
$h->test('the row limit is bounded so a busy order cannot blow up the page',
    (bool) preg_match('/\$limit = max\(1, min\(200, \$limit\)\)/', $helpers));
$h->test('a failed read degrades to an empty trail rather than breaking the page',
    (bool) preg_match('/dc_cafe\.order_activity\.unavailable/', $helpers));

// ── 3. The events that matter ──
$h->section('Coverage');
$h->test('recording a sale is audited', str_contains($orders, "'order.created', 'dc_orders'"));
$h->test('voiding a sale is audited', str_contains($orders, "'order.voided', 'dc_orders'"));
$h->test('selling below recorded stock is audited', str_contains($orders, "'stock.shortfall', 'dc_orders'"));
$h->test('receiving product stock is audited',
    str_contains($products, "'stock.received', 'dc_products'"),
    'deliveries and POS quick stock entry move the ledger and left no trace');
$h->test('a manual stock correction is audited',
    str_contains($products, "'stock.adjusted', 'dc_products'"));
$h->test('the received payload names the products, quantities and branch',
    (bool) preg_match("/'items' => \\\$received/", $products)
    && str_contains($products, "'store_id' => \$itemStoreId"),
    'the trail must be written from what was resolved, not the raw request');
$h->test('the branch is taken from the resolved item, not the payload default',
    !str_contains($products, "'store_id' => (int) (\$i['store_id'] ?? 0)"),
    'reading the raw payload reported store 0 when store_id was omitted');
$h->test('a correction records the before and after',
    (bool) preg_match("/'stock.adjusted'[\s\S]{0,160}\['on_hand_qty' => \\\$oldStock\]/", $products));

// ── 4. Readable entries ──
$h->section('Entry Labels');
$h->test('a sale reads as a human sentence', str_contains($helpers, "'Sale recorded'"));
$h->test('a void names the approver, not just the fact', str_contains($helpers, "'Approved by '"));
$h->test('a void is marked as a loss for scanning', str_contains($helpers, "\$entry['tone'] = 'danger'"));
$h->test('a shortfall is ranked below a full failure', str_contains($helpers, "\$entry['tone'] = 'warn'"));
$h->test('an unrecognised action still renders its raw name rather than vanishing',
    (bool) preg_match("/\\\$entry = \['action' => \\\$action, 'label' => \\\$action/", $helpers));

// ── 5. Live trail ──
$h->section('Live Trail');
$orderId = (int) dcDb()->query(
    "SELECT entity_id FROM audit_logs
     WHERE module = 'dc-cafe' AND entity_type = 'dc_orders' AND action = 'order.voided'
     ORDER BY id DESC LIMIT 1"
)->fetchColumn();

$h->test('a voided order exists to inspect', $orderId > 0, 'order ' . $orderId);
$activity = dcOrderActivity($orderId);
$h->test('the trail returns entries for that order', count($activity) >= 2, 'found ' . count($activity));
$actions = array_column($activity, 'action');
$h->test('the sale and its void are both present',
    in_array('order.created', $actions, true) && in_array('order.voided', $actions, true),
    implode(',', $actions));
$h->test('newest entry comes first', ($actions[0] ?? '') === 'order.voided');
$h->test('the void entry credited the approving supervisor',
    str_contains((string) ($activity[0]['detail'] ?? ''), 'Approved by'));
$h->test('an order with no history returns an empty list rather than failing',
    dcOrderActivity(999999) === []);
$h->test('an invalid order id is rejected before querying', dcOrderActivity(0) === []);

// ── 6. The helper must be reachable from every handler file ──
// The split handler files are loaded directly by some entry points WITHOUT
// handlers.php, so anything they call has to live in helpers.php. Defining
// dc_auditLog in handlers.php made every audit from those files a fatal.
$h->section('Helper Placement');
$h->test('dc_auditLog is defined in helpers.php',
    (bool) preg_match('/function dc_auditLog\(/', $helpers));
$h->test('it is not also defined in handlers.php',
    !preg_match('/function dc_auditLog\(/', $handlersSource),
    'a second definition would collide as the module loads');
$h->test('helpers.php does not depend on the handler files',
    !preg_match('/require[^\n]*handlers(-[a-z]+)?\.php/', $helpers));
$h->test('the split handler files do audit',
    str_contains($orders, 'dc_auditLog(') && str_contains($products, 'dc_auditLog('));
$h->test('the audit helper is callable in this context', function_exists('dc_auditLog'));

// ── 7. The report shows voids and adjusts sales ──
$h->section('Sales Report');
$h->test('voids are reported rather than silently dropped',
    str_contains($orders, "'Voided orders (excluded from the totals above)'"));
$h->test('the products table counts completed orders only',
    str_contains($orders, "o.status = 'completed'"));
$h->test('the voided listing is filtered to voided orders',
    (bool) preg_match("/status = 'voided'/", $orders));
$h->test('a summary states completed, voided and net revenue',
    str_contains($orders, "'Completed orders'") && str_contains($orders, "'Voided orders'")
    && str_contains($orders, "'Net revenue'"));
$h->test('the net figure subtracts reversed revenue',
    (bool) preg_match("/'Net revenue', '', \\\$completedTotals\['revenue'\] - \\\$voidedTotals\['revenue'\]/", $orders));
$h->test('the net row carries no order count, which would have no meaning',
    !preg_match("/'Net revenue', \\\$completedTotals\['orders'\]/", $orders));
$h->test('the voided listing names the items reversed',
    str_contains($orders, "GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name)"));

// ── 8. The Audit view ──
// The label changed from Orders to Audit and the data is now every activity,
// not just sales, with an Actor column.
$h->section('Audit View');
$layout = (string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/layouts/app.disyl');
$auditTpl = (string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/audit/index.disyl');
$routes = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/routes.php');

$h->test('the nav label reads Audit', str_contains($layout, '>Audit</a>'));
$h->test('the nav no longer offers an Orders entry', !str_contains($layout, '>Orders</a>'));
$h->test('the nav points at the audit route', str_contains($layout, 'href="/dc-cafe/audit"'));
$h->test('the audit route is registered',
    (bool) preg_match("~'/dc-cafe/audit'\\s*=>\\s*'dc-cafe:pageAuditLog'~", $routes));
$h->test('the sales list stays reachable from the audit page',
    str_contains($auditTpl, '/dc-cafe/orders'), 'a cashier still needs a route to a sale to void');
$h->test('the audit page carries an Actor column', str_contains($auditTpl, 'Actor'));
$h->test('the page lists every activity across the app, not only this module',
    !preg_match('/\$where = \["al\.module = /', $helpers),
    'the trail must not be pinned to one module');
$h->test('the module is recorded per row so the app-wide list stays attributable',
    str_contains($helpers, "'module' => \$rowModule"));
$h->test('a module filter can narrow the app-wide list',
    (bool) preg_match('/\$module = trim\(\(string\) \(\$filters\[\'module\'\]/', $helpers));
$h->test('the action and module option lists are not module-scoped',
    !preg_match('/SELECT DISTINCT (action|module) FROM audit_logs WHERE module = /', $helpers));
$h->test('the trail reader never writes',
    !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', substr($helpers, strpos($helpers, 'function dcAuditTrail('), 2600)));

// ── 9. Actor resolution ──
// The Actor column must name a person, and degrade honestly rather than
// showing a bare id or an empty cell.
$h->section('Actor Column');
$named = dcAuditTrailEntry([
    'action' => 'order.created', 'entity_type' => 'dc_orders', 'entity_id' => '7',
    'actor_module_user_id' => 3, 'actor_source' => 'dc-cafe',
    'actor_name' => 'Akira Santos', 'actor_username' => 'cashieraki', 'actor_role' => 'cashier',
    'created_at' => '2026-09-19 10:00:00',
], ['total' => 100, 'items' => 2]);
$h->test('a known user is named', $named['actor_label'] === 'Akira Santos');
$h->test('the account and role are shown underneath',
    $named['actor_detail'] === 'cashieraki' && $named['actor_role'] === 'cashier');
$h->test('an unknown module user degrades to a labelled id', (function () {
    $e = dcAuditTrailEntry([
        'module' => 'dc-cafe',
        'action' => 'stock.received', 'actor_module_user_id' => 99, 'actor_source' => 'dc-cafe',
        'created_at' => '2026-09-19 10:00:00',
    ], []);
    return $e['actor_label'] === 'dc-cafe user #99';
})());
$h->test('another module\'s user is never named from our user table', (function () {
    // actor_module_user_id is scoped to the ACTING module, so id 3 means a
    // dc-cafe user on one row and a different person on another. Naming the
    // wrong person would be worse than showing an id.
    $e = dcAuditTrailEntry([
        'module' => 'patient-registry',
        'action' => 'patient.created', 'actor_module_user_id' => 3, 'actor_source' => 'patient-registry',
        'created_at' => '2026-09-19 10:00:00',
    ], []);
    return $e['actor_label'] === 'patient-registry user #3';
})());
$h->test('a foreign row cannot borrow a dc-cafe name', (function () {
    // Even if the join somehow matched, the label must carry the row's module.
    $e = dcAuditTrailEntry([
        'module' => 'scheduling', 'action' => 'appointment.created',
        'actor_module_user_id' => 3, 'actor_source' => 'scheduling',
        'actor_name' => '', 'actor_username' => '',
        'created_at' => '2026-09-19 10:00:00',
    ], []);
    return !str_contains($e['actor_label'], 'dc-cafe') && str_contains($e['actor_label'], 'scheduling');
})());
$h->test('a kernel actor is labelled as such', (function () {
    $e = dcAuditTrailEntry([
        'module' => 'dc-cafe',
        'action' => 'session.started', 'actor_user_id' => 4, 'actor_source' => 'kernel',
        'created_at' => '2026-09-19 10:00:00',
    ], []);
    return $e['actor_label'] === 'Kernel user #4';
})());
$h->test('an automated action reads as System', (function () {
    $e = dcAuditTrailEntry(['module' => 'dc-cafe', 'action' => 'order.created', 'created_at' => '2026-09-19 10:00:00'], []);
    return $e['actor_label'] === 'System';
})());

// ── 10. Labels, tones and details ──
$h->section('Presentation');
$h->test('a known action reads as a sentence', dcAuditActionLabel('order.voided') === 'Sale voided');
$h->test('an unknown action is prettified rather than hidden',
    dcAuditActionLabel('widget.reticulated') === 'Widget reticulated');
$h->test('a void is marked as a loss', dcAuditActionTone('order.voided') === 'danger');
$h->test('routine work is not flagged as a problem',
    dcAuditActionTone('order.created') === 'ok' && dcAuditActionTone('settings.preferences_saved') === 'neutral');
$h->test('a void detail names the approver',
    str_contains(dcAuditDetail('order.voided', ['approved_by_username' => 'supervisor', 'approved_by_role' => 'supervisor']), 'Approved by supervisor'));
$h->test('a sale detail carries the total and item count',
    dcAuditDetail('order.created', ['total' => 150, 'items' => 1]) === 'Total 150.00, 1 line item');
$h->test('a received detail sums the units',
    str_contains(dcAuditDetail('stock.received', ['products' => 2, 'items' => [['quantity' => 1], ['quantity' => 3]]]), '2 product(s), 4 unit(s)'));
$h->test('the settings detail reads the changed keys',
    dcAuditDetail('settings.preferences_saved', [], ['keys' => ['pos_void_enabled']]) === 'Changed: pos_void_enabled');
$h->test('the inventory detail uses the recorded item count',
    dcAuditDetail('inventory_progress.saved', ['item_count' => 3]) === '3 rows counted');
$h->test('an unknown action still yields something readable',
    dcAuditDetail('widget.reticulated', ['size' => 'large']) !== '');

// ── 11. Filtering and paging ──
$h->section('Filtering');
$h->test('no filters means every activity',
    dcAuditTrailCount([]) >= dcAuditTrailCount(['action' => 'order.voided']));
$h->test('an action filter narrows the result', dcAuditTrailCount(['action' => 'order.voided']) > 0);
$h->test('an impossible filter returns nothing rather than everything',
    dcAuditTrailCount(['action' => 'no.such.action']) === 0);
$h->test('paging never returns more than the page size',
    count(dcAuditTrail([], 5, 0)) <= 5);
$h->test('a second page continues rather than repeating',
    dcAuditTrail([], 3, 0) !== dcAuditTrail([], 3, 3) || dcAuditTrailCount([]) <= 3);
$h->test('a negative offset is clamped', is_array(dcAuditTrail([], 3, -10)));
$h->test('the action list is offered for the filter', count(dcAuditActionOptions()) > 0);
$h->test('options carry a stored value and a readable label',
    (function (): bool {
        foreach (dcAuditActionOptions() as $opt) {
            if (!isset($opt['value'], $opt['label']) || $opt['value'] === '' || $opt['label'] === '') {
                return false;
            }
        }
        return true;
    })());
$h->test('a known action is not shown as its raw key',
    (function (): bool {
        foreach (dcAuditActionOptions() as $opt) {
            if ($opt['value'] === 'auth.login_failed' && $opt['label'] !== 'Sign-in failed') {
                return false;
            }
        }
        return true;
    })());
$h->test('no option label is a raw dotted key',
    count(array_filter(dcAuditActionOptions(),
        fn($o) => str_contains($o['label'], '.') || str_contains($o['label'], '_'))) === 0,
    'a filter should read like the Activity column, not like a database key');
$h->test('options are ordered by the label the reader scans',
    (function (): bool {
        $labels = array_column(dcAuditActionOptions(), 'label');
        $sorted = $labels;
        usort($sorted, 'strcasecmp');
        return $labels === $sorted;
    })());
$h->test('the filter value stays the stored action so filtering still matches',
    count(array_filter(dcAuditActionOptions(), fn($o) => $o['value'] === 'order.voided')) === 1);
$h->test('the filter and the column share one label source',
    dcAuditActionLabel('order.voided') === 'Sale voided'
    && (function (): bool {
        foreach (dcAuditActionOptions() as $opt) {
            if ($opt['value'] === 'order.voided') {
                return $opt['label'] === dcAuditActionLabel('order.voided');
            }
        }
        return true;
    })());
$h->test('the template renders the label but submits the value',
    str_contains($auditTpl, 'value="{option.value}"')
    && str_contains($auditTpl, '>{option.label}</option>')
    && str_contains($auditTpl, 'filters.action == option.value'));

$h->done();
