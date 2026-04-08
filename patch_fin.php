<?php
$f = 'modules/wms/helpers/70-financial.php';
$c = file_get_contents($f);
// Need to add warehouse_id to PO or we query it from default configs? 
// PO might be for a warehouse. Let's add warehouse_id to wms_purchase_orders table.
// And rewrite wmsPurchaseOrderCreate / wmsPurchaseOrderSubmit.
