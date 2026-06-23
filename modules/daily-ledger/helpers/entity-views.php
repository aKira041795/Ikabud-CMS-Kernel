<?php

declare(strict_types=1);

/**
 * Daily Ledger module — Entity view contract registrations.
 *
 * Replaces kernel-level built-in defaults so daily-ledger entities
 * are explicitly owned by the daily-ledger module (P2.1 migration).
 */

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();
    $moduleId = 'daily-ledger';

    $views->registerView('daily_ledger_entry', 'compact', [
        'fields' => ['id', 'entry_type', 'amount', 'created_at', 'notes'],
        'actions' => ['view'],
        'limit' => 25,
        'empty_state' => 'No ledger entries.',
        'sortable_fields' => ['entry_type' => 'entry_type', 'amount' => 'amount', 'created_at' => 'created_at'],
    ], $moduleId);
}
