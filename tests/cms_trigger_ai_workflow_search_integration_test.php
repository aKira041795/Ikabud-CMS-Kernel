<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/EventTriggers.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/workflow/helpers.php';
require_once __DIR__ . '/../modules/search/helpers.php';
require_once __DIR__ . '/../modules/ai/helpers.php';

$pass=0;$fail=0;$errs=[];
function t($l,$ok,$d=''){global $pass,$fail,$errs; if($ok){$pass++;echo"  ✓ $l\n";}else{$fail++;$errs[]=$l.($d?": $d":'');echo"  ✗ $l".($d?" — $d":'')."\n";}}
file_put_contents(STORAGE_PATH.'/logs/app.log','');file_put_contents(STORAGE_PATH.'/logs/error.log','');
$capFile=STORAGE_PATH.'/cache/cms_fanout.jsonl';@unlink($capFile);

function test_fanout(mixed $p,string $cid='',string $pid=''):array{
    $id=is_array($p)?(string)($p['content_id']??''):''; $title=is_array($p)?(string)($p['title']??''):''; $slug=is_array($p)?(string)($p['slug']??''):'';
    $out=['e'=>is_array($p)?(string)($p['trigger_event']??''):'','w'=>null,'s'=>null,'a'=>null];
    try{$out['w']=app()->cap()->call('workflow.state.get@1',['workflow_key'=>'cms.content','module'=>'cms','entity_type'=>'cms_content','entity_id'=>$id],['caller_module'=>'cms','caller_user'=>['id'=>1,'role'=>'superadmin','source'=>'cms']]);}catch(Throwable $e){$out['w']=['ok'=>false,'error'=>'ex'];}
    try{$out['s']=app()->cap()->call('search.index.upsert@1',['module'=>'cms','entity_type'=>'post','entity_id'=>$id,'title'=>$title,'excerpt'=>'x','search_text'=>'x','json_metadata'=>['slug'=>$slug]],['caller_module'=>'cms']);}catch(Throwable $e){$out['s']=['ok'=>false,'error'=>'ex'];}
    try{$out['a']=app()->cap()->call('ai.text.generate@1',['messages'=>[['role'=>'system','content'=>'Return OK'],['role'=>'user','content'=>'OK']],'json'=>false,'timeout_ms'=>2000],['caller_module'=>'cms','caller_user'=>['id'=>1,'role'=>'superadmin','source'=>'cms']]);}catch(Throwable $e){$out['a']=['ok'=>false,'error'=>'ex'];}
    @file_put_contents(STORAGE_PATH.'/cache/cms_fanout.jsonl',json_encode($out,JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND);
    return ['ok'=>true];
}
try{app()->capabilities()->register('test.fanout@1','tests','test_fanout',1,['first']);}catch(Throwable $e){}

$db=app()->db();
$db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id='test.fanout@1' AND event_key='cms.content.published'")->execute();
t('trigger saved', kernelTriggerSave('cms','cms.content.published','test.fanout@1',true,null,['m'=>1],null,1));
$title='Trig '.bin2hex(random_bytes(3));$slug=strtolower(str_replace(' ','-',$title));
$db->prepare("INSERT INTO cms_content (uuid,title,slug,body,excerpt,type,status,author_id,comment_status,published_at,created_at) VALUES (?,?,?,?,?,'post','published',1,'open',NOW(),NOW())")
   ->execute([cmsUuid(),$title,$slug,'<p>x</p>','x']);
$cid=(int)$db->lastInsertId(); t('content created',$cid>0);
if(function_exists('kernelEmitEvent')) kernelEmitEvent('cms.content.published',['content_id'=>$cid,'title'=>$title,'slug'=>$slug,'type'=>'post'],'cms');

$row=null; if(is_file($capFile)) foreach(file($capFile,FILE_IGNORE_NEW_LINES) as $ln){$r=json_decode((string)$ln,true); if(is_array($r)&&($r['e']??'')==='cms.content.published'){ $row=$r; break; }}

t('fanout ran', is_array($row));
t('workflow ok', is_array($row)&&!empty($row['w']['ok']), is_array($row)?(string)($row['w']['error']??''):'');
t('search ok', is_array($row)&&!empty($row['s']['ok']), is_array($row)?(string)($row['s']['error']??''):'');
$cnt=0; try{$q=$db->prepare("SELECT COUNT(*) FROM kernel_search_index WHERE module='cms' AND entity_type='post' AND entity_id=?");$q->execute([(string)$cid]);$cnt=(int)$q->fetchColumn();}catch(Throwable $e){}

t('search row exists',$cnt>0);
t('ai invoked', is_array($row)&&is_array($row['a']??null)&&array_key_exists('ok',$row['a']));
if(is_array($row)&&is_array($row['a']??null)&&empty($row['a']['ok'])) t('ai not timeout', !str_contains(strtolower((string)($row['a']['error']??'')),'timed out'), (string)($row['a']['error']??''));

try{$db->prepare('DELETE FROM cms_content WHERE id=?')->execute([$cid]);}catch(Throwable $e){}
try{$db->prepare("DELETE FROM kernel_search_index WHERE module='cms' AND entity_type='post' AND entity_id=?")->execute([(string)$cid]);}catch(Throwable $e){}
try{$db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id='test.fanout@1' AND event_key='cms.content.published'")->execute();}catch(Throwable $e){}

$crit=array_filter(explode("\n",(string)@file_get_contents(STORAGE_PATH.'/logs/app.log')),fn($l)=>str_contains($l,'[critical]'));
t('no app critical', empty($crit), implode('; ',$crit));
t('no php errors', trim((string)@file_get_contents(STORAGE_PATH.'/logs/error.log'))==='');

echo"\n══════════════════════════════════════════════════\n  PASS: $pass  FAIL: $fail\n══════════════════════════════════════════════════\n";
if($errs){echo"\nFailed tests:\n"; foreach($errs as $e) echo"  - $e\n";}
exit($fail?1:0);
