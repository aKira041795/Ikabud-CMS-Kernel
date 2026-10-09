<?php

declare(strict_types=1);

/**
 * Request-local database and phase attribution.
 *
 * PHP request globals are rebuilt for every web request, including when an
 * Apache/FPM worker is reused. Nothing is persisted in APCu, files, or static
 * process state.
 */
if (!function_exists('kernelPerfProbeRequestAttribution')) {
    $GLOBALS['kernel_perf_request_attribution'] = [
        'started_ns' => isset($GLOBALS['kernel_perf_request_started_ns'])
            ? (int)$GLOBALS['kernel_perf_request_started_ns']
            : hrtime(true),
        'db' => [
            'queries' => 0,
            'total_ms' => 0.0,
            'slowest' => [],
            'ddl_queries' => 0,
            'ddl_tables' => [],
        ],
        'phases' => [
            'boot' => null,
            'dispatch' => null,
            'render' => null,
            'shutdown' => null,
        ],
    ];

    function kernelPerfMarkRequestPhase(string $phase): void
    {
        try {
            if (!in_array($phase, ['boot', 'dispatch', 'render', 'shutdown'], true)) {
                return;
            }

            $state = &$GLOBALS['kernel_perf_request_attribution'];
            if (!is_array($state) || !array_key_exists($phase, $state['phases'] ?? [])) {
                return;
            }
            if ($state['phases'][$phase] !== null) {
                return;
            }

            $startedNs = (int)($state['started_ns'] ?? hrtime(true));
            $state['phases'][$phase] = max(0.0, (hrtime(true) - $startedNs) / 1_000_000);
        } catch (Throwable $ignored) {
            // Attribution must never affect request handling.
        }
    }

    function kernelPerfProbeRequestAttribution(): array
    {
        $empty = [
            'db' => ['queries' => 0, 'total_ms' => 0.0, 'slowest' => [], 'ddl_queries' => 0, 'ddl_tables' => []],
            'phases' => ['boot' => null, 'dispatch' => null, 'render' => null, 'shutdown' => null],
        ];

        try {
            $state = $GLOBALS['kernel_perf_request_attribution'] ?? null;
            if (!is_array($state)) {
                return $empty;
            }

            $db = is_array($state['db'] ?? null) ? $state['db'] : [];
            $phases = is_array($state['phases'] ?? null) ? $state['phases'] : [];

            return [
                'db' => [
                    'queries' => max(0, (int)($db['queries'] ?? 0)),
                    'total_ms' => max(0.0, (float)($db['total_ms'] ?? 0.0)),
                    'slowest' => array_slice(is_array($db['slowest'] ?? null) ? $db['slowest'] : [], 0, 5),
                    'ddl_queries' => max(0, (int)($db['ddl_queries'] ?? 0)),
                    'ddl_tables' => is_array($db['ddl_tables'] ?? null) ? $db['ddl_tables'] : [],
                ],
                'phases' => [
                    'boot' => isset($phases['boot']) ? (float)$phases['boot'] : null,
                    'dispatch' => isset($phases['dispatch']) ? (float)$phases['dispatch'] : null,
                    'render' => isset($phases['render']) ? (float)$phases['render'] : null,
                    'shutdown' => isset($phases['shutdown']) ? (float)$phases['shutdown'] : null,
                ],
            ];
        } catch (Throwable $ignored) {
            return $empty;
        }
    }

    try {
        \Ikabud\Kernel\EventBus::getInstance()->listen(
            'kernel.database.query.after',
            static function (array $payload): void {
                try {
                    $state = &$GLOBALS['kernel_perf_request_attribution'];
                    if (!is_array($state) || !is_array($state['db'] ?? null)) {
                        return;
                    }

                    $duration = $payload['duration_ms'] ?? null;
                    if (!is_numeric($duration) || !is_finite((float)$duration)) {
                        return;
                    }
                    $duration = max(0.0, (float)$duration);
                    $sql = preg_replace('/\s+/', ' ', trim((string)($payload['sql'] ?? '')));
                    $sql = substr(is_string($sql) ? $sql : '', 0, 200);

                    $state['db']['queries'] = (int)$state['db']['queries'] + 1;
                    $state['db']['total_ms'] = (float)$state['db']['total_ms'] + $duration;
                    if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $ddlMatch) === 1) {
                        $table = (string)$ddlMatch[1];
                        $state['db']['ddl_queries'] = (int)($state['db']['ddl_queries'] ?? 0) + 1;
                        $state['db']['ddl_tables'][$table] = (int)($state['db']['ddl_tables'][$table] ?? 0) + 1;
                    }
                    $state['db']['slowest'][] = ['ms' => $duration, 'sql' => $sql];
                    usort(
                        $state['db']['slowest'],
                        static fn(array $left, array $right): int => $right['ms'] <=> $left['ms']
                    );
                    if (count($state['db']['slowest']) > 5) {
                        $state['db']['slowest'] = array_slice($state['db']['slowest'], 0, 5);
                    }
                } catch (Throwable $ignored) {
                    // Never query or log from this hot-path listener.
                }
            },
            10,
            // No module owner on purpose. EventBus::fire() wraps any listener that
            // declares a module in moduleWithContext(), which calls
            // moduleContextFor() -> discoverModules(). discoverModules() sets its
            // per-request memo only AFTER its per-module loop, so a listener that
            // declares a module and observes a query fired from inside that loop
            // re-enters discovery and recurses (observed in error.log on
            // 2026-10-09 as a 128 MB memory-exhaustion fatal under concurrent
            // load). This accumulator needs no module context, so declaring one is
            // pure risk.
            //
            // listen() auto-resolves moduleCurrentId() when '' is passed, so this
            // is only safe because no module context is active yet at include time
            // (public/index.php:155, before loadModuleRoutes()). That invariant is
            // asserted by tests/kernel_db_attribution_test.php.
            ''
        );
    } catch (Throwable $ignored) {
        // The app/event bus may not yet be usable in narrow CLI contexts.
    }

    register_shutdown_function(static function (): void {
        kernelPerfMarkRequestPhase('render');
        kernelPerfMarkRequestPhase('shutdown');
    });
}
