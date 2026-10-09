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
