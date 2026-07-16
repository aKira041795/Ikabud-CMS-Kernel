<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$fixturePath = __DIR__ . '/fixtures/navigation-route-module';
$invalidManifest = validateModuleManifest($fixturePath . '/invalid-manifest.json');
if (($invalidManifest['error_code'] ?? '') !== 'manifest_invalid_navigation_dependencies') {
    fwrite(STDERR, 'Expected self-referencing navigation_dependencies to fail manifest validation.' . PHP_EOL);
    exit(1);
}

$baseManifest = [
    'id' => 'navigation-route-module',
    '_path' => $fixturePath,
    'nav' => [
        ['label' => 'Home', 'url' => '/admin/navigation-route-module'],
    ],
];

$pass = validateModuleNavigationRoutes($baseManifest);
if (!$pass['ok'] || $pass['checked'] !== 5) {
    fwrite(STDERR, 'Expected manifest, PHP sidebar, and DiSyL links to resolve: ' . $pass['detail'] . PHP_EOL);
    exit(1);
}

$collectedUrls = moduleNavigationUrls($baseManifest);
if (in_array('https://example.com/help', $collectedUrls, true) || in_array('#details', $collectedUrls, true)) {
    fwrite(STDERR, 'External URLs and fragment anchors must be ignored by route certification.' . PHP_EOL);
    exit(1);
}
foreach (['/admin/navigation-route-module/download', '/admin/navigation-route-module/logout'] as $validatedUrl) {
    if (!in_array($validatedUrl, $collectedUrls, true)) {
        fwrite(STDERR, "Internal navigation action was not route-validated: {$validatedUrl}" . PHP_EOL);
        exit(1);
    }
}

$brokenManifest = $baseManifest;
$brokenManifest['nav'][] = ['label' => 'Broken', 'url' => '/admin/navigation-route-module/missing'];
$failure = validateModuleNavigationRoutes($brokenManifest);
if ($failure['ok'] || $failure['missing'] !== ['/admin/navigation-route-module/missing']) {
    fwrite(STDERR, 'Expected the missing navigation route to fail certification.' . PHP_EOL);
    exit(1);
}

$routeOwnerPath = __DIR__ . '/fixtures/navigation-route-owner';
$installedModules = [
    'navigation-route-owner' => [
        'id' => 'navigation-route-owner',
        '_path' => $routeOwnerPath,
    ],
];
$crossModuleManifest = $baseManifest;
$crossModuleManifest['nav'][] = [
    'label' => 'Shared page',
    'url' => '/admin/navigation-route-owner/shared',
];
$undeclaredCrossModule = validateModuleNavigationRoutes($crossModuleManifest, $installedModules);
if ($undeclaredCrossModule['ok'] || ($undeclaredCrossModule['undeclared_dependencies']['/admin/navigation-route-owner/shared'] ?? []) !== ['navigation-route-owner']) {
    fwrite(STDERR, 'Expected an undeclared cross-module navigation route to fail certification.' . PHP_EOL);
    exit(1);
}

$crossModuleManifest['navigation_dependencies'] = ['navigation-route-owner'];
$declaredCrossModule = validateModuleNavigationRoutes($crossModuleManifest, $installedModules);
if (!$declaredCrossModule['ok']) {
    fwrite(STDERR, 'Expected an explicitly declared cross-module navigation route to pass: ' . $declaredCrossModule['detail'] . PHP_EOL);
    exit(1);
}

if (!moduleRoutePatternMatchesPath(
    '/admin/navigation-route-module/items/{id}/edit',
    '/admin/navigation-route-module/items/{item.id}/edit'
)) {
    fwrite(STDERR, 'Expected dynamic navigation placeholders to match route placeholders.' . PHP_EOL);
    exit(1);
}

echo "module navigation route certification: PASS\n";
