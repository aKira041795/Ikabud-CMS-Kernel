<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\Database\MigrationRunner;

$pass = 0;
$fail = 0;
$errors = [];

function nt(string $label, bool $ok, string $detail = ''): void
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

echo "=== Nested Module Discovery ===\n\n";

$moduleId = 'test-nested-healthcare';
$moduleDir = BASE_PATH . '/modules/healthcare/' . $moduleId;
$migrationDir = $moduleDir . '/database/migrations';
$migrationFile = $migrationDir . '/001_nested_probe.sql';
$templateDir = BASE_PATH . '/templates/modules/healthcare/' . $moduleId . '/pages';
$templateFile = $templateDir . '/probe.disyl';
$tableName = 'nested_healthcare_probe';

if (!is_dir($migrationDir) && !mkdir($migrationDir, 0775, true) && !is_dir($migrationDir)) {
    throw new RuntimeException('Failed to create nested test module directory');
}
if (!is_dir($templateDir) && !mkdir($templateDir, 0775, true) && !is_dir($templateDir)) {
    throw new RuntimeException('Failed to create nested test template directory');
}

file_put_contents($moduleDir . '/module.json', json_encode([
    'id' => $moduleId,
    'name' => 'Nested Healthcare Test',
    'version' => '0.0.1',
    'description' => 'Nested module discovery regression test',
    'migrations' => [
        'database/migrations/001_nested_probe.sql',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

file_put_contents($migrationFile, "CREATE TABLE IF NOT EXISTS `{$tableName}` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n");
file_put_contents($templateFile, "Nested healthcare template ok\n");

register_shutdown_function(static function () use ($moduleDir, $migrationDir, $migrationFile, $templateDir, $templateFile, $tableName): void {
    unset($GLOBALS['_kernel_discovered_modules']);
    try {
        $pdo = app()->db();
        $pdo->exec('DROP TABLE IF EXISTS `' . $tableName . '`');
        $pdo->prepare('DELETE FROM _migrations WHERE module = ?')->execute(['test-nested-healthcare']);
    } catch (Throwable $ignored) {
    }

    @unlink($migrationFile);
    @rmdir($migrationDir);
    @rmdir(dirname($migrationDir));
    @unlink($templateFile);
    @rmdir($templateDir);
    @rmdir(dirname($templateDir));
    @rmdir(dirname(dirname($templateDir)));
    @unlink($moduleDir . '/module.json');
    @rmdir($moduleDir);
    @rmdir(dirname($moduleDir));
});

unset($GLOBALS['_kernel_discovered_modules']);
$modules = discoverModules();
$module = $modules[$moduleId] ?? null;
$registryModules = moduleRegistryRawModuleManifests();

nt('nested module discovered by id', is_array($module));
nt('nested module included in registry raw manifests', isset($registryModules[$moduleId]));
nt('nested module path resolves correctly', modulePathForId($moduleId) === $moduleDir, (string)modulePathForId($moduleId));
nt('nested module manifest path resolves correctly', moduleManifestPathForId($moduleId) === $moduleDir . '/module.json', (string)moduleManifestPathForId($moduleId));
nt('nested module template alias renders correctly', str_contains(app()->render('modules/test-nested-healthcare/pages/probe.disyl'), 'Nested healthcare template ok'));

$runner = new MigrationRunner(app()->db());
$executed = $runner->migrate($moduleId);
nt('migration runner executes nested module migrations', $executed === ['001_nested_probe.sql'], json_encode($executed));
nt('nested module table created', app()->db()->query("SHOW TABLES LIKE '{$tableName}'")->fetchColumn() !== false);
nt('nested module status sees no pending migrations', count($runner->status($moduleId)['pending']) === 0, json_encode($runner->status($moduleId)));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);