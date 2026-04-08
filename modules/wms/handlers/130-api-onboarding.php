<?php

declare(strict_types=1);

function wmsApiOnboardingStatus(): never
{
    wmsRequireRole(['admin', 'supervisor']);
    
    $completed = (bool)wmsConfigGet('onboarding.completed', false);
    
    wmsJson([
        'completed' => $completed,
        'steps' => [
            'warehouse_created' => (wmsDb()->fetchOne('SELECT id FROM wms_warehouses LIMIT 1') !== null),
            'categories_created' => (wmsDb()->fetchOne('SELECT id FROM wms_products LIMIT 1') !== null),
            'locations_generated' => (wmsDb()->fetchOne('SELECT id FROM wms_locations LIMIT 1') !== null)
        ]
    ]);
}

function wmsApiOnboardingStart(): never
{
    wmsRequireRole(['admin']);
    
    $db = wmsDb();
    
    if ((bool)wmsConfigGet('onboarding.completed', false)) {
        wmsJsonError('Onboarding already completed.', 400);
    }
    
    wmsJson(['success' => true, 'message' => 'Onboarding session active.']);
}

function wmsApiOnboardingComplete(): never
{
    wmsRequireRole(['admin', 'supervisor']);
    
    wmsConfigSet('onboarding.completed', true, 'Indicates if the tenant completed the initial setup wizard');
    
    wmsJson(['success' => true, 'message' => 'Onboarding marked as completed.']);
}
