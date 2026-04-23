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
