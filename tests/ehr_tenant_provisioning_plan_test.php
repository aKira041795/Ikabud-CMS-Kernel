<?php
declare(strict_types=1);

$registryPath = __DIR__ . '/../storage/modules.json';
$originalRegistry = is_file($registryPath) ? (string)file_get_contents($registryPath) : null;
$registry = [];
if ($originalRegistry !== null && $originalRegistry !== '') {
    $decoded = json_decode($originalRegistry, true);
    if (is_array($decoded)) {
        $registry = $decoded;
    }
}

$moduleIds = [
    'ehr',
    'ehr-core',
    'patient-registry',
    'encounters',
    'clinical-notes',
    'orders',
    'results',
    'prescriptions',
    'documents',
    'privacy-consent',
    'scheduling',
    'audit',
    'reporting',
    'billing-bridge',
    'cms',
];

foreach ($moduleIds as $moduleId) {
    $entry = $registry[$moduleId] ?? [];
    if (!is_array($entry)) {
        $entry = [];
    }
    $entry['enabled'] = true;
    $registry[$moduleId] = $entry;
}

$dir = dirname($registryPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

register_shutdown_function(static function () use ($registryPath, $originalRegistry): void {
    if ($originalRegistry === null) {
        @unlink($registryPath);
        return;
    }

    file_put_contents($registryPath, $originalRegistry, LOCK_EX);
});

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-registry.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';
require_once __DIR__ . '/../src/http/tenant-entry-modules.php';

// ── Synthetic module fixtures (created before first discoverModules call) ─
// These exercise the planner reverse-dependency closure and the scope-repair
// reference/cleanup decisions deterministically without depending on the real
// module graph staying fixed. They are removed on shutdown.
$fixtureManifests = [
    'ztest-entry-root' => [
        'id' => 'ztest-entry-root',
        'name' => 'ZTest Entry Root',
        'version' => '1.0.0',
        'description' => 'Test fixture entry root',
        'owns_tables' => [],
        'reads_tables' => [],
        'entry_module' => true,
        'migrations' => ['database/migrations/001_initial.sql'],
    ],
    'ztest-rev-dep' => [
        'id' => 'ztest-rev-dep',
        'name' => 'ZTest Reverse Dep',
        'version' => '1.0.0',
        'description' => 'Test fixture leaf dependency with an owned table',
        'owns_tables' => ['ztest_rev_dep_table'],
        'reads_tables' => [],
        'migrations' => ['database/migrations/001_initial.sql'],
    ],
    'ztest-fail-dep' => [
        'id' => 'ztest-fail-dep',
        'name' => 'ZTest Fail Dep',
        'version' => '1.0.0',
        'description' => 'Test fixture for drop-failure safety path',
        'owns_tables' => ['ztest_fail_table'],
        'reads_tables' => [],
        'migrations' => ['database/migrations/001_initial.sql'],
    ],
    'ztest-rev-child' => [
        'id' => 'ztest-rev-child',
        'name' => 'ZTest Reverse Child',
        'version' => '1.0.0',
        'description' => 'Test fixture reverse-dependent on the entry root',
        'depends' => ['ztest-entry-root', 'ztest-rev-dep'],
        'owns_tables' => [],
        'reads_tables' => [],
        'migrations' => ['database/migrations/001_initial.sql'],
    ],
    'ztest-rev-grandchild' => [
        'id' => 'ztest-rev-grandchild',
        'name' => 'ZTest Reverse Grandchild',
        'version' => '1.0.0',
        'description' => 'Test fixture that must NOT be pulled by reverse recursion',
        'depends' => ['ztest-rev-child'],
        'owns_tables' => [],
        'reads_tables' => [],
        'migrations' => ['database/migrations/001_initial.sql'],
    ],
    'ztest-missing-dep-child' => [
        'id' => 'ztest-missing-dep-child',
        'name' => 'ZTest Missing Dep Child',
        'version' => '1.0.0',
        'description' => 'Test fixture with an unresolvable dependency',
        'depends' => ['ztest-does-not-exist'],
        'owns_tables' => [],
        'reads_tables' => [],
        'migrations' => ['database/migrations/001_initial.sql'],
    ],
];
$createdFixtureDirs = [];
foreach ($fixtureManifests as $fixtureId => $fixtureManifest) {
    $fixtureDir = __DIR__ . '/../modules/' . $fixtureId;
    if (is_dir($fixtureDir)) {
        continue;
    }
    @mkdir($fixtureDir . '/database/migrations', 0775, true);
    file_put_contents(
        $fixtureDir . '/module.json',
        json_encode($fixtureManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    file_put_contents($fixtureDir . '/database/migrations/001_initial.sql', "-- fixture migration\n");
    $createdFixtureDirs[] = $fixtureDir;
}
unset($GLOBALS['_kernel_discovered_modules']);

register_shutdown_function(static function () use ($createdFixtureDirs): void {
    foreach ($createdFixtureDirs as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
});

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== EHR Tenant Provisioning Plan ===\n\n";

$ehrPlan = tenantProvisionModulePlan('ehr');
$legacyEhrPlan = tenantProvisionModulePlan('ehr-core');
$cmsPlan = tenantProvisionModulePlan('cms');
$cmsAkiraPlan = tenantProvisionModulePlan('cms-akira-profile-standard');
$entryOptions = listTenantEntryModuleOptions();
$ehrOption = null;
$cmsAkiraOption = null;
foreach ($entryOptions as $option) {
    if ((string)($option['id'] ?? '') === 'ehr') {
        $ehrOption = $option;
    }
    if ((string)($option['id'] ?? '') === 'cms-akira-profile-standard') {
        $cmsAkiraOption = $option;
    }
}

$expectedEhrModules = [
    'patient-registry',
    'encounters',
    'clinical-notes',
    'orders',
    'results',
    'prescriptions',
    'documents',
    'privacy-consent',
    'scheduling',
];

t('ehr plan includes module-owned auth surface', in_array('ehr', $ehrPlan, true), json_encode($ehrPlan));
t('ehr plan includes migratable EHR bundle modules', count(array_diff($expectedEhrModules, $ehrPlan)) === 0, json_encode($ehrPlan));
t('legacy ehr-core plan aliases to include ehr auth module', in_array('ehr', $legacyEhrPlan, true), json_encode($legacyEhrPlan));
t('ehr plan includes reporting bundle members when seeded', in_array('reporting', tenantProvisionEntryBundleModules('ehr'), true) && in_array('billing-bridge', tenantProvisionEntryBundleModules('ehr'), true), json_encode(tenantProvisionEntryBundleModules('ehr')));
t('cms plan still includes cms', in_array('cms', $cmsPlan, true), json_encode($cmsPlan));
t('cms plan does not pull ehr from nav hook declarations', !in_array('ehr', $cmsPlan, true), json_encode($cmsPlan));
t('cms akira plan includes profile standard', in_array('cms-akira-profile-standard', $cmsAkiraPlan, true), json_encode($cmsAkiraPlan));
t('cms akira plan stays scoped (no academic-similarity spillover)', !in_array('academic-similarity', $cmsAkiraPlan, true), json_encode($cmsAkiraPlan));
t('tenant entry option exposes ehr as suite', is_array($ehrOption) && (string)($ehrOption['name'] ?? '') === 'EHR Suite', is_array($ehrOption) ? json_encode($ehrOption) : 'missing');
t('tenant entry option exposes cms akira standard', is_array($cmsAkiraOption) && (string)($cmsAkiraOption['name'] ?? '') === 'CMS Akira Standard Profile', is_array($cmsAkiraOption) ? json_encode($cmsAkiraOption) : 'missing');
t('legacy ehr-core entry normalizes to ehr', (normalizeTenantEntryModuleId('ehr-core')['value'] ?? null) === 'ehr', json_encode(normalizeTenantEntryModuleId('ehr-core')));

// ── Planner: reverse-selected modules receive forward closure ───────────
$fixturePlan = tenantProvisionModulePlan('ztest-entry-root');
t('reverse-selected module receives its forward dependencies', in_array('ztest-rev-dep', $fixturePlan, true), json_encode($fixturePlan));
t('reverse dependency does not recurse into grandchild product trees', !in_array('ztest-rev-grandchild', $fixturePlan, true), json_encode($fixturePlan));

$missingDeps = tenantProvisionPlanMissingDependencies('ztest-missing-dep-child');
$missingFound = false;
foreach ($missingDeps as $missingDep) {
    if ((string)($missingDep['module'] ?? '') === 'ztest-missing-dep-child' && (string)($missingDep['depends'] ?? '') === 'ztest-does-not-exist') {
        $missingFound = true;
        break;
    }
}
t('unresolvable dependency is reported, not silently omitted', $missingFound, json_encode($missingDeps));

$scopedMissing = tenantProvisionPlanMissingDependencies('ztest-entry-root');
$unrelatedReported = false;
foreach ($scopedMissing as $missingEntry) {
    if ((string)($missingEntry['module'] ?? '') === 'ztest-missing-dep-child') {
        $unrelatedReported = true;
        break;
    }
}
t('missing-dependency report is scoped to the selected plan', !$unrelatedReported, json_encode($scopedMissing));

// ── Pure repair decision logic (no DB) ─────────────────────────────────
$allModulesForRepair = discoverModules();

$leafCleanup = tenantComputeMigrationScopeCleanup(['ztest-rev-dep'], 'cms', $allModulesForRepair, []);
t('unrelated leaf module is a cleanup candidate', in_array('ztest-rev-dep', $leafCleanup['cleanup_modules'] ?? [], true), json_encode($leafCleanup));
t('cleanup candidate would drop its owned table', in_array('ztest_rev_dep_table', $leafCleanup['would_drop_tables'] ?? [], true), json_encode($leafCleanup));

$sameFamily = tenantComputeMigrationScopeCleanup(['cms-akira-seo'], 'cms-akira-profile-standard', $allModulesForRepair, []);
t('same-family module is retained via manifest suite', ($sameFamily['retained_modules']['cms-akira-seo'] ?? null) === 'same_family', json_encode($sameFamily));

$tenantEnabledDecision = tenantComputeMigrationScopeCleanup(['ztest-rev-dep'], 'cms', $allModulesForRepair, ['ztest-rev-dep']);
t('tenant-enabled add-on is never a cleanup candidate', ($tenantEnabledDecision['retained_modules']['ztest-rev-dep'] ?? null) === 'tenant_enabled', json_encode($tenantEnabledDecision));

$ghostDecision = tenantComputeMigrationScopeCleanup(['ghost-module'], 'cms', $allModulesForRepair, []);
t('unknown module rows are reported, not destroyed', ($ghostDecision['retained_modules']['ghost-module'] ?? null) === 'manifest_unavailable', json_encode($ghostDecision));

$referencedDecision = tenantComputeMigrationScopeCleanup(['attendance-wage'], 'cms', $allModulesForRepair, []);
t('referenced module tables block cleanup', ($referencedDecision['retained_modules']['attendance-wage'] ?? null) === 'referenced', json_encode($referencedDecision));

// ── Repair: non-destructive by default (injected sqlite DB) ─────────────
function ztestSqliteRepairDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE _migrations (module VARCHAR(80), migration VARCHAR(255), batch INT, executed_at DATETIME)');
    $db->exec('CREATE TABLE ztest_rev_dep_table (id INTEGER PRIMARY KEY)');
    return $db;
}

function ztestSqliteTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :name");
    $stmt->execute([':name' => $table]);
    return (bool)$stmt->fetchColumn();
}

$repairDb = ztestSqliteRepairDb();
$repairDb->exec("INSERT INTO _migrations (module, migration, batch, executed_at) VALUES ('ztest-rev-dep', '001_initial.sql', 1, '2026-01-01 00:00:00')");

$dryRun = tenantRepairMigrationScopeDrift(999, 'cms', false, false, $repairDb);
t('repair dry run reports drift without dropping', !empty($dryRun['dry_run']) && empty($dryRun['changed']) && in_array('ztest-rev-dep', $dryRun['cleanup_modules'] ?? [], true), json_encode($dryRun));
t('repair dry run leaves tables intact', ztestSqliteTableExists($repairDb, 'ztest_rev_dep_table'), 'table dropped during dry run');
$rowAfterDryRun = (int)$repairDb->query("SELECT COUNT(*) FROM _migrations WHERE module='ztest-rev-dep'")->fetchColumn();
t('repair dry run leaves migration rows intact', $rowAfterDryRun === 1, 'migration row removed during dry run');

$profileSwitch = tenantRepairMigrationScopeDrift(999, 'cms', false, false, $repairDb);
t('changing entry profile preserves previous modules (non-destructive)', !empty($profileSwitch['dry_run']) && ztestSqliteTableExists($repairDb, 'ztest_rev_dep_table'), json_encode($profileSwitch));

$noConfirm = tenantRepairMigrationScopeDrift(999, 'cms', true, false, $repairDb);
t('destructive cleanup requires explicit confirmation', empty($noConfirm['ok']) && str_contains((string)($noConfirm['error'] ?? ''), 'confirmation'), json_encode($noConfirm));
t('unconfirmed destructive cleanup leaves tables intact', ztestSqliteTableExists($repairDb, 'ztest_rev_dep_table'), 'table dropped without confirmation');

$repairDbEnabled = ztestSqliteRepairDb();
$repairDbEnabled->exec('CREATE TABLE tenant_module_settings (module_id VARCHAR(100))');
$repairDbEnabled->exec("INSERT INTO tenant_module_settings (module_id) VALUES ('ztest-rev-dep')");
$repairDbEnabled->exec("INSERT INTO _migrations (module, migration, batch, executed_at) VALUES ('ztest-rev-dep', '001_initial.sql', 1, '2026-01-01 00:00:00')");
$enabledRepair = tenantRepairMigrationScopeDrift(999, 'cms', true, true, $repairDbEnabled);
t('tenant-enabled add-on survives even destructive cleanup', !in_array('ztest-rev-dep', $enabledRepair['cleanup_modules'] ?? [], true), json_encode($enabledRepair));

$repairDbGhost = ztestSqliteRepairDb();
$repairDbGhost->exec("INSERT INTO _migrations (module, migration, batch, executed_at) VALUES ('ghost-module', '001_initial.sql', 1, '2026-01-01 00:00:00')");
$ghostRepair = tenantRepairMigrationScopeDrift(999, 'cms', false, false, $repairDbGhost);
$ghostRowCount = (int)$repairDbGhost->query("SELECT COUNT(*) FROM _migrations WHERE module='ghost-module'")->fetchColumn();
t('unknown module rows are reported but not destroyed', !in_array('ghost-module', $ghostRepair['cleanup_modules'] ?? [], true) && $ghostRowCount === 1, json_encode($ghostRepair));

$repairDbConfirm = ztestSqliteRepairDb();
$repairDbConfirm->exec("INSERT INTO _migrations (module, migration, batch, executed_at) VALUES ('ztest-rev-dep', '001_initial.sql', 1, '2026-01-01 00:00:00')");
$confirmedRepair = tenantRepairMigrationScopeDrift(999, 'cms', true, true, $repairDbConfirm);
$tableGone = !ztestSqliteTableExists($repairDbConfirm, 'ztest_rev_dep_table');
$rowGone = (int)$repairDbConfirm->query("SELECT COUNT(*) FROM _migrations WHERE module='ztest-rev-dep'")->fetchColumn() === 0;
$hasBackup = is_array($confirmedRepair['backup'] ?? null) && count($confirmedRepair['backup']) === 1;
t('confirmed destructive cleanup drops tables and rows', !empty($confirmedRepair['changed']) && $tableGone && $rowGone, json_encode($confirmedRepair));
t('confirmed destructive cleanup captures a backup checkpoint', $hasBackup, json_encode($confirmedRepair['backup'] ?? null));

$failDb = new class('sqlite::memory:') extends PDO {
    public function exec(string $statement): int|false
    {
        if (stripos($statement, 'DROP TABLE') !== false && stripos($statement, 'ztest_fail_table') !== false) {
            throw new PDOException('simulated drop failure');
        }
        return parent::exec($statement);
    }
};
$failDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$failDb->exec('CREATE TABLE _migrations (module VARCHAR(80), migration VARCHAR(255), batch INT, executed_at DATETIME)');
$failDb->exec('CREATE TABLE ztest_rev_dep_table (id INTEGER PRIMARY KEY)');
$failDb->exec('CREATE TABLE ztest_fail_table (id INTEGER PRIMARY KEY)');
$failDb->exec("INSERT INTO _migrations (module, migration, batch, executed_at) VALUES ('ztest-rev-dep', '001_initial.sql', 1, '2026-01-01 00:00:00')");
$failDb->exec("INSERT INTO _migrations (module, migration, batch, executed_at) VALUES ('ztest-fail-dep', '001_initial.sql', 1, '2026-01-01 00:00:00')");
$partialRepair = tenantRepairMigrationScopeDrift(999, 'cms', true, true, $failDb);
$rowsAfterPartial = (int)$failDb->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
t('partial cleanup failure does not delete migration rows prematurely', empty($partialRepair['ok']) && $rowsAfterPartial === 2, json_encode($partialRepair));

// ── Typed confirmation phrase (P2 follow-up A) ─────────────────────────
$repairDbPhrase = ztestSqliteRepairDb();
$repairDbPhrase->exec("INSERT INTO _migrations (module, migration, batch, executed_at) VALUES ('ztest-rev-dep', '001_initial.sql', 1, '2026-01-01 00:00:00')");

$phraseDryRun = tenantRepairMigrationScopeDrift(999, 'cms', false, false, $repairDbPhrase);
t('dry run advertises the typed confirmation phrase', ($phraseDryRun['expected_confirmation'] ?? null) === 'REPAIR TENANT 999', json_encode($phraseDryRun));

$wrongPhrase = tenantRepairMigrationScopeDrift(999, 'cms', true, 'REPAIR TENANT 1', $repairDbPhrase);
t('wrong typed phrase is rejected and leaves data intact', empty($wrongPhrase['ok']) && str_contains((string)($wrongPhrase['error'] ?? ''), 'phrase') && ztestSqliteTableExists($repairDbPhrase, 'ztest_rev_dep_table'), json_encode($wrongPhrase));

$rightPhrase = tenantRepairMigrationScopeDrift(999, 'cms', true, 'REPAIR TENANT 999', $repairDbPhrase);
t('correct typed phrase executes destructive cleanup', !empty($rightPhrase['changed']) && !ztestSqliteTableExists($repairDbPhrase, 'ztest_rev_dep_table'), json_encode($rightPhrase));

// ── Manifest-declared entry delegate / routing ─────────────────────────
t('cms akira profile delegates auth to cms via manifest', tenantEntryModuleDelegateId('cms-akira-profile-standard') === 'cms', tenantEntryModuleDelegateId('cms-akira-profile-standard'));
t('legacy ehr-core delegate alias preserved', tenantEntryModuleDelegateId('ehr-core') === 'ehr', tenantEntryModuleDelegateId('ehr-core'));
t('non-delegated module resolves to itself', tenantEntryModuleDelegateId('attendance-wage') === 'attendance-wage', tenantEntryModuleDelegateId('attendance-wage'));

$entryRouter = new \Ikabud\Kernel\Http\TenantEntryRouter();
$landingReflector = new ReflectionMethod($entryRouter, 'entryLandingPath');
$landingReflector->setAccessible(true);
$cmsAkiraLanding = $landingReflector->invoke($entryRouter, 'cms-akira-profile-standard');
t('cms akira profile lands on cms login page', $cmsAkiraLanding === '/cms/login', (string)$cmsAkiraLanding);
$nonCmsLanding = $landingReflector->invoke($entryRouter, 'attendance-wage');
t('non-cms profile routing stays in its own module prefix', is_string($nonCmsLanding) && str_starts_with($nonCmsLanding, '/attendance-wage'), (string)$nonCmsLanding);

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);