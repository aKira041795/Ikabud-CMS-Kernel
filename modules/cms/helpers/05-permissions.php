<?php

declare(strict_types=1);

/**
 * CMS Granular Permission System
 *
 * Defines all capabilities and their default role assignments.
 * Administrators can override the defaults via CMS settings (role_permissions key).
 *
 * Architecture:
 *  - Every action maps to a named capability (e.g. 'content.create', 'media.upload')
 *  - Each capability has a default minimum role
 *  - `cmsUserCan($user, $cap)` checks the effective role for a capability
 *  - `cmsRequireCap($cap)` is the gate function (returns user or exits 401/403)
 *  - Overrides stored in CMS settings as JSON: { "content.publish": "editor", ... }
 */

// ── Default Capability → Minimum Role Map ────────────────────────────

define('CMS_DEFAULT_CAPABILITIES', [
    // ── Dashboard ────────────────────────────────────────────────────
    'dashboard.view'            => 'subscriber',

    // ── Content ──────────────────────────────────────────────────────
    'content.list'              => 'contributor',
    'content.read'              => 'contributor',
    'content.create'            => 'contributor',
    'content.edit_own'          => 'contributor',
    'content.edit_any'          => 'editor',
    'content.publish'           => 'author',
    'content.trash'             => 'author',
    'content.restore'           => 'editor',
    'content.duplicate'         => 'author',
    'content.bulk_actions'      => 'contributor',
    'content.autosave'          => 'contributor',
    'content.schedule'          => 'administrator',

    // ── Builder ──────────────────────────────────────────────────────
    'builder.access'            => 'contributor',
    'builder.save'              => 'contributor',
    'builder.publish'           => 'author',
    'builder.preview'           => 'contributor',
    'builder.revisions'         => 'contributor',
    'builder.revision_restore'  => 'contributor',
    'builder.reusable_list'     => 'contributor',
    'builder.reusable_save'     => 'editor',
    'builder.reusable_delete'   => 'administrator',
    'builder.template_list'     => 'contributor',
    'builder.template_save'     => 'editor',
    'builder.template_delete'   => 'editor',
    'builder.widget_list'       => 'contributor',
    'builder.dynamic_sources'   => 'contributor',

    // ── Media ────────────────────────────────────────────────────────
    'media.list'                => 'contributor',
    'media.upload'              => 'author',
    'media.edit'                => 'author',
    'media.delete'              => 'editor',

    // ── Taxonomy ─────────────────────────────────────────────────────
    'categories.list'           => 'contributor',
    'categories.create'         => 'editor',
    'categories.edit'           => 'editor',
    'categories.delete'         => 'editor',
    'categories.manage'         => 'editor',
    'tags.list'                 => 'contributor',
    'tags.create'               => 'editor',
    'tags.delete'               => 'editor',

    // ── Menus ────────────────────────────────────────────────────────
    'menus.manage'              => 'editor',

    // ── Redirects ────────────────────────────────────────────────────
    'redirects.view'            => 'editor',
    'redirects.create'          => 'editor',
    'redirects.delete'          => 'administrator',

    // ── Saved Blocks ─────────────────────────────────────────────────
    'saved_blocks.list'         => 'contributor',
    'saved_blocks.create'       => 'editor',
    'saved_blocks.edit'         => 'editor',
    'saved_blocks.delete'       => 'administrator',

    // ── Revisions ────────────────────────────────────────────────────
    'revisions.list'            => 'contributor',
    'revisions.view'            => 'contributor',
    'revisions.restore'         => 'editor',

    // ── Content Types ────────────────────────────────────────────────
    'content_types.manage'      => 'administrator',

    // ── Users ────────────────────────────────────────────────────────
    'users.manage'              => 'administrator',

    // ── Settings ─────────────────────────────────────────────────────
    'settings.manage'           => 'administrator',

    // ── Customizer ───────────────────────────────────────────────────
    'customizer.manage'         => 'administrator',

    // ── Import/Export ────────────────────────────────────────────────
    'import_export.manage'      => 'administrator',

    // ── Workflow ─────────────────────────────────────────────────────
    'workflow.view'             => 'contributor',
    'workflow.transition'       => 'contributor',

    // ── AI ───────────────────────────────────────────────────────────
    'ai.summary'                => 'contributor',
    'ai.seo'                    => 'contributor',
    'ai.refine'                 => 'contributor',
    'ai.automation.manage'      => 'administrator',
]);

/**
 * Human-readable category labels for grouping capabilities in the admin UI.
 */
function cmsCapabilityGroups(): array
{
    return [
        'Dashboard'     => ['dashboard.'],
        'Content'       => ['content.'],
        'Builder'       => ['builder.'],
        'Media'         => ['media.'],
        'Taxonomy'      => ['categories.', 'tags.'],
        'Menus'         => ['menus.'],
        'Redirects'     => ['redirects.'],
        'Saved Blocks'  => ['saved_blocks.'],
        'Revisions'     => ['revisions.'],
        'Content Types' => ['content_types.'],
        'Users'         => ['users.'],
        'Settings'      => ['settings.'],
        'Customizer'    => ['customizer.'],
        'Import/Export' => ['import_export.'],
        'Workflow'      => ['workflow.'],
        'AI'            => ['ai.'],
    ];
}

/**
 * Human-readable labels for each capability (for admin UI).
 */
function cmsCapabilityLabels(): array
{
    return [
        'dashboard.view'            => 'View Dashboard',
        'content.list'              => 'List Content',
        'content.read'              => 'Read Content (API)',
        'content.create'            => 'Create Content',
        'content.edit_own'          => 'Edit Own Content',
        'content.edit_any'          => 'Edit Any Content',
        'content.publish'           => 'Publish Content',
        'content.trash'             => 'Trash Content',
        'content.restore'           => 'Restore Trashed Content',
        'content.duplicate'         => 'Duplicate Content',
        'content.bulk_actions'      => 'Bulk Actions',
        'content.autosave'          => 'Autosave',
        'content.schedule'          => 'Scheduled Publishing (cron)',
        'builder.access'            => 'Access Page Builder',
        'builder.save'              => 'Save Builder Documents',
        'builder.publish'           => 'Publish Builder Documents',
        'builder.preview'           => 'Preview Builder Documents',
        'builder.revisions'         => 'View Builder Revisions',
        'builder.revision_restore'  => 'Restore Builder Revisions',
        'builder.reusable_list'     => 'List Reusable Sections',
        'builder.reusable_save'     => 'Save Reusable Sections',
        'builder.reusable_delete'   => 'Delete Reusable Sections',
        'builder.template_list'     => 'List Templates',
        'builder.template_save'     => 'Save Templates',
        'builder.template_delete'   => 'Delete Templates',
        'builder.widget_list'       => 'List Widgets',
        'builder.dynamic_sources'   => 'Dynamic Data Sources',
        'media.list'                => 'List Media',
        'media.upload'              => 'Upload Media',
        'media.edit'                => 'Edit Media',
        'media.delete'              => 'Delete Media',
        'categories.list'           => 'List Categories',
        'categories.create'         => 'Create Categories',
        'categories.edit'           => 'Edit Categories',
        'categories.delete'         => 'Delete Categories',
        'categories.manage'         => 'Manage Categories Page',
        'tags.list'                 => 'List Tags',
        'tags.create'               => 'Create Tags',
        'tags.delete'               => 'Delete Tags',
        'menus.manage'              => 'Manage Menus',
        'redirects.view'            => 'View Redirects',
        'redirects.create'          => 'Create Redirects',
        'redirects.delete'          => 'Delete Redirects',
        'saved_blocks.list'         => 'List Saved Blocks',
        'saved_blocks.create'       => 'Create Saved Blocks',
        'saved_blocks.edit'         => 'Edit Saved Blocks',
        'saved_blocks.delete'       => 'Delete Saved Blocks',
        'revisions.list'            => 'List Revisions',
        'revisions.view'            => 'View Revisions',
        'revisions.restore'         => 'Restore Revisions',
        'content_types.manage'      => 'Manage Content Types',
        'users.manage'              => 'Manage Users',
        'settings.manage'           => 'Manage Settings',
        'customizer.manage'         => 'Manage Customizer',
        'import_export.manage'      => 'Import / Export',
        'workflow.view'             => 'View Workflow State',
        'workflow.transition'       => 'Change Workflow State',
        'ai.summary'               => 'Generate AI Summary',
        'ai.seo'                    => 'Generate AI SEO',
        'ai.refine'                 => 'Refine AI-Generated Content',
        'ai.automation.manage'      => 'Manage AI Content Automation',
    ];
}

// ── Runtime Functions ────────────────────────────────────────────────

/**
 * Get the merged capability map (defaults + admin overrides from settings).
 * Cached in-process.
 *
 * @return array<string, string>  capability => minimum_role
 */
function cmsCapabilityMap(): array
{
    $tid = cmsRuntimeTenantId();
    $cacheKey = 'cms_cap_map_cached_t' . $tid;
    $valueKey = 'cms_cap_map_t' . $tid;

    if (isset($GLOBALS[$cacheKey])) {
        return $GLOBALS[$valueKey];
    }

    $defaults  = CMS_DEFAULT_CAPABILITIES;
    $overrides = [];

    // Load overrides from CMS settings (stored as JSON string)
    try {
        $settings = readCmsSettings();
    } catch (\Throwable $e) {
        $settings = [];
    }
    $raw = $settings['role_permissions'] ?? '';
    if ($raw !== '' && is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $overrides = $decoded;
        }
    }

    // Merge — only allow known capabilities and valid roles
    $validRoles  = array_keys(CMS_ROLES);
    $validCaps   = array_keys($defaults);
    $map = $defaults;

    foreach ($overrides as $cap => $role) {
        if (in_array($cap, $validCaps, true) && in_array($role, $validRoles, true)) {
            $map[$cap] = $role;
        }
    }

    $GLOBALS[$cacheKey] = true;
    $GLOBALS[$valueKey] = $map;
    return $map;
}

/**
 * Clear the in-process capability map cache.
 * Call after saving permission overrides.
 */
function cmsClearCapMapCache(): void
{
    $tid = cmsRuntimeTenantId();
    unset($GLOBALS['cms_cap_map_cached_t' . $tid], $GLOBALS['cms_cap_map_t' . $tid]);
}

/**
 * Check if a user has a specific capability.
 *
 * @param array  $user  User context array (must have 'role', 'source')
 * @param string $cap   Capability name (e.g. 'content.publish')
 * @return bool
 */
function cmsUserCan(array $user, string $cap): bool
{
    $source = (string)($user['source'] ?? '');
    $role   = (string)($user['role'] ?? '');

    // Kernel admin = superadmin-equivalent, can do everything
    if ($source === 'kernel' && $role === 'admin') {
        return true;
    }

    // Must be a CMS user
    if ($source !== 'cms') {
        return false;
    }

    // Superadmins can always do everything
    if ($role === 'superadmin') {
        return true;
    }

    $map     = cmsCapabilityMap();
    $minRole = $map[$cap] ?? null;

    // Unknown capability → deny
    if ($minRole === null) {
        return false;
    }

    return cmsRoleAtLeast($role, $minRole);
}

/**
 * Gate function — require a specific capability.
 * Returns the authenticated user array on success.
 * Exits with 401 (unauthenticated) or 403 (insufficient permission).
 *
 * @param string $cap  Capability name (e.g. 'content.publish')
 * @return array  The authenticated user
 */
function cmsRequireCap(string $cap): array
{
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isApiRoute = str_starts_with($requestUri, '/api/');
    $user = cmsCtxUser();

    if (!$user) {
        if ($isApiRoute) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Auth required']);
        } else {
            cmsRedirect('/cms/login');
        }
        exit;
    }

    if (!cmsUserCan($user, $cap)) {
        http_response_code(403);
        if ($isApiRoute) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Permission denied', 'required' => $cap]);
        } else {
            echo cmsRender('pages/404.disyl', ['page_title' => 'Access Denied']);
        }
        exit;
    }

    return $user;
}

/**
 * Get all capabilities the current user has, for UI feature-gating.
 *
 * @param array $user  User context array
 * @return array<string, bool>  capability => true/false
 */
function cmsUserCapabilities(array $user): array
{
    $map = cmsCapabilityMap();
    $result = [];
    foreach ($map as $cap => $minRole) {
        $result[$cap] = cmsUserCan($user, $cap);
    }
    return $result;
}

/**
 * Get role-permission overrides currently saved in settings.
 *
 * @return array<string, string>  capability => role (only overrides, not defaults)
 */
function cmsPermissionOverrides(): array
{
    $settings = readCmsSettings();
    $raw = $settings['role_permissions'] ?? '';
    if ($raw !== '' && is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

/**
 * Save role-permission overrides.
 * Only stores capabilities that differ from defaults.
 *
 * @param array<string, string> $overrides  capability => role
 */
function cmsSavePermissionOverrides(array $overrides): void
{
    $defaults   = CMS_DEFAULT_CAPABILITIES;
    $validRoles = array_keys(CMS_ROLES);
    $clean      = [];

    foreach ($overrides as $cap => $role) {
        // Only store if it's a known capability, valid role, and different from default
        if (isset($defaults[$cap]) && in_array($role, $validRoles, true) && $role !== $defaults[$cap]) {
            $clean[$cap] = $role;
        }
    }

    saveModuleSettings('cms', ['role_permissions' => json_encode($clean)]);
    cmsClearCapMapCache();
    // Also clear settings cache so next readCmsSettings() picks up changes
    $tid = (function_exists('moduleTenantSettingsTenantId') ? moduleTenantSettingsTenantId() : null) ?? 0;
    $GLOBALS['cms_settings_cached_t' . $tid] = false;
}
