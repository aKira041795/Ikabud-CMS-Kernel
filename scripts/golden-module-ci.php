<?php

declare(strict_types=1);

/**
 * Canonical module lifecycle proof in an isolated shadow repository.
 *
 * Flow: scaffold -> validate -> certify -> pack -> install -> disable ->
 * enable -> fresh boot discovery -> route response -> certify.
 */

$repositoryRoot = dirname(__DIR__);
$moduleId = 'golden-module-' . bin2hex(random_bytes(4));
$shadowRoot = sys_get_temp_dir() . '/ikabud-golden-' . bin2hex(random_bytes(6));
$zipPath = $shadowRoot . '/storage/' . $moduleId . '.zip';
$realRegistryPath = $repositoryRoot . '/storage/modules.json';
$realRegistryExisted = is_file($realRegistryPath);
$realRegistryHash = $realRegistryExisted ? hash_file('sha256', $realRegistryPath) : null;
$checks = [];

$removeTree = static function (string $path) use ($shadowRoot): void {
    if (!is_dir($path)) {
        return;
    }
    $realShadow = realpath($shadowRoot);
    $realPath = realpath($path);
    if ($realShadow === false || $realPath === false || ($realPath !== $realShadow && !str_starts_with($realPath, $realShadow . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Refusing to remove a path outside the golden-module shadow root: ' . $path);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            @unlink($entry->getPathname());
        } else {
            @rmdir($entry->getPathname());
        }
    }
    @rmdir($path);
};

$assert = static function (bool $condition, string $label, string $detail = '') use (&$checks): void {
    $checks[] = ['label' => $label, 'passed' => $condition, 'detail' => $detail];
    if (!$condition) {
        throw new RuntimeException($label . ($detail !== '' ? ': ' . $detail : ''));
    }
};

$run = static function (array $command, string $cwd, int $expectedExitCode = 0) use ($assert): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start subprocess: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $assert(
        $exitCode === $expectedExitCode,
        $expectedExitCode === 0 ? 'subprocess succeeds' : 'subprocess fails as expected',
        implode(' ', $command) . "\n" . trim((string)$stdout . "\n" . (string)$stderr)
    );
    return ['exit_code' => $exitCode, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
};

$link = static function (string $source, string $target): void {
    if (!file_exists($source)) {
        return;
    }
    if (!@symlink($source, $target)) {
        throw new RuntimeException("Unable to link {$source} to {$target}");
    }
};

try {
    foreach (['modules', 'templates/modules', 'tests', 'scripts', 'storage/logs', 'storage/cache'] as $directory) {
        if (!mkdir($shadowRoot . '/' . $directory, 0777, true) && !is_dir($shadowRoot . '/' . $directory)) {
            throw new RuntimeException('Unable to create shadow directory: ' . $directory);
        }
    }

    foreach (['bootstrap.php', 'ikabud'] as $file) {
        if (!copy($repositoryRoot . '/' . $file, $shadowRoot . '/' . $file)) {
            throw new RuntimeException('Unable to copy ' . $file);
        }
    }
    chmod($shadowRoot . '/ikabud', 0755);
    copy($repositoryRoot . '/scripts/guard-module-manifests.php', $shadowRoot . '/scripts/guard-module-manifests.php');

    foreach (['config', 'kernel', 'src', 'vendor', 'public', 'database', 'locales'] as $directory) {
        $link($repositoryRoot . '/' . $directory, $shadowRoot . '/' . $directory);
    }
    foreach (scandir($repositoryRoot . '/templates') ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'modules') {
            continue;
        }
        $link($repositoryRoot . '/templates/' . $entry, $shadowRoot . '/templates/' . $entry);
    }

    $run([PHP_BINARY, 'ikabud', 'make:module', $moduleId], $shadowRoot);
    $assert(is_file($shadowRoot . '/modules/' . $moduleId . '/module.json'), 'scaffold creates module manifest');
    $assert(is_file($shadowRoot . '/templates/modules/' . $moduleId . '/pages/home.disyl'), 'scaffold creates external module template');
    $assert(is_file($shadowRoot . '/tests/' . str_replace('-', '_', $moduleId) . '_module_test.php'), 'scaffold creates focused test');

    $run([PHP_BINARY, 'scripts/guard-module-manifests.php', '--strict'], $shadowRoot);
    $run([PHP_BINARY, 'ikabud', 'module:certify', $moduleId], $shadowRoot);
    $run([PHP_BINARY, 'ikabud', 'module:pack', $moduleId, $zipPath], $shadowRoot);
    $assert(is_file($zipPath) && filesize($zipPath) > 0, 'certified module packs into a non-empty zip');

    $removeTree($shadowRoot . '/modules/' . $moduleId);
    $removeTree($shadowRoot . '/templates/modules/' . $moduleId);
    $assert(!is_dir($shadowRoot . '/templates/modules/' . $moduleId), 'source template is removed before install');
    $installProbe = $shadowRoot . '/storage/install-probe.php';
    file_put_contents($installProbe, <<<'PHP'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
$result = installModuleFromZip($argv[1] ?? '');
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit(!empty($result['ok']) ? 0 : 1);
PHP);
    $install = $run([PHP_BINARY, $installProbe, $zipPath], $shadowRoot);
    $installResult = json_decode(trim($install['stdout']), true);
    $assert(is_array($installResult) && !empty($installResult['ok']), 'zip install uses production installer', trim($install['stdout']));
    $assert(($installResult['module_id'] ?? '') === $moduleId, 'installer returns expected module id');
    $assert(($installResult['enabled'] ?? null) === true, 'installer enables dependency-satisfied module');
    $installedTemplatePath = $shadowRoot . '/templates/modules/' . $moduleId . '/pages/home.disyl';
    $assert(is_file($installedTemplatePath), 'installer restores packaged external module template');
    $assert(
        str_contains((string)file_get_contents($installedTemplatePath), 'Module is ready. Start building!'),
        'installed external template retains scaffolded content'
    );

    $run([PHP_BINARY, 'ikabud', 'module:disable', $moduleId], $shadowRoot);
    $run([PHP_BINARY, 'ikabud', 'module:enable', $moduleId], $shadowRoot);
    $registry = json_decode((string)file_get_contents($shadowRoot . '/storage/modules.json'), true);
    $assert(!empty($registry[$moduleId]['enabled']), 'shadow registry records explicit re-enable');

    $bootProbe = $shadowRoot . '/storage/boot-route-probe.php';
    file_put_contents($bootProbe, <<<'PHP'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
$moduleId = $argv[1] ?? '';
$modules = discoverModules();
if (!isset($modules[$moduleId]) || empty($modules[$moduleId]['_enabled'])) {
    fwrite(STDERR, "module was not discovered as enabled\n");
    exit(1);
}
$routes = require $modules[$moduleId]['_path'] . '/routes.php';
$path = '/api/v1/' . $moduleId . '/health';
$handler = $routes['GET'][$path] ?? '';
[$owner, $function] = array_pad(explode(':', (string)$handler, 2), 2, '');
require_once $modules[$moduleId]['_path'] . '/handlers.php';
if ($owner !== $moduleId || !is_callable($function)) {
    fwrite(STDERR, "health route does not resolve to a callable\n");
    exit(1);
}
ob_start();
$function([]);
$body = (string)ob_get_clean();
$payload = json_decode($body, true);
if (!is_array($payload) || empty($payload['ok']) || ($payload['module'] ?? '') !== $moduleId) {
    fwrite(STDERR, "health route returned an invalid response: {$body}\n");
    exit(1);
}
echo json_encode(['ok' => true, 'route' => $path, 'handler' => $handler, 'response' => $payload], JSON_UNESCAPED_SLASHES) . "\n";
PHP);
    $boot = $run([PHP_BINARY, $bootProbe, $moduleId], $shadowRoot);
    $bootResult = json_decode(trim($boot['stdout']), true);
    $assert(is_array($bootResult) && !empty($bootResult['ok']), 'fresh-process boot discovery and route response pass', trim($boot['stdout']));

    $run([PHP_BINARY, 'ikabud', 'module:certify', $moduleId], $shadowRoot);
    $run([PHP_BINARY, 'scripts/guard-module-manifests.php', '--strict'], $shadowRoot);
    $run([PHP_BINARY, 'ikabud', 'module:uninstall', $moduleId], $shadowRoot);
    $assert(!is_dir($shadowRoot . '/modules/' . $moduleId), 'uninstall removes installed module files');
    $assert(!is_dir($shadowRoot . '/templates/modules/' . $moduleId), 'uninstall removes installed external templates');

    $incompleteModuleId = $moduleId . '-incomplete';
    $incompleteZipPath = $shadowRoot . '/storage/' . $incompleteModuleId . '.zip';
    $incompleteZip = new ZipArchive();
    if ($incompleteZip->open($incompleteZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create incomplete-module test archive');
    }
    $incompleteZip->addFromString('module.json', (string)json_encode([
        'id' => $incompleteModuleId,
        'name' => 'Incomplete Golden Module',
        'version' => '1.0.0',
        'description' => 'Negative post-extraction validation fixture',
        'author' => 'Ikabud',
        'owns_tables' => [],
        'reads_tables' => [],
        'migrations' => [],
        'capabilities' => ['exposes' => [], 'depends' => []],
        'routes' => true,
        'events' => [],
        'nav' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $incompleteZip->addFromString('__ikabud_package/templates/pages/home.disyl', 'must be rolled back');
    $incompleteZip->close();

    $failedInstall = $run([PHP_BINARY, $installProbe, $incompleteZipPath], $shadowRoot, 1);
    $failedInstallResult = json_decode(trim($failedInstall['stdout']), true);
    $assert(
        is_array($failedInstallResult) && ($failedInstallResult['error_code'] ?? '') === 'routes_file_missing',
        'post-extraction validation rejects a missing declared route file',
        trim($failedInstall['stdout'])
    );
    $assert(!is_dir($shadowRoot . '/modules/' . $incompleteModuleId), 'failed install rolls back module files');
    $assert(!is_dir($shadowRoot . '/templates/modules/' . $incompleteModuleId), 'failed install rolls back external templates');

    $appLog = (string)@file_get_contents($shadowRoot . '/storage/logs/app.log');
    $errorLog = (string)@file_get_contents($shadowRoot . '/storage/logs/error.log');
    $assert(!preg_match('/\[(?:fatal|error)\]|Fatal error|Uncaught /i', $appLog . "\n" . $errorLog), 'shadow lifecycle logs contain no fatal or error diagnostics');

    $currentRegistryExisted = is_file($realRegistryPath);
    $currentRegistryHash = $currentRegistryExisted ? hash_file('sha256', $realRegistryPath) : null;
    $assert($currentRegistryExisted === $realRegistryExisted && $currentRegistryHash === $realRegistryHash, 'real module registry is unchanged');
    $assert(!is_dir($repositoryRoot . '/modules/' . $moduleId), 'real modules directory was never touched');
    $assert(!is_dir($repositoryRoot . '/templates/modules/' . $moduleId), 'real templates directory was never touched');

    echo "Golden module lifecycle: PASS ({$moduleId})\n";
    echo json_encode(['ok' => true, 'module_id' => $moduleId, 'checks' => $checks], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Golden module lifecycle: FAIL\n{$e->getMessage()}\n");
    $exitCode = 1;
} finally {
    if (is_dir($shadowRoot)) {
        $removeTree($shadowRoot);
    }
}

exit($exitCode ?? 0);
