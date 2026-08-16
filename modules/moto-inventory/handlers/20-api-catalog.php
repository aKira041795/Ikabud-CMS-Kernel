<?php

declare(strict_types=1);

/**
 * Moto Inventory — Catalog API handlers.
 */

function motoApiMe(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_json_ok([
            'user'          => [
                'id'   => (int)($ctx['user_id'] ?? 0),
                'name' => $ctx['actor_name'],
                'role' => $ctx['role'],
            ],
            'permissions'   => $ctx['permissions'],
            'view_all_branches' => $ctx['view_all_branches'],
            'branches'      => moto_accessible_branches($ctx),
            'settings'      => moto_inventory_settings(),
        ]);
    });
}

function motoApiBranches(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_json_ok([
            'branches' => moto_accessible_branches($ctx),
            'assigned_branch_ids' => $ctx['branch_ids'],
        ]);
    });
}

function motoApiBrands(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        $includeTrashed = (string)($_GET['include_trashed'] ?? '') === '1';
        $includeArchived = (string)($_GET['include_archived'] ?? '') === '1';
        moto_json_ok(CatalogService::brands($ctx, [
            'include_trashed' => $includeTrashed,
            'include_archived' => $includeArchived,
        ]));
    });
}

function motoApiProducts(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        $filters = [
            'page'      => max(1, (int)($_GET['page'] ?? 1)),
            'per_page'  => max(1, min(250, (int)($_GET['per_page'] ?? 50))),
            'q'         => (string)($_GET['q'] ?? ''),
            'brand_id'  => (int)($_GET['brand_id'] ?? 0) ?: null,
            'state'     => (string)($_GET['state'] ?? 'active'),
            'sort'      => (string)($_GET['sort'] ?? 'part_number'),
            'dir'       => (string)($_GET['dir'] ?? 'ASC'),
            'low_stock' => (string)($_GET['low_stock'] ?? ''),
        ];

        // Server-resolved branch scope.
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $filters['branch_id'] = $branch;

        $result = CatalogService::products($ctx, $filters);
        if (!moto_has_permission('moto_inventory.view_cost', $ctx['user'])) {
            foreach ($result['rows'] as &$row) {
                unset($row['cost']);
            }
            unset($row);
        }
        moto_json_ok($result);
    });
}

function motoApiProductGet(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        $productId = (int)($params['id'] ?? 0);
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $product = CatalogService::productById($ctx, $productId, $branch);
        if ($product === null) {
            moto_json_error('Product not found', 404);
            return;
        }
        if (!moto_has_permission('moto_inventory.view_cost', $ctx['user'])) {
            unset($product['cost']);
        }
        moto_json_ok(['product' => $product]);
    });
}

function motoApiBrandCreate(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $result = CatalogService::createBrand($ctx, (string)($input['name'] ?? ''));
        moto_json_ok($result, 201);
    });
}

function motoApiBrandUpdate(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $brandId = (int)($params['id'] ?? 0);
        $input = moto_input();
        $action = (string)($input['action'] ?? '');

        switch ($action) {
            case 'rename':
                $result = CatalogService::renameBrand($ctx, $brandId, (string)($input['name'] ?? ''));
                break;
            case 'archive':
                $result = CatalogService::setBrandArchived($ctx, $brandId, true);
                break;
            case 'restore':
                $result = CatalogService::setBrandArchived($ctx, $brandId, false);
                break;
            case 'trash':
                $result = CatalogService::setBrandTrashed($ctx, $brandId, true);
                break;
            case 'restore_trash':
                $result = CatalogService::setBrandTrashed($ctx, $brandId, false);
                break;
            case 'purge':
                $result = CatalogService::purgeBrand($ctx, $brandId);
                break;
            default:
                moto_json_error('Unknown brand action', 422);
                return;
        }
        moto_json_ok($result);
    });
}

function motoApiProductCreate(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $result = CatalogService::createProduct($ctx, $branchId, $input);
        moto_json_ok($result, 201);
    });
}

function motoApiProductUpdate(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $productId = (int)($params['id'] ?? 0);
        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $result = CatalogService::updateProduct($ctx, $productId, $branchId, $input);
        moto_json_ok($result);
    });
}

function motoApiProductArchive(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $result = CatalogService::setProductArchived($ctx, (int)$params['id'], (int)($input['branch_id'] ?? 0), true);
        moto_json_ok($result);
    });
}

function motoApiProductRestore(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $result = CatalogService::setProductArchived($ctx, (int)$params['id'], (int)($input['branch_id'] ?? 0), false);
        moto_json_ok($result);
    });
}

function motoApiProductDelete(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $result = CatalogService::deleteProduct($ctx, (int)$params['id'], (int)($input['branch_id'] ?? 0));
        moto_json_ok($result);
    });
}
