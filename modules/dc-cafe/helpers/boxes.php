<?php
/**
 * DC Cafe — Box (variable product) composition helpers.
 *
 * A "box" is a product with slot_count > 0. It is priced as a whole and consumes
 * N component products (doughnut flavours). The box itself holds no stock
 * (has_stock = 0) — the components carry the inventory.
 *
 * Component stock leaves the branch as a *pullout*, not a direct sale: the
 * revenue belongs to the box. Movements still use movement_type = 'sale' (so the
 * void handler can restore them) but carry
 *   consumption_channel = 'bundle'  and  bundle_product_id = <box>
 * which is what the reconciliation uses to separate box consumption from
 * counter sales.
 */

declare(strict_types=1);

/**
 * Whether a product row describes a box/bundle container.
 */
function dcBoxIsContainer(array $product): bool
{
    return (int) ($product['slot_count'] ?? 0) > 0;
}

/**
 * Resolve a box's rules and the products eligible to fill its slots.
 *
 * @param array $box     Product row including slot_count/component_* columns.
 * @param int   $storeId Branch the sale happens in (stock availability).
 *
 * @return array{slot_count:int,min_price:?float,max_price:?float,category_id:int,
 *               standard_set:array<int,array>,choices:array<int,array>}
 */
function dcBoxDefinition(array $box, int $storeId): array
{
    $slotCount = (int) ($box['slot_count'] ?? 0);
    if ($slotCount <= 0) {
        throw new \InvalidArgumentException('Product is not a box');
    }

    $db = dcDb();
    $catalogStoreId = dcCatalogStoreId($storeId);
    $categoryId = (int) ($box['component_category_id'] ?? 0);
    $minPrice = ($box['component_min_price'] ?? null) !== null ? (float) $box['component_min_price'] : null;
    $maxPrice = ($box['component_max_price'] ?? null) !== null ? (float) $box['component_max_price'] : null;

    // The standard set: what normally goes in the box.
    $standardRows = $db->query(
        "SELECT pc.slot_no, pc.component_product_id, pc.quantity, pc.substitutable
         FROM dc_product_components pc
         WHERE pc.product_id = ?
         ORDER BY pc.slot_no ASC",
        [(int) $box['product_id']]
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Eligible components: same catalog, active, in the declared category and
    // inside the price band. Boxes are excluded so a box can never nest in a box.
    $sql = "SELECT p.product_id, p.name, p.base_price, p.has_stock, p.category_id,
                   COALESCE(pss.on_hand_qty, 0) AS branch_stock
            FROM dc_products p
            LEFT JOIN dc_product_store_stock pss
                   ON pss.product_id = p.product_id AND pss.store_id = ?
            WHERE p.store_id = ?
              AND p.is_active = 1
              AND p.slot_count IS NULL";
    $params = [$storeId, $catalogStoreId];

    if ($categoryId > 0) {
        $sql .= " AND p.category_id = ?";
        $params[] = $categoryId;
    }
    if ($minPrice !== null) {
        $sql .= " AND p.base_price >= ?";
        $params[] = $minPrice;
    }
    if ($maxPrice !== null) {
        $sql .= " AND p.base_price <= ?";
        $params[] = $maxPrice;
    }
    $sql .= " ORDER BY p.base_price DESC, p.name ASC";

    $choiceRows = $db->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

    $choices = [];
    foreach ($choiceRows as $row) {
        $choices[(int) $row['product_id']] = [
            'product_id'    => (int) $row['product_id'],
            'name'          => (string) $row['name'],
            'price'         => (float) $row['base_price'],
            'has_stock'     => (int) $row['has_stock'] === 1,
            'branch_stock'  => (float) $row['branch_stock'],
        ];
    }

    $standardSet = [];
    foreach ($standardRows as $row) {
        $componentId = (int) $row['component_product_id'];
        $choice = $choices[$componentId] ?? null;
        $standardSet[] = [
            'slot_no'       => (int) $row['slot_no'],
            'product_id'    => $componentId,
            'quantity'      => (float) $row['quantity'],
            'substitutable' => (int) $row['substitutable'] === 1,
            'name'          => $choice['name'] ?? null,
            'price'         => $choice['price'] ?? null,
            'branch_stock'  => $choice['branch_stock'] ?? null,
            // A standard flavour that is out of stock forces a swap.
            'available'     => $choice !== null
                && (!$choice['has_stock'] || $choice['branch_stock'] >= (float) $row['quantity']),
        ];
    }

    return [
        'slot_count'   => $slotCount,
        'min_price'    => $minPrice,
        'max_price'    => $maxPrice,
        'category_id'  => $categoryId,
        'standard_set' => $standardSet,
        'choices'      => array_values($choices),
    ];
}

/**
 * Validate a cashier's slot selection and return canonical components.
 *
 * @param array $box        Product row for the box.
 * @param array $definition Result of dcBoxDefinition().
 * @param array $selection  List of product_ids, one entry per slot.
 * @param array $standard   Standard set (for substituted_from provenance).
 *
 * @return array<int,array{product_id:int,quantity:float,slot_no:int,substituted_from:?int,name:string}>
 */
function dcBoxResolveSelection(
    array $box,
    array $definition,
    array $selection,
    array $standard = []
): array {
    $slotCount = (int) $definition['slot_count'];
    $label = (string) ($box['name'] ?? 'box');

    $selection = array_values($selection);
    if (count($selection) !== $slotCount) {
        dcJsonError(
            "{$label} needs exactly {$slotCount} item(s); " . count($selection) . ' provided'
        );
    }

    $eligible = [];
    foreach ($definition['choices'] as $choice) {
        $eligible[(int) $choice['product_id']] = $choice;
    }

    $resolved = [];
    foreach ($selection as $index => $componentId) {
        $componentId = (int) $componentId;
        $slotNo = $index + 1;

        if ($componentId <= 0) {
            dcJsonError("{$label}: slot {$slotNo} has no item selected");
        }
        $choice = $eligible[$componentId] ?? null;
        if ($choice === null) {
            dcJsonError(
                "{$label}: item for slot {$slotNo} is not allowed in this box " .
                '(wrong category, outside the price band, or inactive)'
            );
        }

        $standardId = null;
        foreach ($standard as $std) {
            if ((int) $std['slot_no'] === $slotNo) {
                $standardId = (int) $std['product_id'];
                break;
            }
        }

        $resolved[] = [
            'product_id'        => $componentId,
            'quantity'          => 1.0,
            'slot_no'           => $slotNo,
            'name'              => (string) $choice['name'],
            'substituted_from'  => ($standardId !== null && $standardId !== $componentId)
                ? $standardId
                : null,
        ];
    }

    return $resolved;
}
