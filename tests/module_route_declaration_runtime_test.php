<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';

$tempRoot = sys_get_temp_dir() . '/ikabud-route-declarations-' . bin2hex(random_bytes(6));
$moduleDefinitions = [
    'route-conventional' => true,
    'route-disabled' => false,
    'route-custom' => 'config/custom-routes.php',
];

$removeFixture = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile() || $entry->isLink()) {
            @unlink($entry->getPathname());
        } else {
            @rmdir($entry->getPathname());
        }
    }
    @rmdir($path);
};

try {
    mkdir($tempRoot, 0777, true);
    $modules = [];
    foreach ($moduleDefinitions as $moduleId => $routesDeclaration) {
        $modulePath = $tempRoot . '/' . $moduleId;
        mkdir($modulePath . '/config', 0777, true);
        $modules[$moduleId] = [
            'id' => $moduleId,
            'name' => $moduleId,
            'version' => '1.0.0',
            'owns_tables' => [],
            'reads_tables' => [],
            'capabilities' => ['exposes' => [], 'depends' => []],
            'events' => [],
            'routes' => $routesDeclaration,
            '_path' => $modulePath,
            '_enabled' => true,
        ];
    }

    file_put_contents($tempRoot . '/route-conventional/routes.php', <<<'PHP'
<?php
return ['GET' => ['/test/conventional' => 'route-conventional:handler']];
PHP);
    file_put_contents($tempRoot . '/route-disabled/routes.php', <<<'PHP'
<?php
return ['GET' => ['/test/disabled' => 'route-disabled:handler']];
PHP);
    file_put_contents($tempRoot . '/route-custom/routes.php', <<<'PHP'
<?php
return ['GET' => ['/test/custom-trap' => 'route-custom:wrongHandler']];
PHP);
    file_put_contents($tempRoot . '/route-custom/config/custom-routes.php', <<<'PHP'
<?php
return ['GET' => ['/test/custom' => 'route-custom:handler']];
PHP);

    $GLOBALS['_kernel_discovered_modules'] = $modules;
    $routes = loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []]);

    $checks = [
        'routes=true loads conventional routes.php' => isset($routes['GET']['/test/conventional']),
        'routes=false suppresses an existing routes.php' => !isset($routes['GET']['/test/disabled']),
        'custom route declaration loads the declared file' => isset($routes['GET']['/test/custom']),
        'custom route declaration does not fall back to routes.php' => !isset($routes['GET']['/test/custom-trap']),
    ];
    $failed = 0;
    foreach ($checks as $label => $passed) {
        echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . "\n";
        if (!$passed) {
            $failed++;
        }
    }
} finally {
    unset($GLOBALS['_kernel_discovered_modules']);
    $removeFixture($tempRoot);
}

exit($failed === 0 ? 0 : 1);
