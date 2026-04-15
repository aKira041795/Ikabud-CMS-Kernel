<?php
/**
 * Tier 3 Feature Completeness Tests
 *
 * Covers: saved payment methods, customer address book, order editing,
 * return→refund auto-link, report caching/timezone, API key auth,
 * encryption key rotation, circuit breaker half-open/events/manual override,
 * builder schema versioning.
 */
require __DIR__ . '/../bootstrap.php';

// Kernel class requires + use statements (must be before executable code)
require_once __DIR__ . '/../kernel/Services/ApiKeyAuth.php';
require_once __DIR__ . '/../kernel/Crypto.php';
require_once __DIR__ . '/../kernel/Capabilities/CapabilityRegistry.php';
require_once __DIR__ . '/../kernel/Capabilities/CapabilityBus.php';
require_once __DIR__ . '/../kernel/Capabilities/CapabilityCallException.php';

use Ikabud\Kernel\Services\ApiKeyAuth;
use Ikabud\Kernel\Crypto;
use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;

$passed = 0;
$failed = 0;
function t3assert(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS: $msg\n"; }
    else       { $failed++; echo "  FAIL: $msg\n"; }
}

// ── Silence HTML output from module loading ──
set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    if ($errno === E_WARNING || $errno === E_NOTICE || $errno === E_DEPRECATED) return true;
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// -----------------------------------------------------------------
// 1. Saved Payment Methods helpers
// -----------------------------------------------------------------
echo "\n=== 1. Saved Payment Methods ===\n";
ob_start();
require_once __DIR__ . '/../src/helpers/module-manager.php';
ob_end_clean();

$ecHelpersDir = __DIR__ . '/../modules/ecommerce/helpers/';
ob_start();
if (file_exists($ecHelpersDir . '00-init.php'))  require_once $ecHelpersDir . '00-init.php';
if (file_exists($ecHelpersDir . '01-settings.php'))  require_once $ecHelpersDir . '01-settings.php';
if (file_exists($ecHelpersDir . '10-products.php'))  require_once $ecHelpersDir . '10-products.php';
if (file_exists($ecHelpersDir . '20-orders.php'))  require_once $ecHelpersDir . '20-orders.php';
if (file_exists($ecHelpersDir . '22-returns.php'))  require_once $ecHelpersDir . '22-returns.php';
if (file_exists($ecHelpersDir . '50-reports.php'))  require_once $ecHelpersDir . '50-reports.php';
if (file_exists($ecHelpersDir . '60-cart.php'))     require_once $ecHelpersDir . '60-cart.php';
if (file_exists($ecHelpersDir . '65-customers.php')) require_once $ecHelpersDir . '65-customers.php';
if (file_exists($ecHelpersDir . '70-checkout.php')) require_once $ecHelpersDir . '70-checkout.php';
if (file_exists($ecHelpersDir . '72-gateway-stripe.php')) require_once $ecHelpersDir . '72-gateway-stripe.php';
if (file_exists($ecHelpersDir . '74-saved-payment-methods.php')) require_once $ecHelpersDir . '74-saved-payment-methods.php';
ob_end_clean();

t3assert(function_exists('ecSavedPaymentMethodsAvailable'), 'ecSavedPaymentMethodsAvailable exists');
t3assert(function_exists('ecSavedPaymentMethodList'), 'ecSavedPaymentMethodList exists');
t3assert(function_exists('ecSavedPaymentMethodGet'), 'ecSavedPaymentMethodGet exists');
t3assert(function_exists('ecSavedPaymentMethodSave'), 'ecSavedPaymentMethodSave exists');
t3assert(function_exists('ecSavedPaymentMethodDelete'), 'ecSavedPaymentMethodDelete exists');
t3assert(function_exists('ecSavedPaymentMethodSetDefault'), 'ecSavedPaymentMethodSetDefault exists');
// Table detection (no DB available → false)
t3assert(ecSavedPaymentMethodsAvailable() === false, 'ecSavedPaymentMethodsAvailable returns false when no DB');

// Stripe customer API functions exist
t3assert(function_exists('ecStripeCreateCustomer'), 'ecStripeCreateCustomer exists');
t3assert(function_exists('ecStripeAttachPaymentMethod'), 'ecStripeAttachPaymentMethod exists');
t3assert(function_exists('ecStripeDetachPaymentMethod'), 'ecStripeDetachPaymentMethod exists');
t3assert(function_exists('ecStripeListPaymentMethods'), 'ecStripeListPaymentMethods exists');

// -----------------------------------------------------------------
// 2. Customer Address Book
// -----------------------------------------------------------------
echo "\n=== 2. Customer Address Book ===\n";
t3assert(function_exists('ecCustomerAddressGet'), 'ecCustomerAddressGet exists');
t3assert(function_exists('ecCustomerAddressCreate'), 'ecCustomerAddressCreate exists');
t3assert(function_exists('ecCustomerAddressUpdate'), 'ecCustomerAddressUpdate exists');
t3assert(function_exists('ecCustomerAddressDelete'), 'ecCustomerAddressDelete exists');
t3assert(function_exists('ecCustomerAddressSetDefault'), 'ecCustomerAddressSetDefault exists');
t3assert(function_exists('ecCustomerDefaultAddress'), 'ecCustomerDefaultAddress exists');
t3assert(function_exists('ecCheckoutPrefillAddress'), 'ecCheckoutPrefillAddress exists');

// Address Create validates required fields (returns error array, not exception)
// Without DB, ecTableExists returns false, so we get table-not-available error
// Test the function exists and returns an error array
$addrResult = ecCustomerAddressCreate(1, []);
t3assert(
    is_array($addrResult) && ($addrResult['ok'] ?? true) === false,
    'ecCustomerAddressCreate returns error for empty data (no DB or missing fields)'
);

// -----------------------------------------------------------------
// 3. Order Editing
// -----------------------------------------------------------------
echo "\n=== 3. Order Editing ===\n";
t3assert(defined('EC_ORDER_EDITABLE_STATUSES'), 'EC_ORDER_EDITABLE_STATUSES constant defined');
t3assert(EC_ORDER_EDITABLE_STATUSES === ['pending', 'processing'], 'Editable statuses are pending + processing');
t3assert(function_exists('ecOrderEditsAvailable'), 'ecOrderEditsAvailable exists');
t3assert(function_exists('ecOrderIsEditable'), 'ecOrderIsEditable exists');
t3assert(function_exists('ecOrderAddItem'), 'ecOrderAddItem exists');
t3assert(function_exists('ecOrderRemoveItem'), 'ecOrderRemoveItem exists');
t3assert(function_exists('ecOrderUpdateItemQty'), 'ecOrderUpdateItemQty exists');
t3assert(function_exists('ecOrderRecordEdit'), 'ecOrderRecordEdit exists');
t3assert(function_exists('ecOrderEditHistory'), 'ecOrderEditHistory exists');

// Order editability logic (pure function, no DB needed)
t3assert(ecOrderIsEditable(['status' => 'pending']) === true, 'Pending order is editable');
t3assert(ecOrderIsEditable(['status' => 'processing']) === true, 'Processing order is editable');
t3assert(ecOrderIsEditable(['status' => 'completed']) === false, 'Completed order is not editable');
t3assert(ecOrderIsEditable(['status' => 'cancelled']) === false, 'Cancelled order is not editable');
t3assert(ecOrderIsEditable(['status' => 'shipped']) === false, 'Shipped order is not editable');
t3assert(ecOrderIsEditable([]) === false, 'Order with no status is not editable');

// -----------------------------------------------------------------
// 4. Return → Refund Auto-Link
// -----------------------------------------------------------------
echo "\n=== 4. Return Auto-Refund ===\n";
t3assert(function_exists('ecReturnRequestAutoRefund'), 'ecReturnRequestAutoRefund exists');
// ecReturnRequestAutoRefund maps items to refund by qty × unit_price
// Without DB, we can't fully test but can confirm the function signature exists

// -----------------------------------------------------------------
// 5. Report Caching & Timezone
// -----------------------------------------------------------------
echo "\n=== 5. Report Caching & Timezone ===\n";
t3assert(function_exists('ecReportCacheAvailable'), 'ecReportCacheAvailable exists');
t3assert(function_exists('ecReportCacheKey'), 'ecReportCacheKey exists');
t3assert(function_exists('ecReportCacheGet'), 'ecReportCacheGet exists');
t3assert(function_exists('ecReportCacheSet'), 'ecReportCacheSet exists');
t3assert(function_exists('ecReportCacheInvalidate'), 'ecReportCacheInvalidate exists');
t3assert(function_exists('ecReportCachePurgeExpired'), 'ecReportCachePurgeExpired exists');
t3assert(function_exists('ecReportTimezone'), 'ecReportTimezone exists');
t3assert(function_exists('ecReportDateRangeWithTimezone'), 'ecReportDateRangeWithTimezone exists');
t3assert(function_exists('ecReportConvertDateToTimezone'), 'ecReportConvertDateToTimezone exists');

// Cache key determinism
$key1 = ecReportCacheKey('sales', ['store_id' => 1, 'start' => '2026-01-01', 'end' => '2026-01-31']);
$key2 = ecReportCacheKey('sales', ['store_id' => 1, 'start' => '2026-01-01', 'end' => '2026-01-31']);
$key3 = ecReportCacheKey('sales', ['store_id' => 2, 'start' => '2026-01-01', 'end' => '2026-01-31']);
t3assert($key1 === $key2, 'Same params produce same cache key');
t3assert($key1 !== $key3, 'Different store_id produces different cache key');
t3assert(str_starts_with($key1, 'sales:'), 'Cache key starts with report type');

// Timezone resolution: explicit param wins
$tz = ecReportTimezone(['timezone' => 'America/New_York']);
t3assert($tz === 'America/New_York', 'Explicit timezone param is used');

// Timezone resolution: fallback to server default
$tz2 = ecReportTimezone([]);
t3assert(is_string($tz2) && $tz2 !== '', 'Timezone fallback returns non-empty string');

// Date conversion
$converted = ecReportConvertDateToTimezone('2026-01-15 12:00:00', 'UTC', 'America/New_York');
t3assert(str_contains($converted, '2026-01-15'), 'Date conversion preserves date');
t3assert($converted !== '2026-01-15 12:00:00', 'Date conversion changes time for different TZ');

// -----------------------------------------------------------------
// 6. API Key Authentication
// -----------------------------------------------------------------
echo "\n=== 6. API Key Authentication ===\n";

// Create an in-memory SQLite DB to test API key operations
$pdo = new \PDO('sqlite::memory:', null, null, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
// Mimic the MySQL table but with SQLite-compatible syntax
$pdo->exec("CREATE TABLE kernel_api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    key_prefix TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    scopes TEXT DEFAULT '[]',
    rate_limit INTEGER DEFAULT 1000,
    last_used_at TEXT NULL,
    expires_at TEXT NULL,
    is_active INTEGER DEFAULT 1,
    created_by TEXT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)");

// ApiKeyAuth uses NOW() which is MySQL only. For SQLite test, we need a wrapper.
// Instead of full class test with INSERT, test the contract at the interface level.
$auth = new ApiKeyAuth($pdo);

// Table exists check
t3assert($auth->tableExists() === true, 'tableExists returns true with table');

// Test extractKeyFromRequest (static, no DB needed)
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test_api_key_12345';
$extracted = ApiKeyAuth::extractKeyFromRequest();
t3assert($extracted === 'test_api_key_12345', 'extractKeyFromRequest extracts Bearer token');
unset($_SERVER['HTTP_AUTHORIZATION']);

$_SERVER['HTTP_X_API_KEY'] = 'xapi_key_67890';
$extracted2 = ApiKeyAuth::extractKeyFromRequest();
t3assert($extracted2 === 'xapi_key_67890', 'extractKeyFromRequest extracts X-API-Key header');
unset($_SERVER['HTTP_X_API_KEY']);

$extracted3 = ApiKeyAuth::extractKeyFromRequest();
t3assert($extracted3 === null, 'extractKeyFromRequest returns null when no key');

// Scope checking (pure logic, no DB)
$mockRecord = ['scopes' => json_encode(['read', 'write'])];
t3assert($auth->hasScope($mockRecord, 'read') === true, 'hasScope matches read');
t3assert($auth->hasScope($mockRecord, 'write') === true, 'hasScope matches write');
t3assert($auth->hasScope($mockRecord, 'delete') === false, 'hasScope rejects missing scope');

$wildcardRecord = ['scopes' => json_encode(['*'])];
t3assert($auth->hasScope($wildcardRecord, 'anything') === true, 'Wildcard scope matches anything');

// Authenticate with invalid key
$noMatch = $auth->authenticate('definitely_not_a_real_key_abcdef1234567890');
t3assert($noMatch === null, 'Invalid key returns null');

// Short key is rejected
$shortResult = $auth->authenticate('short');
t3assert($shortResult === null, 'Short key is rejected');

// listKeys on empty table
$emptyList = $auth->listKeys(999);
t3assert(is_array($emptyList) && count($emptyList) === 0, 'listKeys returns empty for non-existent tenant');

// -----------------------------------------------------------------
// 7. Encryption Key Rotation
// -----------------------------------------------------------------
echo "\n=== 7. Encryption Key Rotation ===\n";

// Single-key operation — use base64-encoded 32-byte key
$rawKeyBytes = random_bytes(32);
$key1B64 = base64_encode($rawKeyBytes);
$crypto = new Crypto($key1B64);
$enc = $crypto->encryptString('hello world');
t3assert(isset($enc['ciphertext']), 'encryptString returns ciphertext');
t3assert(isset($enc['iv']), 'encryptString returns iv');
t3assert(isset($enc['tag']), 'encryptString returns tag');
t3assert(isset($enc['key_id']), 'encryptString returns key_id');

$plain = $crypto->decryptString($enc['ciphertext'], $enc['iv'], $enc['tag']);
t3assert($plain === 'hello world', 'Decryption round-trips correctly');

// Key ring support
$keyIdDefault = $crypto->currentKeyId();
t3assert(is_string($keyIdDefault) && $keyIdDefault !== '', 'currentKeyId returns non-empty string');

$ring = $crypto->keyRingIds();
t3assert(is_array($ring), 'keyRingIds returns array');
t3assert(in_array($keyIdDefault, $ring, true), 'Current key is in ring');

t3assert($crypto->hasKeyId($keyIdDefault) === true, 'hasKeyId returns true for current');
t3assert($crypto->hasKeyId('nonexistent') === false, 'hasKeyId returns false for missing');

// Re-encrypt (no-op when already on current key)
$reEnc = $crypto->reEncrypt($enc['ciphertext'], $enc['iv'], $enc['tag'], $keyIdDefault);
t3assert($reEnc === null, 'reEncrypt returns null when already on current key');

// -----------------------------------------------------------------
// 8. Circuit Breaker: Half-Open, Events & Manual Override
// -----------------------------------------------------------------
echo "\n=== 8. Circuit Breaker Half-Open & Override ===\n";

// Capture events
$firedEvents = [];
if (function_exists('app')) {
    try {
        $events = app()->events();
        $events->on('capability.breaker.opened', function (array $payload) use (&$firedEvents) {
            $firedEvents[] = ['event' => 'opened', 'payload' => $payload];
        });
        $events->on('capability.breaker.closed', function (array $payload) use (&$firedEvents) {
            $firedEvents[] = ['event' => 'closed', 'payload' => $payload];
        });
        $events->on('capability.breaker.half_open', function (array $payload) use (&$firedEvents) {
            $firedEvents[] = ['event' => 'half_open', 'payload' => $payload];
        });
    } catch (\Throwable $e) {
        // EventBus may not be available in test
    }
}

$registry = new CapabilityRegistry();
$bus = new CapabilityBus($registry);

// Manual trip
$bus->manualTrip('test.cap', 'provA');
$health = $bus->healthForProvider('test.cap', 'provA');
t3assert($health['breaker_open'] === true, 'Manual trip opens breaker');
t3assert($health['breaker_manual_trip'] === true, 'Manual trip flag is set');

// Manual reset
$bus->manualReset('test.cap', 'provA');
$health2 = $bus->healthForProvider('test.cap', 'provA');
t3assert($health2['breaker_open'] === false, 'Manual reset closes breaker');
t3assert($health2['breaker_manual_trip'] === false, 'Manual trip flag cleared');

// healthAll includes half_open/manual_trip fields
$allHealth = $bus->healthAll();
if (!empty($allHealth)) {
    $first = $allHealth[0];
    t3assert(array_key_exists('breaker_half_open', $first), 'healthAll includes breaker_half_open');
    t3assert(array_key_exists('breaker_manual_trip', $first), 'healthAll includes breaker_manual_trip');
} else {
    // After manualReset, state is zero — verify healthAll returns entries
    // Re-trip and check
    $bus->manualTrip('test.cap2', 'provB');
    $allHealth = $bus->healthAll();
    t3assert(count($allHealth) >= 1, 'healthAll returns entries after trip');
    $found = false;
    foreach ($allHealth as $h) {
        if (($h['capability_id'] ?? '') === 'test.cap2') {
            t3assert(array_key_exists('breaker_half_open', $h), 'healthAll includes breaker_half_open');
            t3assert(array_key_exists('breaker_manual_trip', $h), 'healthAll includes breaker_manual_trip');
            $found = true;
            break;
        }
    }
    if (!$found) {
        t3assert(false, 'healthAll entry found for tripped cap');
        t3assert(false, 'healthAll includes breaker_half_open (skipped)');
    }
    $bus->manualReset('test.cap2', 'provB');
}

// Events fired (if EventBus available)
if (!empty($firedEvents)) {
    $openEvents = array_filter($firedEvents, fn($e) => $e['event'] === 'opened');
    $closeEvents = array_filter($firedEvents, fn($e) => $e['event'] === 'closed');
    t3assert(count($openEvents) >= 1, 'Breaker opened event fired');
    t3assert(count($closeEvents) >= 1, 'Breaker closed event fired');

    // Check event payload includes manual flag
    $manualOpen = array_filter($openEvents, fn($e) => !empty($e['payload']['manual'] ?? false));
    t3assert(count($manualOpen) >= 1, 'Manual trip event has manual=true');
} else {
    echo "  SKIP: EventBus not available — event assertions skipped\n";
}

// -----------------------------------------------------------------
// 9. Builder Schema Versioning (supplement to builder_lifecycle_test)
// -----------------------------------------------------------------
echo "\n=== 9. Builder Schema Versioning ===\n";
ob_start();
require_once __DIR__ . '/../modules/cms/helpers/50-builder.php';
require_once __DIR__ . '/../modules/cms/builder-renderers.php';
ob_end_clean();

t3assert(defined('CMS_BUILDER_CURRENT_SCHEMA_VERSION'), 'CMS_BUILDER_CURRENT_SCHEMA_VERSION defined');
t3assert(CMS_BUILDER_CURRENT_SCHEMA_VERSION === '1.1', 'Current schema version is 1.1');
t3assert(function_exists('cmsBuilderSchemaVersionCompare'), 'cmsBuilderSchemaVersionCompare exists');
t3assert(function_exists('cmsBuilderSchemaMigrations'), 'cmsBuilderSchemaMigrations exists');
t3assert(function_exists('cmsBuilderSchemaMigrators'), 'cmsBuilderSchemaMigrators exists');
t3assert(function_exists('cmsBuilderSchemaMigrateDocument'), 'cmsBuilderSchemaMigrateDocument exists');

// Migration path from 1.0 to current
$migrations = cmsBuilderSchemaMigrations();
t3assert(is_array($migrations), 'Schema migrations is array');
t3assert(isset($migrations['1.0']), 'Migration path from 1.0 exists');
t3assert($migrations['1.0'] === '1.1', '1.0 migrates to 1.1');

// Migrate a v1.0 document to current
$v10Doc = [
    'schema_version' => '1.0',
    'document' => [
        'id' => 'root',
        'type' => 'Container',
        'props' => [],
        'styles' => [],
        'children' => [
            [
                'id' => 'child1',
                'type' => 'Heading',
                'props' => ['text' => 'Hello'],
                'styles' => [],
                'children' => [],
            ],
        ],
    ],
];
$migrated = cmsBuilderSchemaMigrateDocument($v10Doc);
t3assert($migrated['schema_version'] === CMS_BUILDER_CURRENT_SCHEMA_VERSION, 'Document migrated to current version');
// 1.0→1.1 migration adds meta key to every node
$rootNode = $migrated['document'];
t3assert(array_key_exists('meta', $rootNode), 'Root node has meta after migration');
$childNode = $rootNode['children'][0] ?? null;
t3assert($childNode !== null && array_key_exists('meta', $childNode), 'Child node has meta after migration');

// Already-current doc is no-op
$currentDoc = ['schema_version' => CMS_BUILDER_CURRENT_SCHEMA_VERSION, 'document' => $rootNode];
$unchanged = cmsBuilderSchemaMigrateDocument($currentDoc);
t3assert($unchanged['schema_version'] === CMS_BUILDER_CURRENT_SCHEMA_VERSION, 'Current-version doc stays current');

// -----------------------------------------------------------------
// 10. Migration File Existence
// -----------------------------------------------------------------
echo "\n=== 10. Migration Files Exist ===\n";
t3assert(
    file_exists(__DIR__ . '/../modules/ecommerce/database/migrations/039_ec_tier3_features.sql'),
    'Ecommerce Tier 3 migration file exists'
);
t3assert(
    file_exists(__DIR__ . '/../migrations/007_kernel_api_keys.sql'),
    'Kernel API keys migration file exists'
);

// Verify migration contains expected tables
$ecMigSql = file_get_contents(__DIR__ . '/../modules/ecommerce/database/migrations/039_ec_tier3_features.sql');
t3assert(str_contains($ecMigSql, 'ec_saved_payment_methods'), 'Migration has ec_saved_payment_methods table');
t3assert(str_contains($ecMigSql, 'ec_report_cache'), 'Migration has ec_report_cache table');
t3assert(str_contains($ecMigSql, 'ec_order_edits'), 'Migration has ec_order_edits table');

$kernelMigSql = file_get_contents(__DIR__ . '/../migrations/007_kernel_api_keys.sql');
t3assert(str_contains($kernelMigSql, 'kernel_api_keys'), 'Kernel migration has kernel_api_keys table');

// ── Summary ──
echo "\n" . str_repeat('=', 50) . "\n";
echo "Tier 3 Feature Completeness Tests: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
