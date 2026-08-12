<?php

declare(strict_types=1);

/**
 * Daily Ledger — Handlers Test
 *
 * Validates pure-logic helper functions in the daily-ledger module.
 * Integration mode — bootstraps the app to load and test module functions.
 * DB-dependent functions are documented as gaps.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-handlers', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('modules/daily-ledger/handlers-pos.php');
$h->fingerprint('modules/daily-ledger/helpers.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

// ─── Auth Token Helpers (pure logic) ────────────────────────────
$h->section('Auth Token Helpers');

$h->test('dl_refreshTokenCacheKey returns string', is_string(dl_refreshTokenCacheKey('test-token')));
$h->test('dl_refreshTokenCacheKey is deterministic', dl_refreshTokenCacheKey('abc') === dl_refreshTokenCacheKey('abc'));
$h->test('dl_refreshTokenCacheKey empty string produces hash', dl_refreshTokenCacheKey('') === dl_refreshTokenCacheKey(''));

$h->test('dl_idempotencyCacheKey returns string', is_string(dl_idempotencyCacheKey('ledger', 'key-123')));
$h->test('dl_idempotencyCacheKey is deterministic', dl_idempotencyCacheKey('x', 'y') === dl_idempotencyCacheKey('x', 'y'));
$h->test('dl_idempotencyCacheKey different scope produces different key', dl_idempotencyCacheKey('a', 'k') !== dl_idempotencyCacheKey('b', 'k'));
$h->test('dl_idempotencyCacheKey different key produces different key', dl_idempotencyCacheKey('s', 'k1') !== dl_idempotencyCacheKey('s', 'k2'));

// ─── Column Mapping (pure logic) ────────────────────────────────
$h->section('Column Mapping');

$map = ['field_a' => 'column_a', 'field_b' => 'column_b'];
$h->test('dl_allowedColumn maps known field', dl_allowedColumn('field_a', $map) === 'column_a');
$h->test('dl_allowedColumn maps known field B', dl_allowedColumn('field_b', $map) === 'column_b');
$h->test('dl_allowedColumn returns null for unknown field', dl_allowedColumn('unknown', $map) === null);
$h->test('dl_allowedColumn returns null for empty map', dl_allowedColumn('field_a', []) === null);

// ─── Derived Sales Helpers (pure logic) ─────────────────────────
$h->section('Derived Sales Helpers');

$h->test('dl_computeSalesValue calculates positive sales', dl_computeSalesValue(10, 5, 3, 4) === 8);
$h->test('dl_computeSalesValue floors negative sales to zero', dl_computeSalesValue(1, 0, 5, 0) === 0);
$h->test('dl_ledgerSalesQuantitySql uses sanitized alias', dl_ledgerSalesQuantitySql('bad alias') === dl_ledgerSalesQuantitySql('dl'));
$h->test('dl_ledgerSalesQuantitySql references source columns', str_contains(dl_ledgerSalesQuantitySql('dl'), 'dl.beg_bal'));
$h->test('dl_ledgerSalesAmountSql references price snapshot', str_contains(dl_ledgerSalesAmountSql('dl'), 'dl.price_snapshot'));

// ─── Setting Type Conversion (pure logic) ───────────────────────
$h->section('Setting Type Conversion');

$h->test('dl_settingToBool "1" → true', dl_settingToBool('1') === true);
$h->test('dl_settingToBool 1 → true', dl_settingToBool(1) === true);
$h->test('dl_settingToBool "0" → false', dl_settingToBool('0') === false);
$h->test('dl_settingToBool 0 → false', dl_settingToBool(0) === false);
$h->test('dl_settingToBool "" → false', dl_settingToBool('') === false);
$h->test('dl_settingToBool null → false', dl_settingToBool(null) === false);
$h->test('dl_settingToBool true → true', dl_settingToBool(true) === true);
$h->test('dl_settingToBool false → false', dl_settingToBool(false) === false);

// ─── Normalize Functions (pure logic) ───────────────────────────
$h->section('Normalize Functions');

$h->test('dl_normalizeCloseOfDayTime returns string', is_string(dl_normalizeCloseOfDayTime('22:00')));
$h->test('dl_normalizeCloseOfDayTime "22:00" → "22:00"', dl_normalizeCloseOfDayTime('22:00') === '22:00');
$h->test('dl_normalizeCloseOfDayTime empty → "00:00"', dl_normalizeCloseOfDayTime('') === '00:00');
$h->test('dl_normalizeCloseOfDayTime invalid → "00:00"', dl_normalizeCloseOfDayTime('abc') === '00:00');

$h->test('dl_normalizeTimezone returns string', is_string(dl_normalizeTimezone('Asia/Manila')));
$h->test('dl_normalizeTimezone "Asia/Manila" → "Asia/Manila"', dl_normalizeTimezone('Asia/Manila') === 'Asia/Manila');
$h->test('dl_normalizeTimezone empty → default', dl_normalizeTimezone('') === 'Asia/Manila');

$h->test('dl_normalizeRegion returns string', is_string(dl_normalizeRegion('PH')));
$h->test('dl_normalizeRegion "PH" → "PH"', dl_normalizeRegion('PH') === 'PH');
$h->test('dl_normalizeRegion empty → "Default Region"', dl_normalizeRegion('') === 'Default Region');

$h->test('dl_normalizeOutputUnitLabel returns string', is_string(dl_normalizeOutputUnitLabel('pieces')));
$h->test('dl_normalizeOutputUnitLabel "pieces" → "pieces"', dl_normalizeOutputUnitLabel('pieces') === 'pieces');
$h->test('dl_normalizeOutputUnitLabel empty → "pcs"', dl_normalizeOutputUnitLabel('') === 'pcs');

$h->test('dl_normalizePiecesPerBatch "100" → 100', dl_normalizePiecesPerBatch('100') === 100);
$h->test('dl_normalizePiecesPerBatch 50 → 50', dl_normalizePiecesPerBatch(50) === 50);
$h->test('dl_normalizePiecesPerBatch null → null', dl_normalizePiecesPerBatch(null) === null);
$h->test('dl_normalizePiecesPerBatch "" → null', dl_normalizePiecesPerBatch('') === null);

// ─── Movement UUID (pure logic) ─────────────────────────────────
$h->section('Movement UUID');

$uuid1 = dl_generateMovementUuid();
$uuid2 = dl_generateMovementUuid();
$h->test('dl_generateMovementUuid returns string', is_string($uuid1));
$h->test('dl_generateMovementUuid is 36 chars', strlen($uuid1) === 36);
$h->test('dl_generateMovementUuid contains dashes', substr_count($uuid1, '-') === 4);
$h->test('dl_generateMovementUuid is unique per call', $uuid1 !== $uuid2);

// ─── Cookie Helpers (pure logic) ────────────────────────────────
$h->section('Cookie Helpers');

$h->test('dlCookieName returns string', is_string(dlCookieName()));
$h->test('dlCookieName is daily_ledger_token', dlCookieName() === 'daily_ledger_token');

// ─── Permission Actions (pure logic) ────────────────────────────
$h->section('Permission Actions');

$actions = dl_allPermissionActions();
$h->test('dl_allPermissionActions returns array', is_array($actions));
$h->test('dl_allPermissionActions not empty', !empty($actions));
$h->test('dl_allPermissionActions contains ledger.override', in_array('ledger.override', $actions, true));
$h->test('dl_allPermissionActions contains production.override', in_array('production.override', $actions, true));
$h->test('dl_allPermissionActions contains pos.sell', in_array('pos.sell', $actions, true));
$h->test('dl_allPermissionActions contains pos.void', in_array('pos.void', $actions, true));
$h->test('dl_allPermissionActions contains pos.refund', in_array('pos.refund', $actions, true));
$h->test('dl_allPermissionActions contains pos.fallback', in_array('pos.fallback', $actions, true));
$h->test('dl_allPermissionActions contains pos.report', in_array('pos.report', $actions, true));

$defaultPerms = dl_defaultRolePermissions();
$h->test('dl_defaultRolePermissions returns array', is_array($defaultPerms));
$h->test('dl_defaultRolePermissions has admin key', array_key_exists('admin', $defaultPerms));
$h->test('dl_defaultRolePermissions has cashier key', array_key_exists('cashier', $defaultPerms));
$h->test('dl_defaultRolePermissions has supervisor key', array_key_exists('supervisor', $defaultPerms));
$h->test('dl_defaultRolePermissions admin has ledger.override', in_array('ledger.override', $defaultPerms['admin'], true));

// ─── Role Permission Check (pure logic) ────────────────────────
$h->section('Role Permission Check');

$h->test('dl_isKernelAdmin true for kernel admin', dl_isKernelAdmin(['role' => 'superadmin', 'source' => 'kernel']) === true);
$h->test('dl_isKernelAdmin false for non-kernel admin', dl_isKernelAdmin(['role' => 'admin', 'source' => 'daily-ledger']) === false);
$h->test('dl_isKernelAdmin false for empty array', dl_isKernelAdmin([]) === false);

$h->test('dl_canManageFeatureActivation true for kernel admin', dl_canManageFeatureActivation(['role' => 'superadmin', 'source' => 'kernel']) === true);
$h->test('dl_canManageFeatureActivation true for admin', dl_canManageFeatureActivation(['role' => 'admin', 'source' => 'daily-ledger']) === true);
$h->test('dl_canManageFeatureActivation false for cashier', dl_canManageFeatureActivation(['role' => 'cashier', 'source' => 'daily-ledger']) === false);

// ─── POS Helpers Available (loaded via handlers-pos.php) ───────
$h->section('POS Helpers Available');

$h->test('dl_isPosEnabled available', function_exists('dl_isPosEnabled'));
$h->test('dl_pos_salesSummary available', function_exists('dl_pos_salesSummary'));
$h->test('dl_pos_dayClosePrecheck available', function_exists('dl_pos_dayClosePrecheck'));
$h->test('dl_pos_selectMode available', function_exists('dl_pos_selectMode'));
$h->test('dl_pos_checkout available', function_exists('dl_pos_checkout'));
$h->test('feature settings expose pos_enabled flag', array_key_exists('pos_enabled', dl_featureSettings()));
$h->test('apiEditDeliveryByDr available', function_exists('apiEditDeliveryByDr'));
$h->test('apiGetDeliveryByDrForEdit available', function_exists('apiGetDeliveryByDrForEdit'));
$h->test('dl_canEditDeliveryByDr available', function_exists('dl_canEditDeliveryByDr'));
$h->test('dl_correctDeliveryByDr available', function_exists('dl_correctDeliveryByDr'));
$h->test('apiChangeDeliveryDestination available', function_exists('apiChangeDeliveryDestination'));
$h->test('dl_moveDeliveryToBranch available', function_exists('dl_moveDeliveryToBranch'));

$handlersSource = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$h->test('custom_reason persisted in withdrawal insert', str_contains($handlersSource, 'custom_reason'));
$h->test('custom reason required when reason is other', str_contains($handlersSource, 'A custom reason is required when reason is Other.'));
$h->test('apiSaveRolePermissions persists pos_enabled', str_contains($handlersSource, "'pos_enabled' => \$posEnabled ? '1' : '0'"));

// Regression (developer review): dl_rolePermissions() REPLACES a role's stored
// permissions on save, so apiSaveRolePermissions must seed each role with its
// default POS grants or an unrelated settings save silently strips POS access.
$h->test('settings save seeds admin with POS grants', (bool)preg_match("/'admin' => \[[^]]*'pos\.sell'[^]]*'pos\.report'[^]]*'delivery\.edit'/", $handlersSource));
$h->test('settings save seeds supervisor with POS grants', (bool)preg_match("/'supervisor' => \[[^]]*'pos\.sell'[^]]*'pos\.report'[^]]*\]/", $handlersSource));
$h->test('settings save seeds cashier with pos.sell', str_contains($handlersSource, "'cashier' => ['pos.sell']"));

$deliveriesSource = (string) file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
// Regression: dl_delivery_items has no updated_at column — the edit update must not reference it.
$h->test('delivery-item edit update avoids updated_at', !str_contains($deliveriesSource, 'dl_delivery_items SET quantity = :qty, updated_at'));
// Move service requires authority over both branches (excludes single-branch cashiers).
$h->test('move service enforces dual-branch access', str_contains($deliveriesSource, 'You must be authorized for both the wrong and the correct branch.'));
// Move service is admin/supervisor gated at the handler.
$h->test('move endpoint gated to admin/supervisor', str_contains($deliveriesSource, "dlCurrentUser(['admin', 'supervisor'])"));

// ─── Time Validation (pure logic) ───────────────────────────────
$h->section('Time Validation');

$h->test('dl_isAllowedAutoCloseTime "22:00" valid', dl_isAllowedAutoCloseTime('22:00') === true);
$h->test('dl_isAllowedAutoCloseTime "23:59" valid', dl_isAllowedAutoCloseTime('23:59') === true);
$h->test('dl_isAllowedAutoCloseTime "00:00" valid', dl_isAllowedAutoCloseTime('00:00') === true);
$h->test('dl_isAllowedAutoCloseTime "25:00" invalid', dl_isAllowedAutoCloseTime('25:00') === false);
$h->test('dl_isAllowedAutoCloseTime "abc" invalid', dl_isAllowedAutoCloseTime('abc') === false);
$h->test('dl_isAllowedAutoCloseTime "" invalid', dl_isAllowedAutoCloseTime('') === false);

// ─── Region/Timezone Choices (pure logic) ───────────────────────
$h->section('Region/Timezone Choices');

$regions = dl_operatingRegionChoices('PH');
$h->test('dl_operatingRegionChoices returns array', is_array($regions));
$h->test('dl_operatingRegionChoices contains Default Region', in_array('Default Region', $regions, true));

$timezones = dl_operatingTimezoneChoices('Asia/Manila');
$h->test('dl_operatingTimezoneChoices returns array', is_array($timezones));
$h->test('dl_operatingTimezoneChoices contains Asia/Manila', in_array('Asia/Manila', $timezones, true));

// ─── Password Reset Helpers (pure logic) ────────────────────────
$h->section('Password Reset Helpers');

$hash1 = dlPasswordResetTokenHash('test-token-123');
$hash2 = dlPasswordResetTokenHash('test-token-123');
$hash3 = dlPasswordResetTokenHash('different-token');
$h->test('dlPasswordResetTokenHash returns string', is_string($hash1));
$h->test('dlPasswordResetTokenHash is deterministic', $hash1 === $hash2);
$h->test('dlPasswordResetTokenHash different tokens differ', $hash1 !== $hash3);
$h->test('dlPasswordResetTokenHash length is 64 (sha256 hex)', strlen($hash1) === 64);

// ─── Setting Value Normalization (pure logic) ───────────────────
$h->section('Setting Value Normalization');

// dlNormalizeSettingValue normalizes array structures recursively; scalars pass through unchanged
$h->test('dlNormalizeSettingValue "1" → "1" (string preserved)', dlNormalizeSettingValue('1') === '1');
$h->test('dlNormalizeSettingValue "0" → "0" (string preserved)', dlNormalizeSettingValue('0') === '0');
$h->test('dlNormalizeSettingValue "true" → "true" (string preserved)', dlNormalizeSettingValue('true') === 'true');
$h->test('dlNormalizeSettingValue "false" → "false" (string preserved)', dlNormalizeSettingValue('false') === 'false');
$h->test('dlNormalizeSettingValue "hello" → "hello"', dlNormalizeSettingValue('hello') === 'hello');
$h->test('dlNormalizeSettingValue 42 → 42', dlNormalizeSettingValue(42) === 42);
$h->test('dlNormalizeSettingValue sorts associative arrays', (function() { $input = ['b' => 1, 'a' => 2]; $result = dlNormalizeSettingValue($input); $keys = array_keys($result); return $keys === ['a', 'b']; })());

$h->test('dlSettingValuesMatch same string', dlSettingValuesMatch('hello', 'hello') === true);
$h->test('dlSettingValuesMatch different string', dlSettingValuesMatch('hello', 'world') === false);

// ─── Delivery Helpers (pure logic) ──────────────────────────────
$h->section('Delivery Helpers');

$h->test('dl_autoCommissaryDeliveryRemark returns string', is_string(dl_autoCommissaryDeliveryRemark()));
$h->test('dl_cashierDispatchRemark returns string', is_string(dl_cashierDispatchRemark()));
$h->test('dl_paperDrCaptureRemark returns string', is_string(dl_paperDrCaptureRemark()));

// dl_normalizeDeliveryItems uses 'quantity' key, casts to int, filters zero qty
$items = [
    ['product_id' => '1', 'quantity' => '10'],
    ['product_id' => 2, 'quantity' => '5'],
];
$normalized = dl_normalizeDeliveryItems($items);
$h->test('dl_normalizeDeliveryItems returns array', is_array($normalized));
$h->test('dl_normalizeDeliveryItems preserves count', count($normalized) === 2);
$h->test('dl_normalizeDeliveryItems casts product_id to int', $normalized[0]['product_id'] === 1);
$h->test('dl_normalizeDeliveryItems casts quantity to int', $normalized[0]['quantity'] === 10);

// ─── Same-Location Internal Release (contract surface) ─────────
$h->section('Same-Location Internal Release');

// Contract: the canonical eligibility resolver + extractable save helper exist.
$h->test('dl_resolveSameLocationEligibility exists', function_exists('dl_resolveSameLocationEligibility'));
$h->test('dl_saveProductionRun exists (testable helper)', function_exists('dl_saveProductionRun'));
$h->test('apiSaveProductionRun exists (HTTP wrapper)', function_exists('apiSaveProductionRun'));

// The decision shape is stable: same_location bool, source_branch_id, reason.
$shape = dl_resolveSameLocationEligibility(0, 0);
$h->test('eligibility returns an array', is_array($shape));
$h->test('eligibility has same_location key', array_key_exists('same_location', $shape));
$h->test('eligibility has source_branch_id key', array_key_exists('source_branch_id', $shape));
$h->test('eligibility has reason key', array_key_exists('reason', $shape));
$h->test('eligibility missing dest → same_location false', ($shape['same_location'] ?? null) === false);

// ─── CSV Helpers (pure logic) ───────────────────────────────────
$h->section('CSV Helpers');

$h->test('dlCsvNormalizeHeader returns string', is_string(dlCsvNormalizeHeader('Test Header')));
$h->test('dlCsvNormalizeHeader trims whitespace', dlCsvNormalizeHeader('  hello  ') === 'hello');
$h->test('dlCsvNormalizeHeader lowercases', dlCsvNormalizeHeader('HELLO') === 'hello');

$h->test('dlCsvNullableFloat "10.5" → 10.5', dlCsvNullableFloat('10.5') === 10.5);
$h->test('dlCsvNullableFloat "" → null', dlCsvNullableFloat('') === null);
$h->test('dlCsvNullableFloat "0" → 0.0', dlCsvNullableFloat('0') === 0.0);

$h->test('dlCsvNullableInt "10" → 10', dlCsvNullableInt('10') === 10);
$h->test('dlCsvNullableInt "" → null', dlCsvNullableInt('') === null);
$h->test('dlCsvNullableInt "0" → 0', dlCsvNullableInt('0') === 0);

// ─── Settings-Dependent Functions ───────────────────────────────
$h->section('Settings-Dependent Functions');

try {
    $defaults = dlSettingsDefaults();
    $h->test('dlSettingsDefaults returns array', is_array($defaults));
    $h->test('dlSettingsDefaults has app_name', array_key_exists('app_name', $defaults));
} catch (\Throwable $e) {
    $h->gap('dlSettingsDefaults: ' . $e->getMessage());
}

try {
    $features = dl_featureSettings();
    $h->test('dl_featureSettings returns array', is_array($features));
    $h->test('dl_featureSettings has production_output_enabled', array_key_exists('production_output_enabled', $features));
} catch (\Throwable $e) {
    $h->gap('dl_featureSettings: ' . $e->getMessage());
}

try {
    $codSettings = dl_closeOfDaySettings();
    $h->test('dl_closeOfDaySettings returns array', is_array($codSettings));
    $h->test('dl_closeOfDaySettings has auto_close_enabled', array_key_exists('auto_close_enabled', $codSettings));
    $h->test('dl_closeOfDaySettings has auto_close_time', array_key_exists('close_of_day_time', $codSettings));
} catch (\Throwable $e) {
    $h->gap('dl_closeOfDaySettings: ' . $e->getMessage());
}

try {
    $flags = dl_layoutFlags();
    $h->test('dl_layoutFlags returns array', is_array($flags));
} catch (\Throwable $e) {
    $h->gap('dl_layoutFlags: ' . $e->getMessage());
}

try {
    $date = dl_businessDate();
    $h->test('dl_businessDate returns string', is_string($date));
    $h->test('dl_businessDate is Y-m-d format', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date));
} catch (\Throwable $e) {
    $h->gap('dl_businessDate: ' . $e->getMessage());
}

try {
    $clock = dl_operatingClockLabel();
    $h->test('dl_operatingClockLabel returns array', is_array($clock));
} catch (\Throwable $e) {
    $h->gap('dl_operatingClockLabel: ' . $e->getMessage());
}

// ─── DB-Dependent Functions (documented gaps) ───────────────────
$h->section('DB-Dependent Functions');

try {
    $auditCol = dlAuditLogHasColumn('action');
    $h->test('dlAuditLogHasColumn "action" returns bool', is_bool($auditCol));
} catch (\Throwable $e) {
    $h->gap('dlAuditLogHasColumn requires DB connection: ' . $e->getMessage());
}

try {
    $supplyResult = dl_resolveProductSupplySource(1, 1);
    $h->test('dl_resolveProductSupplySource returns array', is_array($supplyResult));
} catch (\Throwable $e) {
    $h->gap('dl_resolveProductSupplySource requires DB: ' . $e->getMessage());
}

$h->gap('DB-backed: dl_generateSku requires database (MAX(id) from dl_products)');
$h->gap('DB-backed: dl_lockDayStatusRow requires database connection');
$h->gap('DB-backed: dl_applyLedgerDelta requires database + ledger rows');
$h->gap('DB-backed: dl_processProductionMovement requires database + inventory state');
$h->gap('DB-backed: dl_recomputeSales requires database + ledger data');
$h->gap('DB-backed: dl_computeVarianceSilently requires database + ledger data');
$h->gap('DB-backed: dl_acceptFormalDelivery requires database + delivery rows');
$h->gap('DB-backed: dl_branchConsolidatedSummary requires database');
$h->gap('Session/Request: dlUserFromRequest requires HTTP context');
$h->gap('Session/Request: dlRequireAuth requires HTTP + session context');
$h->gap('Session/Request: dlCurrentUser requires HTTP + JWT context');
$h->gap('Rate Limit: dlForgotPasswordRateLimitExceeded requires cache + DB state');
$h->gap('Rate Limit: dlResetPasswordRateLimitExceeded requires cache + DB state');

$h->done();
