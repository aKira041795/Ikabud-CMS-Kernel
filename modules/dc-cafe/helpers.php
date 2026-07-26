<?php
/**
 * DC Cafe POS Module — Helpers
 *
 * Scoped context helpers following the example-notes convention.
 * Prefix: dc (for DC Cafe).
 *
 * @see modules/example-notes/helpers.php
 */

declare(strict_types=1);

/**
 * Returns the scoped ModuleContext for dc-cafe.
 */
function dcCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('dc-cafe');
    if (!$ctx) {
        throw new \RuntimeException('DC Cafe module context unavailable');
    }
    return $ctx;
}

/**
 * Returns the scoped ModuleDB for dc-cafe.
 */
function dcDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return dcCtx()->db();
}

/**
 * Returns the decoded request input (JSON or form).
 */
function dcInput(?string $key = null, mixed $default = null): mixed
{
    return dcCtx()->input($key, $default);
}

/**
 * Renders a DiSyL template from this module's template directory.
 */
function dcRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/dc-cafe/')
        ? $template
        : 'modules/dc-cafe/' . ltrim($template, '/');

    return dcCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

/**
 * Returns JSON response and exits.
 */
function dcJsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Returns error JSON response and exits.
 */
function dcJsonError(string $message, int $status = 400): void
{
    dcJsonResponse(['ok' => false, 'error' => $message], $status);
}

/**
 * Base URL for DC Cafe routes.
 */
function dcBaseUrl(): string
{
    return '/dc-cafe';
}

/**
 * Resolve the effective catalog store for a requested branch.
 *
 * DC Cafe currently seeds menu products into a primary store only. Branches
 * without store-local product rows should still see and sell the shared menu.
 */
function dcCatalogStoreId(int $requestedStoreId): int
{
    $db = dcDb();

    if ($requestedStoreId > 0) {
        $row = $db->query(
            "SELECT COUNT(*) AS cnt
             FROM dc_products
             WHERE store_id = ? AND is_active = 1",
            [$requestedStoreId]
        )->fetch(\PDO::FETCH_ASSOC);
        if ((int) ($row['cnt'] ?? 0) > 0) {
            return $requestedStoreId;
        }
    }

    $fallback = $db->query(
        "SELECT store_id
         FROM dc_products
         WHERE is_active = 1
         GROUP BY store_id
         ORDER BY COUNT(*) DESC, store_id ASC
         LIMIT 1"
    )->fetch(\PDO::FETCH_ASSOC);

    return (int) ($fallback['store_id'] ?? $requestedStoreId ?: 1);
}

// ── Capability Handler Map ───────────────────────────────────────

function dc_cafe_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1'   => 'dc_cap_kernel_auth_authenticate_1',
        'entity.list.dc_product@1'     => 'dc_cap_entity_list_product_1',
        'entity.list.dc_product_stock@1' => 'dc_cap_entity_list_product_stock_1',
        'entity.get.dc_product@1'      => 'dc_cap_entity_get_product_1',
        'entity.list.dc_order@1'       => 'dc_cap_entity_list_order_1',
        'entity.get.dc_order@1'        => 'dc_cap_entity_get_order_1',
        'entity.list.dc_customer@1'    => 'dc_cap_entity_list_customer_1',
        'entity.get.dc_customer@1'     => 'dc_cap_entity_get_customer_1',
        'entity.list.dc_inventory@1'   => 'dc_cap_entity_list_inventory_1',
    ];
}

// Load entity view capability implementations at module registration time
// so is_callable() checks in module-routes.php can find them.
require_once __DIR__ . '/helpers/entity-views.php';
