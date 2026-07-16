<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$fixturePath = __DIR__ . '/fixtures/navigation-route-module';
$baseManifest = [
    'id' => 'navigation-route-module',
    '_path' => $fixturePath,
    'nav' => [
        ['label' => 'Home', 'url' => '/admin/navigation-route-module'],
    ],
];

$pass = validateModuleNavigationRoutes($baseManifest);
if (!$pass['ok'] || $pass['checked'] !== 3) {
    fwrite(STDERR, 'Expected manifest, PHP sidebar, and DiSyL links to resolve: ' . $pass['detail'] . PHP_EOL);
    exit(1);
}

$brokenManifest = $baseManifest;
$brokenManifest['nav'][] = ['label' => 'Broken', 'url' => '/admin/navigation-route-module/missing'];
$failure = validateModuleNavigationRoutes($brokenManifest);
if ($failure['ok'] || $failure['missing'] !== ['/admin/navigation-route-module/missing']) {
    fwrite(STDERR, 'Expected the missing navigation route to fail certification.' . PHP_EOL);
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
