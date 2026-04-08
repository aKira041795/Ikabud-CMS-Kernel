<?php

declare(strict_types=1);

function wmsApiIntelligenceSlotting(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $warehouseId = (int)(wmsInput('warehouse_id', 0));
        $days = (int)(wmsInput('days', 30));
        
        if ($warehouseId <= 0) {
            wmsJsonError('Warehouse ID is required for slotting intelligence.');
        }
        
        $data = wmsIntelligenceSlottingSuggest($warehouseId, $days);
        wmsJsonOk(['data' => $data]);
    });
}

function wmsApiIntelligenceForecast(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $warehouseId = (int)(wmsInput('warehouse_id', 0));
        $days = (int)(wmsInput('days', 30));
        
        if ($warehouseId <= 0) {
            wmsJsonError('Warehouse ID is required for forecasting intelligence.');
        }
        
        $data = wmsIntelligenceForecast($warehouseId, $days);
        wmsJsonOk(['data' => $data]);
    });
}
