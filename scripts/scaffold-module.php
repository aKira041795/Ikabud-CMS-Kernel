<?php

declare(strict_types=1);

/**
 * Module scaffolder.
 *
 * Generates a minimal, idiomatic Ikabud module skeleton with:
 *   - module.json (semver, owns_tables, capabilities slots)
 *   - routes.php (declarative)
 *   - handlers.php (handler stubs)
 *   - tests/<id>_smoke_test.php (one passing baseline test)
 *
 * Usage:
 *   php scripts/scaffold-module.php <module-id> [--name="Display Name"] [--force]
 *
 * Conventions enforced:
 *   - module-id must be lowercase kebab-case
 *   - folder name == manifest id
 *   - version starts at 0.1.0 (graduate to 1.0.0 once stable)
 *   - one owned table by default: <module_id_underscored>_items
 */

$basePath = dirname(__DIR__);
$argv = $_SERVER['argv'] ?? [];
array_shift($argv);

$moduleId = '';
$displayName = '';
$force = false;
/** @var array<string,bool> */
$with = [
    'capability' => false,
    'event' => false,
    'migration' => false,
];

foreach ($argv as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif (str_starts_with($arg, '--name=')) {
        $displayName = trim(substr($arg, 7), " \t\"'");
    } elseif (str_starts_with($arg, '--with=')) {
        $list = explode(',', substr($arg, 7));
        foreach ($list as $token) {
            $key = strtolower(trim($token));
            if ($key === '') {
                continue;
            }
            if (!array_key_exists($key, $with)) {
                fwrite(STDERR, "ERROR: unknown --with token '{$key}' (allowed: capability, event, migration)\n");
                exit(2);
            }
            $with[$key] = true;
        }
    } elseif ($moduleId === '' && !str_starts_with($arg, '--')) {
        $moduleId = trim($arg);
    }
}

if ($moduleId === '') {
    fwrite(STDERR, "Usage: php scripts/scaffold-module.php <module-id> [--name=\"Display Name\"] [--with=capability,event,migration] [--force]\n");
    exit(2);
}

if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $moduleId)) {
    fwrite(STDERR, "ERROR: module-id must be lowercase kebab-case (got '{$moduleId}')\n");
    exit(2);
}

if ($displayName === '') {
    $displayName = ucwords(str_replace('-', ' ', $moduleId));
}

$modulePath = $basePath . '/modules/' . $moduleId;
if (is_dir($modulePath) && !$force) {
    fwrite(STDERR, "ERROR: module directory already exists: {$modulePath}\n");
    fwrite(STDERR, "Use --force to overwrite (will not delete existing files outside of those scaffolded).\n");
    exit(1);
}

if (!is_dir($modulePath) && !mkdir($modulePath, 0o755, true)) {
    fwrite(STDERR, "ERROR: failed to create module directory: {$modulePath}\n");
    exit(1);
}

$tableName = str_replace('-', '_', $moduleId) . '_items';
$handlerPrefix = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleId))));

$capabilityIdRoot = str_replace('-', '_', $moduleId);
$capabilityId = $capabilityIdRoot . '.example.read@1';
$eventId = $capabilityIdRoot . '.example.fired';

$manifest = [
    'id' => $moduleId,
    'name' => $displayName,
    'version' => '0.1.0',
    'description' => $displayName . ' module.',
    'author' => 'Ikabud Kernel Team',
    'owns_tables' => [$tableName],
    'routes' => 'routes.php',
    'handlers' => 'handlers.php',
    'capabilities' => [
        'exposes' => $with['capability'] ? [
            [
                'id' => $capabilityId,
                'description' => 'Example read capability for ' . $displayName,
                'handler' => $handlerPrefix . 'CapabilityRead',
            ],
        ] : [],
        'depends' => [],
    ],
    'events' => $with['event'] ? [
        [
            'id' => $eventId,
            'description' => 'Fires when an example action occurs in ' . $displayName,
        ],
    ] : [],
    'hooks' => [],
];

if ($with['migration']) {
    $manifest['migrations'] = ['database/migrations/001_' . str_replace('-', '_', $moduleId) . '_init.sql'];
}

$files = [
    'module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",

    'routes.php' => <<<PHP
<?php

declare(strict_types=1);

/**
 * Routes for the {$displayName} module.
 *
 * Handler references use the "module-id:functionName" convention.
 */

return [
    'GET /{$moduleId}' => '{$moduleId}:{$handlerPrefix}Index',
];
PHP
        . "\n",

    'handlers.php' => <<<PHP
<?php

declare(strict_types=1);

/**
 * Handlers for the {$displayName} module.
 *
 * Each handler receives a ModuleContext and returns a response (string, array, or void).
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function {$handlerPrefix}Index(ModuleContext \$ctx): string
{
    return 'Hello from {$moduleId}';
}
PHP
        . ($with['capability'] ? "\n\nfunction {$handlerPrefix}CapabilityRead(ModuleContext \$ctx, array \$payload = []): array\n{\n    // Implement capability '{$capabilityId}' here.\n    return ['ok' => true, 'module' => '{$moduleId}', 'payload' => \$payload];\n}\n" : "\n"),
];

if ($with['migration']) {
    $migrationDir = $modulePath . '/database/migrations';
    if (!is_dir($migrationDir)) {
        @mkdir($migrationDir, 0o755, true);
    }
    $migName = '001_' . str_replace('-', '_', $moduleId) . '_init.sql';
    $files['database/migrations/' . $migName] = <<<SQL
-- Initial schema for module {$moduleId}.
CREATE TABLE IF NOT EXISTS `{$tableName}` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        . "\n";
}

$testsDir = $basePath . '/tests';
if (!is_dir($testsDir)) {
    @mkdir($testsDir, 0o755, true);
}
$smokeTestPath = $testsDir . '/' . str_replace('-', '_', $moduleId) . '_smoke_test.php';
$smokeTestRel = 'tests/' . basename($smokeTestPath);
$files[$smokeTestRel] = <<<PHP
<?php

declare(strict_types=1);

/**
 * Smoke test for the {$displayName} module.
 *
 * Verifies:
 *   - manifest is valid JSON and parses
 *   - module.json id matches folder name
 *
 * Run from repo root: php tests/{$smokeTestRel}
 */

\$basePath = dirname(__DIR__);
require_once \$basePath . '/bootstrap.php';
require_once \$basePath . '/src/helpers/module-manager.php';

\$manifestPath = \$basePath . '/modules/{$moduleId}/module.json';
\$check = validateModuleManifest(\$manifestPath);

if (empty(\$check['ok'])) {
    fwrite(STDERR, "FAIL: manifest invalid - " . (\$check['error'] ?? 'unknown') . "\n");
    exit(1);
}

\$manifest = \$check['manifest'] ?? [];
if ((\$manifest['id'] ?? '') !== '{$moduleId}') {
    fwrite(STDERR, "FAIL: manifest id mismatch\n");
    exit(1);
}

echo "PASS: {$moduleId} smoke test\n";
exit(0);
PHP
    . "\n";

$written = [];
foreach ($files as $relPath => $content) {
    $absPath = (str_starts_with($relPath, 'tests/'))
        ? $basePath . '/' . $relPath
        : $modulePath . '/' . $relPath;
    if (file_exists($absPath) && !$force) {
        fwrite(STDERR, "SKIP (exists): {$absPath}\n");
        continue;
    }
    if (file_put_contents($absPath, $content) === false) {
        fwrite(STDERR, "ERROR: failed to write {$absPath}\n");
        exit(1);
    }
    $written[] = $absPath;
}

fwrite(STDOUT, "Scaffolded module '{$moduleId}' (" . count($written) . " files):\n");
foreach ($written as $path) {
    fwrite(STDOUT, "  + {$path}\n");
}
fwrite(STDOUT, "\nNext steps:\n");
fwrite(STDOUT, "  1. Review modules/{$moduleId}/module.json (owns_tables, capabilities)\n");
fwrite(STDOUT, "  2. Add migrations under modules/{$moduleId}/migrations/ if needed\n");
fwrite(STDOUT, "  3. Run: php scripts/guard-module-manifests.php\n");
fwrite(STDOUT, "  4. Run: php tests/" . basename($smokeTestPath) . "\n");
exit(0);
