<?php
$f = 'modules/wms/helpers/70-financial.php';
$content = file_get_contents($f);

// 1. fix fetchAll
$content = str_replace('$db->fetchAll(', 'wmsFetchAll(', $content);

// 2. fix fetchColumn 1
$content = str_replace(
    "\$avgCost = (float)\$db->fetchColumn(\n                    \"SELECT AVG(unit_cost) FROM wms_movements \n                     WHERE product_id = ? AND qty > 0 AND unit_cost IS NOT NULL AND unit_cost > 0\",\n                    [\$p['id']]\n                ) ?: 0.0;",
    "\$avgCostObj = wmsFetchOne(\n                    \"SELECT AVG(unit_cost) as avg FROM wms_movements \n                     WHERE product_id = ? AND qty > 0 AND unit_cost IS NOT NULL AND unit_cost > 0\",\n                    [\$p['id']]\n                );\n                \$avgCost = \$avgCostObj ? (float)\$avgCostObj['avg'] : 0.0;",
    $content
);

// 3. fix fetchColumn 2
$content = str_replace(
    "\$defaultLocationId = (int)\$db->fetchColumn('SELECT id FROM wms_locations WHERE warehouse_id = ? AND deleted_at IS NULL ORDER BY is_active DESC, code ASC LIMIT 1', [\$po['warehouse_id']]);",
    "\$locObj = wmsFetchOne('SELECT id FROM wms_locations WHERE warehouse_id = ? AND deleted_at IS NULL ORDER BY is_active DESC, code ASC LIMIT 1', [\$po['warehouse_id']]);\n        \$defaultLocationId = \$locObj ? (int)\$locObj['id'] : 0;",
    $content
);

file_put_contents($f, $content);
