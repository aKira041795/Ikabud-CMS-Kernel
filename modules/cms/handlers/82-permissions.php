<?php

declare(strict_types=1);

/**
 * Admin page: Role Permissions matrix.
 */
function cmsAdminPermissions(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    $roles      = array_keys(CMS_ROLES);
    $defaults   = CMS_DEFAULT_CAPABILITIES;
    $overrides  = cmsPermissionOverrides();
    $groups     = cmsCapabilityGroups();
    $labels     = cmsCapabilityLabels();

    // Build the current effective map
    $effective = $defaults;
    $validRoles = $roles;
    foreach ($overrides as $cap => $role) {
        if (isset($defaults[$cap]) && in_array($role, $validRoles, true)) {
            $effective[$cap] = $role;
        }
    }

    echo cmsRender('modules/cms/admin/permissions.disyl', array_merge(cmsAdminContext($user, 'permissions', [
        ['label' => 'Permissions', 'url' => ''],
    ]), [
        'page_title'      => 'Role Permissions',
        'roles_json'      => json_encode($roles),
        'defaults_json'   => json_encode($defaults),
        'effective_json'  => json_encode($effective),
        'groups_json'     => json_encode($groups),
        'labels_json'     => json_encode($labels),
        'overrides_json'  => json_encode($overrides),
        'role_levels_json' => json_encode(CMS_ROLES),
    ]));
}

/**
 * API: Get current permissions map + meta.
 */
function cmsApiPermissionsGet(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');

    app()->json([
        'ok'        => true,
        'defaults'  => CMS_DEFAULT_CAPABILITIES,
        'overrides' => cmsPermissionOverrides(),
        'groups'    => cmsCapabilityGroups(),
        'labels'    => cmsCapabilityLabels(),
        'roles'     => array_keys(CMS_ROLES),
        'role_levels' => CMS_ROLES,
    ]);
}

/**
 * API: Save permissions overrides.
 */
function cmsApiPermissionsSave(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    $input = cmsInput();
    $overrides = $input['overrides'] ?? [];

    if (!is_array($overrides)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'overrides must be an object']);
        exit;
    }

    cmsSavePermissionOverrides($overrides);

    // Audit
    if ($ctx = module('cms')) {
        $ctx->audit('cms.permissions.save', null, 'cms_settings', 'role_permissions', null, [
            'overrides_count' => count($overrides),
        ]);
    }

    echo json_encode(['ok' => true, 'message' => 'Permissions saved']);
    exit;
}

/**
 * API: Reset permissions to defaults.
 */
function cmsApiPermissionsReset(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    cmsSavePermissionOverrides([]);

    if ($ctx = module('cms')) {
        $ctx->audit('cms.permissions.reset', null, 'cms_settings', 'role_permissions');
    }

    echo json_encode(['ok' => true, 'message' => 'Permissions reset to defaults']);
    exit;
}
