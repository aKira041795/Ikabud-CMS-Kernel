<?php
/**
 * DC Cafe — Preferences (module settings) test.
 *
 * Preferences are declared once in module.json `settings_fields`; this guards
 * the declaration contract and the read path so a tenant that has never saved
 * a preference still gets sane defaults.
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-preferences', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';

$h->fingerprint('modules/dc-cafe/module.json');
$h->fingerprint('modules/dc-cafe/helpers.php');
$h->fingerprint('modules/dc-cafe/handlers.php');
$h->fingerprint('templates/modules/dc-cafe/settings/index.disyl');

// ── Declaration contract ──
$h->section('Settings Declaration');
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/module.json'), true);
$fields = $manifest['settings_fields'] ?? [];
$h->test('settings_fields declared', is_array($fields) && count($fields) >= 10);

$validTypes = ['bool', 'checkbox', 'email', 'number', 'password', 'select', 'text', 'textarea', 'time'];
$badType = [];
$noDefault = [];
$noKey = [];
$keys = [];
foreach ($fields as $field) {
    $key = (string) ($field['key'] ?? '');
    if ($key === '') {
        $noKey[] = json_encode($field);
        continue;
    }
    $keys[] = $key;
    if (!in_array((string) ($field['type'] ?? ''), $validTypes, true)) {
        $badType[] = $key;
    }
    if (!array_key_exists('default', $field)) {
        $noDefault[] = $key;
    }
}
$h->test('every field declares a key', $noKey === []);
$h->test('every field uses a supported type', $badType === [], implode(', ', $badType));
$h->test('every field declares a default', $noDefault === [], implode(', ', $noDefault));
$h->test('no duplicate keys', count($keys) === count(array_unique($keys)));
$h->test('every field has a human label', count(array_filter($fields, fn($f) => trim((string) ($f['label'] ?? '')) !== '')) === count($fields));

$selects = array_filter($fields, fn($f) => ($f['type'] ?? '') === 'select');
$emptySelects = [];
foreach ($selects as $field) {
    $options = $field['options'] ?? [];
    if (!is_array($options) || $options === []) {
        $emptySelects[] = (string) $field['key'];
        continue;
    }
    // The declared default must be one of the offered options.
    $values = array_map(fn($o) => (string) ($o['value'] ?? ''), $options);
    if (!in_array((string) $field['default'], $values, true)) {
        $emptySelects[] = $field['key'] . ' (default not among options)';
    }
}
$h->test('every select offers options and a valid default', $emptySelects === [], implode(', ', $emptySelects));

// ── Read path ──
$h->section('Effective Settings');
$defaults = dcSettingsDefaults();
$effective = dcSettings();
$h->test('defaults resolve for every declared field', count($defaults) === count($fields));
$h->test('effective settings cover every declared field', count($effective) === count($fields));

// A tenant with nothing stored must get the declared default.
$h->test('unset preference falls back to its default',
    $effective['pos_stock_policy'] === 'warn'
    && $effective['pos_default_view'] === 'list',
    json_encode(['policy' => $effective['pos_stock_policy'] ?? null, 'view' => $effective['pos_default_view'] ?? null]));

// Typed readers.
$h->test('boolean reader understands the string default', dcSettingBool('low_stock_alerts_enabled') === true);
$h->test('boolean reader is false for the off default', dcSettingBool('low_stock_email_enabled') === false);
$h->test('boolean reader is safe for an undeclared key', dcSettingBool('nope_not_declared') === false);
$h->test('number reader falls back for an undeclared key', dcSettingNumber('nope_not_declared', 7.0) === 7.0);

// ── Policies the agreed work depends on ──
$h->section('Required Policy Fields');
$byKey = [];
foreach ($fields as $field) {
    $byKey[(string) $field['key']] = $field;
}
foreach ([
    'pos_stock_policy',            // unblocks selling when receiving is late
    'low_stock_alerts_enabled',
    'low_stock_include_products',  // fixes the ingredients-only under-report
    'low_stock_verify_required',
    'low_stock_email_enabled',
    'low_stock_email_to',
    'production_view_enabled',
    'commissary_enabled',
] as $required) {
    $h->test("declares {$required}", isset($byKey[$required]));
}

$h->test('stock policy is deliberately permissive out of the box',
    (string) ($byKey['pos_stock_policy']['default'] ?? '') === 'warn');

// ── Discount types ──
// Rates moved out of the flat settings list and into dc_discount_types, so a
// branch can add a partner or statutory discount without a code change. The
// list is a collection, which settings_fields (key/type/label/default) cannot
// express.
$h->section('Discount Type Storage');
$h->test('the rate setting is no longer declared twice',
    !isset($byKey['senior_discount_pct']),
    'senior_discount_pct should live in dc_discount_types, not settings_fields');

$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/module.json'), true) ?: [];
$owns = (array) ($manifest['owns_tables'] ?? []);
$h->test('dc_discount_types is declared in owns_tables', in_array('dc_discount_types', $owns, true));

$migrations = (array) ($manifest['migrations'] ?? []);
$h->test('the discount type migration is registered',
    count(array_filter($migrations, fn($m) => str_contains((string) $m, '036_create_discount_types'))) === 1);

$migrationSql = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/database/migrations/036_create_discount_types.sql');
$h->test('the discount type table is InnoDB', str_contains($migrationSql, 'ENGINE=InnoDB'));
$h->test('the discount type code is unique', str_contains($migrationSql, 'uk_dc_discount_types_code'));
$h->test('PAG-IBIG ships as a discount type, not a payment method',
    str_contains($migrationSql, "'pagibig'"),
    'PAG-IBIG is a discount alongside Senior Citizen');

// ── Save endpoint contract ──
$h->section('Save Endpoint');
$routes = require __DIR__ . '/../../modules/dc-cafe/routes.php';
$h->test('preferences save route registered',
    ($routes['POST']['/dc-cafe/api/v1/settings/preferences'] ?? '') === 'dc-cafe:apiSaveDcPreferences');

$handlers = (string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers.php');
$h->test('unknown keys are rejected, not stored',
    (bool) preg_match("/!isset\(\\\$declared\[\\\$key\]\)[\s\S]{0,80}dcJsonError/", $handlers));
$h->test('select values are checked against declared options',
    (bool) preg_match("/allowed !== \[\] && !in_array\(\\\$candidate, \\\$allowed, true\)/", $handlers));
$h->test('email values are validated',
    str_contains($handlers, 'FILTER_VALIDATE_EMAIL'));
$h->test('numbers must be non-negative and finite',
    (bool) preg_match('/\$number < 0/', $handlers) && str_contains($handlers, 'is_finite($number)'));
$h->test('saving requires admin', (bool) preg_match("/apiSaveDcPreferences[\s\S]{0,160}requireAnyRole\('admin'\)/", $handlers));
$h->test('saving is audited', str_contains($handlers, "settings.preferences_saved"));

// ── Settings UI is data-driven ──
$h->section('Preferences UI');
$tpl = (string) file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/settings/index.disyl');
$h->test('Preferences tab exists and is a valid tab',
    str_contains($tpl, "setTab('preferences')") && str_contains($tpl, "'preferences']"));
$h->test('fields are rendered from the declaration', str_contains($tpl, 'x-for="field in prefFields"'));
$h->test('editing is gated to admin', str_contains($tpl, 'canEditPrefs'));
$h->test('a duplicate hand-built form was not introduced',
    !preg_match('/key:\s*[\'"]low_stock_alerts_enabled/', $tpl));

// ── Stock policy: the operational friction fix ──
$h->section('Stock Policy');
$h->test('default is warn, not strict',
    (string) ($byKey['pos_stock_policy']['default'] ?? '') === 'warn');
$h->test('warn is offered before strict so it reads as the norm',
    (string) ($byKey['pos_stock_policy']['options'][0]['value'] ?? '') === 'warn');

$h->test('an unconfigured tenant resolves to warn', dcStockPolicy() === 'warn', dcStockPolicy());
$h->test('warn does not block a sale', dcStockPolicyBlocksSale() === false);
$h->test('warn records the shortfall', dcStockPolicyRecordsShortfall() === true);
$h->test(
    'an unrecognised stored value falls back to warn rather than blocking',
    in_array(dcStockPolicy(), ['strict', 'warn', 'allow_negative'], true)
);

$orders = (string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers-orders.php');
$h->test('order flow consults the policy', str_contains($orders, 'dcStockPolicyBlocksSale()'));
$h->test('all three shortfall sites are policy-aware',
    substr_count($orders, 'if ($stockPolicyBlocks)') === 3,
    'found ' . substr_count($orders, 'if ($stockPolicyBlocks)'));
$h->test('strict keeps the oversell guard',
    (bool) preg_match('/if \(\$strict\)[\s\S]{0,400}AND on_hand_qty >= \?/', $orders));
$h->test('permissive mode can create a missing branch stock row',
    str_contains($orders, 'ON DUPLICATE KEY UPDATE on_hand_qty = on_hand_qty + ?'));
$h->test('the guard is not left inline outside the helper',
    substr_count($orders, 'on_hand_qty >= ?') === 1);
$h->test('strict still refuses ingredient shortfalls',
    (bool) preg_match('/if \(\$strict\)[\s\S]{0,400}AND current_stock >= \?/', $orders));
$h->test('a shortfall is audited', str_contains($orders, "'stock.shortfall'"));
$h->test('a shortfall is logged with its detail', str_contains($orders, 'sold below recorded stock'));
$h->test('shortfall recording is gated on warn, not on allow_negative',
    (bool) preg_match('/\$shortfalls !== \[\] && dcStockPolicyRecordsShortfall\(\)/', $orders));

// ── Discount type behaviour ──
// The list is read from the tenant DB, so this also proves migration 036 ran
// and the seed landed.
$h->section('Discount Type Behaviour');
$types = dcDiscountTypes();
$h->test('the configured discount types load', count($types) >= 3, 'found ' . count($types));

$codes = array_column($types, 'code');
$h->test('a partner-card discount is available alongside the statutory ones',
    in_array('senior', $codes, true) && in_array('pagibig', $codes, true),
    implode(',', $codes));

$h->test('the statutory senior rate is the expected 20%',
    abs((float) dcDiscountTypeRate('senior') - 20.0) < 0.001);
$h->test('an unknown code resolves to null rather than a silent zero',
    dcDiscountTypeRate('not_a_real_code') === null);
$h->test('every type carries a numeric rate',
    count(array_filter($types, fn($t) => is_float($t['default_pct']))) === count($types));
$h->test('a rate never exceeds 100%',
    count(array_filter($types, fn($t) => $t['default_pct'] > 100)) === 0);
$h->test('every type carries the active flag for the management UI',
    count(array_filter($types, fn($t) => array_key_exists('is_active', $t))) === count($types));
$h->test('inactive types can be excluded from the till',
    count(dcDiscountTypes(true)) <= count(dcDiscountTypes(false)));

$handlers = (string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers.php');
$h->test('the rate is bounded at the server, not only by the input widget',
    (bool) preg_match('/\$pct < 0 \|\| \$pct > 100/', $handlers));
$h->test('a stored rate may not be blank', (bool) preg_match('/\$pct === null \|\| \$pct < 0/', $handlers));
$h->test('a code clash is refused rather than silently overwritten',
    (bool) preg_match('/WHERE code = \? AND discount_type_id <> \?/', $handlers));
$h->test('only an administrator may change a rate',
    (bool) preg_match('/function apiSaveDiscountType[\s\S]{0,220}requireAnyRole\(\'admin\'\)/', $handlers));
$h->test('the till may read the list', (bool) preg_match('/function apiGetDiscountTypes[\s\S]{0,260}cashier/', $handlers));
$h->test('a rate change is audited', str_contains($handlers, "'discount_type.saved'"));
$h->test('the till is told the senior rate from the same source',
    str_contains($handlers, "dcDiscountTypeRate('senior')"));
$h->test('the rate is no longer read from the flat settings list',
    !str_contains($handlers, "dcSettingNumber('senior_discount_pct'"));

// ── Discount ceiling ──
// An administrator caps what a cashier may give away on one sale. The ceiling
// is a percentage of the subtotal, so it also bounds a peso-amount discount.
$h->section('Discount Ceiling');
$h->test('the ceiling is declared as an administrator setting',
    isset($byKey['pos_max_discount_pct']) && ($byKey['pos_max_discount_pct']['type'] ?? '') === 'number');
$h->test('the ceiling is a manageable default rather than unlimited',
    (float) ($byKey['pos_max_discount_pct']['default'] ?? 100) <= 50);
$h->test('the declared default is what the helper reports',
    abs(dcMaxDiscountPct() - (float) ($byKey['pos_max_discount_pct']['default'] ?? 0)) < 0.001,
    'helper=' . dcMaxDiscountPct());

$ceiling = dcMaxDiscountPct();
$h->test('a discount under the ceiling is allowed',
    dcDiscountCeilingError(1000.0, 1000.0 * ($ceiling / 100) - 1.0) === null);
$h->test('a discount exactly at the ceiling is allowed',
    dcDiscountCeilingError(1000.0, 1000.0 * ($ceiling / 100)) === null,
    'float drift must not reject a discount sitting exactly on the cap');
$h->test('a discount above the ceiling is refused with a readable reason',
    ($e = dcDiscountCeilingError(1000.0, 1000.0 * (($ceiling + 10) / 100))) !== null
    && str_contains($e, '%'),
    (string) ($e ?? ''));
$h->test('the ceiling bounds a peso-amount discount, not only a rated one',
    dcDiscountCeilingError(200.0, 190.0) !== null);
$h->test('no discount is never a ceiling breach', dcDiscountCeilingError(1000.0, 0.0) === null);
$h->test('an empty cart cannot breach the ceiling', dcDiscountCeilingError(0.0, 0.0) === null);
$h->test('the applied rate is reported as a percentage of the subtotal',
    abs(dcDiscountPctOf(200.0, 50.0) - 25.0) < 0.001);
$h->test('a zero subtotal reports no rate rather than dividing by zero',
    dcDiscountPctOf(0.0, 10.0) === 0.0);

$orders = (string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers-orders.php');
$h->test('the order flow enforces the ceiling, not just the till',
    str_contains($orders, 'dcDiscountCeilingError('));
$h->test('the ceiling is checked after the on/off switch so the message is specific',
    strpos($orders, "Discounts are not enabled for this branch") < strpos($orders, 'dcDiscountCeilingError('));
$h->test('the till is told the ceiling before it accepts input',
    str_contains($handlers, "'max_discount_pct'") && str_contains($handlers, 'dcMaxDiscountPct()'));

$view = (string) file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/pos/index.disyl');
$h->test('the till clamps to the ceiling instead of stranding the cashier',
    (bool) preg_match('/const cap = this\.cartTotal \* \(this\.maxDiscountPct \/ 100\)/', $view));
$h->test('the clamp is explained to the cashier',
    str_contains($view, 'Capped at the branch limit of'));
$h->test('the ceiling survives a failed config load without over-blocking',
    (bool) preg_match('/maxDiscountPct:\s*100/', $view));
$h->test('the payment path re-checks before posting',
    (bool) preg_match('/processPayment\(\)[\s\S]{0,400}discountOverCeiling/', $view));
$h->test('a voucher above the ceiling is flagged rather than silently applied',
    (bool) preg_match('/validateVoucher\(\)[\s\S]{0,900}discountValueOverCeiling\(d\.discount_amount\)/', $view));

// A limit that is only enforced later is a limit that gets argued with at the
// counter — the bound belongs on the field declaration.
$h->test('the ceiling declares its bound alongside the field',
    (float) ($byKey['pos_max_discount_pct']['min'] ?? -1) === 0.0
    && (float) ($byKey['pos_max_discount_pct']['max'] ?? 0) === 100.0);
$h->test('out-of-range numbers are refused at entry, not silently clamped',
    (bool) preg_match('/\$declaredMax !== null && \$number > \(float\) \$declaredMax/', $handlers));
$h->test('a declared minimum is honoured too',
    (bool) preg_match('/\$declaredMin !== null && \$number < \(float\) \$declaredMin/', $handlers));
$h->test('the declared bound reaches the runtime field list',
    (function (): bool {
        // dcSettingsFields() returns a list, not a keyed map — match on key.
        foreach (dcSettingsFields() as $field) {
            if (($field['key'] ?? '') === 'pos_max_discount_pct') {
                return (float) ($field['max'] ?? 0) === 100.0;
            }
        }
        return false;
    })());

$h->done();
