<?php

declare(strict_types=1);

function wmsRequestBodyItems(string $key = 'items'): array
{
    $items = wmsInput($key, []);
    return is_array($items) ? array_values(array_filter($items, static fn ($item) => is_array($item))) : [];
}

function wmsResponseGuard(callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('wms handler error: ' . $e->getMessage(), 'error');
        }
        try {
            wmsCtx()->log('wms handler error: ' . $e->getMessage(), 'error');
        } catch (Throwable $ignored) {
        }
        wmsJsonError($e->getMessage(), 422);
    }
}

function wmsApiHealth(): void
{
    $tenantId = app()->tenant()->current();
    wmsJsonOk([
        'service' => 'wms',
        'tenant_id' => $tenantId,
        'time' => gmdate('c'),
    ]);
}
