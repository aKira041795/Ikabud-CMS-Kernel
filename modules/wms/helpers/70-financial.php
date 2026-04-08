<?php

declare(strict_types=1);

function wmsCalculateInventoryValuation(int $productId = 0): array
{
    $db = wmsDb();
    $method = strtoupper((string)wmsConfigGet('financial.costing_method', 'FIFO'));
    $currency = (string)wmsConfigGet('financial.default_currency', 'USD');

    $where = 's.qty_on_hand > 0 AND p.deleted_at IS NULL';
    $params = [];
    if ($productId > 0) {
        $where .= ' AND p.id = ?';
        $params[] = $productId;
    }

    $products = wmsFetchAll(
        "SELECT p.id, p.sku, p.name, SUM(s.qty_on_hand) AS total_qty
         FROM wms_products p
         JOIN wms_stocks s ON s.product_id = p.id
         WHERE $where
         GROUP BY p.id",
        $params
    );

    $valuations = [];
    $totalValue = 0.0;

    foreach ($products as $p) {
        $qty = (float)$p['total_qty'];
        $val = 0.0;

        if ($qty > 0) {
            if ($method === 'MAC') {
                // Simplified Moving Average Cost: Average of all 'in' unit costs for this product
                // In a perfect ledger, MAC is computed incrementally, but for a fast approximation:
                $avgCost = (float)($db->query(
                    "SELECT AVG(unit_cost) FROM wms_movements 
                     WHERE product_id = ? AND qty > 0 AND unit_cost IS NOT NULL AND unit_cost > 0",
                    [$p['id']]
                )->fetchColumn() ?: 0.0);
                $val = $qty * $avgCost;
            } else {
                // FIFO (Default): Roll forward through 'in' movements until we cover total_qty on hand
                $inbound = wmsFetchAll(
                    "SELECT qty, unit_cost FROM wms_movements 
                     WHERE product_id = ? AND qty > 0 AND unit_cost IS NOT NULL AND unit_cost > 0
                     ORDER BY created_at DESC", // Actually we need newest stock to represent what's "on hand" in FIFO
                    [$p['id']]
                );

                $remaining = $qty;
                foreach ($inbound as $in) {
                    $inQty = (float)$in['qty'];
                    $cost = (float)$in['unit_cost'];
                    if ($remaining >= $inQty) {
                        $val += ($inQty * $cost);
                        $remaining -= $inQty;
                    } else {
                        $val += ($remaining * $cost);
                        $remaining = 0;
                        break;
                    }
                }
            }
        }

        $valuations[] = [
            'product_id' => $p['id'],
            'sku' => $p['sku'],
            'name' => $p['name'],
            'qty_on_hand' => round($qty, 4),
            'valuation' => round($val, 2)
        ];
        $totalValue += $val;
    }

    return [
        'currency' => $currency,
        'method' => $method,
        'total_valuation' => round($totalValue, 2),
        'products' => $valuations
    ];
}

function wmsPurchaseOrderCreate(array $data, int $actorId): int
{
    $db = wmsDb();
    $supplierId = wmsRequirePositiveId((int)($data['supplier_id'] ?? 0), 'Supplier ID');
    $warehouseId = wmsRequirePositiveId((int)($data['warehouse_id'] ?? 0), 'Warehouse ID');
    
    $supplier = wmsFetchOne('SELECT id FROM wms_suppliers WHERE id = ? AND deleted_at IS NULL', [$supplierId]);
    if (!$supplier) throw new RuntimeException('Invalid or deleted supplier');

    if (empty($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException('Purchase order must contain items');
    }

    $db->beginTransaction();
    try {
        $db->execute(
            'INSERT INTO wms_purchase_orders (supplier_id, warehouse_id, expected_delivery_date, notes) VALUES (?, ?, ?, ?)',
            [
                $supplierId,
                $warehouseId,
                empty($data['expected_delivery_date']) ? null : $data['expected_delivery_date'],
                empty($data['notes']) ? null : $data['notes']
            ]
        );

        $poId = (int)$db->lastInsertId();

        foreach ($data['items'] as $item) {
            $prodId = wmsRequirePositiveId((int)($item['product_id'] ?? 0), 'Product ID');
            $qty = wmsNormalizeDecimal($item['qty'] ?? 0);
            $cost = wmsNormalizeDecimal($item['unit_cost'] ?? 0);
            if ($qty <= 0) throw new RuntimeException('Item qty must be greater than zero');

            $db->execute(
                'INSERT INTO wms_purchase_order_items (purchase_order_id, product_id, qty, unit_cost) VALUES (?, ?, ?, ?)',
                [$poId, $prodId, $qty, $cost]
            );
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    wmsLog("Purchase Order #$poId created by user #$actorId");
    return $poId;
}

function wmsPurchaseOrderSubmit(int $poId, int $actorId): int
{
    $db = wmsDb();
    
    $po = wmsFetchOne('SELECT * FROM wms_purchase_orders WHERE id = ?', [$poId]);
    if (!$po || $po['deleted_at']) throw new RuntimeException('PO not found');
    if ($po['status'] !== 'draft') throw new RuntimeException('Only draft POs can be submitted');

    $items = wmsFetchAll('SELECT * FROM wms_purchase_order_items WHERE purchase_order_id = ?', [$poId]);
    $supplier = wmsFetchOne('SELECT id, name FROM wms_suppliers WHERE id = ? AND deleted_at IS NULL LIMIT 1', [(int)$po['supplier_id']]);
    if ($supplier === null) {
        throw new RuntimeException('Supplier not found for purchase order.');
    }
    
    $db->beginTransaction();
    try {
        $db->execute('UPDATE wms_purchase_orders SET status = ? WHERE id = ?', ['submitted', $poId]);
        
        // Create an expected Inbound Delivery for this PO automatically
        $referenceNumber = 'PO-' . str_pad((string)$poId, 6, '0', STR_PAD_LEFT);
        $db->execute(
            'INSERT INTO wms_deliveries (reference_number, supplier_name, supplier_id, warehouse_id, status, expected_at, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$referenceNumber, $supplier['name'], $po['supplier_id'], $po['warehouse_id'], 'pending', $po['expected_delivery_date'], 'Auto-generated from PO #' . $poId, $actorId]
        );
        $deliveryId = (int)$db->lastInsertId();

        $defaultLocationId = (int)($db->query('SELECT id FROM wms_locations WHERE warehouse_id = ? AND deleted_at IS NULL ORDER BY is_active DESC, code ASC LIMIT 1', [$po['warehouse_id']])->fetchColumn() ?: 0);
        if ($defaultLocationId <= 0) {
            throw new RuntimeException('No active location found for the selected warehouse.');
        }
        
        foreach ($items as $item) {
            $db->execute(
                'INSERT INTO wms_delivery_items (delivery_id, product_id, location_id, qty_expected, unit_cost, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [$deliveryId, $item['product_id'], $defaultLocationId, $item['qty'], $item['unit_cost']]
            );
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    wmsLog("Purchase Order #$poId submitted, converting into Delivery #$deliveryId by user #$actorId");
    return $deliveryId;
}
