<?php

declare(strict_types=1);

/**
 * Bakeshop module — Entity view contract registrations.
 *
 * Replaces kernel-level built-in defaults so bakeshop entities
 * are explicitly owned by the bakeshop module (P2.1 migration).
 */

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();
    $moduleId = 'bakeshop';

    $views->registerView('bakeshop_product', 'compact', [
        'fields' => ['id', 'name', 'price', 'unit', 'stock_qty', 'category'],
        'actions' => ['view'],
        'limit' => 20,
        'empty_state' => 'No products found.',
        'sortable_fields' => ['name' => 'name', 'price' => 'price', 'stock_qty' => 'stock_qty', 'category' => 'category'],
    ], $moduleId);
}
