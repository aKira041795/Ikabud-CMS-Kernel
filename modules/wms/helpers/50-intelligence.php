<?php

function wmsIntelligenceSlottingSuggest(int $warehouseId, int $days = 30): array
{
    // Phase 4: Slotting Optimization
    // Analyze fast-moving items vs current locations. 
    // Suggest moving high-velocity items to "Prime" (accessible) locations if they aren't already.
    // For simplicity, we define velocity = total qty picked/transferred OUT in the last N days.
    
    $movements = wmsFetchAll(
        "SELECT product_id, location_id, SUM(ABS(qty)) as total_out_qty 
         FROM wms_movements 
         WHERE warehouse_id = ? 
           AND movement_type IN ('out', 'transfer_out') 
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY product_id, location_id 
         ORDER BY total_out_qty DESC",
        [$warehouseId, $days]
    );
    
    // Batch-load locations and products instead of N+1 queries
    if (empty($movements)) {
        return [];
    }
    
    $locationIds = array_unique(array_column($movements, 'location_id'));
    $productIds = array_unique(array_column($movements, 'product_id'));
    
    $locations = [];
    if (!empty($locationIds)) {
        $locList = wmsFetchAll(
            "SELECT id, code, name FROM wms_locations WHERE id IN (" . implode(',', array_fill(0, count($locationIds), '?')) . ")",
            $locationIds
        );
        foreach ($locList as $loc) {
            $locations[(int)$loc['id']] = $loc;
        }
    }
    
    $products = [];
    if (!empty($productIds)) {
        $prodList = wmsFetchAll(
            "SELECT id, sku, name FROM wms_products WHERE id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")",
            $productIds
        );
        foreach ($prodList as $prod) {
            $products[(int)$prod['id']] = $prod;
        }
    }
    
    $suggestions = [];
    foreach ($movements as $m) {
        $loc = $locations[(int)$m['location_id']] ?? ['code' => '', 'name' => ''];
        $prod = $products[(int)$m['product_id']] ?? ['sku' => '', 'name' => ''];
        
        $suggestions[] = [
            'product_id' => $m['product_id'],
            'product_name' => $prod['name'] ?? '',
            'sku' => $prod['sku'] ?? '',
            'current_location_id' => $m['location_id'],
            'current_location_code' => $loc['code'] ?? '',
            'velocity_score' => (float)$m['total_out_qty'],
            'recommendation' => 'Review location accessibility for high-velocity item'
        ];
    }
    
    return $suggestions;
}

function wmsIntelligenceForecast(int $warehouseId, int $days = 30): array
{
    // Phase 4: Simple Forecasting
    // Calculate moving average (daily run rate) over the past N days.
    // Predict how many days of stock remain based on qty_available.
    
    $movements = wmsFetchAll(
        "SELECT product_id, SUM(ABS(qty)) as total_out_qty 
         FROM wms_movements 
         WHERE warehouse_id = ? 
           AND movement_type IN ('out', 'transfer_out')
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY product_id",
        [$warehouseId, $days]
    );

    if (empty($movements)) {
        return [];
    }

    // Batch-load product data instead of N+1 queries
    $productIds = array_column($movements, 'product_id');
    $products = [];
    if (!empty($productIds)) {
        $prodList = wmsFetchAll(
            "SELECT id, sku, name, reorder_point FROM wms_products WHERE id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")",
            $productIds
        );
        foreach ($prodList as $prod) {
            $products[(int)$prod['id']] = $prod;
        }
    }
    
    // Batch-load stock aggregates instead of N+1 queries
    $stocks = [];
    if (!empty($productIds)) {
        $stockList = wmsFetchAll(
            "SELECT product_id, SUM(qty_available) as total_available 
             FROM wms_stocks 
             WHERE warehouse_id = ? AND product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
             GROUP BY product_id",
            array_merge([$warehouseId], $productIds)
        );
        foreach ($stockList as $stock) {
            $stocks[(int)$stock['product_id']] = (float)($stock['total_available'] ?? 0);
        }
    }

    $forecast = [];
    foreach ($movements as $m) {
        $pid = (int)$m['product_id'];
        $totalOut = (float)$m['total_out_qty'];
        $dailyRunRate = $totalOut / max(1, $days);
        $available = $stocks[$pid] ?? 0;
        $daysRemaining = $dailyRunRate > 0 ? ($available / $dailyRunRate) : 999;
        
        $prod = $products[$pid] ?? ['sku' => '', 'name' => '', 'reorder_point' => 0];
        
        $forecast[] = [
            'product_id' => $pid,
            'product_name' => $prod['name'] ?? '',
            'sku' => $prod['sku'] ?? '',
            'qty_available' => $available,
            'daily_run_rate' => round($dailyRunRate, 2),
            'days_remaining' => round($daysRemaining, 1),
            'reorder_point' => (float)($prod['reorder_point'] ?? 0),
            'status' => $daysRemaining <= 7 ? 'critical' : ($daysRemaining <= 14 ? 'warning' : 'healthy')
        ];
    }
    
    // Sort by most critical first
    usort($forecast, function($a, $b) {
        return $a['days_remaining'] <=> $b['days_remaining'];
    });
    
    return $forecast;
}
