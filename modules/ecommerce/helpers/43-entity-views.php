<?php

declare(strict_types=1);

/**
 * Ecommerce module — Entity view contract registrations.
 *
 * Replaces the kernel-level built-in defaults so these entities are
 * explicitly owned by the ecommerce module (P2.1 migration).
 */

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();
    $moduleId = 'ecommerce';

    $views->registerView('products', 'default', [
        'fields' => ['id', 'name', 'price', 'image'],
        'actions' => ['view', 'add_to_cart'],
        'limit' => 20,
        'empty_state' => 'No products found.',
    ], $moduleId);

    $views->registerView('products', 'compact', [
        'fields' => ['id', 'name', 'price', 'image'],
        'actions' => ['view', 'add_to_cart'],
        'limit' => 20,
        'empty_state' => 'No products found.',
    ], $moduleId);

    $views->registerView('ecommerce_product', 'compact', [
        'fields' => ['id', 'name', 'price', 'image', 'stock_status'],
        'actions' => ['view', 'add_to_cart'],
        'limit' => 20,
        'empty_state' => 'No products found.',
        'sortable_fields' => ['name' => 'name', 'price' => 'price', 'created_at' => 'created_at'],
    ], $moduleId);

    $views->registerView('ecommerce_product', 'card_grid', [
        'fields' => ['name', 'excerpt', 'price', 'stock_status', 'id'],
        'role_fields' => ['title' => 'name', 'subtitle' => 'excerpt'],
        'excerpt_length' => 18,
        'actions' => ['view', 'add_to_cart'],
        'limit' => 12,
        'empty_state' => 'No products found.',
        'sortable_fields' => ['name' => 'name', 'price' => 'price', 'created_at' => 'created_at'],
    ], $moduleId);

    $views->registerView('ecommerce_order', 'compact', [
        'fields' => ['id', 'order_number', 'status', 'total', 'created_at'],
        'actions' => ['view'],
        'limit' => 15,
        'empty_state' => 'No orders yet.',
        'sortable_fields' => ['order_number' => 'order_number', 'total' => 'total', 'created_at' => 'created_at', 'status' => 'status'],
    ], $moduleId);
}
