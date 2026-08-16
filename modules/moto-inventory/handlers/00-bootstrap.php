<?php

declare(strict_types=1);

/**
 * Moto Inventory — Bootstrap handlers (guards + shared page context).
 */

/**
 * List the branches the current user can access. Server-resolved only.
 */
function moto_accessible_branches(array $ctx): array
{
    $tenantId = (int)$ctx['tenant_id'];
    $db = moto_db($tenantId);

    $stmt = $db->query('SELECT id, branch_key, name, is_active FROM moto_branches WHERE tenant_id = :tid ORDER BY name', [':tid' => $tenantId]);
    $all = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    if ($ctx['view_all_branches']) {
        return $all;
    }
    $assigned = array_flip($ctx['branch_ids'] ?? []);
    return array_values(array_filter($all, static fn (array $b): bool => isset($assigned[(int)$b['id']])));
}

/**
 * Require a page-level permission; redirects to the module dashboard when the
 * user lacks access.
 */
function moto_require_page_permission(array $ctx, string $permission): void
{
    if (in_array($permission, $ctx['permissions'] ?? [], true)) {
        return;
    }
    app()->redirect('/moto-inventory');
    // @codeCoverageIgnoreStart
    exit;
    // @codeCoverageIgnoreEnd
}

function moto_page_permission_gate(array $ctx, string $permission): bool
{
    return in_array($permission, $ctx['permissions'] ?? [], true);
}

/**
 * Build the shared page render context for DiSyL templates.
 */
function moto_page_context(array $ctx, string $page, string $title, array $extra = []): array
{
    $branches = moto_accessible_branches($ctx);
    $csrfToken = function_exists('app') ? app()->csrfToken() : '';
    $mePayload = [
        'user'        => [
            'id'   => (int)($ctx['user_id'] ?? 0),
            'name' => $ctx['actor_name'],
            'role' => $ctx['role'],
        ],
        'permissions'    => $ctx['permissions'],
        'view_all_branches' => (bool)$ctx['view_all_branches'],
        'branches'       => $branches,
        'settings'       => moto_inventory_settings(),
        'csrf'           => $csrfToken,
    ];

    return array_merge([
        'user'           => $ctx['user'],
        'actor_name'     => $ctx['actor_name'],
        'role'           => $ctx['role'],
        'permissions'    => $ctx['permissions'],
        'can_manage'     => in_array('moto_inventory.manage', $ctx['permissions'], true),
        'can_sell'       => in_array('moto_inventory.sell', $ctx['permissions'], true),
        'can_void'       => in_array('moto_inventory.void', $ctx['permissions'], true),
        'can_view_cost'  => in_array('moto_inventory.view_cost', $ctx['permissions'], true),
        'can_view_profit'=> in_array('moto_inventory.view_profit', $ctx['permissions'], true),
        'can_view_audit' => in_array('moto_inventory.view_audit', $ctx['permissions'], true),
        'view_all_branches' => (bool)$ctx['view_all_branches'],
        'branches'       => $branches,
        'active_nav'     => $page,
        'page_title'     => $title,
        'csrf_token'     => $csrfToken,
        'base_url'       => '/moto-inventory',
        'asset_base'     => '/moto-inventory',
        'settings'       => moto_inventory_settings(),
        'app_name'       => 'Moto Inventory',
        'me_payload'     => $mePayload,
        'mi_config_json' => json_encode([
            'csrf' => $csrfToken,
            'me'   => $mePayload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ], $extra);
}

/**
 * JSON error wrapper used by API handlers.
 */
function moto_api_guard(callable $callback): void
{
    try {
        $callback();
    } catch (\RuntimeException $e) {
        moto_json_error($e->getMessage() !== '' ? $e->getMessage() : 'Forbidden', 403);
    } catch (\InvalidArgumentException $e) {
        moto_json_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('moto-inventory handler error: ' . $e->getMessage(), 'error');
        }
        moto_json_error('Unexpected server error.', 500);
    }
}
