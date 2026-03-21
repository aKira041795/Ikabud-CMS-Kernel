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
]);

/**
 * Check if a CMS role has at least the given minimum level.
 */
