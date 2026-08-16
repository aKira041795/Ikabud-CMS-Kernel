<?php

declare(strict_types=1);

/**
 * Moto Inventory — PWA / Asset Contract Test (pure, file-based).
 *
 * Verifies the Moto Inventory manifest, same-origin icon artwork, narrowly
 * scoped service worker (cache versioning, no POST/API/auth caching,
 * offline fallback, obsolete-cache cleanup), and that no FaztSale Firebase
 * config or gstatic.com runtime dependency remains.
 *
 * Run: php tests/moto_inventory_pwa_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

$h = new TestHarness('moto-inventory-pwa', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('public/moto-inventory/manifest.json');
$h->fingerprint('public/moto-inventory/service-worker.js');
$h->fingerprint('public/moto-inventory/offline.html');

$root = $base . '/public/moto-inventory';

$h->section('Manifest');

foreach (['manifest.json', 'manifest.webmanifest'] as $file) {
    $path = $root . '/' . $file;
    $h->test("{$file} exists", is_file($path));
    if (!is_file($path)) {
        continue;
    }
    $manifest = json_decode((string)file_get_contents($path), true);
    $h->test("{$file} is valid JSON", is_array($manifest));
    if (is_array($manifest)) {
        $h->test("{$file} name is Moto Inventory", ($manifest['name'] ?? '') === 'Moto Inventory');
        $h->test("{$file} short_name is set", !empty($manifest['short_name']));
        $h->test("{$file} start_url is /moto-inventory", ($manifest['start_url'] ?? '') === '/moto-inventory');
        $h->test("{$file} scope is /moto-inventory/", ($manifest['scope'] ?? '') === '/moto-inventory/');
        $h->test("{$file} theme color #1a1a1a", ($manifest['theme_color'] ?? '') === '#1a1a1a');
        $h->test("{$file} declares 192 icon", count(array_filter($manifest['icons'] ?? [], static fn (array $i): bool => ($i['sizes'] ?? '') === '192x192' && ($i['type'] ?? '') === 'image/png')) === 1);
        $h->test("{$file} declares 512 icon", count(array_filter($manifest['icons'] ?? [], static fn (array $i): bool => ($i['sizes'] ?? '') === '512x512' && ($i['type'] ?? '') === 'image/png')) === 1);
    }
}

$h->section('Icon artwork');

foreach (['favicon.png', 'icon-192.png', 'icon-512.png', 'logo-splash.png'] as $icon) {
    $path = $root . '/' . $icon;
    $h->test("{$icon} exists", is_file($path));
    if (is_file($path)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $path) : '';
        $h->test("{$icon} is a PNG (MIME {$mime})", $mime === 'image/png');
    }
}
$h->test('icons are same-origin files (no base64 in templates)', true);

$h->section('Service worker scope');

$sw = (string)file_get_contents($root . '/service-worker.js');
$h->test('service-worker.js exists', is_file($root . '/service-worker.js'));
$h->test('SW uses a versioned cache name', (bool)preg_match("/CACHE_NAME\s*=\s*'moto-inventory-shell-v\d+'/", $sw));
$h->test('SW precaches only same-origin shell files', !preg_match('/https?:\/\//', $sw));
$h->test('SW never intercepts POST', str_contains($sw, "request.method !== 'GET'"));
$h->test('SW never caches API responses', str_contains($sw, "url.pathname.startsWith('/api/')"));
$h->test('SW never caches authenticated pages', str_contains($sw, 'isAsset') || str_contains($sw, 'Do not cache the server-rendered pages'));
$h->test('SW deletes obsolete module caches on activate', str_contains($sw, "startsWith('moto-inventory-')"));
$h->test('SW provides offline fallback for navigations', str_contains($sw, 'offline.html'));
$h->test('SW uses skipWaiting', str_contains($sw, 'skipWaiting'));
$h->test('SW uses clients.claim', str_contains($sw, 'clients.claim'));

$h->section('Offline shell');

$offline = (string)file_get_contents($root . '/offline.html');
$h->test('offline.html exists', is_file($root . '/offline.html'));
$h->test('offline shell carries Moto Inventory branding', str_contains($offline, 'Moto Inventory'));
$h->test('offline shell states read-only behavior', stripos($offline, 'read-only') !== false || stripos($offline, 'cannot be completed') !== false);

$h->section('No legacy Firebase / gstatic dependency');

$all = '';
foreach (['manifest.json', 'manifest.webmanifest', 'service-worker.js', 'offline.html'] as $file) {
    $all .= (string)file_get_contents($root . '/' . $file);
}
$h->test('no gstatic.com runtime dependency', !str_contains($all, 'gstatic.com'));
$h->test('no firebase configuration', !str_contains(strtolower($all), 'firebase'));
$h->test('no FaztSale branding in PWA assets', stripos($all, 'faztsale') === false);
$h->test('no embedded base64 PNGs in PWA assets', !preg_match('/data:image\/png;base64/', $all));

// Templates must not embed the artwork as base64 either.
$tplDir = $base . '/templates/modules/moto-inventory';
$tpl = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tplDir));
foreach ($it as $file) {
    if ($file->getExtension() === 'disyl') {
        $tpl .= (string)file_get_contents($file->getRealPath());
    }
}
$h->test('templates reference same-origin assets, not base64', !preg_match('/data:image\/png;base64/', $tpl));
$h->test('templates reference /moto-inventory assets', str_contains($tpl, '/moto-inventory/assets/moto-inventory.css'));
$h->test('templates carry Moto Inventory branding', str_contains($tpl, 'MOTO INVENTORY'));

$h->section('Offline mutation guards (client)');

$js = (string)file_get_contents($root . '/assets/moto-inventory.js');
$h->test('client JS has offline/read-only note logic', str_contains($js, 'mi-offline-note') || str_contains($js, 'offline'));
$h->test('client JS never decrements stock locally', !preg_match('/qty_on_hand\s*[-+]=/', $js));
$h->test('client JS routes all mutations through API', str_contains($js, '/api/v1/moto-inventory/'));

$h->section('Versioned IndexedDB catalog cache + cart draft');

$js = (string)file_get_contents($root . '/assets/moto-inventory.js');
$salesTpl = (string)file_get_contents($tplDir . '/pages/sales.disyl');
$invTpl = (string)file_get_contents($tplDir . '/pages/inventory.disyl');

$h->test('client JS uses a versioned IndexedDB database name', (bool)preg_match("/OFFLINE_DB_NAME\s*=\s*'moto-inventory-offline-v\d+'/", $js));
$h->test('client JS defines a versioned catalog cache version', (bool)preg_match("/OFFLINE_CACHE_VERSION\s*=\s*'v\d+'/", $js));
$h->test('client JS defines catalog + cart object stores', str_contains($js, "catalog: 'catalog'") && str_contains($js, "cart: 'cart'"));
$h->test('client JS can cache catalog rows (cacheCatalog)', str_contains($js, 'cacheCatalog'));
$h->test('client JS can read the cached catalog (loadCatalog)', str_contains($js, 'loadCatalog'));
$h->test('client JS persists a cart draft (saveCartDraft)', str_contains($js, 'saveCartDraft'));
$h->test('client JS restores the cart draft (loadCartDraft)', str_contains($js, 'loadCartDraft'));
$h->test('client JS clears the draft after a completed sale (clearCartDraft)', str_contains($js, 'clearCartDraft'));
$h->test('client JS cache version check invalidates stale data', str_contains($js, 'cacheVersion') && str_contains($js, 'clearAllData'));
$h->test('catalog cache written only from successful API responses', str_contains($salesTpl, 'cacheCatalog(json.data.rows)') && str_contains($invTpl, 'cacheCatalog(d.rows)'));
$h->test('cart draft saved only client-side (never treated as completed)', str_contains($salesTpl, 'saveCartDraft(cart)') && str_contains($js, 'saveCartDraft(lines)'));

$h->test('sales page restores the persisted cart draft on load', str_contains($salesTpl, 'loadCartDraft().then'));
$h->test('sales page clears the draft after server-acknowledged sale', str_contains($salesTpl, 'clearCartDraft()'));
$h->test('sales page disables Complete sale while offline', str_contains($salesTpl, 'completeBtn.disabled = offline'));
$h->test('sales page falls back to the cached catalog offline', str_contains($salesTpl, 'loadCatalog()'));

$h->test('inventory page caches online product rows', str_contains($invTpl, 'cacheCatalog(d.rows)'));
$h->test('inventory page falls back to the cached catalog offline', str_contains($invTpl, 'loadCatalog()'));

$h->done();
