<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-enforcement', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('modules/daily-ledger/database/migrations/068_delivery_ledger_enforcement_notifications.sql');
$h->fingerprint('templates/modules/daily-ledger/admin/variances.disyl');
$h->allowLogLines('disyl.compile.phases');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$origin=99581; $destination=99582; $product=99581; $delivery=995801; $unresolved=995802; $date='2033-05-19'; $autoDr='EF-AUTO-DR';
$countNotifications=static function() use($db):array {
    return [
        'notifications'=>(int)$db->query('SELECT COUNT(*) FROM dl_integrity_notifications')->fetchColumn(),
        'recipients'=>(int)$db->query('SELECT COUNT(*) FROM dl_integrity_notification_recipients')->fetchColumn(),
    ];
};
$payload=tempnam(sys_get_temp_dir(),'dl-enforce-');
$runApi=static function(string $mode,array $body) use($payload):array {
    file_put_contents($payload,json_encode($body,JSON_THROW_ON_ERROR));
    $out=[];$code=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/daily_ledger_integrity_resolution_harness.php').' '.escapeshellarg($mode).' '.escapeshellarg($payload).' 2>/dev/null',$out,$code);
    return ['process_code'=>$code,'body'=>json_decode(implode("\n",$out),true),'raw'=>implode("\n",$out)];
};
$cleanup=static function() use($db,$origin,$destination,$product,$delivery,$unresolved,$date,$autoDr):void {
    $autoStmt=$db->prepare('SELECT id FROM dl_deliveries WHERE destination_id=? AND delivery_date=? AND dr_number=?');
    $autoStmt->execute([$destination,$date,$autoDr]); $autoIds=array_map('intval',$autoStmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
    $deliveryIds=array_values(array_unique(array_merge([$delivery,$unresolved],$autoIds)));
    $deliveryMarks=implode(',',array_fill(0,count($deliveryIds),'?'));
    $effectStmt=$db->prepare("SELECT id FROM dl_delivery_ledger_effects WHERE delivery_id IN ($deliveryMarks)");
    $effectStmt->execute($deliveryIds); $effectIds=array_map('strval',$effectStmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
    if($effectIds){$effectMarks=implode(',',array_fill(0,count($effectIds),'?'));$db->prepare("DELETE FROM audit_logs WHERE module='daily-ledger' AND entity_type='dl_delivery_ledger_effects' AND entity_id IN ($effectMarks)")->execute($effectIds);}
    $db->prepare("DELETE FROM audit_logs WHERE module='daily-ledger' AND entity_type='dl_deliveries' AND entity_id IN ($deliveryMarks)")->execute(array_map('strval',$deliveryIds));
    $noticeWhere="branch_id IN (?,?) OR (entity_type='dl_deliveries' AND entity_id IN (?,?)) OR aggregate_key IN (?,?) OR aggregate_key LIKE 'variance-9958%'";
    $noticeArgs=[$origin,$destination,$delivery,$unresolved,'delivery-origin-'.$delivery,'delivery-origin-'.$unresolved];
    $db->prepare("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE $noticeWhere)")->execute($noticeArgs);
    $db->prepare("DELETE FROM dl_integrity_notifications WHERE $noticeWhere")->execute($noticeArgs);
    $db->prepare('DELETE FROM dl_variance_flags WHERE branch_id=? AND product_id=? AND ledger_date=?')->execute([$destination,$product,$date]);
    $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($deliveryMarks)")->execute($deliveryIds);
    $db->prepare('DELETE FROM dl_production_runs WHERE destination_branch_id=? AND product_id=? AND ledger_date=? AND dr_number=?')->execute([$destination,$product,$date,$autoDr]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id=? AND product_id=? AND ledger_date=?')->execute([$origin,$product,$date]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?,?) AND product_id=?')->execute([$origin,$destination,$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?,?)')->execute([$origin,$destination]);
    $db->prepare('DELETE FROM dl_products WHERE id=?')->execute([$product]);
};
$cleanup();
$tenantCountsBefore=$countNotifications();
$tenantCountsAfter=[];

try {
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')->execute([$origin,'EF-ORIG','Enforcement Origin']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,assigned_commissary_id,default_supply_mode,is_active) VALUES (?,?,?,0,?,"commissary_supplied",1)')->execute([$destination,'EF-DEST','Enforcement Destination',$origin]);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')->execute([$product,'EF-P','Enforcement Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,1)')->execute([$origin,$product]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,beg_qty,produced_qty,dispatched_qty,actual_end_qty) VALUES (?,?,?,20,30,4,40)')->execute([$origin,$product,$date]);
    $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,status) VALUES (? ,"commissary",?,"branch",?,?,?,"posted")')->execute([$delivery,$origin,$destination,'EF-DR',$date]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,7,"pcs",10)')->execute([$delivery,$product]);

    $before=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);
    $db->beginTransaction(); $first=dl_applyPostedDeliveryCommissaryLedger($db,$delivery,1); $db->commit();
    $after=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);
    $db->beginTransaction(); $retry=dl_applyPostedDeliveryCommissaryLedger($db,$delivery,1); $db->commit();
    $afterRetry=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);
    $void=$runApi('void',['delivery_id'=>$delivery,'reason'=>'fixture reversal']);
    $reversed=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);

    $h->section('enforced commissary debit and reversal');
    $h->test('posting debits dispatched 4→11 and remaining 26→19 (revert leaves both unchanged)', $before['dispatched_qty']===4 && $before['remaining_qty']===26 && $before['calc_variance']===-6 && $after['dispatched_qty']===11 && $after['remaining_qty']===19 && $after['calc_variance']===1, json_encode([$before,$after]));
    $h->test('retry applies the item effect once (revert doubles dispatched to 18)', $first['applied']===1 && $retry['applied']===0 && $afterRetry===$after, json_encode([$first,$retry,$afterRetry]));
    $h->test('void automatically restores dispatched 11→4 and remaining 19→26 (revert leaves ledger wrong)', !empty($void['body']['ok']) && $reversed['dispatched_qty']===4 && $reversed['remaining_qty']===26 && $reversed['calc_variance']===-6, $void['raw'].' '.json_encode($reversed));

    $h->section('auto-production delivery uses the same durable reversal');
    $db->prepare('INSERT INTO dl_production_runs (ledger_date,product_id,baker_name,run_type,primary_input_qty,primary_input_type,yield_qty,dr_number,destination_branch_id,recorded_by) VALUES (?, ?, "Fixture Baker", "regular", 1, "kilo", 6, ?, ?, 1)')->execute([$date,$product,$autoDr,$destination]);
    $autoBefore=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);
    $db->beginTransaction(); $autoDelivery=dl_syncAutoCommissaryDeliveryFromRuns($db,$date,$destination,$autoDr,1); $db->commit();
    $autoPosted=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);
    $autoEffect=$db->query("SELECT delivery_id,quantity,effect_status,before_dispatched_qty,after_dispatched_qty FROM dl_delivery_ledger_effects WHERE delivery_id=".(int)$autoDelivery)->fetch(PDO::FETCH_ASSOC);
    $db->beginTransaction(); $autoReplay=dl_syncAutoCommissaryDeliveryFromRuns($db,$date,$destination,$autoDr,1); $db->commit();
    $autoAfterReplay=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);
    $db->prepare('DELETE FROM dl_production_runs WHERE destination_branch_id=? AND product_id=? AND ledger_date=? AND dr_number=?')->execute([$destination,$product,$date,$autoDr]);
    $db->beginTransaction(); $autoVoid=dl_syncAutoCommissaryDeliveryFromRuns($db,$date,$destination,$autoDr,1); $db->commit();
    $autoReversed=dl_commissaryLedgerSnapshot($db,$origin,$product,$date);
    $autoEffectAfter=$db->query("SELECT effect_status,reverse_before_dispatched_qty,reverse_after_dispatched_qty FROM dl_delivery_ledger_effects WHERE delivery_id=".(int)$autoDelivery)->fetch(PDO::FETCH_ASSOC);
    $autoStatus=$db->query("SELECT status FROM dl_deliveries WHERE id=".(int)$autoDelivery)->fetchColumn();
    $h->test('auto-production post debits 4→10 and stores its per-item effect (revert leaves no durable reversal evidence)', $autoBefore['dispatched_qty']===4 && $autoPosted['dispatched_qty']===10 && (int)($autoEffect['quantity']??0)===6 && ($autoEffect['effect_status']??'')==='applied' && (int)($autoEffect['before_dispatched_qty']??-1)===4 && (int)($autoEffect['after_dispatched_qty']??-1)===10, json_encode([$autoBefore,$autoPosted,$autoEffect]));
    $h->test('auto-production replay is idempotent (revert debits the same run twice)', $autoReplay===$autoDelivery && $autoAfterReplay===$autoPosted, json_encode([$autoPosted,$autoAfterReplay]));
    $h->test('auto-production void reverses only its applied 6 and marks the effect reversed (revert leaves dispatched at 10)', $autoVoid===null && $autoStatus==='voided' && $autoReversed['dispatched_qty']===4 && ($autoEffectAfter['effect_status']??'')==='reversed' && (int)($autoEffectAfter['reverse_before_dispatched_qty']??-1)===10 && (int)($autoEffectAfter['reverse_after_dispatched_qty']??-1)===4, json_encode([$autoPosted,$autoReversed,$autoEffectAfter]));

    $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,status) VALUES (? ,"commissary",NULL,"branch",?,?,?,"posted")')->execute([$unresolved,$destination,'EF-UNKNOWN',$date]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,3,"pcs",10)')->execute([$unresolved,$product]);
    $db->beginTransaction(); $unknownResult=dl_applyPostedDeliveryCommissaryLedger($db,$unresolved,1); $db->commit();
    $unknownNotice=$db->query("SELECT n.id,n.title,r.user_id,r.notified_at,r.seen_at FROM dl_integrity_notifications n JOIN dl_integrity_notification_recipients r ON r.notification_id=n.id WHERE n.aggregate_key='delivery-origin-$unresolved' ORDER BY r.user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $h->test('unattributable dispatch stores unresolved notification and guesses no debit (revert silently skips without notifying)', $unknownResult['status']==='unresolved_origin' && (int)$unknownNotice['user_id']>0 && $unknownNotice['notified_at']!==null && $unknownNotice['seen_at']===null && dl_commissaryLedgerSnapshot($db,$origin,$product,$date)===$reversed, json_encode([$unknownResult,$unknownNotice]));
    $renderedPage=$runApi('variances-page',[]);
    $seenAt=$db->query("SELECT seen_at FROM dl_integrity_notification_recipients WHERE notification_id=".(int)$unknownNotice['id']." AND user_id=".(int)$unknownNotice['user_id'])->fetchColumn();
    $h->test('addressed admin sees the recorded notification in-app and seen_at is stored (revert leaves no provable delivery)', str_contains($renderedPage['raw'],'Dispatch origin must be resolved') && $seenAt!==false && $seenAt!==null, 'seen_at='.(string)$seenAt);

    dl_upsertVarianceFlag($db,$destination,$product,$date,'ending','PM',-2,12,10,12,null,0,0);
    $varianceId=(int)$db->query("SELECT id FROM dl_variance_flags WHERE branch_id=$destination AND product_id=$product AND ledger_date='$date' AND kind='ending' AND shift='PM'")->fetchColumn();
    $variancePage=$runApi('variances-page',[]);
    $tracePage=$runApi('trace-page',['branch_id'=>$destination,'date_from'=>$date,'date_to'=>$date,'product_id'=>$product,'variance_id'=>$varianceId]);
    $direct=$runApi('variance',['variance_id'=>$varianceId,'status'=>'corrected','review_note'=>'should refuse']);
    $investigate=$runApi('variance',['variance_id'=>$varianceId,'status'=>'investigated','review_note'=>'Checked DR EF-DR against production sheet']);
    $correct=$runApi('variance',['variance_id'=>$varianceId,'status'=>'corrected','review_note'=>'Corrected after signed recount']);
    $stored=$db->query("SELECT resolution_status,reviewed_by,reviewed_at,review_note FROM dl_variance_flags WHERE id=$varianceId")->fetch(PDO::FETCH_ASSOC);
    $reopen=$runApi('variance',['variance_id'=>$varianceId,'status'=>'investigated','review_note'=>'Reopened after second witness']);
    $auditCount=(int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module='daily-ledger' AND action='variance_status' AND entity_type='dl_variance_flags' AND entity_id='$varianceId'")->fetchColumn();

    $h->section('notification-investigation-resolution ordering');
    $expectedTraceUrl='/admin/trace?branch_id='.$destination.'&date_from='.$date.'&date_to='.$date.'&product_id='.$product.'&variance_id='.$varianceId;
    $h->test('finding links to its filtered Production Delivery Audit and the audit links back (revert strands the investigation)', str_contains($variancePage['raw'],$expectedTraceUrl) && str_contains($tracePage['raw'],'data-variance-investigation="'.$varianceId.'"') && str_contains($tracePage['raw'],'Enforcement Product') && str_contains($tracePage['raw'],'Back to variance findings'));
    $h->test('direct unreviewed→corrected is refused with HTTP 409 (revert accepts the owner-forbidden shortcut)', (int)($direct['body']['status']??0)===409 && ($direct['body']['ok']??null)===false && str_contains((string)($direct['body']['error']??''),'investigated'), $direct['raw']);
    $h->test('investigated→corrected stores actor, time, and note (revert permits unaudited resolution)', !empty($investigate['body']['ok']) && !empty($correct['body']['ok']) && $stored['resolution_status']==='corrected' && (int)$stored['reviewed_by']===1 && $stored['reviewed_at']!==null && $stored['review_note']==='Corrected after signed recount', json_encode($stored));
    $h->test('reopen returns to investigated and preserves all three revisions in audit (revert erases history)', !empty($reopen['body']['ok']) && $auditCount===3 && $db->query("SELECT resolution_status FROM dl_variance_flags WHERE id=$varianceId")->fetchColumn()==='investigated', 'audit revisions='.$auditCount);
    $digestCount=(int)$db->query("SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key='historical-count-basis-null'")->fetchColumn();
    $h->test('historical unknown-count findings have one aggregate notification (revert floods one per receipt)', $digestCount===1, 'digest rows='.$digestCount);
} finally {
    $cleanup();
    $tenantCountsAfter=$countNotifications();
    if(is_file($payload)) unlink($payload);
}
$h->section('fixture cleanup invariant');
$h->test('tenant notification and recipient counts are identical before/after (removing fixture cleanup makes this assertion fail)', $tenantCountsAfter===$tenantCountsBefore, 'before='.json_encode($tenantCountsBefore).' after='.json_encode($tenantCountsAfter));
echo '  measured tenant counts: before='.json_encode($tenantCountsBefore).' after='.json_encode($tenantCountsAfter).PHP_EOL;
$h->done();
