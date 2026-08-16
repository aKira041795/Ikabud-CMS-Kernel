<?php

declare(strict_types=1);

/**
 * Moto Inventory — Sales API handlers.
 */

function motoApiSales(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $filters = [
            'page'      => max(1, (int)($_GET['page'] ?? 1)),
            'per_page'  => max(1, min(250, (int)($_GET['per_page'] ?? 50))),
            'branch_id' => $branch,
            'from'      => (string)($_GET['from'] ?? ''),
            'to'        => (string)($_GET['to'] ?? ''),
            'status'    => (string)($_GET['status'] ?? ''),
        ];
        $result = SaleService::history($ctx, $filters);
        if (!moto_has_permission('moto_inventory.view_cost', $ctx['user']) && !moto_has_permission('moto_inventory.view_profit', $ctx['user'])) {
            foreach ($result['rows'] as &$row) {
                unset($row['cost'], $row['profit']);
            }
            unset($row);
        }
        moto_json_ok($result);
    });
}

function motoApiSaleGet(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $sale = SaleService::saleById($ctx, (int)$params['id'], $branch);
        if ($sale === null) {
            moto_json_error('Sale not found', 404);
            return;
        }
        if (!moto_has_permission('moto_inventory.view_cost', $ctx['user'])
            && !moto_has_permission('moto_inventory.view_profit', $ctx['user'])) {
            unset($sale['cost'], $sale['profit']);
            foreach ($sale['items'] as &$item) {
                unset($item['cost']);
            }
            unset($item);
        }
        moto_json_ok(['sale' => $sale]);
    });
}

function motoApiSaleComplete(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.sell');
        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $lines = $input['lines'] ?? null;
        $customer = isset($input['customer']) ? (string)$input['customer'] : null;
        $idem = (string)($input['idempotency_key'] ?? '');
        // A browser flag cannot grant negative-stock authority. Only a user
        // with the management permission may request the explicit override.
        $override = !empty($input['allow_override'])
            && moto_has_permission('moto_inventory.manage', $ctx['user']);

        if (!is_array($lines)) {
            moto_json_error('Cart is required');
            return;
        }
        $result = SaleService::complete($ctx, $branchId, $lines, $customer, $idem !== '' ? $idem : null, $override);
        moto_json_ok($result, 201);
    });
}

function motoApiSaleUndo(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.sell');
        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $result = SaleService::undoLatest($ctx, $branchId);
        moto_json_ok($result);
    });
}

function motoApiSaleVoid(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.void');
        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $result = SaleService::void($ctx, $branchId, (int)$params['id']);
        moto_json_ok($result);
    });
}
