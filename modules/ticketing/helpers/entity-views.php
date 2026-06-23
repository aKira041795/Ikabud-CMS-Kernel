<?php

declare(strict_types=1);

/**
 * Ticketing module — Entity view contract registrations.
 *
 * Replaces kernel-level built-in defaults so ticket entities
 * are explicitly owned by the ticketing module (P2.1 migration).
 */

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();
    $moduleId = 'ticketing';

    $views->registerView('tickets', 'compact', [
        'fields' => ['id', 'subject', 'status', 'created_at'],
        'actions' => ['view'],
        'limit' => 15,
        'empty_state' => 'No tickets.',
        'sortable_fields' => ['subject' => 'subject', 'status' => 'status', 'created_at' => 'created_at'],
    ], $moduleId);
}
