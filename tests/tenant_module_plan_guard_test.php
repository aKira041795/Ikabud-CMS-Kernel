<?php
/**
 * A module's migrations may only be written into a tenant that would provision it anyway.
 *
 * `php ikabud migrate <module>` used to sync that module into every tenant database with
 * its own connection, consulting neither entitlements nor the tenant's plan. That is how
 * the CMS tenant's database came to hold all 28 of dc-cafe's tables, dc-cafe's four
 * default seeded accounts, and a copy of its product catalogue — for a module it was
 * never granted. A bare `migrate` was already safe because it goes through
 * tenantProvisionModulePlan(); the explicit form did not.
 *
 * Run: php tests/tenant_module_plan_guard_test.php
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once 'bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';
require_once __DIR__ . '/../src/helpers/module-catalog.php';

$pass = 0;
$fail = 0;
$errors = [];

function ok(bool $cond, string $label): void
{
    global $pass, $fail, $errors;
    if ($cond) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label;
        echo "  ✗ {$label}\n";
    }
}

function section(string $title): void
{
    echo "\n── {$title} ──\n";
}

$kernel = app()->db();

// ─── 1. The rule itself ────────────────────────────────────────────
section('The Rule');

ok(
    tenantModuleIsInProvisionPlan('cms', 'dc-cafe') === false,
    'a CMS tenant is not offered dc-cafe'
);
ok(
    tenantModuleIsInProvisionPlan('dc-cafe', 'dc-cafe') === true,
    "and dc-cafe's own tenant still is"
);
ok(
    tenantModuleIsInProvisionPlan('cms', 'cms') === true,
    'a tenant is always offered its own entry module'
);
ok(
    tenantModuleIsInProvisionPlan('wms', 'dc-cafe') === false,
    'the rule holds for a tenant unrelated to both'
);
ok(
    tenantModuleIsInProvisionPlan('dc-cafe', '_kernel') === true,
    'kernel migrations are never withheld'
);
ok(
    tenantModuleIsInProvisionPlan('', 'dc-cafe') === true,
    'a tenant with no entry module fails open, rather than being starved'
);
ok(
    tenantModuleIsInProvisionPlan('cms', 'ecommerce') === true,
    'a module that is genuinely in the plan is still allowed'
);

// ─── 2. It is actually wired into the migrate path ─────────────────
section('Wired Into The Path');

$source = (string) file_get_contents(__DIR__ . '/../src/helpers/module-migrations.php');
ok(
    str_contains($source, 'tenantModuleIsInProvisionPlan($entryModuleId, $requestedModuleId)'),
    'syncTenantCliMigrationsForTenant consults the rule'
);
// The command already carried a branch for this result; nothing produced it until now.
// Asserting the value rather than a new one keeps the two ends on one contract.
ok(
    str_contains($source, "'skipped' => 'module_not_in_plan'"),
    "and reports 'module_not_in_plan', the value the migrate command reads"
);

$cli = (string) file_get_contents(__DIR__ . '/../ikabud');
ok(
    str_contains($cli, "=== 'module_not_in_plan'"),
    'the migrate command has a branch for that result'
);
ok(
    str_contains($cli, 'Skipped'),
    'and names the withheld module instead of reporting "Nothing to migrate"'
);

// ─── 3. The live tenant databases agree with the plans ─────────────
section('Live Databases Match The Plans');

$targets = $kernel->query(
    'SELECT t.id, t.tenant_key, t.entry_module_id, c.db_name
     FROM kernel_tenants t
     INNER JOIN kernel_tenant_db_connections c ON c.tenant_id = t.id
     ORDER BY t.id'
)->fetchAll(PDO::FETCH_ASSOC);

$strayIn = [];
$owningTenants = [];
foreach ($targets as $row) {
    $id = (int) $row['id'];
    $entry = trim((string) $row['entry_module_id']);
    $dbName = (string) $row['db_name'];

    $inPlan = false;
    if ($entry !== '') {
        try {
            $inPlan = in_array('dc-cafe', tenantProvisionModulePlan($entry), true);
        } catch (Throwable $e) {
            $inPlan = false;
        }
    }
    if ($inPlan) {
        $owningTenants[] = $id;
    }

    $st = $kernel->prepare(
        'SELECT COUNT(*) c FROM information_schema.tables
         WHERE table_schema = ? AND table_name LIKE ?'
    );
    $st->execute([$dbName, 'dc\_%']);
    $count = (int) $st->fetch()['c'];

    // The assertion that would have caught the original defect: a database holding
    // a module's tables while its tenant's plan excludes that module.
    if ($count > 0 && !$inPlan) {
        $strayIn[] = $dbName . ' (' . $count . ' tables, tenant #' . $id . ')';
    }
}

ok(
    $owningTenants !== [],
    "at least one tenant plans dc-cafe (so the assertion below is not vacuous): "
        . implode(', ', array_map(static fn(int $i): string => '#' . $i, $owningTenants))
);
ok(
    $strayIn === [],
    'no tenant whose plan excludes dc-cafe holds any of its tables'
        . ($strayIn !== [] ? ' — found in: ' . implode('; ', $strayIn) : '')
);

// ─── Result ────────────────────────────────────────────────────────
echo "\n" . str_repeat('═', 38) . "\n";
echo "  RESULTS\n";
echo "  {$pass}/" . ($pass + $fail) . " passed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $e) {
        echo "    - {$e}\n";
    }
}
echo str_repeat('═', 38) . "\n";

exit($fail === 0 ? 0 : 1);
