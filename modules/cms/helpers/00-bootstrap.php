<?php

declare(strict_types=1);

// Load per-widget builder renderers (dispatch-table pattern)
require_once dirname(__DIR__) . '/builder-renderers.php';

// ── Slot Contribution Registration ─────────────────────────────
// Modules declare slot contributions here so themes can render them
// via {ikb_slot name="..."} in shell layouts.
//
// Slot conditions are evaluated at render time against the current
// rendering context (entity_type, view, route, role, capabilities).

\Ikabud\Kernel\Services\SlotRegistry::register('content.after', [
    'id' => 'cms.related_content',
    'component' => 'ikb_entity_list',
    'attrs' => [
        'source' => 'cms_post.recent',
        'view' => 'card_grid',
        'limit' => '3',
    ],
    'priority' => 10,
    'conditions' => [
        'entity_type' => 'cms.post',
        'view' => 'detail',
    ],
]);

\Ikabud\Kernel\Services\SlotRegistry::register('hero', [
    'id' => 'cms.page_hero',
    'component' => 'ikb_section',
    'attrs' => [
        'type' => 'hero',
        'padding' => 'large',
        'bg' => 'primary',
    ],
    'priority' => 10,
    'conditions' => [
        'entity_type' => 'cms.page',
    ],
]);

// Load animation definitions
require_once dirname(__DIR__) . '/animation-definitions.php';

/**
 * CMS Module — Helpers (auto-loaded at boot)
 *
 * Registers:
 * - Auth provider on kernel.auth.authenticate@1 (pipeline)
 * - kernel.home_url hook for CMS roles
 * - kernel.auth_cookie_names hook for CMS cookie
 */

// ── CMS Role Hierarchy ──────────────────────────────────────────────

define('CMS_ROLES', [
    'superadmin'    => 100,
    'administrator' => 90,
    'editor'        => 70,
    'author'        => 50,
    'contributor'   => 30,
    'subscriber'    => 10,
    // Ecommerce-only role: can log in and access orders/downloads but NOT /cms/admin.
    // Level 8 is intentionally below subscriber (10) so all CMS capability gates
    // (minimum 'subscriber') automatically block customers without extra guards.
    'customer'      => 8,
]);

app()->hooks()->on('kernel.request.before_dispatch', static function (array $context): array {
    $dispatchPath = kernelRequestDispatchPath($context);
    if (!in_array($dispatchPath, ['/cms/login', '/cms/register', '/cms/admin'], true)) {
        return $context;
    }

    $user = cmsCtxUser();
    if (!is_array($user)) {
        return $context;
    }

    $source = (string)($user['source'] ?? '');
    $role = (string)($user['role'] ?? '');
    $isCmsUser = $source === 'cms';
    $isKernelDashboardUser = $source === 'kernel' && in_array($role, ['admin', 'superadmin'], true);

    if (!$isCmsUser && !$isKernelDashboardUser) {
        return $context;
    }

    $target = kernelResolveAuthenticatedHomeRedirect($user, false);
    if (!is_string($target) || $target === '') {
        $target = '/cms/admin';
    }

    if ($target === $dispatchPath) {
        return $context;
    }

    return kernelRequestDispatchRedirect($context, $target);
}, 50);

function cmsRuntimeTenantId(): int
{
    $tenantId = function_exists('moduleTenantSettingsTenantId')
        ? moduleTenantSettingsTenantId()
        : null;

    if ($tenantId !== null) {
        return (int) $tenantId;
    }

    try {
        $current = app()->tenant()->current();
        return $current !== null ? (int) $current : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Check if a CMS role has at least the given minimum level.
 */

// ── DiSyL 4.3 fragment-cache invalidation ───────────────────────────
// Public render surface (home.disyl, etc.) wraps content in {cache tags=[...]}.
// Invalidate those tags whenever editor-controlled state changes so cached
// fragments are refreshed on the next request.
foreach ([
    'cms.content.created' => ['cms:content:list','cms:content:item'],
    'cms.content.updated' => ['cms:content:list','cms:content:item'],
    'cms.content.deleted' => ['cms:content:list','cms:content:item'],
    'cms.content.published' => ['cms:content:list','cms:content:item'],
    'cms.content.bulk' => ['cms:content:list','cms:content:item'],
    'cms.builder.document.saved' => ['cms:content:item','cms:content:list'],
    'cms.builder.document.published' => ['cms:content:item','cms:content:list'],
    'cms.builder.document.restored' => ['cms:content:item','cms:content:list'],
    'cms.settings.updated' => ['cms:settings','cms:menu'],
] as $__cmsCacheEvt => $__cmsCacheTags) {
    app()->events()->listen($__cmsCacheEvt, static function (array $payload = []) use ($__cmsCacheTags) {
        try {
            $tenantId = function_exists('cmsRuntimeTenantId') ? cmsRuntimeTenantId() : 0;
            app()->templates()->fragmentStore()->invalidate(
                $__cmsCacheTags,
                $tenantId > 0 ? (string)$tenantId : '_global'
            );
        } catch (\Throwable $e) {
            // Best-effort; never break the write path on cache failure.
            if (function_exists('write_log')) {
                write_log('cms cache invalidate failed: ' . $e->getMessage(), 'warning');
            }
        }
    }, 100, 'cms');
}
