<?php

declare(strict_types=1);

/**
 * WMS module — Entity view contract registrations.
 *
 * Replaces kernel-level built-in defaults so WMS entities
 * are explicitly owned by the wms module (P2.1 migration).
 */

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();
    $moduleId = 'wms';

    $views->registerView('wms_stock', 'compact', [
        'fields' => ['id', 'sku', 'name', 'qty', 'location_name', 'updated_at'],
        'actions' => ['view', 'move'],
        'limit' => 30,
        'empty_state' => 'No stock items.',
        'sortable_fields' => ['sku' => 'sku', 'name' => 'name', 'qty' => 'qty', 'updated_at' => 'updated_at'],
    ], $moduleId);

    $views->registerView('wms_location', 'compact', [
        'fields' => ['id', 'name', 'type', 'is_staging'],
        'actions' => ['view'],
        'limit' => 20,
        'empty_state' => 'No locations.',
        'sortable_fields' => ['name' => 'name', 'type' => 'type'],
    ], $moduleId);
}
