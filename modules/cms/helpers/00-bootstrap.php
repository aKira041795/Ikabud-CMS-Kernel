<?php

declare(strict_types=1);

// Load per-widget builder renderers (dispatch-table pattern)
require_once dirname(__DIR__) . '/builder-renderers.php';

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
    if ($dispatchPath !== '/cms/login' && $dispatchPath !== '/cms/register') {
        return $context;
    }

    $user = cmsCtxUser();
    if (is_array($user) && ($user['source'] ?? '') === 'cms') {
        $target = kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/cms/admin';
        return kernelRequestDispatchRedirect($context, $target);
    }

    if (is_array($user) && ($user['source'] ?? '') === 'kernel' && ($user['role'] ?? '') === 'admin') {
        return kernelRequestDispatchRedirect($context, '/cms/admin');
    }

    return $context;
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
foreach (['cms.content.created','cms.content.updated','cms.content.deleted','cms.content.published','cms.content.bulk'] as $__cmsCacheEvt) {
    app()->events()->listen($__cmsCacheEvt, static function (array $payload = []) {
        try {
            $tenantId = function_exists('cmsRuntimeTenantId') ? cmsRuntimeTenantId() : 0;
            app()->templates()->fragmentStore()->invalidate(
                ['cms:content:list'],
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
