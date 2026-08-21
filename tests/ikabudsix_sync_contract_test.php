<?php

declare(strict_types=1);

/**
 * Ikabudsix kernel-sync contract test.
 *
 * Guards the applicationostest sync with the ikabudsix barebones-kernel fixes
 * across three areas:
 *   1. Module manager installation — kernel-admin config handoff (no inline
 *      Config modal), manifest `icon`, companion-only `allow_kernel_admin`.
 *   2. Allow access to kernel admin — kernel-users companion bypass in
 *      getModuleNavItems() + "Companion Modules" nav section marker.
 *   3. Dropping shared DB for modules — kernel migrations run BEFORE module
 *      migrations on tenant provisioning; tenant kernel artifacts include
 *      007_tenant_module_settings.sql; admin seeded into the auth-owned
 *      module's own users_table; no shared-DB auto-create connection row.
 *
 * Run from repo root: php tests/ikabudsix_sync_contract_test.php
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== IKABUDSIX KERNEL-SYNC CONTRACT TEST ===\n";

// ── 1. Tenant kernel artifacts include the module-settings table ──────────
$artifacts = tenantSafeKernelMigrationArtifacts(null);
t(
    'tenant_module_settings artifact present',
    isset($artifacts['007_tenant_module_settings.sql']),
    '007_tenant_module_settings.sql must be a tenant kernel artifact'
);
t(
    'tenant_module_settings.sql exists on disk',
    is_file((string)($artifacts['007_tenant_module_settings.sql'] ?? '')),
    (string)($artifacts['007_tenant_module_settings.sql'] ?? 'missing')
);

// Every declared artifact must exist on disk (a missing file silently skips
// the table, recreating the 1146/42S22 class of failures).
$missing = [];
foreach ($artifacts as $name => $path) {
    if (!is_file($path)) {
        $missing[] = $name;
    }
}
t('all tenant kernel artifacts exist on disk', $missing === [], implode(', ', $missing));

// ── 2. Provisioning order: kernel migrations BEFORE module migrations ─────
$provisioner = (string)file_get_contents(BASE_PATH . '/kernel/Services/TenantProvisioner.php');
$cli = (string)file_get_contents(BASE_PATH . '/ikabud');

t(
    'TenantProvisioner runs kernel migrations before module migrations',
    strpos($provisioner, 'runKernelMigrations') !== false
        && strpos($provisioner, 'runModuleMigrations') !== false
        && strpos($provisioner, 'runKernelMigrations') < strpos($provisioner, 'runModuleMigrations'),
    'kernel migration call must precede module migration call'
);
t(
    'ikabud tenant:provision runs kernel migrations before module migrations',
    strpos($cli, 'Running kernel migrations') !== false
        && strpos($cli, 'Running module migrations') !== false
        && strpos($cli, 'Running kernel migrations') < strpos($cli, 'Running module migrations'),
    'kernel migration step must precede module migration step'
);

// ── 3. Admin seeding resolves auth_owned from the on-disk manifest ────────
t(
    'TenantProvisioner falls back to on-disk manifest auth_owned spec',
    str_contains($provisioner, 'discoverModules')
        && str_contains($provisioner, 'kernelNormalizeAuthOwnedSpec'),
    'provisioner must resolve auth_owned spec from the on-disk manifest'
);
t(
    'ikabud CLI resolves auth-owned users table from manifest',
    str_contains($cli, 'authSpec[\'users_table\']'),
    'CLI must seed into the module\'s own users_table'
);
t(
    'ikabud CLI seeds into the resolved users table (not only users)',
    str_contains($cli, 'INSERT INTO `{$usersTable}`'),
    'CLI must insert into the resolved $usersTable'
);

// ── 4. tenant:verify resolves the auth-owned admin table/columns ──────────
t(
    'ikabud tenant:verify resolves auth-owned admin table',
    str_contains($cli, '$adminTable = is_array($authSpec)'),
    'verify must resolve the admin users table from the manifest'
);
t(
    'ikabud tenant:verify checks _migrations/_kernel tracking',
    str_contains($cli, "WHERE module = :mod")
        && str_contains($cli, "'_kernel'"),
    'verify must check _migrations tracked for _kernel'
);

// ── 5. Shared-DB capability discontinued ──────────────────────────────────
$adminHandlers = (string)file_get_contents(BASE_PATH . '/src/http/admin-handlers.php');
t(
    'tenant create no longer auto-creates a shared-DB connection to the base app DB',
    !str_contains($adminHandlers, 'Auto-create a shared-DB connection record')
        && !str_contains($adminHandlers, '$_ENV[\'DB_DATABASE\']'),
    'shared-DB auto-create block must be removed from kernelHandleApiTenantCreate'
);
t(
    'tenant create defers migration sync until a dedicated DB is configured',
    str_contains($adminHandlers, 'db_configured'),
    'create response must signal db_configured=false when no dedicated DB exists'
);
t(
    'dedicated tenant DB upsert still records the explicit connection',
    str_contains($adminHandlers, 'INSERT INTO kernel_tenant_db_connections'),
    'the explicit tenant DB upsert handler must remain'
);

// ── 6. Kernel-admin companion bypass + nav section ────────────────────────
$moduleManager = (string)file_get_contents(BASE_PATH . '/src/helpers/module-manager.php');
t(
    'getModuleNavItems scopes bypass to declared kernel_companion modules',
    str_contains($moduleManager, '$declaredCompanion = !empty($module[\'kernel_companion\'] ?? false)')
        && str_contains($moduleManager, '($declaredCompanion && $usesKernelUsers)'),
    'nav gate must only bypass the stored opt-in for declared kernel companions'
);
t(
    'getModuleNavItems groups companions under a section marker',
    str_contains($moduleManager, 'Companion Modules'),
    'kernel-admin companion nav must be grouped under a labeled section'
);

$previousUser = app()->user();
app()->setUser([
    'id' => 999901,
    'role' => 'admin',
    'source' => 'kernel',
]);
$kernelAdminNav = getModuleNavItems();
app()->setUser(is_array($previousUser) ? $previousUser : []);

$navModuleIds = array_values(array_filter(array_map(static fn(array $i): string => (string)($i['module'] ?? ''), $kernelAdminNav), static fn(string $m): bool => $m !== '_kernel'));

t(
    'kernel-admin nav includes the Companion Modules section marker',
    !empty(array_filter($kernelAdminNav, static fn(array $i): bool => ($i['section'] ?? '') === 'Companion Modules')),
    'section marker must carry section=Companion Modules'
);
t(
    'kernel-admin nav includes the declared kernel companion (gui-settings)',
    in_array('gui-settings', $navModuleIds, true),
    'gui-settings must appear in kernel-admin companion nav: ' . implode(', ', $navModuleIds)
);
t(
    'kernel-admin nav excludes suite extension modules (cms-akira)',
    !in_array('cms-akira-editor', $navModuleIds, true) && !in_array('cms-akira-media', $navModuleIds, true) && !in_array('cms-akira-search-adapter', $navModuleIds, true),
    'extension/adapter modules must NOT appear by default: ' . implode(', ', $navModuleIds)
);
t(
    'kernel-admin nav excludes standalone kernel-users modules',
    !in_array('academic-similarity', $navModuleIds, true) && !in_array('anti-spam', $navModuleIds, true),
    'standalone modules must NOT appear by default: ' . implode(', ', $navModuleIds)
);

// ── 7. Module manager page: no inline config, icon passed, companion gating ─
$pageHandlers = (string)file_get_contents(BASE_PATH . '/src/http/page-handlers.php');
t(
    'kernelHandlePageAdminModules offers no inline config fields',
    str_contains($pageHandlers, '$editableSettingsFields = [];'),
    'page handler must force editable settings fields empty (tenant-owned config)'
);
t(
    'kernelHandlePageAdminModules explains settings are tenant-configured',
    str_contains($pageHandlers, 'Module settings are configured by the admin on the tenant domain'),
    'page handler must show the tenant-domain settings notice'
);
t(
    'kernelHandlePageAdminModules passes manifest icon',
    str_contains($pageHandlers, "'icon' => trim"),
    'page handler must pass the manifest icon'
);
t(
    'kernelHandlePageAdminModules computes companion gating',
    str_contains($pageHandlers, 'kernel_admin_guaranteed')
        && str_contains($pageHandlers, 'show_allow_kernel_admin')
        && str_contains($pageHandlers, "['kernel_companion'] ?? false")
        && !str_contains($pageHandlers, 'MODULE_KIND_EXTENSION'),
    'page handler must gate allow_kernel_admin on declared kernel companions only'
);

// ── 8. Template sync ──────────────────────────────────────────────────────
$adminModules = (string)file_get_contents(BASE_PATH . '/templates/pages/admin-modules.disyl');
t(
    'admin-modules template no longer has the Config modal',
    !str_contains($adminModules, 'module-config-modal'),
    'Config modal markup must be removed'
);
t(
    'admin-modules template renders the manifest icon',
    str_contains($adminModules, 'kernel-admin-icon.disyl'),
    'module cards must render the manifest icon via kernel-admin-icon'
);
t(
    'admin-modules template gates the opt-in row on show_allow_kernel_admin',
    str_contains($adminModules, 'show_allow_kernel_admin'),
    'opt-in row must only render for companion modules'
);

$appLayout = (string)file_get_contents(BASE_PATH . '/templates/layouts/app.disyl');
$kernelAdminLayout = (string)file_get_contents(BASE_PATH . '/templates/layouts/kernel-admin.disyl');
t('app layout renders nav.section', str_contains($appLayout, 'nav.section'), 'app.disyl must render section markers');
t('kernel-admin layout renders item.section', str_contains($kernelAdminLayout, 'item.section'), 'kernel-admin.disyl must render section markers');

// ── 9. gui-settings manifest ──────────────────────────────────────────────
$guiSettings = json_decode((string)file_get_contents(BASE_PATH . '/modules/gui-settings/module.json'), true);
t('gui-settings module.json is valid JSON', is_array($guiSettings), json_last_error_msg());
t('gui-settings declares kernel_companion', is_array($guiSettings) && ($guiSettings['kernel_companion'] ?? false) === true);
t('gui-settings declares icon', is_array($guiSettings) && trim((string)($guiSettings['icon'] ?? '')) !== '');

// ── 10. Manifest validation accepts the new fields ────────────────────────
require_once BASE_PATH . '/src/helpers/manifest-validation.php';
$fixture = [
    'id' => 'sync-fixture',
    'name' => 'Sync Fixture',
    'version' => '1.0.0',
    'icon' => 'box',
    'kernel_companion' => true,
    'owns_tables' => [],
    'reads_tables' => [],
    'routes' => false,
    'capabilities' => ['exposes' => [], 'depends' => []],
];
$validated = validateModuleManifestV1($fixture, ['module_path' => sys_get_temp_dir()]);
t('manifest with icon + kernel_companion validates', !empty($validated['ok']), json_encode($validated['diagnostics'] ?? [], JSON_UNESCAPED_SLASHES));

$badFixture = $fixture;
$badFixture['icon'] = 'Not An Icon!';
$badFixture['kernel_companion'] = 'yes';
$badValidated = validateModuleManifestV1($badFixture, ['module_path' => sys_get_temp_dir()]);
$badDiagnostics = implode(', ', array_column($badValidated['diagnostics'] ?? [], 'rule'));
t(
    'manifest with invalid icon/kernel_companion is rejected',
    empty($badValidated['ok'])
        && str_contains($badDiagnostics, 'manifest.v1.icon')
        && str_contains($badDiagnostics, 'manifest.v1.kernel-companion'),
    $badDiagnostics
);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
t('no error.log errors', $errorLog === '', $errorLog);

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
