<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;
use Ikabud\Kernel\Cache;
use Ikabud\Kernel\EventBus;
use Ikabud\Kernel\Hooks;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
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

function heading(string $label): void
{
    echo "\n=== {$label} ===\n";
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }

    @rmdir($path);
}

heading('Request Context');

kernel_request_context_delete('_hardening_value');
kernel_request_context_delete('_hardening_stack');
kernel_request_context_set('_hardening_value', 'alpha');
t('request context stores values', kernel_request_context_get('_hardening_value') === 'alpha');
t('request context mirrors legacy globals', ($GLOBALS['_hardening_value'] ?? null) === 'alpha');

$legacyKey = '_hardening_legacy';
$GLOBALS[$legacyKey] = 'legacy';
t('request context reads existing legacy globals', kernel_request_context_get($legacyKey) === 'legacy');

kernel_request_context_push('_hardening_stack', 'one');
kernel_request_context_push('_hardening_stack', 'two');
$popped = kernel_request_context_pop('_hardening_stack');
t('request context stack is LIFO', $popped === 'two' && kernel_request_context_get('_hardening_stack') === ['one'], json_encode(kernel_request_context_get('_hardening_stack')));

kernel_request_context_delete('_hardening_value');
kernel_request_context_delete('_hardening_stack');
unset($GLOBALS[$legacyKey]);
t('request context delete clears mirrored globals', !isset($GLOBALS['_hardening_value']));

heading('Redirect Validation');

t('relative redirect target is allowed', kernel_validate_redirect_target('/login') === '/login');

heading('Base Path Inference');

$originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
$originalHttpHost = $_SERVER['HTTP_HOST'] ?? null;
$originalHttps = $_SERVER['HTTPS'] ?? null;

$_SERVER['SCRIPT_NAME'] = '/kernelappos/index.php';
$_SERVER['PHP_SELF'] = '/kernelappos/index.php';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';

t(
    'base path infers shared-hosting subdirectory from script name when APP_URL path is empty',
    kernel_request_base_path('/kernelappos/index.php', 'https://example.com') === '/kernelappos'
);
t(
    'external base url reuses inferred shared-hosting subdirectory',
    external_base_url('https://example.com') === 'https://example.com/kernelappos',
    external_base_url('https://example.com')
);

if ($originalScriptName === null) {
    unset($_SERVER['SCRIPT_NAME']);
} else {
    $_SERVER['SCRIPT_NAME'] = $originalScriptName;
}

if ($originalPhpSelf === null) {
    unset($_SERVER['PHP_SELF']);
} else {
    $_SERVER['PHP_SELF'] = $originalPhpSelf;
}

if ($originalHttpHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $originalHttpHost;
}

if ($originalHttps === null) {
    unset($_SERVER['HTTPS']);
} else {
    $_SERVER['HTTPS'] = $originalHttps;
}

$originalHttpHost = $_SERVER['HTTP_HOST'] ?? null;
$originalHttps = $_SERVER['HTTPS'] ?? null;
$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['HTTPS'] = 'on';

t(
    'same-origin absolute redirect target is allowed',
    kernel_validate_redirect_target('https://applicationos.test/login') === 'https://applicationos.test/login'
);

$threw = false;
try {
    kernel_validate_redirect_target('https://evil.test/login');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
t('redirect validator rejects cross-origin absolute redirects', $threw);

$threw = false;
try {
    kernel_validate_redirect_target('//evil.test/login');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
t('redirect validator rejects protocol-relative redirects', $threw);

if ($originalHttpHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $originalHttpHost;
}

if ($originalHttps === null) {
    unset($_SERVER['HTTPS']);
} else {
    $_SERVER['HTTPS'] = $originalHttps;
}

$threw = false;
try {
    kernel_validate_redirect_target("/bad\r\nX-Test: injected");
} catch (InvalidArgumentException $e) {
    $threw = true;
}
t('redirect validator rejects raw CRLF', $threw);

$threw = false;
try {
    kernel_validate_redirect_target('/bad%0d%0aX-Test:%20injected');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
t('redirect validator rejects encoded CRLF', $threw);

heading('DB Validation Cadence');

// shouldValidateConnection now lives on DatabaseManager (refactor 2026-04).
$dbManagerForTest = new \Ikabud\Kernel\Services\DatabaseManager(
    ['app' => ['database' => ['idle_validation_seconds' => 60]]],
    static function (): void {},
    static function () { return null; },
    static function (): ?int { return null; }
);
$dbManagerReflection = new ReflectionClass($dbManagerForTest);
$shouldValidateConnection = $dbManagerReflection->getMethod('shouldValidateConnection');
$shouldValidateConnection->setAccessible(true);

t(
    'db validation skips ping before configured idle threshold',
    $shouldValidateConnection->invoke($dbManagerForTest, time() - 30) === false
);
t(
    'db validation pings after configured idle threshold',
    $shouldValidateConnection->invoke($dbManagerForTest, time() - 61) === true
);
unset($dbManagerForTest, $dbManagerReflection, $shouldValidateConnection);

heading('Security Headers');

$originalHttpHost = $_SERVER['HTTP_HOST'] ?? null;
$originalHttps = $_SERVER['HTTPS'] ?? null;
$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['HTTPS'] = 'on';

$securityHeaders = new \Ikabud\Kernel\Http\SecurityHeaders('/login', 'applicationos.test');
$headerList = $securityHeaders->headers();
$cspHeaders = array_values(array_filter($headerList, static function (string $header): bool {
    return str_starts_with($header, 'Content-Security-Policy: ');
}));

t(
    'security header builder emits a CSP header without nonce (unsafe-inline present; nonce would override it per CSP3)',
    count($cspHeaders) === 1
        && str_contains($cspHeaders[0], "default-src 'self'")
        && str_contains($cspHeaders[0], "'unsafe-inline'")
        && !str_contains($cspHeaders[0], 'nonce-'),
    json_encode($headerList)
);
t(
    'security header builder emits HSTS on HTTPS requests',
    in_array('Strict-Transport-Security: max-age=31536000; includeSubDomains', $headerList, true),
    json_encode($headerList)
);
t(
    'security header builder does not emit deprecated X-XSS-Protection',
    empty(array_filter($headerList, static function (string $header): bool {
        return str_starts_with($header, 'X-XSS-Protection:');
    })),
    json_encode($headerList)
);

if ($originalHttpHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $originalHttpHost;
}

if ($originalHttps === null) {
    unset($_SERVER['HTTPS']);
} else {
    $_SERVER['HTTPS'] = $originalHttps;
}

heading('CSRF Rotation');

$originalToken = app()->csrfToken();
$rotatedToken = app()->csrfRotate();
t('csrfRotate changes the token', $rotatedToken !== $originalToken && strlen($rotatedToken) === 64, $originalToken . ' -> ' . $rotatedToken);
t('csrfToken returns the rotated token', app()->csrfToken() === $rotatedToken);

heading('Hooks and Events');

$hooks = Hooks::getInstance();
$hooks->reset();
$hookOrder = [];
$hooks->on('hardening.filter', function (string $value) use (&$hookOrder): string {
    $hookOrder[] = 'late';
    return $value . 'B';
}, 20);
$hooks->on('hardening.filter', function (string $value) use (&$hookOrder): string {
    $hookOrder[] = 'early';
    return $value . 'A';
}, 5);
$hookResult = $hooks->filter('hardening.filter', '');
t('hooks preserve listener priority after lazy sorting', $hookOrder === ['early', 'late'] && $hookResult === 'AB', json_encode([$hookOrder, $hookResult]));

$hooks->reset();
$hooks->on('hardening.filter.null', static function (string $value): ?string {
    return null;
}, 10);
$hookNullPreserved = $hooks->filter('hardening.filter.null', 'seed');
t('hooks filter treats null as no-op by default', $hookNullPreserved === 'seed', (string)$hookNullPreserved);

$hooks->reset();
$hooks->on('hardening.filter.nullable', static function (string $value): ?string {
    return null;
}, 10);
$hookNullableResult = $hooks->filterNullable('hardening.filter.nullable', 'seed');
t('hooks filterNullable allows null results explicitly', $hookNullableResult === null, json_encode($hookNullableResult));

$hooks->reset();
$hooks->on('kernel.request.before_dispatch', static function (array $context): array {
    $context['method'] = 'post';
    $context['uri'] = '/rewritten-path?from=test';
    $context['handled'] = true;
    return $context;
}, 10);
$dispatchContext = kernelApplyRequestBeforeDispatch(kernelBuildRequestDispatchContext('get', '/original-path'));
t(
    'request before dispatch hook can rewrite and short-circuit dispatch context',
    ($dispatchContext['method'] ?? '') === 'POST'
        && ($dispatchContext['uri'] ?? '') === '/rewritten-path?from=test'
        && !empty($dispatchContext['handled']),
    json_encode($dispatchContext)
);
t(
    'current request dispatch context reflects hook-filtered value',
    (kernelCurrentRequestDispatchContext()['uri'] ?? null) === '/rewritten-path?from=test',
    json_encode(kernelCurrentRequestDispatchContext())
);

$hooks->reset();
$shutdownCalls = 0;
$shutdownAppIsKernel = false;
$hooks->on('kernel.shutdown', static function ($appInstance) use (&$shutdownCalls, &$shutdownAppIsKernel): void {
    $shutdownCalls++;
    $shutdownAppIsKernel = $appInstance === app();
}, 10);
kernelFireShutdownHooks();
kernelFireShutdownHooks();
t('kernel shutdown hook fires once and passes app instance', $shutdownCalls === 1 && $shutdownAppIsKernel);

$events = EventBus::getInstance();
$events->reset();
$eventTrace = [];
$events->listen('order.*', function (array $payload, string $event) use (&$eventTrace): void {
    $eventTrace[] = 'wildcard:' . $event;
}, 20);
$events->listen('order.placed', function (array $payload, string $event) use (&$eventTrace): void {
    $eventTrace[] = 'exact:' . $event;
}, 5);
$called = $events->fire('order.placed', ['id' => 1]);
t('event bus fires exact and wildcard listeners in priority order', $called === 2 && $eventTrace === ['exact:order.placed', 'wildcard:order.placed'], json_encode($eventTrace));
t('event bus hasListeners still matches wildcard subscriptions', $events->hasListeners('order.cancelled'));

$events->reset();
$deferredTrace = [];
$events->listen('order.deferred', function (array $payload, string $event) use (&$deferredTrace): void {
    $deferredTrace[] = $event . ':' . (string)($payload['id'] ?? '');
}, 10);
$queued = $events->fireDeferred('order.deferred', ['id' => 7], 'kernel');
t(
    'event bus queues deferred events without firing immediately',
    $queued === 1 && $events->deferredCount() === 1 && $deferredTrace === [],
    json_encode([$queued, $events->deferredCount(), $deferredTrace])
);
$deferredCalled = $events->flushDeferred();
t(
    'event bus flushes deferred events later',
    $deferredCalled === 1 && $events->deferredCount() === 0 && $deferredTrace === ['order.deferred:7'],
    json_encode([$deferredCalled, $events->deferredCount(), $deferredTrace])
);

heading('Capability Runtime State');

$metricsFile = capability_cache_path('capability_metrics.json');
$breakersFile = capability_cache_path('capability_breakers.json');
$metricsBackup = is_file($metricsFile) ? (string)file_get_contents($metricsFile) : null;
$breakersBackup = is_file($breakersFile) ? (string)file_get_contents($breakersFile) : null;

if (is_file($metricsFile)) {
    @unlink($metricsFile);
}
if (is_file($breakersFile)) {
    @unlink($breakersFile);
}

register_shutdown_function(static function () use ($metricsFile, $breakersFile, $metricsBackup, $breakersBackup): void {
    if ($metricsBackup === null) {
        @unlink($metricsFile);
    } else {
        @file_put_contents($metricsFile, $metricsBackup);
    }

    if ($breakersBackup === null) {
        @unlink($breakersFile);
    } else {
        @file_put_contents($breakersFile, $breakersBackup);
    }
});

$registry = new CapabilityRegistry();
$registry->register('hardening.pass@1', 'test', static fn(): array => ['ok' => true], 10, ['first']);
$bus = new CapabilityBus($registry);
$result = $bus->call('hardening.pass@1', []);
$health = $bus->healthForProvider('hardening.pass@1', 'test');
t('capability health updates in memory before flush', is_array($result) && (int)($health['count'] ?? 0) === 1 && (int)($health['errors'] ?? 0) === 0, json_encode($health));
$bus->flushRuntimeState();
$persistedMetrics = is_file($metricsFile) ? json_decode((string)file_get_contents($metricsFile), true) : [];
t('capability flush persists request metric deltas once', (int)($persistedMetrics['hardening.pass@1|test']['count'] ?? 0) >= 1, json_encode($persistedMetrics));

$failureRegistry = new CapabilityRegistry();
$failureRegistry->register('hardening.fail@1', 'test', static function (): array {
    throw new RuntimeException('boom');
}, 10, ['first']);
$failureBus = new CapabilityBus($failureRegistry);
try {
    $failureBus->call('hardening.fail@1', []);
} catch (Throwable $ignored) {
}
$failureHealth = $failureBus->healthForProvider('hardening.fail@1', 'test');
t('capability breaker state updates in memory before flush', (int)($failureHealth['errors'] ?? 0) === 1 && (int)($failureHealth['breaker_failures'] ?? 0) === 1, json_encode($failureHealth));
$failureBus->flushRuntimeState();
$persistedBreakers = is_file($breakersFile) ? json_decode((string)file_get_contents($breakersFile), true) : [];
t('capability flush persists breaker failures once per request', (int)($persistedBreakers['hardening.fail@1|test']['failures'] ?? 0) >= 1, json_encode($persistedBreakers));

heading('Cache Lazy Stats');

$cacheRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ikabud_cache_hardening_' . bin2hex(random_bytes(6));
@mkdir($cacheRoot, 0775, true);

register_shutdown_function(static function () use ($cacheRoot): void {
    removeTree($cacheRoot);
});

$cache = new Cache($cacheRoot);
$statsFile = $cacheRoot . '/.cache_stats.json';
$cache->saveStats();
t('unused cache does not write stats on shutdown path', !is_file($statsFile));

$originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$cache->shouldCache('/hardening');
$cache->saveStats();
t('used cache writes stats after first stat mutation', is_file($statsFile));
if ($originalMethod === null) {
    unset($_SERVER['REQUEST_METHOD']);
} else {
    $_SERVER['REQUEST_METHOD'] = $originalMethod;
}

// ── CSP Browser Contract ─────────────────────────────────────────────────────
// These assertions guard the exact trust model required by Alpine.js v3 (CDN)
// and Tailwind CSS CDN JIT: both use new Function() / eval-based scanning.
// A nonce in script-src overrides 'unsafe-inline' per CSP Level 2/3 spec,
// breaking all inline scripts without matching nonce="" attributes.
heading('CSP Browser Contract');

$cspBrowserHeaders = new \Ikabud\Kernel\Http\SecurityHeaders('/login', 'applicationos.test');
$cspBrowserHeaderList = $cspBrowserHeaders->headers();
$cspBrowserValues = array_values(array_filter($cspBrowserHeaderList, static function (string $h): bool {
    return str_starts_with($h, 'Content-Security-Policy: ');
}));
$cspValue = count($cspBrowserValues) === 1 ? substr($cspBrowserValues[0], strlen('Content-Security-Policy: ')) : '';
$scriptSrcMatch = '';
foreach (explode(';', $cspValue) as $directive) {
    $directive = trim($directive);
    if (str_starts_with($directive, 'script-src ')) {
        $scriptSrcMatch = $directive;
        break;
    }
}

t(
    "CSP script-src contains 'unsafe-eval' (required by Alpine.js v3 + Tailwind CDN JIT)",
    str_contains($scriptSrcMatch, "'unsafe-eval'"),
    $scriptSrcMatch !== '' ? $scriptSrcMatch : 'script-src directive not found'
);
t(
    "CSP script-src contains 'unsafe-inline'",
    str_contains($scriptSrcMatch, "'unsafe-inline'"),
    $scriptSrcMatch
);
t(
    "CSP script-src has no nonce- token (nonce overrides unsafe-inline per CSP3)",
    !str_contains($scriptSrcMatch, 'nonce-'),
    $scriptSrcMatch
);
t(
    "CSP X-XSS-Protection omitted (deprecated, causes bypass on old browsers)",
    empty(array_filter($cspBrowserHeaderList, static function (string $h): bool {
        return str_starts_with($h, 'X-XSS-Protection:');
    })),
    json_encode($cspBrowserHeaderList)
);

// ── Error Page Rendering ─────────────────────────────────────────────────────
heading('Error Page Rendering');

$render500Error = null;
$render500Html  = '';
try {
    $render500Html = app()->templates()->render('pages/500', ['base_url' => '']);
} catch (Throwable $renderThrowable) {
    $render500Error = $renderThrowable->getMessage();
}

t(
    '500.disyl renders without throwing',
    $render500Error === null,
    (string)$render500Error
);
t(
    '500.disyl output is non-empty HTML',
    strlen($render500Html) > 50 && str_contains($render500Html, '<'),
    'length=' . strlen($render500Html)
);
t(
    '500.disyl output does not leak exception details',
    !str_contains($render500Html, 'Exception')
        && !str_contains($render500Html, 'Stack trace')
        && !str_contains($render500Html, '#0 '),
    'no trace leaked'
);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);
