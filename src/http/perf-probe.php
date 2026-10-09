<?php

if (!function_exists('kernelPerfProbeElapsedMs')) {
    /**
     * Elapsed milliseconds since an hrtime(true) mark.
     *
     * Deliberately NOT clamped to a floor. Clamping to a minimum makes "this
     * cost nothing" unrepresentable and turns every `ms > 0` assertion built on
     * it into a guard that cannot fail.
     */
    function kernelPerfProbeElapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}

if (!function_exists('kernelPerfProbeColdModuleDiscover')) {
    function kernelPerfProbeColdModuleDiscover(): array
    {
        $startedAt = hrtime(true);
        $modules = [];

        try {
            $modules = discoverModulesScanAll();
        } catch (Throwable $e) {
            $modules = [];
        }

        return [
            'ms' => kernelPerfProbeElapsedMs($startedAt),
            'modules' => count($modules),
        ];
    }
}

if (!function_exists('kernelPerfProbeSettingsPreload')) {
    function kernelPerfProbeSettingsPreload(): array
    {
        $tenantId = null;
        try {
            kernel_request_context_delete('_tenant_module_settings_cache');
            $tenantId = moduleTenantSettingsTenantId();
        } catch (Throwable $e) {
            // A missing tenant is an expected state on kernel superadmin pages.
        }

        if ($tenantId === null) {
            return [
                'ms' => 0.0,
                'state' => 'no_tenant',
                'tenant_id' => null,
                'rows' => 0,
            ];
        }

        $startedAt = hrtime(true);
        $rows = 0;
        try {
            preloadAllTenantModuleSettings();
            $settings = kernel_request_context_get('_tenant_module_settings_cache', []);
            if (is_array($settings)) {
                foreach ($settings as $moduleSettings) {
                    if (is_array($moduleSettings)) {
                        $rows += count($moduleSettings);
                    }
                }
            }
        } catch (Throwable $e) {
            // Probe seams report their result without taking down the page.
        }

        return [
            'ms' => kernelPerfProbeElapsedMs($startedAt),
            'state' => 'measured',
            'tenant_id' => (int)$tenantId,
            'rows' => $rows,
        ];
    }
}

if (!function_exists('kernelPerfProbeDisylRender')) {
    function kernelPerfProbeDisylRender(): array
    {
        $template = 'pages/_perf-probe.disyl';
        $sourcePath = (defined('TEMPLATES_PATH') ? TEMPLATES_PATH : dirname(__DIR__, 2) . '/templates')
            . '/' . $template;
        $source = @file_get_contents($sourcePath);
        $extends = is_string($source) && str_contains($source, '{extends');
        $startedAt = hrtime(true);
        $result = [
            'ms' => 0.0,
            'template' => $template,
            'extends' => $extends,
            'ok' => false,
            // TemplateEngine exposes compiled-mode enablement, but not which path a render used.
            'compiled' => null,
        ];

        try {
            $context = [
                'page_title' => '__perf_probe__',
                'base_url' => function_exists('external_base_url') ? external_base_url() : '',
            ];
            $cacheRoot = (string)app()->config('paths.cache', '');
            if ($cacheRoot !== '' && is_dir($cacheRoot) && !is_writable($cacheRoot)) {
                // CLI deployments can run as a different user from PHP-FPM. Use a
                // writable cache only for that environment; engine flags still apply.
                $fallbackCache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . 'ikabud-perf-probe-disyl';
                $engine = new \Ikabud\Kernel\DiSyL\TemplateEngine(
                    defined('TEMPLATES_PATH') ? TEMPLATES_PATH : dirname(__DIR__, 2) . '/templates',
                    $fallbackCache,
                    false
                );
                $html = $engine->render($template, $context);
            } else {
                $html = app()->render($template, $context);
            }
            $result['ok'] = str_contains($html, 'id="kernel-perf-probe-marker"');
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $result['ms'] = kernelPerfProbeElapsedMs($startedAt);
        return $result;
    }
}

if (!function_exists('kernelPerfOpcacheSnapshot')) {
    /**
     * One cheap read of the OPcache state, or null when OPcache is not in play.
     *
     * `opcache_get_status(false)` deliberately omits the per-script list: enumerating thousands of
     * cached scripts costs more than the number is worth, and this runs on the request path.
     *
     * Returns null - never a zero - when the function is unavailable (hosts disable it through
     * `disable_functions`) or OPcache is off. A fabricated 0 would read as "cached nothing", which is a
     * different claim from "could not ask".
     */
    function kernelPerfOpcacheSnapshot(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        try {
            $status = @opcache_get_status(false);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return null;
        }
        $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];

        return [
            'cached_scripts' => (int)($stats['num_cached_scripts'] ?? 0),
            'max_cached_keys' => (int)($stats['max_cached_keys'] ?? 0),
            'hits' => (int)($stats['hits'] ?? 0),
            'misses' => (int)($stats['misses'] ?? 0),
            'oom_restarts' => (int)($stats['oom_restarts'] ?? 0),
            'hash_restarts' => (int)($stats['hash_restarts'] ?? 0),
            'used_mb' => round(((int)($memory['used_memory'] ?? 0)) / 1048576, 1),
            'free_mb' => round(((int)($memory['free_memory'] ?? 0)) / 1048576, 1),
            'wasted_mb' => round(((int)($memory['wasted_memory'] ?? 0)) / 1048576, 1),
        ];
    }
}

if (!function_exists('kernelPerfProbeOpcache')) {
    /**
     * OPcache state, plus how many scripts THIS request had to compile.
     *
     * The delta is the whole point. A cached-script count alone cannot tell "OPcache is serving the 347
     * module helper files" apart from "OPcache is enabled and those same files are compiled every
     * request" - and those two states need opposite fixes. A cached count can even look healthy while
     * the files that matter are recompiled each time, because the count is per-worker and stays high.
     *
     * `misses` is cumulative for the worker, so the difference against the request's own baseline
     * (captured before any module is registered) counts the compilations that happened DURING module
     * registration - which is exactly where the helper files are included.
     */
    function kernelPerfProbeOpcache(): array
    {
        $now = kernelPerfOpcacheSnapshot();
        $baseline = $GLOBALS['kernel_perf_request_attribution']['opcache_baseline'] ?? null;
        if (!is_array($baseline)) {
            $baseline = null;
        }

        $report = [
            'available' => $now !== null,
            'now' => $now,
            'baseline_available' => $baseline !== null,
            // null, never 0: without a baseline there is no measurement, and 0 would claim there was.
            'compiled_this_request' => null,
            'validate_timestamps' => null,
            'revalidate_freq' => null,
        ];

        if ($now !== null && $baseline !== null) {
            $report['compiled_this_request'] = max(0, $now['misses'] - (int)$baseline['misses']);
        }

        // ini_get returns false for an unknown or unreadable directive; report that as unknown.
        $validate = ini_get('opcache.validate_timestamps');
        if ($validate !== false) {
            $report['validate_timestamps'] = (string)$validate;
        }
        $freq = ini_get('opcache.revalidate_freq');
        if ($freq !== false) {
            $report['revalidate_freq'] = (string)$freq;
        }

        return $report;
    }
}

if (!function_exists('kernelPerfProbeManifestFingerprint')) {
    /**
     * Which path did the manifest fingerprint take, and what would the other one have cost?
     *
     * moduleManifestScanFingerprint() runs on every request to key the discovery cache. It used to
     * re-walk the module tree each time; it now revalidates a cached tree state with stat() calls. A
     * fast path that silently never engages looks EXACTLY like an optimization that did not work, so
     * this row reports the path as well as the timing — `state_cached=false` on a warm page means the
     * stat path was not taken, whatever the clock says.
     */
    function kernelPerfProbeManifestFingerprint(): array
    {
        $out = [
            'available' => false,
            'fingerprint_ms' => null,
            'walk_ms' => null,
            'validate_ms' => null,
            'validate_ok' => null,
            'dirs' => null,
            'files' => null,
            'dirs_complete' => null,
            'apcu_usable' => false,
            'state_cached' => false,
        ];

        if (!function_exists('modulesPath')
            || !function_exists('moduleManifestScanFingerprint')
            || !function_exists('moduleManifestTreeState')
            || !function_exists('moduleManifestStateIsCurrent')) {
            return $out;
        }

        $dir = modulesPath();
        if (!is_dir($dir)) {
            return $out;
        }

        $out['available'] = true;
        $out['apcu_usable'] = function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && (bool)ini_get('apc.enabled');

        // The call discoverModules() actually makes, timed as the request made it.
        $started = hrtime(true);
        moduleManifestScanFingerprint();
        $out['fingerprint_ms'] = kernelPerfProbeElapsedMs($started);

        $cached = apcu_fetch('kernel.module_manifest_state_v1', $hit);
        $out['state_cached'] = (bool)$hit;
        unset($cached);

        // What a full walk costs, versus what revalidating that state costs.
        $started = hrtime(true);
        $state = moduleManifestTreeState($dir);
        $out['walk_ms'] = kernelPerfProbeElapsedMs($started);
        $out['dirs'] = count($state['dirs']);
        $out['files'] = count($state['files']);
        $out['dirs_complete'] = (bool)$state['dirs_complete'];

        $started = hrtime(true);
        $out['validate_ok'] = moduleManifestStateIsCurrent($dir, $state);
        $out['validate_ms'] = kernelPerfProbeElapsedMs($started);

        return $out;
    }
}
