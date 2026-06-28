<?php

declare(strict_types=1);

/**
 * CMS module — entity-view contract registration.
 *
 * Registers view contracts with the Kernel OS EntityViewResolver so that
 * CMS content types can be rendered via governed DiSyL components:
 *
 *   {ikb_entity_list source="cms.posts.recent" view="card_grid" /}
 *   {ikb_entity_detail source="cms.page" id="42" view="detailed" /}
 *
 * Each content type gets:
 *  - default, compact, card_grid, and admin_row views
 *  - declared fields, actions, limits, and empty-state messages
 *  - capability gates where appropriate
 *
 * This is the Phase 3 module-adoption milestone: the first module to use
 * the EntityViewResolver contract system.
 */

// ── Register during CMS module bootstrap ──

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();

    // ── CMS Pages ──
    $views->registerView('cms_page', 'default', [
        'fields' => ['id', 'title', 'slug', 'status', 'author_name', 'created_at', 'updated_at'],
        'actions' => ['view', 'edit'],
        'limit' => 25,
        'sort' => ['field' => 'updated_at', 'direction' => 'desc'],
        'empty_state' => 'No pages yet. Create your first page in the CMS admin.',
        'exportable' => true,
        'capability' => 'cms.content.list@1',
    ]);

    $views->registerView('cms_page', 'compact', [
        'fields' => ['title', 'status', 'updated_at'],
        'actions' => ['view'],
        'limit' => 15,
        'sort' => ['field' => 'updated_at', 'direction' => 'desc'],
        'empty_state' => 'No pages found.',
    ]);

    $views->registerView('cms_page', 'admin_row', [
        'fields' => ['id', 'title', 'status', 'author_name', 'created_at'],
        'actions' => ['view', 'edit', 'delete'],
        'limit' => 50,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No pages in this collection.',
    ]);

    // ── CMS Posts / Blog ──
    $views->registerView('cms_post', 'default', [
        'fields' => ['id', 'title', 'slug', 'status', 'excerpt', 'author_name', 'published_at'],
        'actions' => ['view', 'edit'],
        'limit' => 25,
        'sort' => ['field' => 'published_at', 'direction' => 'desc'],
        'empty_state' => 'No posts published yet.',
        'exportable' => true,
    ]);

    $views->registerView('cms_post', 'card_grid', [
        'fields' => ['title', 'excerpt', 'image', 'author_name', 'published_at'],
        'actions' => ['view'],
        'limit' => 12,
        'sort' => ['field' => 'published_at', 'direction' => 'desc'],
        'empty_state' => 'No posts to display.',
    ]);

    $views->registerView('cms_post', 'compact', [
        'fields' => ['title', 'published_at'],
        'actions' => ['view'],
        'limit' => 10,
        'sort' => ['field' => 'published_at', 'direction' => 'desc'],
        'empty_state' => 'No recent posts.',
    ]);

    $views->registerView('cms_post', 'admin_row', [
        'fields' => ['id', 'title', 'status', 'author_name', 'published_at'],
        'actions' => ['view', 'edit', 'delete'],
        'limit' => 50,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No posts yet.',
    ]);

    // ── CMS Products (ecommerce) ──
    $views->registerView('cms_product', 'default', [
        'fields' => ['id', 'name', 'price', 'status', 'image', 'created_at'],
        'actions' => ['view', 'edit'],
        'limit' => 25,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No products in the catalog.',
        'exportable' => true,
    ]);

    $views->registerView('cms_product', 'card_grid', [
        'fields' => ['name', 'price', 'image', 'status'],
        'actions' => ['view', 'add_to_cart'],
        'limit' => 20,
        'sort' => ['field' => 'name', 'direction' => 'asc'],
        'empty_state' => 'No products available.',
    ]);

    $views->registerView('cms_product', 'compact', [
        'fields' => ['name', 'price'],
        'actions' => ['view'],
        'limit' => 10,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No featured products.',
    ]);

    $views->registerView('cms_product', 'admin_row', [
        'fields' => ['id', 'name', 'price', 'status', 'inventory', 'created_at'],
        'actions' => ['view', 'edit', 'delete'],
        'limit' => 50,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No products in catalog.',
    ]);

    // ── CMS Users ──
    $views->registerView('cms_user', 'admin_row', [
        'fields' => ['id', 'display_name', 'email', 'role', 'is_active', 'created_at'],
        'actions' => ['view', 'edit'],
        'limit' => 50,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No users found.',
        'capability' => 'cms.content.list@1',
    ]);

    // ── CMS Media ──
    $views->registerView('cms_media', 'card_grid', [
        'fields' => ['filename', 'thumbnail_url', 'file_size', 'mime_type', 'created_at'],
        'actions' => ['view', 'delete'],
        'limit' => 30,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No media uploaded yet.',
    ]);
}
