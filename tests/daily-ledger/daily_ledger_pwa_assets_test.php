<?php

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pwa-assets');
$h->fingerprint('public/daily-ledger/manifest.webmanifest');
$h->fingerprint('public/daily-ledger/sw.js');
$h->fingerprint('public/daily-ledger/offline.html');
$h->fingerprint('public/daily-ledger/assets/offline-app.js');
$h->fingerprint('public/daily-ledger/assets/offline-vault.js');
$h->fingerprint('public/daily-ledger/assets/tailwindcss.js');
$h->fingerprint('public/daily-ledger/assets/htmx-1.9.10.min.js');
$h->fingerprint('public/daily-ledger/assets/alpine-3.min.js');
$h->fingerprint('public/daily-ledger/assets/fontawesome/all.min.css');
$h->fingerprint('templates/modules/daily-ledger/layouts/app.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/offline_reference.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/offline_auth.disyl');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/routes.php');

$base = $h->basePath();
$manifestPath = $base . '/public/daily-ledger/manifest.webmanifest';
$workerPath = $base . '/public/daily-ledger/sw.js';
$shellPath = $base . '/public/daily-ledger/offline.html';
$offlineAppPath = $base . '/public/daily-ledger/assets/offline-app.js';
$offlineVaultPath = $base . '/public/daily-ledger/assets/offline-vault.js';
$layoutPath = $base . '/templates/modules/daily-ledger/layouts/app.disyl';
$ledgerPath = $base . '/templates/modules/daily-ledger/cashier/ledger.disyl';
$handlersPath = $base . '/modules/daily-ledger/handlers.php';
$offlineHandlersPath = $base . '/modules/daily-ledger/handlers-offline.php';
$routesPath = $base . '/modules/daily-ledger/routes.php';

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
$h->test('token-free offline shell exists', is_file($shellPath) && is_readable($shellPath));
$h->test('offline shell controller exists', is_file($offlineAppPath) && is_readable($offlineAppPath));
$h->test('offline vault module exists', is_file($offlineVaultPath) && is_readable($offlineVaultPath));
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$h->test('manifest is valid JSON', is_array($manifest));
$h->test('manifest has ledger scope and start URL', ($manifest['scope'] ?? '') === '/daily-ledger/' && ($manifest['start_url'] ?? '') === '/daily-ledger/ledger');
$h->test('manifest has stable id', ($manifest['id'] ?? '') === '/daily-ledger/ledger');
$h->test('manifest is standalone', ($manifest['display'] ?? '') === 'standalone');
$h->test('manifest declares PNG icons', count($manifest['icons'] ?? []) === 2 && array_column($manifest['icons'], 'type') === ['image/png', 'image/png']);
$icon192 = @getimagesize($base . '/public/daily-ledger/icons/icon-192.png');
$h->test('192 icon has correct dimensions and MIME', is_array($icon192) && $icon192[0] === 192 && $icon192[1] === 192 && ($icon192['mime'] ?? '') === 'image/png');
$icon512 = @getimagesize($base . '/public/daily-ledger/icons/icon-512.png');
$h->test('512 icon has correct dimensions and MIME', is_array($icon512) && $icon512[0] === 512 && $icon512[1] === 512 && ($icon512['mime'] ?? '') === 'image/png');

$httpBase = rtrim((string)(getenv('TEST_BASE_URL') ?: 'http://baronledger.test'), '/');
$manifestHeaders = pwaHead($httpBase . '/daily-ledger/manifest.webmanifest');
$workerHeaders = pwaHead($httpBase . '/daily-ledger/sw.js');
// The three HTTP-delivery checks require a live web server at $httpBase.
// A clean checkout / CI machine may not have the dev vhost running. When no
// server is reachable these are skipped (the file-level checks above still
// prove the assets exist and are valid) instead of failing the whole suite.
$serverReachable = is_array($manifestHeaders) && ($manifestHeaders[0] ?? '') !== '';
if (!$serverReachable) {
    $h->skip('manifest is served with HTTP 200 and manifest MIME', 'No test HTTP server reachable at ' . $httpBase . '; file-level asset checks still apply.');
    $h->skip('worker is served with HTTP 200 and JavaScript MIME', 'No test HTTP server reachable at ' . $httpBase . '; file-level asset checks still apply.');
    $h->skip('offline shell is served with HTTP 200', 'No test HTTP server reachable at ' . $httpBase . '; file-level asset checks still apply.');
    $h->skip('all local offline runtime assets are served with HTTP 200', 'No test HTTP server reachable at ' . $httpBase . '; file-level asset checks still apply.');
} else {
    $manifestType = is_array($manifestHeaders['Content-Type'] ?? null) ? end($manifestHeaders['Content-Type']) : ($manifestHeaders['Content-Type'] ?? '');
    $workerType = is_array($workerHeaders['Content-Type'] ?? null) ? end($workerHeaders['Content-Type']) : ($workerHeaders['Content-Type'] ?? '');
    $h->test('manifest is served with HTTP 200 and manifest MIME', str_contains((string)($manifestHeaders[0] ?? ''), '200') && str_contains((string)$manifestType, 'application/manifest+json'));
    $h->test('worker is served with HTTP 200 and JavaScript MIME', str_contains((string)($workerHeaders[0] ?? ''), '200') && (str_contains((string)$workerType, 'javascript')));
    $shellHeaders = pwaHead($httpBase . '/daily-ledger/offline.html');
    $h->test('offline shell is served with HTTP 200', str_contains((string)($shellHeaders[0] ?? ''), '200'));
    $localRuntimeAssets = [
        '/daily-ledger/assets/offline-app.js',
        '/daily-ledger/assets/offline-vault.js',
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
}

$h->section('Service worker boundaries (static shell, no authenticated HTML)');
$worker = (string) file_get_contents($workerPath);
$h->test('worker uses versioned cache lifecycle', str_contains($worker, 'CACHE_VERSION') && str_contains($worker, 'caches.delete') && str_contains($worker, 'clients.claim'));
$h->test('worker precaches only the token-free static shell', str_contains($worker, 'REQUIRED_PRECACHE') && str_contains($worker, "OFFLINE_SHELL = '/daily-ledger/offline.html'") && str_contains($worker, 'offline-app.js') && str_contains($worker, 'offline-vault.js'));
$h->test('worker verifies required entries and tolerates optional failures', str_contains($worker, 'REQUIRED_PRECACHE') && str_contains($worker, 'OPTIONAL_PRECACHE') && str_contains($worker, 'Promise.allSettled'));
$h->test('worker no longer caches or rewrites authenticated HTML', !str_contains($worker, "window.DL_CSRF = ''") && !str_contains($worker, "window.DL_TOKEN = ''") && !str_contains($worker, 'cache.put(LEDGER_PATH') && !str_contains($worker, 'window.DL_OFFLINE_SHELL = true'));
$h->test('worker falls back to the shell only for the ledger document', str_contains($worker, 'networkFirstLedger') && str_contains($worker, "url.pathname === LEDGER_PATH") && str_contains($worker, "request.mode === 'navigate'") && str_contains($worker, 'cache.match(OFFLINE_SHELL)'));
$h->test('worker never serves the shell for APIs or unrelated routes', str_contains($worker, "request.method !== 'GET'") && str_contains($worker, "'/daily-ledger/api/'") && str_contains($worker, "url.origin !== self.location.origin"));
$h->test('worker excludes auth routes', str_contains($worker, 'login|logout|forgot-password|reset-password'));
$h->test('worker forwards launch messages to clients', str_contains($worker, 'dl-offline-activated') && str_contains($worker, 'SKIP_WAITING'));
$h->test('worker cache is at v6 (fresh static shell)', str_contains($worker, "daily-ledger-pwa-v6") && !str_contains($worker, "daily-ledger-pwa-v5"));

$h->section('Offline shell states');
$shell = (string) file_get_contents($shellPath);
$offlineApp = (string) file_get_contents($offlineAppPath);
$offlineVault = (string) file_get_contents($offlineVaultPath);
$h->test('shell loads only local vault/app assets', str_contains($shell, '/daily-ledger/assets/offline-vault.js') && str_contains($shell, '/daily-ledger/assets/offline-app.js') && !str_contains($shell, 'https://') && !str_contains($shell, 'unpkg.com'));
$h->test('shell declares all deterministic states', str_contains($offlineApp, 'Not enrolled') && str_contains($offlineApp, 'Preparing') && str_contains($offlineApp, 'Offline ready') && str_contains($offlineApp, 'Unlock') && str_contains($offlineApp, 'Expired') && str_contains($offlineApp, 'Revoked') && str_contains($offlineApp, 'Update required') && str_contains($offlineApp, 'Storage unavailable'));
$h->test('shell requires worker, storage, crypto, and enrollment before readiness', str_contains($offlineApp, 'no-service-worker') && str_contains($offlineApp, 'no-indexeddb') && str_contains($offlineApp, 'no-webcrypto') && str_contains($offlineApp, 'insecure-context'));
$h->test('shell unlocks via the encrypted vault (PIN, throttled)', str_contains($offlineApp, 'V.unlock(pin)') && str_contains($offlineVault, 'MAX_ATTEMPTS') && str_contains($offlineVault, 'LOCK_MS') && str_contains($offlineVault, 'PBKDF2'));
$h->test('shell hides online-only actions with an explanation', str_contains($offlineApp, 'online-only') && str_contains($offlineApp, 'Day close (online only)'));
$h->test('vault consolidates enrollment/bootstrap/ops/receipts/quarantine in one DB', str_contains($offlineVault, "'daily-ledger-offline-vault'") && str_contains($offlineVault, "'enrollment'") && str_contains($offlineVault, "'bootstrap'") && str_contains($offlineVault, "'operations'") && str_contains($offlineVault, "'receipts'") && str_contains($offlineVault, "'quarantine'"));
$h->test('vault encrypts bootstrap and operations with AES-GCM and AAD scope binding', str_contains($offlineVault, "'AES-GCM'") && str_contains($offlineVault, 'additionalData') && str_contains($offlineVault, 'canonicalAAD') && str_contains($offlineVault, 'scope-mismatch'));
$h->test('vault wraps the data key with a PIN-derived key (never plaintext PIN or key)', str_contains($offlineVault, "'wrapped_data_key'") && str_contains($offlineVault, 'deriveWrappingKey'));
$h->test('vault persists operations durably (encrypted envelope) before resolving', str_contains($offlineVault, 'enqueueOperation') && str_contains($offlineVault, 'envelope'));
$h->test('vault migrates legacy storage and quarantines mismatches', str_contains($offlineVault, 'migrateLegacy') && str_contains($offlineVault, 'LEGACY_IDB_NAME') && str_contains($offlineVault, 'scope-mismatch'));

$h->section('Template wiring');
$layout = (string) file_get_contents($layoutPath);
$ledger = (string) file_get_contents($ledgerPath);
$production = (string) file_get_contents($base . '/templates/modules/daily-ledger/admin/production-output.disyl');
$handlers = (string) file_get_contents($handlersPath);
$withdrawalModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$receiveModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
$dispatchModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
$editDeliveryModal = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/edit_delivery_modal.disyl');
$offlineReference = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/offline_reference.disyl');
$offlineAuth = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/offline_auth.disyl');
$routes = (string) file_get_contents($routesPath);
$offlineHandlers = (string) file_get_contents($offlineHandlersPath);
$h->test('layout links manifest', str_contains($layout, '<link rel="manifest" href="/daily-ledger/manifest.webmanifest">'));
$h->test('layout uses only local PWA runtime dependencies', str_contains($layout, '/daily-ledger/assets/tailwindcss.js') && str_contains($layout, '/daily-ledger/assets/fontawesome/all.min.css') && str_contains($layout, '/daily-ledger/assets/htmx-1.9.10.min.js') && str_contains($layout, '/daily-ledger/assets/alpine-3.min.js') && !str_contains($layout, 'cdn.tailwindcss.com') && !str_contains($layout, 'unpkg.com'));
$h->test('layout registers scoped worker with launch messaging', str_contains($layout, "serviceWorker.register('/daily-ledger/sw.js')") && str_contains($layout, 'dlNotifyOfflineActivated') && str_contains($layout, 'SKIP_WAITING'));
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
$h->test('ledger exposes explicit enrollment + verified readiness', str_contains($ledger, 'dlOpenOfflineAccess') && str_contains($ledger, 'offline-ready-badge') && str_contains($ledger, '/api/v1/offline/enroll') && str_contains($ledger, 'Offline ready'));
$h->test('ledger routes pending saves and ops through the encrypted vault adapter', str_contains($ledger, 'offline-vault.js') && str_contains($ledger, 'vaultEnqueue') && str_contains($ledger, 'drainVault') && str_contains($ledger, 'vaultOrLegacyAddPending') && str_contains($ledger, '/api/v1/offline/reconcile'));
$h->test('withdrawal modal queues offline and sends idempotency key', str_contains($withdrawalModal, "enqueueOperation('withdrawal'") && str_contains($withdrawalModal, 'payload.idempotency_key'));
$h->test('paper-DR receive queues offline and sends idempotency key', str_contains($receiveModal, "enqueueOperation('receive_paper_dr'") && str_contains($receiveModal, 'payload.idempotency_key'));
$h->test('dispatch and delivery-edit block offline with a clear message', str_contains($dispatchModal, 'Sending stock requires cloud connectivity') && str_contains($editDeliveryModal, 'Delivery correction requires cloud connectivity'));
$h->test('offline auth overlay is retired to a compatibility shim', str_contains($offlineAuth, 'RETIRED') && !str_contains($offlineAuth, 'id="offline-lock"') && str_contains($offlineAuth, 'dlMaybeLockOffline'));
$h->test('offline reference is vault-backed with legacy fallback', str_contains($offlineReference, 'DLOfflineVault') && str_contains($offlineReference, 'getBootstrap') && str_contains($offlineReference, 'daily-ledger-reference') && str_contains($offlineReference, 'dlReadProductReference'));
$h->test('cashier modals fall back to the (vault-backed) offline product reference', str_contains($withdrawalModal, 'dlReadProductReference') && str_contains($receiveModal, 'dlReadProductReference'));
$h->test('offline routes are additive and versioned', str_contains($routes, "'/daily-ledger/api/v1/offline/enroll'") && str_contains($routes, "'/daily-ledger/api/v1/offline/status'") && str_contains($routes, "'/daily-ledger/api/v1/offline/bootstrap'") && str_contains($routes, "'/daily-ledger/api/v1/offline/revoke'") && str_contains($routes, "'/daily-ledger/api/v1/offline/reconcile'"));
$h->test('offline handlers enforce server-derived scope and never store credentials', str_contains($offlineHandlers, 'dl_authorizeBranch') && str_contains($offlineHandlers, 'dl_offlineDeviceHash') && str_contains($offlineHandlers, 'dl_offlineValidateEnrollment'));
$h->test('offline handlers bound the reconcile batch', str_contains($offlineHandlers, 'count($operations) > 200'));
$h->test('queued operation endpoints enforce idempotency', str_contains($handlers, "dl_loadIdempotentResponse('cashier_withdrawal'") && str_contains($handlers, "dl_loadIdempotentResponse('receive_paper_dr'") && str_contains($handlers, "dl_storeIdempotentResponse('cashier_withdrawal'") && str_contains($handlers, "dl_storeIdempotentResponse('receive_paper_dr'"));

$h->done();
