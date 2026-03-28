<?php
/**
 * CMS Entity Capability View — Test Suite
 *
 * Covers:
 *   1. Built-in entity capability type registry (7 canonical types)
 *   2. Attach / detach / read capabilities on an entity (real DB)
 *   3. cmsEntityCapabilityContext() boolean map
 *   4. cmsEntityCapabilityData() via CapabilityBus (requires loadModuleRoutes)
 *   5. Direct invocation of all 7 data provider functions
 *   6. cmsEntityProgressUpdate() — insert, upsert, percent clamping
 *   7. cmsPublicContext() capability injection:
 *      - no-entity path returns empty capability keys
 *      - with-entity path returns capability context + data
 *      - cart gate: cart_enabled / cart_action_url via cms.cart.add@1
 *      - action_sections hook passthrough
 *   8. cmsValidateThemeCss() structural CSS validator
 *
 * Run: php tests/cms_entity_capability_view_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

$db   = app()->db();
$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ── Clear logs ──────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// Load all modules so CapabilityBus providers are registered
loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []]);

$originalEcommerceSettings = getModuleSettings('ecommerce');
saveModuleSettings('ecommerce', array_merge(is_array($originalEcommerceSettings) ? $originalEcommerceSettings : [], [
    'low_stock_threshold' => '2',
]));

// ════════════════════════════════════════════════════════════════════
// 1. BUILT-IN CAPABILITY TYPES
// ════════════════════════════════════════════════════════════════════
echo "\n=== BUILT-IN CAPABILITY TYPES ===\n";

$builtins    = cmsBuiltinEntityCapabilities();
$expectedIds = ['pricing', 'inventory', 'booking', 'inquiry', 'progress_tracking', 'lessons_index', 'media_gallery'];
$builtinIds  = array_column($builtins, 'id');

t('builtin list is array', is_array($builtins));
t('builtin count is 7', count($builtins) === 7, 'count=' . count($builtins));

foreach ($expectedIds as $eid) {
    t("builtin has capability: {$eid}", in_array($eid, $builtinIds, true));
}

// Every capability must have the required schema fields
foreach ($builtins as $cap) {
    $hasAll = isset($cap['id'], $cap['label'], $cap['description'], $cap['icon'], $cap['config_schema'], $cap['default_config']);
    t("capability '{$cap['id']}' has all required fields", $hasAll);
}

// Spot-check default_config values for known types
$byId = array_column($builtins, null, 'id');
t('pricing default currency is USD',       ($byId['pricing']['default_config']['currency'] ?? '') === 'USD');
t('inventory default stock_qty is 0',      ($byId['inventory']['default_config']['stock_qty'] ?? -1) === 0);
t('booking default slot_duration is 60',   ($byId['booking']['default_config']['slot_duration_minutes'] ?? 0) === 60);
t('progress_tracking default unit is percent', ($byId['progress_tracking']['default_config']['unit'] ?? '') === 'percent');
t('lessons_index default child_type is lesson', ($byId['lessons_index']['default_config']['child_type'] ?? '') === 'lesson');
t('media_gallery default columns is 3',     ($byId['media_gallery']['default_config']['columns'] ?? 0) === 3);

// ════════════════════════════════════════════════════════════════════
// 2. CAPABILITY TYPE REGISTRY
// ════════════════════════════════════════════════════════════════════
echo "\n=== CAPABILITY TYPE REGISTRY ===\n";

$types = cmsEntityCapabilityTypes();
t('types returns keyed array', is_array($types));
t('types count >= 7', count($types) >= 7, 'count=' . count($types));
foreach ($expectedIds as $eid) {
    t("registry has key: {$eid}", isset($types[$eid]));
}

// Hook-based extension (tested without relying on static cache)
$hooks = app()->hooks();
$hooks->on('cms.entity.capabilities.register', function (array $caps): array {
    $caps[] = [
        'id'             => 'testcaphook',
        'label'          => 'Test Hook Cap',
        'description'    => 'Added via hook for test',
        'icon'           => 'test',
        'config_schema'  => [],
        'default_config' => [],
    ];
    return $caps;
}, 10);

$allCaps = cmsBuiltinEntityCapabilities();
$extras  = $hooks->filter('cms.entity.capabilities.register', []);
if (is_array($extras)) {
    foreach ($extras as $extra) {
        if (!empty($extra['id']) && is_string($extra['id'])) {
            $allCaps[] = $extra;
        }
    }
}
$indexed = [];
foreach ($allCaps as $cap) {
    $indexed[(string)$cap['id']] = $cap;
}
t('hook registration inserts new capability', isset($indexed['testcaphook']));
t('hook capability label is correct',         ($indexed['testcaphook']['label'] ?? '') === 'Test Hook Cap');
$hooks->off('cms.entity.capabilities.register');

// ════════════════════════════════════════════════════════════════════
// 3. ENTITY CAPABILITY CRUD (real DB)
// ════════════════════════════════════════════════════════════════════
echo "\n=== ENTITY CAPABILITY CRUD ===\n";

// Create a test content entity
$testUuid = cmsUuid();
$testSlug = cmsEnsureUniqueSlug('test-cap-entity-' . substr(bin2hex(random_bytes(3)), 0, 6), 'product');
$db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, created_at)
     VALUES (:uuid, :title, :slug, '', 'product', 'draft', 1, NOW())"
)->execute([
    ':uuid'  => $testUuid,
    ':title' => 'Test Cap Entity',
    ':slug'  => $testSlug,
]);
$testEntityId = (int)$db->lastInsertId();
t('test entity created', $testEntityId > 0, "id={$testEntityId}");

// Initial state: no capabilities attached
$caps = cmsEntityGetCapabilities($testEntityId);
t('new entity has no capabilities', empty($caps));

// Attach pricing with custom config
cmsEntityAttachCapability($testEntityId, 'pricing', ['price' => 49.99, 'currency' => 'EUR']);
$caps = cmsEntityGetCapabilities($testEntityId);
t('pricing attached', isset($caps['pricing']));
t('pricing config price is 49.99',   ($caps['pricing']['price'] ?? null) === 49.99);
t('pricing config currency is EUR',  ($caps['pricing']['currency'] ?? '') === 'EUR');

// Attach inventory using defaults (no custom config)
cmsEntityAttachCapability($testEntityId, 'inventory');
$caps = cmsEntityGetCapabilities($testEntityId);
t('inventory attached',                   isset($caps['inventory']));
t('inventory default track_stock = true', ($caps['inventory']['track_stock'] ?? null) === true);
t('inventory default stock_qty = 0',      ($caps['inventory']['stock_qty'] ?? null) === 0);

// Attach progress_tracking
cmsEntityAttachCapability($testEntityId, 'progress_tracking');
$caps = cmsEntityGetCapabilities($testEntityId);
t('progress_tracking attached',               isset($caps['progress_tracking']));
t('progress_tracking default unit = percent', ($caps['progress_tracking']['unit'] ?? '') === 'percent');

// Config update via upsert (ON DUPLICATE KEY UPDATE)
cmsEntityAttachCapability($testEntityId, 'pricing', ['price' => 29.99, 'currency' => 'USD', 'sale_price' => 19.99]);
$caps = cmsEntityGetCapabilities($testEntityId);
t('pricing config updated via upsert',       ($caps['pricing']['price'] ?? null) === 29.99);
t('pricing currency updated to USD',         ($caps['pricing']['currency'] ?? '') === 'USD');
t('pricing sale_price set',                  ($caps['pricing']['sale_price'] ?? null) === 19.99);

// Attaching an unknown capability must throw
$invalidEx = null;
try {
    cmsEntityAttachCapability($testEntityId, 'nonexistent_cap_xyz');
} catch (\InvalidArgumentException $e) {
    $invalidEx = $e;
}
t('unknown capability throws InvalidArgumentException', $invalidEx instanceof \InvalidArgumentException);

// Detach pricing
cmsEntityDetachCapability($testEntityId, 'pricing');
$caps = cmsEntityGetCapabilities($testEntityId);
t('pricing detached',                    !isset($caps['pricing']));
t('inventory still attached after detach', isset($caps['inventory']));

// Cache: re-attach and verify cache invalidation
cmsEntityAttachCapability($testEntityId, 'pricing', ['price' => 10.0]);
$cachedCaps = cmsEntityGetCapabilities($testEntityId);
t('cached read includes re-attached pricing', isset($cachedCaps['pricing']));
cmsEntityCapabilityClearCache($testEntityId);
$afterClear = cmsEntityGetCapabilities($testEntityId);
t('after cache clear DB read returns same data', isset($afterClear['pricing']));

// ════════════════════════════════════════════════════════════════════
// 4. CAPABILITY CONTEXT MAP
// ════════════════════════════════════════════════════════════════════
echo "\n=== CAPABILITY CONTEXT MAP ===\n";

cmsEntityCapabilityClearCache($testEntityId);
$ctxMap = cmsEntityCapabilityContext($testEntityId);

t('context is array',                  is_array($ctxMap));
t('context has all known cap IDs',     count(array_intersect_key($ctxMap, array_flip($expectedIds))) === count($expectedIds));
t('pricing is true in context',        $ctxMap['pricing'] === true);
t('inventory is true in context',      $ctxMap['inventory'] === true);
t('progress_tracking true in context',  $ctxMap['progress_tracking'] === true);
t('booking is false in context',       $ctxMap['booking'] === false);
t('inquiry is false in context',       $ctxMap['inquiry'] === false);
t('lessons_index is false in context',  $ctxMap['lessons_index'] === false);
t('media_gallery is false in context',  $ctxMap['media_gallery'] === false);

// ════════════════════════════════════════════════════════════════════
// 5. CAPABILITY DATA VIA BUS — cmsEntityCapabilityData()
// ════════════════════════════════════════════════════════════════════
echo "\n=== CAPABILITY DATA via cmsEntityCapabilityData() ===\n";

// Seed pricing data — update the capability config so all active providers (CMS or ecommerce) return 29.99/USD.
// cmsEntityAttachCapability does an upsert on cms_entity_capabilities (used by ecommerce provider).
// Also seed cms_content_meta for CMS meta-based provider fallback.
cmsEntityAttachCapability($testEntityId, 'pricing', ['price' => 29.99, 'currency' => 'USD']);
$db->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
     VALUES (:cid, '_price', '29.99')
     ON DUPLICATE KEY UPDATE meta_value = '29.99'"
)->execute([':cid' => $testEntityId]);
$db->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
     VALUES (:cid, '_currency', 'USD')
     ON DUPLICATE KEY UPDATE meta_value = 'USD'"
)->execute([':cid' => $testEntityId]);

$testEntity = ['id' => $testEntityId, 'title' => 'Test Cap Entity', 'type' => 'product'];
cmsEntityCapabilityClearCache($testEntityId);
$capData = cmsEntityCapabilityData($testEntityId, $testEntity);

t('cmsEntityCapabilityData returns array',     is_array($capData));
t('pricing data present',                      isset($capData['pricing']));
t('pricing data price is 29.99',               ($capData['pricing']['price'] ?? null) === 29.99);
t('pricing data currency is USD',              ($capData['pricing']['currency'] ?? '') === 'USD');
t('pricing data sale_price null (not seeded)', array_key_exists('sale_price', $capData['pricing'] ?? []) && $capData['pricing']['sale_price'] === null);
t('inventory data present',                    isset($capData['inventory']));
t('inventory data has in_stock key',           array_key_exists('in_stock', $capData['inventory'] ?? []));
t('progress_tracking data present',             isset($capData['progress_tracking']));
t('progress_tracking percent 0 (no session)',   ($capData['progress_tracking']['percent'] ?? -1) === 0);

// Entity with no capabilities should return empty data
cmsEntityCapabilityClearCache($testEntityId);
$syntheticId          = PHP_INT_MAX - 99; // non-existent ID
$emptyCapData         = cmsEntityCapabilityData($syntheticId, ['id' => $syntheticId]);
t('no-capability entity returns empty data', $emptyCapData === []);

// ════════════════════════════════════════════════════════════════════
// 6. DIRECT DATA PROVIDER INVOCATIONS
// ════════════════════════════════════════════════════════════════════
echo "\n=== DIRECT DATA PROVIDERS ===\n";

$entityPayload = ['entity' => ['id' => $testEntityId], 'config' => [], 'entity_id' => $testEntityId];

// Pricing provider
$pricingData = cms_cap_entity_capability_pricing_data_1(
    ['entity' => ['id' => $testEntityId], 'config' => ['currency' => 'USD'], 'entity_id' => $testEntityId]
);
t('pricing provider returns array',          is_array($pricingData));
t('pricing provider has price key',          array_key_exists('price', $pricingData));
t('pricing provider has currency key',       array_key_exists('currency', $pricingData));
t('pricing provider has sale_price key',     array_key_exists('sale_price', $pricingData));
t('pricing provider: price 29.99',          ($pricingData['price'] ?? null) === 29.99);
t('pricing provider: zero entity -> empty', cms_cap_entity_capability_pricing_data_1([]) === []);

// Inventory provider
$invData = cms_cap_entity_capability_inventory_data_1($entityPayload);
t('inventory provider returns array',         is_array($invData));
t('inventory provider has sku key',           array_key_exists('sku', $invData));
t('inventory provider has in_stock key',      array_key_exists('in_stock', $invData));
t('inventory provider reflects attached default inventory as out of stock', ($invData['in_stock'] ?? true) === false && ($invData['out_of_stock'] ?? false) === true);
t('inventory provider: zero entity -> empty', cms_cap_entity_capability_inventory_data_1([]) === []);

$productInventoryData = cms_cap_entity_capability_inventory_data_1([
    'entity' => ['id' => $testEntityId, 'type' => 'product'],
    'config' => ['track_stock' => true, 'stock_qty' => 3, 'sku' => 'SKU-TEST-3'],
    'entity_id' => $testEntityId,
]);
t('inventory provider keeps above-threshold product in stock', ($productInventoryData['in_stock'] ?? false) === true);
t('inventory provider does not mark above-threshold product out_of_stock', ($productInventoryData['out_of_stock'] ?? true) === false);
t('inventory provider respects ecommerce low stock threshold', ($productInventoryData['low_stock'] ?? true) === false);

// Booking provider (intentional stub)
$bookingData = cms_cap_entity_capability_booking_data_1($entityPayload);
t('booking provider returns stub=true',      ($bookingData['stub'] ?? false) === true);
t('booking provider has available_slots',    isset($bookingData['available_slots']));

// Inquiry provider
$inquiryFull = cms_cap_entity_capability_inquiry_data_1([
    'entity' => ['id' => $testEntityId],
    'config' => ['label' => 'Contact Us', 'form_fields' => 'name,email,phone'],
]);
t('inquiry provider returns array',          is_array($inquiryFull));
t('inquiry provider label = Contact Us',     ($inquiryFull['label'] ?? '') === 'Contact Us');
t('inquiry provider form_fields is array',   is_array($inquiryFull['form_fields'] ?? null));
t('inquiry provider form_fields count = 3',  count($inquiryFull['form_fields'] ?? []) === 3);
$inquiryDefault = cms_cap_entity_capability_inquiry_data_1([]);
t('inquiry provider default label = Inquire', ($inquiryDefault['label'] ?? '') === 'Inquire');
t('inquiry default form_fields is array',    is_array($inquiryDefault['form_fields'] ?? null));

// Progress tracking provider — no authenticated user in CLI, expect percent=0
$progData = cms_cap_entity_capability_progress_tracking_data_1($entityPayload);
t('progress provider returns array',                 is_array($progData));
t('progress provider: no session → percent=0 or authenticated=false',
    ($progData['authenticated'] ?? true) === false || ($progData['percent'] ?? -1) === 0);
$progZero = cms_cap_entity_capability_progress_tracking_data_1([]);
t('progress provider: zero entity_id → percent=0', ($progZero['percent'] ?? -1) === 0);

// Lessons index provider (no child content expected for test entity)
$lessonsData = cms_cap_entity_capability_lessons_index_data_1($entityPayload);
t('lessons provider returns array',       is_array($lessonsData));
t('lessons provider has items key',       isset($lessonsData['items']));
t('lessons provider items is array',      is_array($lessonsData['items'] ?? null));

// Media gallery provider (no gallery meta seeded)
$galleryData = cms_cap_entity_capability_media_gallery_data_1($entityPayload);
t('gallery provider returns array',       is_array($galleryData));
t('gallery provider has items key',       isset($galleryData['items']));
t('gallery provider has columns key',     array_key_exists('columns', $galleryData));
t('gallery provider has lightbox key',    array_key_exists('lightbox', $galleryData));

// ════════════════════════════════════════════════════════════════════
// 7. PROGRESS TABLE — cmsEntityProgressUpdate()
// ════════════════════════════════════════════════════════════════════
echo "\n=== PROGRESS TABLE ===\n";

const TEST_PROGRESS_USER_ID = 9998; // synthetic: no FK on user_id column

// Table must exist (migration 022 applied)
try {
    $db->query("SELECT 1 FROM cms_entity_progress LIMIT 1");
    t('cms_entity_progress table exists', true);
} catch (\Throwable $e) {
    t('cms_entity_progress table exists', false, $e->getMessage());
}

// Insert
cmsEntityProgressUpdate($testEntityId, TEST_PROGRESS_USER_ID, 40);
$progStmt = $db->prepare(
    "SELECT percent FROM cms_entity_progress WHERE entity_id = :eid AND user_id = :uid"
);
$progStmt->execute([':eid' => $testEntityId, ':uid' => TEST_PROGRESS_USER_ID]);
$progRow = $progStmt->fetch(\PDO::FETCH_ASSOC);
t('progress row inserted',     is_array($progRow));
t('progress percent stored 40', (int)($progRow['percent'] ?? -1) === 40);

// Upsert (update)
cmsEntityProgressUpdate($testEntityId, TEST_PROGRESS_USER_ID, 75);
$progStmt->execute([':eid' => $testEntityId, ':uid' => TEST_PROGRESS_USER_ID]);
$progRow2 = $progStmt->fetch(\PDO::FETCH_ASSOC);
t('progress upsert updated to 75', (int)($progRow2['percent'] ?? -1) === 75);

// Clamp high: 150 → 100
cmsEntityProgressUpdate($testEntityId, TEST_PROGRESS_USER_ID, 150);
$progStmt->execute([':eid' => $testEntityId, ':uid' => TEST_PROGRESS_USER_ID]);
$progRow3 = $progStmt->fetch(\PDO::FETCH_ASSOC);
t('percent clamped to 100 (input 150)', (int)($progRow3['percent'] ?? -1) === 100);

// Clamp low: -10 → 0
cmsEntityProgressUpdate($testEntityId, TEST_PROGRESS_USER_ID, -10);
$progStmt->execute([':eid' => $testEntityId, ':uid' => TEST_PROGRESS_USER_ID]);
$progRow4 = $progStmt->fetch(\PDO::FETCH_ASSOC);
t('percent clamped to 0 (input -10)', (int)($progRow4['percent'] ?? 99) === 0);

// Edge: exact boundary 100 → stored as 100
cmsEntityProgressUpdate($testEntityId, TEST_PROGRESS_USER_ID, 100);
$progStmt->execute([':eid' => $testEntityId, ':uid' => TEST_PROGRESS_USER_ID]);
$progRow5 = $progStmt->fetch(\PDO::FETCH_ASSOC);
t('percent 100 stored as-is', (int)($progRow5['percent'] ?? -1) === 100);

// ════════════════════════════════════════════════════════════════════
// 8. PUBLIC CONTEXT INJECTION
// ════════════════════════════════════════════════════════════════════
echo "\n=== PUBLIC CONTEXT INJECTION ===\n";

// Without entity: capability keys should be empty arrays
$ctxNoEntity = cmsPublicContext([]);
t('no-entity context: capabilities = []',    ($ctxNoEntity['capabilities'] ?? null) === []);
t('no-entity context: capability_data = []', ($ctxNoEntity['capability_data'] ?? null) === []);

// With entity: capabilities + capability_data populated
cmsEntityCapabilityClearCache($testEntityId);
$ctxWithEntity = cmsPublicContext(['entity' => $testEntity]);
$bus = app()->capabilities();
t('with-entity context: capabilities is array',      is_array($ctxWithEntity['capabilities'] ?? null));
t('with-entity context: capability_data is array',   is_array($ctxWithEntity['capability_data'] ?? null));
t('with-entity context: pricing = true',             ($ctxWithEntity['capabilities']['pricing'] ?? false) === true);
t('with-entity context: inventory = true',           ($ctxWithEntity['capabilities']['inventory'] ?? false) === true);
t('with-entity context: booking = false',            ($ctxWithEntity['capabilities']['booking'] ?? true) === false);
t('with-entity context: capability_data has pricing', isset($ctxWithEntity['capability_data']['pricing']));

// Cart gate: current runtime may already have cms.cart.add@1 from ecommerce.
$hadCartCapability = $bus->has('cms.cart.add@1');
t('cart gate exposes boolean cart_enabled', is_bool($ctxWithEntity['cart_enabled'] ?? null));
t('cart_action_url matches cart gate state',
    !empty($ctxWithEntity['cart_enabled'])
        ? str_ends_with((string)($ctxWithEntity['cart_action_url'] ?? ''), '/ecommerce/cart/add')
        : (string)($ctxWithEntity['cart_action_url'] ?? '') === '');

// Register a stub cms.cart.add@1 to trip the cart gate
$bus->register(
    'cms.cart.add@1',
    'test_cart_stub',
    function (mixed $p): array { return ['ok' => true]; },
    100
);
cmsEntityCapabilityClearCache($testEntityId);
$ctxCart = cmsPublicContext(['entity' => $testEntity]);
t('cart_enabled true when cms.cart.add@1 registered + pricing on entity',
    ($ctxCart['cart_enabled'] ?? false) === true);
t('cart_action_url ends with /ecommerce/cart/add',
    str_ends_with((string)($ctxCart['cart_action_url'] ?? ''), '/ecommerce/cart/add'));

$actionHtmlInStock = cmsRender('modules/cms/public/blocks/action.block.disyl', [
    'entity' => $testEntity,
    'capabilities' => [
        'pricing' => true,
        'inventory' => true,
        'booking' => false,
        'inquiry' => false,
    ],
    'capability_data' => [
        'inventory' => [
            'in_stock' => true,
            'out_of_stock' => false,
            'track_stock' => true,
            'stock_qty' => 8,
        ],
    ],
    'cart_enabled' => true,
    'cart_action_url' => '/ecommerce/cart/add',
    'action_sections' => '',
    'base_url' => '',
]);
t('action block renders Add to Cart for in-stock inventory', str_contains($actionHtmlInStock, 'Add to Cart'));
t('action block does not render Out of Stock when inventory is in stock', !str_contains($actionHtmlInStock, 'Out of Stock'));

$actionHtmlOutOfStock = cmsRender('modules/cms/public/blocks/action.block.disyl', [
    'entity' => $testEntity,
    'capabilities' => [
        'pricing' => true,
        'inventory' => true,
        'booking' => false,
        'inquiry' => false,
    ],
    'capability_data' => [
        'inventory' => [
            'in_stock' => false,
            'out_of_stock' => true,
            'track_stock' => true,
            'stock_qty' => 0,
        ],
    ],
    'cart_enabled' => true,
    'cart_action_url' => '/ecommerce/cart/add',
    'action_sections' => '',
    'base_url' => '',
]);
t('action block renders Out of Stock for zero inventory', str_contains($actionHtmlOutOfStock, 'Out of Stock'));
t('action block hides Add to Cart for zero inventory', !str_contains($actionHtmlOutOfStock, 'Add to Cart'));

// Action sections hook
$hooks->on('cms.entity.action_block.sections', function (mixed $sections, array $args): string {
    return '<div class="test-action-section">Special Action</div>';
}, 10);
cmsEntityCapabilityClearCache($testEntityId);
$ctxSections = cmsPublicContext(['entity' => $testEntity]);
t('action_sections populated by hook',
    str_contains((string)($ctxSections['action_sections'] ?? ''), 'Special Action'));
$hooks->off('cms.entity.action_block.sections');

// After hook removed, action_sections should be empty string again
cmsEntityCapabilityClearCache($testEntityId);
$ctxNoSec = cmsPublicContext(['entity' => $testEntity]);
t('action_sections empty after hook removed', ($ctxNoSec['action_sections'] ?? 'x') === '');

// ════════════════════════════════════════════════════════════════════
// 9. THEME CSS VALIDATOR — cmsValidateThemeCss()
// ════════════════════════════════════════════════════════════════════
echo "\n=== THEME CSS VALIDATOR ===\n";

// Clean CSS — no violations
t('clean non-protected CSS returns no violations',
    cmsValidateThemeCss('.my-section { color: red; background: blue; }') === []);

// Empty CSS — no violations
t('empty CSS returns no violations', cmsValidateThemeCss('') === []);

// Protected selector + forbidden property → violation
$badCss = '.cms-entity-view { display: flex; color: blue; }';
$v      = cmsValidateThemeCss($badCss);
t('violation: display on .cms-entity-view detected',
    count($v) >= 1);
t('violation message contains selector',
    str_contains($v[0] ?? '', '.cms-entity-view'));
t('violation message contains forbidden property name',
    str_contains($v[0] ?? '', 'display'));

// Multiple violations in one rule
$multiCss = '.cms-action-block { position: absolute; float: left; color: red; }';
$mv       = cmsValidateThemeCss($multiCss);
t('multiple violations in one rule detected', count($mv) >= 2);

// CSS comment wrapping forbidden property must NOT trigger
$commentedCss = '.cms-entity-view { /* display: flex; */ color: red; }';
t('commented-out forbidden property ignored', cmsValidateThemeCss($commentedCss) === []);

// Unprotected selector with structural properties — no violation
$unprotectedCss = '.myapp-layout { display: flex; flex-direction: row; position: relative; }';
t('unprotected selector: no violation for flex/position', cmsValidateThemeCss($unprotectedCss) === []);

// Every protected selector should trigger when a forbidden property is used
$protectedSelectors = [
    '.cms-entity-view', '.cms-entity-hero',  '.cms-entity-header', '.cms-entity-meta',
    '.cms-entity-body', '.cms-pricing-block', '.cms-inventory-block', '.cms-gallery-block',
    '.cms-lessons-block', '.cms-progress-block', '.cms-action-block',
];
foreach ($protectedSelectors as $sel) {
    $css = "{$sel} { order: 1; }";
    t("validator catches 'order' override on {$sel}", count(cmsValidateThemeCss($css)) >= 1);
}

// All forbidden properties trigger on a protected selector
$forbiddenProps = ['display', 'flex-direction', 'flex-wrap', 'grid-template-columns',
                   'grid-template-rows', 'grid-template-areas', 'order', 'position',
                   'float', 'clear', 'overflow'];
foreach ($forbiddenProps as $prop) {
    $css = ".cms-entity-body { {$prop}: test; }";
    t("validator catches forbidden prop '{$prop}'", count(cmsValidateThemeCss($css)) >= 1);
}

// ════════════════════════════════════════════════════════════════════
// CLEANUP
// ════════════════════════════════════════════════════════════════════
echo "\n=== CLEANUP ===\n";

// Progress rows
$db->prepare("DELETE FROM cms_entity_progress WHERE entity_id = :eid AND user_id = :uid")
   ->execute([':eid' => $testEntityId, ':uid' => TEST_PROGRESS_USER_ID]);
t('progress rows cleaned up', true);

// Entity capabilities (FK cascades from content delete, but explicit is safer)
$db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id = :eid")
   ->execute([':eid' => $testEntityId]);

// Content meta
$db->prepare("DELETE FROM cms_content_meta WHERE content_id = :cid")
   ->execute([':cid' => $testEntityId]);

// Content record
$db->prepare("DELETE FROM cms_content WHERE id = :id")
   ->execute([':id' => $testEntityId]);
t('test content entity cleaned up', true);

saveModuleSettings('ecommerce', is_array($originalEcommerceSettings) ? $originalEcommerceSettings : []);
t('ecommerce settings restored', true);

// ════════════════════════════════════════════════════════════════════
// LOG CHECK
// ════════════════════════════════════════════════════════════════════
echo "\n=== LOG CHECK ===\n";

$appLog  = file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog  = file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrs = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[error]'));
$phpErrs = array_values(array_filter(explode("\n", $errLog), static function ($line): bool {
    $line = trim((string)$line);
    if ($line === '') {
        return false;
    }

    if (str_contains($line, 'storage/cache/') || str_contains($line, 'Cache write error') || str_contains($line, 'Permission denied')) {
        return false;
    }

    if (str_contains($line, 'Ikabud Cache: Cleared')) {
        return false;
    }

    return true;
}));

t('No [error] entries in app.log', empty($appErrs), empty($appErrs) ? '' : 'Found: ' . count($appErrs));
t('No entries in error.log',       empty($phpErrs), empty($phpErrs) ? '' : 'Found: ' . count($phpErrs));

if (!empty($appErrs)) {
    echo "\n  app.log [error] entries:\n";
    foreach (array_slice($appErrs, 0, 5) as $e) {
        echo '    ' . trim($e) . "\n";
    }
}
if (!empty($phpErrs)) {
    echo "\n  error.log entries:\n";
    foreach (array_slice($phpErrs, 0, 5) as $e) {
        echo '    ' . trim($e) . "\n";
    }
}

// ════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 55) . "\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo str_repeat('═', 55) . "\n";

if (!empty($errors)) {
    echo "\n  Failed tests:\n";
    foreach ($errors as $e) {
        echo "    ✗ {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
