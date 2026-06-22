---
name: inventory-costing
description: Movement-first inventory with weighted average costing, cost snapshots
applyTo: "**/services/**"
---

# Inventory Costing

## Movement-first principle
Inventory quantity is **derived** from authorized stock movements. Never directly `UPDATE inventory_balances SET quantity = N`.

## Movement types
| Type | Effect on quantity | Effect on cost |
|---|---|---|
| stock_in | + | Updates average cost |
| issue | - | Uses current average cost |
| return | + | At original issued cost |
| waste | - | No cost change (already expensed) |
| adjustment | +/- | Requires approval |
| reversal | +/- | Reverses original movement |

## Weighted average cost
```php
function calculateNewAverageCost(
    float $currentQty, float $currentAvgCost,
    float $newQty, float $newUnitCost
): float {
    if ($currentQty + $newQty <= 0) return $newUnitCost;
    return (($currentQty * $currentAvgCost) + ($newQty * $newUnitCost))
         / ($currentQty + $newQty);
}
```

## Cost snapshot at issuance
Material issuance cost uses the **average cost at the time of issuance**.

```php
function issueMaterial(int $materialId, float $qty, string $projectId): void
{
    $avgCost = $this->getCurrentAverageCost($materialId);
    // Store the cost snapshot in the movement record
    $this->createMovement([
        'material_id' => $materialId,
        'type'        => 'issue',
        'quantity'    => -$qty,
        'unit_cost'   => $avgCost,  // snapshot
        'total_cost'  => $avgCost * $qty,
        'project_id'  => $projectId,
    ]);
}
```

## Do not recalculate historic costs
Future purchases change the average cost, but already-issued materials keep their original cost snapshot. This prevents project cost fluctuations from unrelated purchases.

## Inventory balance derivation
```php
$balance = $db->query("
    SELECT material_id,
           SUM(CASE WHEN type IN ('stock_in','return','adjustment') THEN quantity ELSE 0 END) -
           SUM(CASE WHEN type = 'issue' THEN ABS(quantity) ELSE 0 END) AS current_qty
    FROM pal_inventory_movements
    WHERE tenant_id = :tid
    GROUP BY material_id
");
```

## Stock availability check
```php
function isStockAvailable(int $materialId, float $requiredQty): bool
{
    $balance = $this->getCurrentBalance($materialId);
    return $balance >= $requiredQty;
}
```

## Reversal of movements
When voiding a purchase or issuance, create a reversal movement entry rather than deleting the original movement.
