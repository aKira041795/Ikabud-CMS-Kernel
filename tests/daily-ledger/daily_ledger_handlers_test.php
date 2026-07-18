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
