<?php

declare(strict_types=1);

/**
 * PAL Advanced Reporting — capability handlers.
 *
 * Evidence module proving the product suite + extension model is not
 * CMS-specific. All handlers are loaded from helpers.php per convention.
 */

/**
 * Capability handler map for pal-advanced-reporting.
 */
function pal_advanced_reporting_capability_handlers(): array
{
    return [
        'pal.reports.advanced.list@1' => 'par_cap_pal_reports_advanced_list_1',
    ];
}

/**
 * List advanced PAL reports (evidence capability).
 */
function par_cap_pal_reports_advanced_list_1(mixed $payload = [], string $capabilityId = 'pal.reports.advanced.list@1', string $caller = 'unknown'): array
{
    return [
        'ok' => true,
        'data' => [
            'reports' => [],
            'provider' => 'pal-advanced-reporting',
        ],
    ];
}

/**
 * Route handler for /admin/pal/advanced-reports.
 */
function palAdvancedReports(array $params = []): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>PAL Advanced Reports</title></head>'
        . '<body><h1>PAL Advanced Reports</h1><p>Extension surface contributed dynamically by pal-advanced-reporting.</p></body></html>';
}
