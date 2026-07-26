<?php
/**
 * DC Cafe — Settings Integration Test
 *
 * Tests handler definitions, nav config, payment method/soft-serve CRUD,
 * and stock audit row creation. Uses direct DB assertions since handlers
 * call dcJsonResponse()/exit which prevents test continuation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-settings', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../src/helpers/module-migrations.php';
require_once __DIR__ . '/../../modules/dc-cafe/handlers.php';
$h->fingerprint('modules/dc-cafe/routes.php');
$h->fingerprint('modules/dc-cafe/handlers.php');
$h->fingerprint('modules/dc-cafe/handlers-inventory.php');
$h->fingerprint('modules/dc-cafe/handlers-products.php');
$h->fingerprint('modules/dc-cafe/module.json');
$h->fingerprint('templates/modules/dc-cafe/settings/index.disyl');

// ── 1. Nav config from module.json ──
$h->section('Navigation Config');
$moduleJson = json_decode((string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/module.json'), true);
$nav = $moduleJson['nav'] ?? [];
$settingsNav = null;
foreach ($nav as $item) {
    if (($item['url'] ?? '') === '/dc-cafe/settings') {
        $settingsNav = $item;
        break;
    }
}
$h->test('Settings nav entry exists', is_array($settingsNav));
$h->test('Settings nav for admin/supervisor only', is_array($settingsNav) && $settingsNav['roles'] === ['admin', 'supervisor']);

// ── 2. Bootstrap DB ──
$h->section('Database Bootstrap');
$db = app()->db();
$h->test('DB connection available', $db instanceof PDO);
if (!$db instanceof PDO) {
    $h->done();
}

// ── 2b. Settings page render smoke ──
$h->section('Settings Page Render');
$previousUser = app()->user();
app()->setUser([
    'id' => 999001,
    'user_id' => 999001,
    'username' => 'settings_admin',
    'name' => 'Settings Admin',
    'full_name' => 'Settings Admin',
    'role' => 'admin',
    'store_id' => 1,
    'source' => 'dc-cafe',
]);
ob_start();
pageDcCafeSettings([]);
$settingsHtml = (string) ob_get_clean();
app()->setUser(is_array($previousUser) ? $previousUser : []);
$h->test('Settings page renders JSON payload script', str_contains($settingsHtml, 'id="dc-settings-data"'));
$h->test('Settings page bootstraps Alpine settingsApp', str_contains($settingsHtml, 'function settingsApp(initialData)'));
$h->test('Settings page includes payment tab label', str_contains($settingsHtml, 'Payment Methods'));
$h->test('Settings page includes Ledger Organization tab', str_contains($settingsHtml, 'Ledger Organization'));
$h->test('Settings tab hash is validated against supported tabs', str_contains($settingsHtml, "validTabs.includes(hashTab)"));
$h->test('Settings Ledger links to reconciliation', str_contains($settingsHtml, 'href="/dc-cafe/inventory/ledger"'));

$routes = require __DIR__ . '/../../modules/dc-cafe/routes.php';
$h->test('Store creation is not exposed through GET', !isset($routes['GET']['/dc-cafe/api/v1/stores/create']));
$h->test('User creation is not exposed through GET', !isset($routes['GET']['/dc-cafe/api/v1/users/create']));
$h->test(
    'Ledger group routes use read and mutation methods',
    isset($routes['GET']['/dc-cafe/api/v1/settings/ledger-groups'])
    && isset($routes['POST']['/dc-cafe/api/v1/settings/ledger-groups/create'])
    && isset($routes['POST']['/dc-cafe/api/v1/settings/ledger-groups/remap'])
    && isset($routes['PUT']['/dc-cafe/api/v1/settings/ledger-groups/{id}'])
);

$ledgerTableExists = (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'dc_ledger_groups'"
)->fetchColumn();
$h->test('Normalized ledger group table exists', $ledgerTableExists === 1);
$unassignedActiveCategories = (int) $db->query(
    "SELECT COUNT(*) FROM dc_categories WHERE is_active = 1 AND ledger_group_id IS NULL"
)->fetchColumn();
$h->test('Active categories are backfilled to ledger groups', $unassignedActiveCategories === 0, (string) $unassignedActiveCategories);

// ── 3. Payment method CRUD (DB-level) ──
$h->section('Payment Method DB Operations');

$pmSuffix = 'pm_' . bin2hex(random_bytes(4));
$pmCode = 'TEST' . strtoupper($pmSuffix);
$db->prepare(
    "INSERT INTO dc_payment_methods (code, name, sort_order) VALUES (:code, :name, :sort)"
)->execute(['code' => $pmCode, 'name' => 'Test Payment ' . $pmSuffix, 'sort' => 50]);
$pmId = (int) $db->lastInsertId();
$h->test('Payment method created', $pmId > 0);

$pmRow = $db->prepare("SELECT * FROM dc_payment_methods WHERE payment_method_id = :id");
$pmRow->execute(['id' => $pmId]);
$pmRow = $pmRow->fetch(\PDO::FETCH_ASSOC);
$h->test('Payment method persisted with correct code', is_array($pmRow) && $pmRow['code'] === $pmCode);

$db->prepare("UPDATE dc_payment_methods SET name = :name, is_active = 0 WHERE payment_method_id = :id")
    ->execute(['name' => 'Updated ' . $pmSuffix, 'id' => $pmId]);
$updStmt = $db->prepare("SELECT name, is_active FROM dc_payment_methods WHERE payment_method_id = :id");
$updStmt->execute(['id' => $pmId]);
$updated = $updStmt->fetch(\PDO::FETCH_ASSOC);
$h->test('Payment method name updated', is_array($updated) && str_starts_with((string) $updated['name'], 'Updated'));
$h->test('Payment method toggled inactive', is_array($updated) && (int) $updated['is_active'] === 0);

$db->prepare("DELETE FROM dc_payment_methods WHERE payment_method_id = :id")->execute(['id' => $pmId]);
$delStmt = $db->prepare("SELECT COUNT(*) FROM dc_payment_methods WHERE payment_method_id = :id");
$delStmt->execute(['id' => $pmId]);
$deleted = $delStmt->fetchColumn();
$h->test('Payment method cleanup succeeds', (int) $deleted === 0);

// ── 4. Soft-serve option CRUD (DB-level) ──
$h->section('Soft-Serve Option DB Operations');

// Base
$baseSuffix = 'BASE_' . bin2hex(random_bytes(4));
$db->prepare("INSERT INTO dc_soft_serve_bases (name) VALUES (:name)")->execute(['name' => $baseSuffix]);
$baseId = (int) $db->lastInsertId();
$h->test('Soft-serve base created', $baseId > 0);
$db->prepare("UPDATE dc_soft_serve_bases SET is_active = 0 WHERE base_id = :id")->execute(['id' => $baseId]);
$baStmt = $db->prepare("SELECT is_active FROM dc_soft_serve_bases WHERE base_id = :id");
$baStmt->execute(['id' => $baseId]);
$baseActive = $baStmt->fetchColumn();
$h->test('Soft-serve base deactivated', (int) $baseActive === 0);
$db->prepare("DELETE FROM dc_soft_serve_bases WHERE base_id = :id")->execute(['id' => $baseId]);

// Sauce
$sauceSuffix = 'SAUCE_' . bin2hex(random_bytes(4));
$db->prepare("INSERT INTO dc_soft_serve_sauces (name) VALUES (:name)")->execute(['name' => $sauceSuffix]);
$sauceId = (int) $db->lastInsertId();
$h->test('Soft-serve sauce created', $sauceId > 0);
$db->prepare("DELETE FROM dc_soft_serve_sauces WHERE sauce_id = :id")->execute(['id' => $sauceId]);

// Topping
$topSuffix = 'TOP_' . bin2hex(random_bytes(4));
$db->prepare("INSERT INTO dc_soft_serve_toppings (name) VALUES (:name)")->execute(['name' => $topSuffix]);
$topId = (int) $db->lastInsertId();
$h->test('Soft-serve topping created', $topId > 0);
$db->prepare("DELETE FROM dc_soft_serve_toppings WHERE topping_id = :id")->execute(['id' => $topId]);

// Addon
$addonSuffix = 'ADDON_' . bin2hex(random_bytes(4));
$db->prepare("INSERT INTO dc_soft_serve_addons (name, price, type) VALUES (:name, 25.00, 'sauce')")->execute(['name' => $addonSuffix]);
$addonId = (int) $db->lastInsertId();
$h->test('Soft-serve addon created', $addonId > 0);
$db->prepare("UPDATE dc_soft_serve_addons SET price = 30.00, type = 'topping' WHERE addon_id = :id")->execute(['id' => $addonId]);
$auStmt = $db->prepare("SELECT price, type FROM dc_soft_serve_addons WHERE addon_id = :id");
$auStmt->execute(['id' => $addonId]);
$addonUpdated = $auStmt->fetch(\PDO::FETCH_ASSOC);
$h->test('Soft-serve addon price updated', is_array($addonUpdated) && abs((float) $addonUpdated['price'] - 30.00) < 0.01);
$h->test('Soft-serve addon type updated', is_array($addonUpdated) && $addonUpdated['type'] === 'topping');
$db->prepare("DELETE FROM dc_soft_serve_addons WHERE addon_id = :id")->execute(['id' => $addonId]);

// ── 5. Store profile update ──
$h->section('Store Profile DB Operations');

$store = $db->prepare("SELECT store_id, name FROM dc_stores ORDER BY store_id ASC LIMIT 1");
$store->execute();
$storeRow = $store->fetch(\PDO::FETCH_ASSOC);
$h->test('Store row exists', is_array($storeRow));

if ($storeRow) {
    $storeId = (int) $storeRow['store_id'];
    $origName = (string) $storeRow['name'];
    $db->prepare("UPDATE dc_stores SET name = :name, contact_number = :contact WHERE store_id = :id")
        ->execute(['name' => $origName . ' REV', 'contact' => '0917-TEST', 'id' => $storeId]);
    $updatedStore = $db->prepare("SELECT name, contact_number FROM dc_stores WHERE store_id = :id");
    $updatedStore->execute(['id' => $storeId]);
    $updatedStore = $updatedStore->fetch(\PDO::FETCH_ASSOC);
    $h->test('Store name updated', is_array($updatedStore) && str_contains((string) $updatedStore['name'], 'REV'));
    $h->test('Store contact updated', is_array($updatedStore) && $updatedStore['contact_number'] === '0917-TEST');
    $db->prepare("UPDATE dc_stores SET name = :name, contact_number = NULL WHERE store_id = :id")
        ->execute(['name' => $origName, 'id' => $storeId]);
}

// ── 6. User account toggle ──
$h->section('User Account DB Operations');

$db->prepare(
    "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
     VALUES (:u, :p, :e, :n, 'cashier', :s, 1)"
)->execute(['u' => 'testuser_' . $pmSuffix, 'p' => password_hash('x', PASSWORD_BCRYPT), 'e' => 't+' . $pmSuffix . '@test', 'n' => 'Test User', 's' => 1]);
$testUserId = (int) $db->lastInsertId();
$h->test('Test user created', $testUserId > 0);

$db->prepare("UPDATE dc_users SET is_active = 0 WHERE user_id = :id")->execute(['id' => $testUserId]);
$inactiveCheck = $db->prepare("SELECT is_active FROM dc_users WHERE user_id = :id");
$inactiveCheck->execute(['id' => $testUserId]);
$h->test('User deactivated', (int) $inactiveCheck->fetchColumn() === 0);

$db->prepare("DELETE FROM dc_users WHERE user_id = :id")->execute(['id' => $testUserId]);
$delUser = $db->prepare("SELECT COUNT(*) FROM dc_users WHERE user_id = :id");
$delUser->execute(['id' => $testUserId]);
$h->test('Test user cleanup succeeds', (int) $delUser->fetchColumn() === 0);

$h->done();
