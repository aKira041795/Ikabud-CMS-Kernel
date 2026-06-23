<?php
require __DIR__ . '/../bootstrap.php';
// Check the most recent attachment
foreach ([1, 502] as $t) {
    $db = app()->dbForTenant($t);
    $rows = $db->query("SELECT id, tenant_id, entity_type, entity_id, original_filename FROM pal_attachments ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo "Tenant $t: found " . count($rows) . " attachments\n";
        foreach ($rows as $r) echo "  id={$r['id']} tenant={$r['tenant_id']} type={$r['entity_type']}/{$r['entity_id']} file={$r['original_filename']}\n";
    } else {
        echo "Tenant $t: no attachments\n";
    }
}
