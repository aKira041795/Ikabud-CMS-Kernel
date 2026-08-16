<?php

declare(strict_types=1);

/**
 * Moto Inventory — User management API handlers.
 *
 * Administers kernel users (login/password lives in the kernel `users` table;
 * kernel auth stays the identity authority) plus per-tenant module roles
 * (moto_user_roles) and branch memberships (moto_user_branches).
 */

function motoApiUsers(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        moto_json_ok(['users' => moto_list_users($ctx)]);
    });
}

function motoApiUserCreate(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $result = moto_create_kernel_user($ctx, moto_input());
        moto_json_ok($result, 201);
    });
}

function motoApiUserPassword(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $userId = (int)($params['id'] ?? 0);
        $input = moto_input();
        moto_set_user_password($ctx, $userId, (string)($input['password'] ?? ''));
        moto_json_ok(['id' => $userId]);
    });
}

function motoApiUserRole(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $userId = (int)($params['id'] ?? 0);
        $input = moto_input();
        $motoRole = trim((string)($input['moto_role'] ?? ''));
        if ($motoRole === '') {
            moto_json_error('moto_role is required');
            return;
        }
        moto_set_user_moto_role($ctx, $userId, $motoRole);
        moto_json_ok(['id' => $userId, 'moto_role' => $motoRole]);
    });
}

function motoApiUserStatus(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $userId = (int)($params['id'] ?? 0);
        $input = moto_input();
        moto_set_user_active($ctx, $userId, !empty($input['is_active']));
        moto_json_ok(['id' => $userId]);
    });
}

function motoApiUserBranch(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $userId = (int)($params['id'] ?? 0);
        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $assigned = !empty($input['assigned']);
        if ($branchId <= 0) {
            moto_json_error('branch_id is required');
            return;
        }
        moto_assign_user_branch($ctx, $branchId, $userId, $assigned);
        moto_json_ok(['user_id' => $userId, 'branch_id' => $branchId, 'assigned' => $assigned]);
    });
}
