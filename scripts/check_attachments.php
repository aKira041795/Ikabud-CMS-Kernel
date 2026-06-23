<?php
require __DIR__ . '/../bootstrap.php';
$db = app()->dbForTenant(502); // palsystem
$tid = (int)(app()->tenant()->current() ?? 502);
echo "Tenant from current(): $tid\n";
$count = $db->query("SELECT COUNT(*) FROM pal_attachments WHERE tenant_id = $tid")->fetchColumn();
echo "Attachments for tenant $tid: $count\n";
$all = $db->query("SELECT * FROM pal_attachments LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $a) {
    echo "  id={$a['id']} entity={$a['entity_type']}/{$a['entity_id']} file={$a['original_filename']} path={$a['file_path']}\n";
}
