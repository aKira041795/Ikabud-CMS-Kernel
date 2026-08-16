<?php
/**
 * CI Module-Enabling Seed
 *
 * Enables modules that CI HTTP tests exercise but that are not part of a
 * tenant's entry-module dependency spine (so they would be disabled by
 * default). The e2e tests hit these hosts over HTTP; for their routes to be
 * registered the module must be enabled for the tenant that resolves the
 * request host.
 *
 *   - ticketing_e2e_test hits http://applicationos.test (default tenant).
 *     Enables `ticketing` + `contact-form` for that tenant.
 *
 * Idempotent: enableModuleForTenant() upserts tenant_module_settings rows.
 * Run AFTER application migrations (tenant_module_settings table must exist)
 * and AFTER ci_tenants.php (kernel_tenants / db connections must exist).
 *
 * Usage: php database/seeds/ci_enable_modules.php
 */

declare(strict_types=1);

chdir(dirname(dirname(__DIR__)));
require_once 'bootstrap.php';
require_once 'src/helpers/module-manager.php';

/**
 * Resolve the tenant id that the given host maps to at request time.
 * Mirrors TenantResolver strategy 7 (config default) when the host is not
 * explicitly mapped, so the seed targets the same tenant the HTTP tests see.
 */
function ciResolveTenantForHost(string $host): ?int
{
    $record = \Ikabud\Kernel\TenantResolver::lookupControlHostRecord($host);
    if (is_array($record) && isset($record['tenant_id'])) {
        return (int) $record['tenant_id'];
    }

    $default = $_ENV['APP_TENANT_DEFAULT'] ?? null;
    if ($default !== null && trim((string) $default) !== '') {
        return (int) $default;
    }

    return null;
}

$host = 'applicationos.test';
$tenantId = ciResolveTenantForHost($host);
if ($tenantId === null || $tenantId <= 0) {
    fwrite(STDERR, "CI module seed: could not resolve a tenant for {$host}; ticketing/contact-form/guidance/bakeshop enabling skipped.\n");
} else {
    // ticketing + contact-form: e2e HTTP routes (/submit-ticket, /tickets).
    // guidance: guidance_password_reset_test authenticates through the
    // kernel.auth.authenticate@1 pipeline; its provider is only registered
    // when the guidance module is enabled for the resolved tenant (the direct
    // capability function works regardless, but the HTTP login handler goes
    // through the registered provider pipeline → 401 without this).
    // bakeshop: bakeshop_tenant_admin_password_push_behavior_test provisions
    // a fresh bakeshop tenant while applicationos.test is the active context;
    // TenantProvisioner::seedAdminUser() only seeds bakeshop_users when the
    // bakeshop module is enabled (kernelAuthOwnedSpecForModule → getEnabledModules()).
    $modules = ['ticketing', 'contact-form', 'guidance', 'bakeshop'];

    echo "CI module seed: enabling " . implode(', ', $modules) . " for tenant #{$tenantId} ({$host})\n";
    foreach ($modules as $moduleId) {
        enableModuleForTenant($moduleId, $tenantId);
        $state = isModuleEnabledForTenant($moduleId, $tenantId) ? 'enabled' : 'DISABLED';
        echo "  {$moduleId}: {$state}\n";
    }
    ciEnsureBakeshopUsers($tenantId);
}

// CMS entity-widget tests (cms_builder_entity_widgets_test etc.) render over
// cmsnew.test, which resolves to the CMS tenant (applicationos / clientsite).
// The ecommerce module provides the `entity.list.ecommerce_product@1`
// capability the entity_list widget delegates to; without it the widget
// renders "No items found" even though the products exist.
$cmsHost = 'cmsnew.test';
$cmsTenantId = ciResolveTenantForHost($cmsHost);
if ($cmsTenantId === null || $cmsTenantId <= 0) {
    fwrite(STDERR, "CI module seed: could not resolve a tenant for {$cmsHost}; ecommerce enabling skipped.\n");
} else {
    $cmsModules = ['ecommerce'];

    echo "CI module seed: enabling " . implode(', ', $cmsModules) . " for tenant #{$cmsTenantId} ({$cmsHost})\n";
    foreach ($cmsModules as $moduleId) {
        enableModuleForTenant($moduleId, $cmsTenantId);
        $state = isModuleEnabledForTenant($moduleId, $cmsTenantId) ? 'enabled' : 'DISABLED';
        echo "  {$moduleId}: {$state}\n";
    }

    // ecommerce_digital_purchase_e2e_test signs the license JWT with the
    // ecommerce module's license_private_key_pem and verifies it against the
    // bundled modules/guidance/license-key.pem public key. Those must be a
    // matching pair. In the dev DB the pair is pre-seeded on the cmsnew.test
    // tenant; CI starts from an empty settings store, so the test would fall
    // back to generating a random key that does NOT match the bundled public
    // key and every "License key signature is invalid" assertion fails. Mirror
    // the dev DB by seeding the same private key here.
    ciEnsureEcommerceLicenseKey($cmsTenantId);

    // superadmin_feature_settings_relevance_test asserts that the CMS tenant's
    // relevant-module map includes the "WordPress importer CMS data add-on"
    // (wordpress-importer). That relevance is driven by the CMS tenant's
    // `_installed_submodules` setting (read in superadminTenantRelevantModuleMap).
    // The dev CMS tenant has wordpress-importer/content-ingestion in that list;
    // CI's fresh tenant does not, so the assertion fails. Mirror the dev state.
    ciEnsureCmsInstalledSubmodules($cmsTenantId);

    // bakeshop_characterization_test (cmsnew.test → this tenant) asserts the
    // bakeshop tables have baseline data (ingredients, products). CI's fresh
    // tenant has migrations only; the dev DB carries the Julies Bakeshop
    // fixture. Apply that fixture here so the characterization assertions pass,
    // and enable the bakeshop module for this tenant (users are already seeded
    // by the bakeshop user provisioning block below).
    enableModuleForTenant('bakeshop', $cmsTenantId);
    ciEnsureBakeshopBaseline($cmsTenantId);
}

/**
 * Seed the CMS tenant's `_installed_submodules` setting so the superadmin
 * feature-settings relevance map reports the same CMS data add-ons that the
 * dev database has (wordpress-importer, content-ingestion, etc.). Idempotent:
 * merges the given ids into the existing list.
 */
function ciEnsureCmsInstalledSubmodules(int $tenantId): void
{
    $settings = readTenantModuleSettingsForTenant('cms', $tenantId);
    $current = $settings['_installed_submodules'] ?? [];
    if (is_string($current)) {
        $decoded = json_decode($current, true);
        $current = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($current)) {
        $current = [];
    }

    $target = ['wordpress-importer', 'content-ingestion', 'contact-form', 'theme-studio'];
    $changed = false;
    foreach ($target as $moduleId) {
        if (!in_array($moduleId, $current, true)) {
            $current[] = $moduleId;
            $changed = true;
        }
    }

    if ($changed) {
        saveTenantModuleSettingsForTenant('cms', $tenantId, ['_installed_submodules' => $current]);
        invalidateTenantModuleSettingsCache();
    }
    echo "  cms _installed_submodules: " . implode(',', $current) . " (tenant #{$tenantId})\n";
}

/**
 * Ensure the cmsnew.test tenant has the ecommerce license signing private key
 * that pairs with the bundled modules/guidance/license-key.pem public key.
 * The license JWT is signed with this key and verified against the public key;
 * a random generated key would not verify. Idempotent upsert.
 */
function ciEnsureEcommerceLicenseKey(int $tenantId): void
{
    $privateKeyPem = "-----BEGIN PRIVATE KEY-----\n"
        . "MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC/FRal1X4OtimB\n"
        . "7ESKa7Eko6EprzipL5Bewxs44m5U/jXeLLnhxKwZSCl6PMKLWHZKiMnLJPU0uS7j\n"
        . "OlvrCp+/O6alRfg6DQETPiKTkbnrS118HvN2Ek6MBynu8XVHFxBhQE0zfQeRwbHW\n"
        . "QsLTnDvkMWm38EzY/RVfD5PsOSO2DwmiZ6ZXHWE25RtZlvI1KrOXZW9GEIINrhlY\n"
        . "1VbTAkTF2t0WqIYXvDvfWaWkBn9MTTCQevVuG+kw5/xIkxYEAYsm3e9wBtMxouJH\n"
        . "CWIxkclRN5/Emtp7VdnadT0dTbZEttwGJQsalkRwHXlSCd0lrt/WXzXOVdI9Njo6\n"
        . "mUVZXuR9AgMBAAECggEACIT+L4KnTiydCSfpnmpSyZlqFBu14QU34KG+Uvj1hmCX\n"
        . "MkK4PvKv4aiwAL04x1G4ZHZY2O/a5vDiwErX0lD08mfMdE38VUpDJAJ/NCkpKu5/\n"
        . "Sotuu3LxgZjIK9kkK3lBx7RAPO10KvGB5lWvrhOnL/NsDUFvi7UtAMIHDF830mQh\n"
        . "VwKYLHn5e6BWVSXxcLxiL0X5Za3PMX4vL58aQcVYolqWRigYw+zCRmKy2stwmUtf\n"
        . "iKa0RtyJa9depNAFRHjbzXU99CUB2wZPhPjRuCsqRwQcCa2UcYkdJGtiHUW/8xKU\n"
        . "E2nbiUfWvYsrzDAR5JE4vsouF5hxH8OmXbj84vJTwQKBgQD9zWLiX0Zj8o5CZU2y\n"
        . "1+FvFrcI9tTeWMvEqKv2cs+KDqR2YGr3Grw4fnReIhgXxscJ+uefRl7J9NQoLF8I\n"
        . "h/dbQnaehkyL8HdJ/FX+ZJSHJZ9NLp3E4kwoYofVJk6o8uYMxK8Sv4cFPWoN00NO\n"
        . "aI+ctvXjqZrrrsX0azbXeC1HhQKBgQDAvKsfhAE4s8vmUPRjZpaioI7NBS7ycU2B\n"
        . "6Zv7i90ClUme5KRVVpSVlsdiArGnw44jbZqdVWaJj8O1mwGU42j1VxUwMzyk0hCQ\n"
        . "HoPYD9jF6/SSj4nz23xnkMNOUyzabts46mGPpe8t57ahsfPCBerBdQOdVWUnXuKu\n"
        . "eQ5xvYRumQKBgQDH/eHfs2fKNkW3OBBjzw0K9oFAhQ/0LVBUJP1sc8fqZ+NcjFl9\n"
        . "YgnTEoIr8v29LpuE17tQnKjwxwWuqlgwZsOZm+PQws7qro+xMy+oCCWp4RGIRiV4\n"
        . "EUIlyI50fX0aUFzKzumOAnIoxN4fCsxMqsQPn3Re8zTqZowCL8HFRCOZ6QKBgHV5\n"
        . "SW/vHHN8GxZpV1vSppO++ur5ctDwwEYjpiAe8nlllrbTM1qUaAH5IdOaQsA3UEZF\n"
        . "wsyMxe+ogagKL1+ZcFrBVjfHsvne05uUDdY+amjQVYSTGolYyS2yrWfrCFam5NV+\n"
        . "/jH4+JxpNAbAGQu0YY7CjI50AzCJA+9F98jZs4NZAoGBAMN0UScKQGJ7cUSgjKIU\n"
        . "QRKHms31b4DOyqUJsHK50SF2dPqx+yoCdlVQmrF62NvX9C4GQnyA8n1aeNskth9N\n"
        . "E/VhrOzEGWVZpCAkHnKr4pF7NiiIv0boDQ1kRQybM2VRUM1XrG4GoyfOOFAf/Y2h\n"
        . "vVApI5MkIReD6+GrcGL6N0nf\n"
        . "-----END PRIVATE KEY-----";

    $current = readTenantModuleSettingsForTenant('ecommerce', $tenantId);
    if (is_array($current) && trim((string)($current['license_private_key_pem'] ?? '')) !== '') {
        echo "  ecommerce license_private_key_pem: already present on tenant #{$tenantId}, skipped\n";
        return;
    }

    saveTenantModuleSettingsForTenant('ecommerce', $tenantId, ['license_private_key_pem' => $privateKeyPem]);
    invalidateTenantModuleSettingsCache();
    echo "  ecommerce license_private_key_pem: seeded matching key on tenant #{$tenantId}\n";
}

/**
 * Ensure the bakeshop module has the users the bakeshop test suites render
 * as. The bakeshop migration only seeds the default `bakeshopadmin` (id=1),
 * but several bakeshop tests (e.g. bakeshop_supervisor_settings_panel_test,
 * bakeshop_print_summary_test) render the supervisor shell as user id=2.
 * Without an id=2 bakeshop_users row, bakeshopCurrentUser() rejects the
 * stale session → the handler emits a 403 JSON body + http_response_code()
 * after the test already echoed output → error.log fills with
 * "headers already sent" warnings and the page assertions fail.
 */
function ciEnsureBakeshopUsers(int $tenantId): void
{
    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        fwrite(STDERR, "  bakeshop users: no DB connection for tenant #{$tenantId}\n");
        return;
    }

    try {
        $exists = $db->query("SHOW TABLES LIKE 'bakeshop_users'")->fetchColumn();
        if ($exists === false) {
            fwrite(STDERR, "  bakeshop users: bakeshop_users table missing in tenant DB (is bakeshop enabled?)\n");
            return;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "  bakeshop users: cannot inspect bakeshop_users ({$e->getMessage()})\n");
        return;
    }

    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // bcrypt of 'password'
    $rows = [
        ['id' => 1, 'username' => 'admin', 'email' => 'admin@bakeshop.local', 'full_name' => 'Bakeshop Admin', 'role' => 'admin'],
        ['id' => 2, 'username' => 'supervisor', 'email' => 'supervisor@bakeshop.local', 'full_name' => 'Bakeshop Supervisor', 'role' => 'supervisor'],
    ];

    $stmt = $db->prepare(
        'INSERT INTO bakeshop_users (id, username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) '
        . 'VALUES (:id, :u, :e, NULL, :h, :n, :r, 1, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE username = VALUES(username), email = VALUES(email), role = VALUES(role), is_active = 1'
    );

    foreach ($rows as $row) {
        $stmt->execute([
            ':id' => $row['id'],
            ':u' => $row['username'],
            ':e' => $row['email'],
            ':h' => $hash,
            ':n' => $row['full_name'],
            ':r' => $row['role'],
        ]);
        echo "  bakeshop_users id={$row['id']} ({$row['username']}, {$row['role']}): ensured\n";
    }
}

/**
 * Apply the Julie's Bakeshop fixture to the tenant's bakeshop tables if the
 * baseline ingredients/products are missing. bakeshop_characterization_test
 * asserts bakeshop_ingredients / bakeshop_products have data (the dev DB
 * carries the Julies fixture; CI's fresh tenant has migrations only). The
 * fixture is standard SQL (temp tables + transaction, no DELIMITER), so it can
 * be executed directly against the tenant DB. Idempotent: skips when products
 * already exist.
 */
function ciEnsureBakeshopBaseline(int $tenantId): void
{
    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        fwrite(STDERR, "  bakeshop baseline: no DB connection for tenant #{$tenantId}\n");
        return;
    }

    // Ensure bakeshop tables exist first (the characterization test expects to
    // find seeded data; CI's tenant DB has no bakeshop tables yet).
    try {
        $hasTables = (bool)$db->query("SHOW TABLES LIKE 'bakeshop_products'")->fetchColumn();
    } catch (Throwable $e) {
        $hasTables = false;
    }
    if (!$hasTables) {
        try {
            $runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
            $runner->migrate('bakeshop');
            echo "  bakeshop baseline: ran bakeshop migrations on tenant #{$tenantId}\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "  bakeshop baseline: bakeshop migration failed ({$e->getMessage()})\n");
            return;
        }
    }

    try {
        $productCount = (int)$db->query('SELECT COUNT(*) FROM bakeshop_products')->fetchColumn();
    } catch (Throwable $e) {
        fwrite(STDERR, "  bakeshop baseline: cannot inspect bakeshop_products ({$e->getMessage()})\n");
        return;
    }

    if ($productCount > 0) {
        echo "  bakeshop baseline: already has {$productCount} products, skipped\n";
        return;
    }

    $seedPath = __DIR__ . '/002_bakeshop_julies_bread_pastry.sql';
    if (!is_file($seedPath)) {
        fwrite(STDERR, "  bakeshop baseline: fixture not found at {$seedPath}\n");
        return;
    }

    $sql = (string)file_get_contents($seedPath);
    if (trim($sql) === '') {
        fwrite(STDERR, "  bakeshop baseline: fixture is empty\n");
        return;
    }

    try {
        // The fixture uses START TRANSACTION / temp tables / INSERT ... SELECT.
        // KernelPDO's exec() supports multiple statements.
        $db->exec($sql);
        $count = (int)$db->query('SELECT COUNT(*) FROM bakeshop_products')->fetchColumn();
        echo "  bakeshop baseline: applied Julies fixture ({$count} products)\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  bakeshop baseline: fixture apply failed ({$e->getMessage()})\n");
    }
}

// Bakeshop test suites render over cmsnew.test (applicationos tenant), which
// maps to the clientsite tenant DB in CI. Provision its bakeshop users too.
$bakeshopHost = 'cmsnew.test';
$bakeshopTenantId = ciResolveTenantForHost($bakeshopHost);
if ($bakeshopTenantId !== null && $bakeshopTenantId > 0) {
    echo "CI module seed: provisioning bakeshop users for tenant #{$bakeshopTenantId} ({$bakeshopHost})\n";
    ciEnsureBakeshopUsers($bakeshopTenantId);
} else {
    fwrite(STDERR, "CI module seed: could not resolve a tenant for {$bakeshopHost}; skipping bakeshop user seed.\n");
}

/**
 * Provision daily-ledger demo data that the daily_ledger_full_process_test
 * expects on the baronledger tenant. That test is a full-process stress test
 * that assumes a provisioned tenant: a Ledger-Admin user (id=1), the canonical
 * PANDESAL (id=10) / MONAY (id=11) products, the feature flags enabled, and a
 * default price group. CI starts from an empty tenant DB (migrations only), so
 * without this seed the test's S0 flag checks, user lookup, and the
 * dl_product_prices FK insert (product 10) all fail.
 *
 * Idempotent: ON DUPLICATE KEY / INSERT IGNORE. Settings are upserted into the
 * per-tenant settings store read by dlModuleSettings().
 */
function ciEnsureDailyLedgerData(int $tenantId): void
{
    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        fwrite(STDERR, "  daily-ledger data: no DB connection for tenant #{$tenantId}\n");
        return;
    }

    try {
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        fwrite(STDERR, "  daily-ledger data: cannot inspect tenant DB ({$e->getMessage()})\n");
        return;
    }

    if (in_array('dl_users', $tables, true)) {
        $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // bcrypt of 'password'
        $db->prepare(
            'INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, created_at, updated_at) '
            . 'VALUES (1, :u, :h, :n, :r, 1, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE username = VALUES(username), role = VALUES(role), is_active = 1'
        )->execute([
            ':u' => 'Ledger-Admin',
            ':h' => $hash,
            ':n' => 'Ledger Admin',
            ':r' => 'admin',
        ]);
        echo "  dl_users: Ledger-Admin (id=1, role=admin): ensured\n";
    } else {
        fwrite(STDERR, "  daily-ledger data: dl_users table missing in tenant DB\n");
    }

    if (in_array('dl_products', $tables, true)) {
        $stmt = $db->prepare(
            'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active, created_at, updated_at) '
            . 'VALUES (:id, :sku, :name, :price, :sort, 1, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), current_price = VALUES(current_price), is_active = 1'
        );
        foreach ([
            ['id' => 10, 'sku' => 'PANDESAL', 'name' => 'PANDESAL', 'price' => 30.00, 'sort' => 1],
            ['id' => 11, 'sku' => 'MONAY', 'name' => 'MONAY', 'price' => 25.00, 'sort' => 2],
        ] as $product) {
            $stmt->execute($product);
            echo "  dl_products: {$product['name']} (id={$product['id']}, current_price={$product['price']}): ensured\n";
        }
    } else {
        fwrite(STDERR, "  daily-ledger data: dl_products table missing in tenant DB\n");
    }

    // Enable the feature flags the full-process test asserts are ON.
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, [
        'production_output_enabled' => '1',
        'formal_delivery_workflow_enabled' => '1',
        'selling_accounts_enabled' => '1',
        'price_groups_enabled' => '1',
    ]);
    echo "  daily-ledger settings: production_output/formal_delivery/selling_accounts/price_groups enabled\n";
}

// daily_ledger_full_process_test renders over baronledger.test, which in CI
// resolves to the baron-001 (id=4) daily-ledger tenant DB. Provision the demo
// data it requires.
$dailyLedgerHost = 'baronledger.test';
$dailyLedgerTenantId = ciResolveTenantForHost($dailyLedgerHost);
if ($dailyLedgerTenantId !== null && $dailyLedgerTenantId > 0) {
    echo "CI module seed: provisioning daily-ledger data for tenant #{$dailyLedgerTenantId} ({$dailyLedgerHost})\n";
    ciEnsureDailyLedgerData($dailyLedgerTenantId);
} else {
    fwrite(STDERR, "CI module seed: could not resolve a tenant for {$dailyLedgerHost}; skipping daily-ledger data seed.\n");
}

echo "CI module seed complete.\n";
