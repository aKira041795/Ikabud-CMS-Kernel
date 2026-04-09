<?php

declare(strict_types=1);

function wms_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'wms_cap_kernel_auth_authenticate_1',
        'wms.stock.query@1' => 'wms_cap_wms_stock_query_1',
        'wms.stock.reserve@1' => 'wms_cap_wms_stock_reserve_1',
        'wms.stock.release@1' => 'wms_cap_wms_stock_release_1',
        'wms.order.create@1' => 'wms_cap_wms_order_create_1',
        'wms.order.cancel@1' => 'wms_cap_wms_order_cancel_1',
    ];
}
