<?php
/**
 * Cms Akira Navigation Module — Helpers
 *
 * This file is auto-loaded when the module is enabled.
 * Scoped helper functions provide isolated access to module context,
 * database, input, and rendering. Register event listeners here too.
 *
 * @see docs/kernel/module-development-guide.md
 * @see docs/kernel/module-quickstart.md
 */

declare(strict_types=1);

// ── Scoped Context Helpers ───────────────────────────────────────

function canCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-navigation');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Navigation module context unavailable');
    }
    return $ctx;
}

function canDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return canCtx()->db();
}

function canInput(?string $key = null, mixed $default = null): mixed
{
    return canCtx()->input($key, $default);
}

function canRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-navigation/')
        ? $template
        : 'modules/cms-akira-navigation/' . ltrim($template, '/');

    return canCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function cms_akira_navigation_capability_handlers(): array
{
    return [
        'akira.navigation.resolve@1' => 'can_cap_akira_navigation_resolve_1',
    ];
}

function can_cap_akira_navigation_resolve_1(mixed $payload, string $capabilityId = 'akira.navigation.resolve@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $slug = trim((string)($payload['slug'] ?? ''));
    $trail = [
        ['label' => 'Home', 'url' => '/'],
    ];
    if ($slug !== '') {
        $trail[] = ['label' => ucfirst(str_replace('-', ' ', $slug)), 'url' => '/content/' . $slug];
    }

    // Delegate to the canonical CMS navigation/menu authority (modules/cms):
    // real menus, menu locations, and the menu item trees.
    if (function_exists('cmsGetMenus') && function_exists('cmsGetMenuLocations')) {
        try {
            $menus = cmsGetMenus();
            $locations = cmsGetMenuLocations();
            $menuTree = [];
            if (function_exists('cmsGetMenuItemsTree') && is_array($menus)) {
                foreach ($menus as $menu) {
                    $menuId = (int)($menu['id'] ?? 0);
                    $menuTree[] = [
                        'id' => $menuId,
                        'name' => (string)($menu['name'] ?? ''),
                        'location' => (string)($menu['location'] ?? ''),
                        'item_count' => (int)($menu['item_count'] ?? 0),
                        'items' => $menuId > 0 ? cmsGetMenuItemsTree($menuId) : [],
                    ];
                }
            }

            return [
                'ok' => true,
                'data' => [
                    'breadcrumb' => $trail,
                    'menus' => $menus,
                    'menu_locations' => $locations,
                    'menu_tree' => $menuTree,
                    'provider' => 'cms-akira-navigation',
                    'resolved_from' => 'cms',
                ],
            ];
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    return [
        'ok' => true,
        'data' => [
            'breadcrumb' => $trail,
            'provider' => 'cms-akira-navigation',
            'resolved_from' => 'fallback',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-navigation');
