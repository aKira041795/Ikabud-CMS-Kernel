<?php

declare(strict_types=1);

function wmsApiOnboardingStatus(): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');

        $completed = (bool)wmsConfigGet('onboarding.completed', false);

        wmsJsonOk([
            'completed' => $completed,
            'steps' => [
                'warehouse_created' => (wmsFetchOne('SELECT id FROM wms_warehouses LIMIT 1') !== null),
                'categories_created' => (wmsFetchOne('SELECT id FROM wms_products LIMIT 1') !== null),
                'locations_generated' => (wmsFetchOne('SELECT id FROM wms_locations LIMIT 1') !== null)
            ]
        ]);
    });
}

function wmsApiOnboardingStart(): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin');

        if ((bool)wmsConfigGet('onboarding.completed', false)) {
            wmsJsonError('Onboarding already completed.', 400);
        }

        wmsJsonOk(['success' => true, 'message' => 'Onboarding session active.']);
    });
}

function wmsApiOnboardingComplete(): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');

        wmsConfigSet('onboarding.completed', true, 'Indicates if the tenant completed the initial setup wizard');

        wmsJsonOk(['success' => true, 'message' => 'Onboarding marked as completed.']);
    });
}
