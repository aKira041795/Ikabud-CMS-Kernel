<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-integrity-resolution', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/trace.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_integrity_resolution_harness.php');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$origin = 99481; $destination = 99482; $product = 99481;
$delivery = 994801; $duplicateDelivery = 994802; $date = '2032-04-18';
$payload = tempnam(sys_get_temp_dir(), 'dl-integrity-');
$cleanup = static function () use ($db, $origin, $destination, $product, $delivery, $duplicateDelivery): void {
    $ids = [$delivery, $duplicateDelivery]; $marks = '?,?';
    $r = $db->prepare("SELECT id FROM dl_branch_receivings WHERE delivery_id IN ($marks)"); $r->execute($ids);
    $receivings = array_map('intval', $r->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($receivings) {
        $rm = implode(',', array_fill(0, count($receivings), '?'));
        $db->prepare("DELETE FROM audit_logs WHERE module='daily-ledger' AND entity_type='dl_branch_receivings' AND entity_id IN ($rm)")->execute(array_map('strval', $receivings));
    }
    $db->prepare("DELETE FROM audit_logs WHERE module='daily-ledger' AND entity_type='dl_deliveries' AND entity_id IN ($marks)")->execute(array_map('strval', $ids));
    $noticeWhere="(entity_type='dl_deliveries' AND entity_id IN ($marks)) OR branch_id IN (?,?) OR aggregate_key IN (?,?)";
    $noticeArgs=array_merge($ids,[$origin,$destination,'delivery-origin-'.$delivery,'delivery-origin-'.$duplicateDelivery]);
    if ($receivings) {
        $receivingMarks=implode(',',array_fill(0,count($receivings),'?'));
        $noticeWhere.=" OR (entity_type='dl_branch_receivings' AND entity_id IN ($receivingMarks))";
        $noticeArgs=array_merge($noticeArgs,$receivings);
    }
    $db->prepare("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE $noticeWhere)")->execute($noticeArgs);
    $db->prepare("DELETE FROM dl_integrity_notifications WHERE $noticeWhere")->execute($noticeArgs);
    $db->prepare("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ($marks)")->execute($ids);
    $db->prepare("DELETE FROM dl_branch_receivings WHERE delivery_id IN ($marks)")->execute($ids);
    $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($ids);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id=? AND product_id=?')->execute([$origin, $product]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id=? AND product_id=?')->execute([$destination, $product]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?,?) AND product_id=?')->execute([$origin, $destination, $product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?,?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_products WHERE id=?')->execute([$product]);
};
$cleanup();

$runApi = static function (string $mode, array $body) use ($payload): array {
    file_put_contents($payload, json_encode($body, JSON_THROW_ON_ERROR));
    $out=[]; $code=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/daily_ledger_integrity_resolution_harness.php').' '.escapeshellarg($mode).' '.escapeshellarg($payload).' 2>/dev/null', $out, $code);
    return ['code'=>$code, 'body'=>json_decode(implode("\n", $out), true), 'raw'=>implode("\n", $out)];
};

try {
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')->execute([$origin,'IR-ORIG','Integrity Origin']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,assigned_commissary_id,is_active) VALUES (?,?,?,0,?,1)')->execute([$destination,'IR-DEST','Integrity Destination',$origin]);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')->execute([$product,'IR-P','Integrity Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,1)')->execute([$origin,$product]);
    $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,status,remarks) VALUES (?,"commissary",NULL,"branch",?,?,?,"posted","[captured-from-paper-dr]")')->execute([$delivery,$destination,'IR-994801',$date]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,10,"pcs",10)')->execute([$delivery,$product]);
    $receiving = dl_acceptFormalDelivery($db,$destination,$delivery,1,$date,null,'AM');
    dl_markReceivingCountBasis($db,$receiving,'copied');

    $trace = dl_buildAdminTraceData($db,['dr'=>'IR-994801'],[$origin,$destination],'admin');
    $doc = $trace['documents'][0] ?? [];
    $h->section('truthful unresolved labels');
    $h->test('NULL origin reads origin unresolved (revert falls back to Commissary)', ($doc['origin_label'] ?? '') === 'origin unresolved');
    $h->test('copied receipt is labelled not independently counted in receiving evidence (revert shows a counted 10)', ($doc['receiving']['rows'][0]['count_basis_label'] ?? '') === 'not independently counted');
    $h->test('copied receipt cannot render as a variance match (revert omits the exact-match row)', ($doc['variance']['items'][0]['state'] ?? '') === 'uncounted' && ($doc['variance']['items'][0]['received_display'] ?? '') === 'not independently counted');
    $matrix = dl_fetchProductionSheetReceivingMatrix($db,$date,$origin);
    $h->test('Daily Sheet carries both unresolved-origin and uncounted labels (revert excludes/marks the cell received)', !empty($matrix[$product][$destination]['origin_unresolved']) && !empty($matrix[$product][$destination]['not_independently_counted']));

    $h->section('editable logged admin resolutions');
    $originResult = $runApi('origin',['delivery_id'=>$delivery,'origin_id'=>$origin,'note'=>'paper archive box 7']);
    $countResult = $runApi('count',['receiving_id'=>$receiving,'items'=>[(string)(int)$db->query("SELECT id FROM dl_delivery_items WHERE delivery_id=$delivery")->fetchColumn()=>4],'note'=>'signed recount sheet']);
    $resolved = $db->query("SELECT resolved_origin_id FROM dl_deliveries WHERE id=$delivery")->fetchColumn();
    $countRow = $db->query("SELECT count_basis,count_resolved_by,count_resolved_at FROM dl_branch_receivings WHERE id=$receiving")->fetch(PDO::FETCH_ASSOC);
    $actual = $db->query("SELECT quantity_received FROM dl_branch_receiving_items WHERE receiving_id=$receiving")->fetchColumn();
    $audit = $db->prepare("SELECT action FROM audit_logs WHERE module='daily-ledger' AND ((entity_type='dl_deliveries' AND entity_id=?) OR (entity_type='dl_branch_receivings' AND entity_id=?))");
    $audit->execute([(string)$delivery,(string)$receiving]); $actions=$audit->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $h->test('admin origin resolution persists and is logged (revert leaves resolved_origin_id NULL)', !empty($originResult['body']['ok']) && (int)$resolved===$origin && in_array('resolve_delivery_origin',$actions,true), $originResult['raw']);
    $h->test('admin actual count is editable provenance and logged (revert leaves copied 10)', !empty($countResult['body']['ok']) && $countRow['count_basis']==='independently_counted' && $countRow['count_resolved_at']!==null && (int)$actual===4 && in_array('resolve_receiving_count',$actions,true), $countResult['raw']);
    $trace2=dl_buildAdminTraceData($db,['dr'=>'IR-994801'],[$origin,$destination],'admin'); $doc2=$trace2['documents'][0]??[];
    $h->test('resolved count clears uncounted label and exposes variance -6 (revert still claims copied match)', ($doc2['receiving']['rows'][0]['count_basis_label']??'')==='independently counted' && ($doc2['variance']['items'][0]['delta_display']??'')==='-6');

    $h->section('duplicate delivery lines');
    $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,status) VALUES (?,"commissary",?,"branch",?,?,?,"posted")')->execute([$duplicateDelivery,$origin,$destination,'IR-DUP',$date]);
    $ins=$db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,?,"pcs",10)');
    $ins->execute([$duplicateDelivery,$product,5]); $itemA=(int)$db->lastInsertId();
    $ins->execute([$duplicateDelivery,$product,9]); $itemB=(int)$db->lastInsertId();
    $db->beginTransaction();
    $dupReceiving=dl_acceptFormalDeliveryByItem($db,$destination,$duplicateDelivery,1,$date,[$itemA=>3,$itemB=>7],'AM');
    $db->commit();
    $q=$db->prepare('SELECT delivery_item_id,quantity_received FROM dl_branch_receiving_items WHERE receiving_id=? ORDER BY delivery_item_id'); $q->execute([$dupReceiving]);
    $got=[]; foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$got[(int)$row['delivery_item_id']]=(int)$row['quantity_received'];}
    $h->test('duplicate-product counts stay keyed to delivery item (revert applies one product count to both lines)', ($got[$itemA]??null)===3 && ($got[$itemB]??null)===7);
} finally {
    $cleanup();
    if (is_file($payload)) unlink($payload);
}
$h->done();
