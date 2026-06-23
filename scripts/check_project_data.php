<?php
require __DIR__ . '/../bootstrap.php';
$pid = 1;
foreach ([1, 502] as $t) {
    $db = app()->dbForTenant($t);
    $exp = $db->query("SELECT COUNT(*) FROM pal_expenses WHERE project_id = $pid AND tenant_id = $t")->fetchColumn();
    $col = $db->query("SELECT COUNT(*) FROM pal_collections WHERE project_id = $pid AND tenant_id = $t")->fetchColumn();
    $po  = $db->query("SELECT COUNT(*) FROM pal_purchases WHERE project_id = $pid AND tenant_id = $t")->fetchColumn();
    echo "Tenant $t: Expenses=$exp Collections=$col POs=$po\n";
}
