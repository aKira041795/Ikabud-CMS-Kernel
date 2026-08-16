<?php

declare(strict_types=1);

/**
 * Moto Inventory — Stock API handlers.
 */

function motoApiStockBalances(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $filters = [
            'branch_id' => $branch,
            'brand_id'  => (int)($_GET['brand_id'] ?? 0) ?: null,
        ];
        $result = StockService::balances($ctx, $filters);
        if (!moto_has_permission('moto_inventory.view_cost', $ctx['user'])) {
            unset($result['stock_value_cost']);
        }
        moto_json_ok($result);
    });
}

function motoApiStockMovements(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $filters = [
            'branch_id'     => $branch,
            'product_id'    => (int)($_GET['product_id'] ?? 0) ?: null,
            'movement_type' => (string)($_GET['movement_type'] ?? ''),
            'from'          => (string)($_GET['from'] ?? ''),
            'to'            => (string)($_GET['to'] ?? ''),
            'limit'         => (int)($_GET['limit'] ?? 100),
        ];
        moto_json_ok(['movements' => StockService::movements($ctx, $filters)]);
    });
}

function motoApiStockAdjust(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $productId = (int)($input['product_id'] ?? 0);
        $delta = moto_qty($input['delta'] ?? 0);
        $reason = (string)($input['reason'] ?? '');
        $idem = (string)($input['idempotency_key'] ?? '');

        $result = StockService::adjust($ctx, $branchId, $productId, $delta, $reason, $idem !== '' ? $idem : null);
        moto_json_ok($result);
    });
}
