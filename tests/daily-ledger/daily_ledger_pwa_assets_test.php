<?php

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pwa-assets');
$h->fingerprint('public/daily-ledger/manifest.webmanifest');
$h->fingerprint('public/daily-ledger/sw.js');
$h->fingerprint('public/daily-ledger/assets/tailwindcss.js');
$h->fingerprint('public/daily-ledger/assets/htmx-1.9.10.min.js');
$h->fingerprint('public/daily-ledger/assets/alpine-3.min.js');
$h->fingerprint('public/daily-ledger/assets/fontawesome/all.min.css');
$h->fingerprint('templates/modules/daily-ledger/layouts/app.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/production-output.disyl');
$h->fingerprint('modules/daily-ledger/handlers.php');

$base = $h->basePath();
$manifestPath = $base . '/public/daily-ledger/manifest.webmanifest';
$workerPath = $base . '/public/daily-ledger/sw.js';
$layoutPath = $base . '/templates/modules/daily-ledger/layouts/app.disyl';
$ledgerPath = $base . '/templates/modules/daily-ledger/cashier/ledger.disyl';
$productionPath = $base . '/templates/modules/daily-ledger/admin/production-output.disyl';
$handlersPath = $base . '/modules/daily-ledger/handlers.php';

/** @return array<int|string,mixed> */
function pwaHead(string $url): array
{
    $context = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true]]);
    $headers = @get_headers($url, true, $context);
    return is_array($headers) ? $headers : [];
}

$h->section('Static assets');
$h->test('manifest exists and is readable', is_file($manifestPath) && is_readable($manifestPath));
$h->test('service worker exists and is readable', is_file($workerPath) && is_readable($workerPath));
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$h->test('manifest is valid JSON', is_array($manifest));
$h->test('manifest has ledger scope and start URL', ($manifest['scope'] ?? '') === '/daily-ledger/' && ($manifest['start_url'] ?? '') === '/daily-ledger/ledger');
$h->test('manifest is standalone', ($manifest['display'] ?? '') === 'standalone');
$h->test('manifest declares PNG icons', count($manifest['icons'] ?? []) === 2 && array_column($manifest['icons'], 'type') === ['image/png', 'image/png']);
$icon192 = @getimagesize($base . '/public/daily-ledger/icons/icon-192.png');
$h->test('192 icon has correct dimensions and MIME', is_array($icon192) && $icon192[0] === 192 && $icon192[1] === 192 && ($icon192['mime'] ?? '') === 'image/png');
$icon512 = @getimagesize($base . '/public/daily-ledger/icons/icon-512.png');
$h->test('512 icon has correct dimensions and MIME', is_array($icon512) && $icon512[0] === 512 && $icon512[1] === 512 && ($icon512['mime'] ?? '') === 'image/png');

$httpBase = rtrim((string)(getenv('TEST_BASE_URL') ?: 'http://baronledger.test'), '/');
$manifestHeaders = pwaHead($httpBase . '/daily-ledger/manifest.webmanifest');
$workerHeaders = pwaHead($httpBase . '/daily-ledger/sw.js');
$manifestType = is_array($manifestHeaders['Content-Type'] ?? null) ? end($manifestHeaders['Content-Type']) : ($manifestHeaders['Content-Type'] ?? '');
$workerType = is_array($workerHeaders['Content-Type'] ?? null) ? end($workerHeaders['Content-Type']) : ($workerHeaders['Content-Type'] ?? '');
$h->test('manifest is served with HTTP 200 and manifest MIME', str_contains((string)($manifestHeaders[0] ?? ''), '200') && str_contains((string)$manifestType, 'application/manifest+json'));
$h->test('worker is served with HTTP 200 and JavaScript MIME', str_contains((string)($workerHeaders[0] ?? ''), '200') && (str_contains((string)$workerType, 'javascript')));
$localRuntimeAssets = [
    '/daily-ledger/assets/tailwindcss.js',
    '/daily-ledger/assets/htmx-1.9.10.min.js',
    '/daily-ledger/assets/alpine-3.min.js',
    '/daily-ledger/assets/fontawesome/all.min.css',
    '/daily-ledger/assets/webfonts/fa-brands-400.woff2',
    '/daily-ledger/assets/webfonts/fa-regular-400.woff2',
    '/daily-ledger/assets/webfonts/fa-solid-900.woff2',
    '/daily-ledger/assets/webfonts/fa-v4compatibility.woff2',
];
$runtimeAssetsServed = true;
foreach ($localRuntimeAssets as $assetUrl) {
    $headers = pwaHead($httpBase . $assetUrl);
    if (!str_contains((string)($headers[0] ?? ''), '200')) {
        $runtimeAssetsServed = false;
        break;
    }
}
$h->test('all local offline runtime assets are served with HTTP 200', $runtimeAssetsServed);

$h->section('Service worker boundaries');
$worker = (string) file_get_contents($workerPath);
$h->test('worker uses versioned cache lifecycle', str_contains($worker, 'CACHE_VERSION') && str_contains($worker, 'caches.delete') && str_contains($worker, 'clients.claim'));
$h->test('worker caches only ledger navigation', str_contains($worker, "url.pathname === LEDGER_PATH") && str_contains($worker, "request.mode === 'navigate'"));
$h->test('worker serves local static assets from cache for offline rendering', str_contains($worker, 'isLocalStaticAsset(url)') && str_contains($worker, 'event.respondWith(cacheFirst(request))') && str_contains($worker, "'/daily-ledger/assets/"));
$h->test('worker does not intercept non-GET or APIs', str_contains($worker, "request.method !== 'GET'") && str_contains($worker, "'/daily-ledger/api/'"));
$h->test('worker excludes login and purges cached ledger on logout', str_contains($worker, "login|logout") && str_contains($worker, "url.pathname === '/daily-ledger/logout'") && str_contains($worker, 'cache.delete(LEDGER_PATH)'));
$h->test('worker deterministically precaches local app dependencies', str_contains($worker, '/daily-ledger/assets/tailwindcss.js') && str_contains($worker, '/daily-ledger/assets/htmx-1.9.10.min.js') && str_contains($worker, '/daily-ledger/assets/alpine-3.min.js') && !str_contains($worker, 'OPTIONAL_CDN_URLS'));
$h->test('worker rejects redirects and sanitizes cached credentials', str_contains($worker, '!response.redirected') && str_contains($worker, "window.DL_CSRF = '';") && str_contains($worker, "window.DL_TOKEN = '';"));

$h->section('Template wiring');
$layout = (string) file_get_contents($layoutPath);
$ledger = (string) file_get_contents($ledgerPath);
$production = (string) file_get_contents($productionPath);
$handlers = (string) file_get_contents($handlersPath);
$withdrawalModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$receiveModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
$dispatchModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
$editDeliveryModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/edit_delivery_modal.disyl');
$h->test('layout links manifest', str_contains($layout, '<link rel="manifest" href="/daily-ledger/manifest.webmanifest">'));
$h->test('layout uses only local PWA runtime dependencies', str_contains($layout, '/daily-ledger/assets/tailwindcss.js') && str_contains($layout, '/daily-ledger/assets/fontawesome/all.min.css') && str_contains($layout, '/daily-ledger/assets/htmx-1.9.10.min.js') && str_contains($layout, '/daily-ledger/assets/alpine-3.min.js') && !str_contains($layout, 'cdn.tailwindcss.com') && !str_contains($layout, 'unpkg.com'));
$h->test('layout registers scoped worker', str_contains($layout, "serviceWorker.register('/daily-ledger/sw.js')"));
$h->test('layout suppresses redundant offline row refresh', str_contains($layout, "htmx:beforeRequest") && str_contains($layout, "'/daily-ledger/ledger/rows'"));
$h->test('ledger has connectivity banner and probe', str_contains($ledger, 'id="connectivity-banner"') && str_contains($ledger, "'/api/v1/cashier/ledger/day-status'"));
$h->test('ledger retries on online event and interval', str_contains($ledger, "addEventListener('online', probeCloud)") && str_contains($ledger, 'setInterval'));
$h->test('ledger blocks required-online actions', str_contains($ledger, 'data-online-action="Day close"') && str_contains($ledger, 'data-online-action="Day reopen"') && str_contains($ledger, 'data-online-action="POS"'));
$h->test('storage failure has red stop message', str_contains($ledger, 'Device storage unavailable — stop entering data'));
$h->test('ledger cache includes server-rendered editable rows', str_contains($ledger, 'partials/ledger-rows.disyl') && str_contains($handlers, "'rows' => \$ledgerRows"));
$h->test('queue completion cannot remove a newer entry', str_contains($ledger, 'removePendingIfUnchanged(payload)') && str_contains($ledger, 'saveFieldVersions[saveKey] !== saveVersion'));
$h->test('production output has scoped offline queue and idempotency', str_contains($production, 'daily-ledger:pending-production-output') && str_contains($production, 'idempotency_key') && str_contains($production, "addEventListener('online', retryPendingOutputBatches)") && str_contains($handlers, "'tenant_scope' => \$tenantScope") && str_contains($handlers, "'dl_user_id' => \$actorId"));
$h->test('cashier modals open offline (no online-action block on openers)', !str_contains($ledger, 'data-online-action="Receiving"') && !str_contains($ledger, 'data-online-action="Stock adjustment"') && !str_contains($ledger, 'data-online-action="Sending stock"') && !str_contains($ledger, 'data-online-action="Delivery correction"') && str_contains($ledger, 'data-online-action="POS"') && str_contains($ledger, 'data-online-action="Day close"'));
$h->test('ledger has offline operation queue with idempotency keys', str_contains($ledger, 'daily-ledger:pending-ops') && str_contains($ledger, 'window.enqueueOperation') && str_contains($ledger, 'replayPendingOperations') && str_contains($ledger, 'window.generateOperationId'));
$h->test('withdrawal modal queues offline and sends idempotency key', str_contains($withdrawalModal, "enqueueOperation('withdrawal'") && str_contains($withdrawalModal, 'payload.idempotency_key'));
$h->test('paper-DR receive queues offline and sends idempotency key', str_contains($receiveModal, "enqueueOperation('receive_paper_dr'") && str_contains($receiveModal, 'payload.idempotency_key'));
$h->test('dispatch and delivery-edit block offline with a clear message', str_contains($dispatchModal, 'Sending stock requires cloud connectivity') && str_contains($editDeliveryModal, 'Delivery correction requires cloud connectivity'));
$h->test('queued operation endpoints enforce idempotency', str_contains($handlers, "dl_loadIdempotentResponse('cashier_withdrawal'") && str_contains($handlers, "dl_loadIdempotentResponse('receive_paper_dr'") && str_contains($handlers, "dl_storeIdempotentResponse('cashier_withdrawal'") && str_contains($handlers, "dl_storeIdempotentResponse('receive_paper_dr'"));

$h->done();
